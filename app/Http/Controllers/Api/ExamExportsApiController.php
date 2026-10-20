<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مركز التصدير لعضو هيئة التدريس — /api/v1/exam-system/exports/*
 *
 *   GET    exports               سجل تصديراتي (الأحدث أولًا)
 *   POST   exports               {export_type: exam_results|students_results, exam_id?, format}
 *   GET    exports/{id}/preview  أول 100 صف زي ما كانت وقت التصدير
 *   GET    exports/{id}/download الملف المحفوظ
 *   DELETE exports/{id}
 *
 * بيستخدم نفس payload builders بتاعة التنزيل المباشر (examResultsPayload / studentsPayload)
 * فالأرقام متطابقة. الملفات بتتحفظ في storage/app/exam-exports/{staffId}/ (خاصة، مش public)
 * وكل صف مقيّد بـ academic_staff_id بتاع صاحب الحساب — عمره ما بييجي من العميل.
 */
class ExamExportsApiController extends ExamResultsHubApiController
{
    private const TYPES = ['exam_results', 'students_results'];
    private const FORMATS = ['csv', 'xlsx', 'pdf', 'json'];
    private const PREVIEW_ROWS = 100;
    private const KEEP_LAST = 100;

    public function index(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }

        $rows = DB::table('exam_exports')->where('academic_staff_id', $staffId)
            ->orderByDesc('id')->limit(self::KEEP_LAST)
            ->get(['id', 'export_type', 'exam_id', 'title', 'format', 'file_size', 'row_count', 'created_at']);

        return $this->apiSuccess($rows, 'Exports retrieved successfully.');
    }

    public function store(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }

        $type = (string) $request->input('export_type', '');
        $format = strtolower((string) $request->input('format', 'csv'));
        if (!in_array($type, self::TYPES, true) || !in_array($format, self::FORMATS, true)) {
            return $this->apiError('Unknown export type or format.', null, 422);
        }

        $examId = null;
        if ($type === 'exam_results') {
            $exam = $this->examSystem->findOwnedExam((int) $request->input('exam_id', 0), $staffId);
            if (!$exam) {
                return $this->apiError('Exam not found.', null, 404);
            }
            $examId = (int) $exam->id;
            [$title, $meta, $sections] = $this->examResultsPayload($exam);
        } else {
            [$title, $meta, $sections] = $this->studentsPayload($staffId, trim((string) $request->input('q', '')), (int) $request->input('exam_id', 0));
        }

        $dir = storage_path('app/exam-exports/' . $staffId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = ($type === 'exam_results' ? 'exam_results_' . $examId : 'students_results') . '_' . date('Ymd_His') . '.' . $format;
        $path = $dir . '/' . $name;

        try {
            $this->writeExportFile($format, $path, $title, $meta, $sections);
        } catch (\Throwable $e) {
            @unlink($path);
            return $this->apiError($e->getMessage(), null, 422);
        }

        $section = $sections[0] ?? ['header' => [], 'rows' => []];
        $total = count($section['rows']);
        $preview = [
            'header'    => array_values($section['header']),
            'rows'      => array_map('array_values', array_slice($section['rows'], 0, self::PREVIEW_ROWS)),
            'total'     => $total,
            'truncated' => $total > self::PREVIEW_ROWS,
            'meta'      => $meta,
        ];

        $id = DB::table('exam_exports')->insertGetId([
            'academic_staff_id' => $staffId,
            'export_type'       => $type,
            'exam_id'           => $examId,
            'title'             => mb_substr($title, 0, 255),
            'format'            => $format,
            'file_path'         => 'exam-exports/' . $staffId . '/' . $name,
            'file_size'         => (int) @filesize($path),
            'row_count'         => $total,
            'preview_json'      => json_encode($preview, JSON_UNESCAPED_UNICODE),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'academic_staff.exam_system.export_created', 'ExamExport', $id, null, ['format' => $format, 'type' => $type], $request->ip());
        $this->pruneOld($staffId);

        $row = DB::table('exam_exports')->where('id', $id)
            ->first(['id', 'export_type', 'exam_id', 'title', 'format', 'file_size', 'row_count', 'created_at']);

        return $this->apiSuccess($row, 'Export saved.', 201);
    }

    public function preview(Request $request, $id)
    {
        [$row, $err] = $this->findOwnedExport($request, $id);
        if ($err) {
            return $err;
        }
        $preview = json_decode((string) $row->preview_json, true) ?: ['header' => [], 'rows' => [], 'total' => 0, 'truncated' => false, 'meta' => []];

        return $this->apiSuccess($preview + [
            'id' => (int) $row->id, 'title' => $row->title, 'format' => $row->format, 'created_at' => $row->created_at,
        ], 'Preview retrieved successfully.');
    }

    public function download(Request $request, $id)
    {
        [$row, $err] = $this->findOwnedExport($request, $id);
        if ($err) {
            return $err;
        }
        $path = storage_path('app/' . $row->file_path);
        if (!is_file($path)) {
            return $this->apiError('The export file is no longer available.', null, 404);
        }

        return response()->download($path, basename($path));
    }

    public function destroy(Request $request, $id)
    {
        [$row, $err] = $this->findOwnedExport($request, $id);
        if ($err) {
            return $err;
        }
        @unlink(storage_path('app/' . $row->file_path));
        DB::table('exam_exports')->where('id', $row->id)->delete();

        return $this->apiSuccess(null, 'Export deleted.');
    }

    /**
     * POST exports/bulk-delete — {ids: int[]} يحذف المحدد، أو {all: true} يحذف كل تصديراتي.
     * دايمًا مقيّد بـ academic_staff_id بتاع صاحب الحساب: أي id مش بتاعه بيتجاهل.
     */
    public function bulkDestroy(Request $request)
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return $err;
        }

        $query = DB::table('exam_exports')->where('academic_staff_id', $staffId);
        if (!$request->boolean('all')) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
            if (!$ids) {
                return $this->apiError('Select at least one export.', null, 422);
            }
            $query->whereIn('id', array_slice($ids, 0, 500));
        }

        $rows = $query->get(['id', 'file_path']);
        foreach ($rows as $r) {
            @unlink(storage_path('app/' . $r->file_path));
        }
        $deleted = $rows->isEmpty() ? 0 : DB::table('exam_exports')
            ->where('academic_staff_id', $staffId)->whereIn('id', $rows->pluck('id')->all())->delete();

        $this->auditLog->record((int) $request->attributes->get('uip_user_id'), 'academic_staff.exam_system.exports_bulk_deleted', 'ExamExport', null, null, ['count' => $deleted, 'all' => $request->boolean('all')], $request->ip());

        return $this->apiSuccess(['deleted' => (int) $deleted], 'Exports deleted.');
    }

    // ---------------------------------------------------------------------

    /** @return array{0:?object,1:mixed} */
    private function findOwnedExport(Request $request, $id): array
    {
        [$staffId, $err] = $this->resolveStaffId($request);
        if ($err) {
            return [null, $err];
        }
        $row = DB::table('exam_exports')->where('id', (int) $id)->where('academic_staff_id', $staffId)->first();

        return $row ? [$row, null] : [null, $this->apiError('Export not found.', null, 404)];
    }

    /** بنحتفظ بآخر KEEP_LAST تصدير بس لكل عضو (ملف + صف). */
    private function pruneOld(int $staffId): void
    {
        $old = DB::table('exam_exports')->where('academic_staff_id', $staffId)
            ->orderByDesc('id')->skip(self::KEEP_LAST)->take(500)->get(['id', 'file_path']);
        foreach ($old as $o) {
            @unlink(storage_path('app/' . $o->file_path));
            DB::table('exam_exports')->where('id', $o->id)->delete();
        }
    }
}
