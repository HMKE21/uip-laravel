<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiFile;
use App\Services\ApiFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نسخة طبق الأصل من app/Controllers/Api/FilesApiController.php القديمة —
 * نفس الـ 5 actions، نفس {success,message,data,errors,meta} envelope (عن
 * طريق Controller::apiSuccess()/apiError()). المستخدم الحالي بييجي من
 * $request->attributes->get('uip_user_id') (حطته UipAuthMiddleware) بدل
 * Session::userId() القديمة — كل action بيقيّد نفسه بملكية الملف بنفس
 * الطريقة بالظبط، فمستخدم متقدرش يشوف/يحمّل/يمسح ملف مستخدم تاني.
 */
class FilesApiController extends Controller
{
    public function __construct(private ApiFileService $files)
    {
    }

    /** GET /api/v1/files */
    public function index(Request $request): JsonResponse
    {
        $filters = array_filter([
            'category'  => $request->query('category'),
            'search'    => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
            'sort'      => $request->query('sort'),
            'page'      => $request->query('page'),
            'per_page'  => $request->query('per_page'),
        ], fn ($v) => $v !== null && $v !== '');

        $result = $this->files->search($request->attributes->get('uip_user_id'), $filters);

        $page = (int) $result['page'];
        $perPage = (int) $result['per_page'];
        $total = (int) $result['total'];

        $items = $result['items']->map(fn (ApiFile $f) => $this->toRow($f))->all();

        return $this->apiSuccess($items, 'Files retrieved successfully.', 200, [
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /** GET /api/v1/files/{id} */
    public function show(Request $request, $id): JsonResponse
    {
        $file = $this->files->findOwned($id, $request->attributes->get('uip_user_id'));
        if (!$file) {
            return $this->apiError('File not found.', null, 404);
        }
        return $this->apiSuccess($this->toRow($file), 'File retrieved successfully.');
    }

    /**
     * POST /api/v1/files
     * multipart/form-data: file=<binary>, category=<optional string, default 'general'>.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $file = $this->files->upload(
                $request->file('file'),
                $request->attributes->get('uip_user_id'),
                (string) $request->input('category', 'general')
            );
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess($this->toRow($file), 'File uploaded successfully.', 201);
    }

    /**
     * GET /api/v1/files/{id}/download
     * بيبعت محتوى الملف مباشرة بدل ما يكشف مسار public/uploads الخام —
     * نفس أسلوب القديم.
     */
    public function download(Request $request, $id)
    {
        $file = $this->files->findOwned($id, $request->attributes->get('uip_user_id'));
        if (!$file) {
            return $this->apiError('File not found.', null, 404);
        }

        $path = public_path($file->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File no longer exists.', null, 404);
        }

        $this->files->recordDownload($file->id);

        return response(file_get_contents($path), 200, [
            'Content-Type'        => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $file->original_name . '"',
        ]);
    }

    /** DELETE /api/v1/files/{id} */
    public function delete(Request $request, $id): JsonResponse
    {
        $ok = $this->files->delete($id, $request->attributes->get('uip_user_id'));
        if (!$ok) {
            return $this->apiError('File not found.', null, 404);
        }
        return $this->apiSuccess(null, 'File deleted successfully.');
    }

    private function toRow(ApiFile $f): array
    {
        return [
            'id'             => $f->id,
            'category'       => $f->category,
            'original_name'  => $f->original_name,
            'mime_type'      => $f->mime_type,
            'extension'      => $f->extension,
            'size_bytes'     => (int) $f->size_bytes,
            'download_count' => (int) $f->download_count,
            'download_url'   => '/api/v1/files/' . $f->id . '/download',
            'created_at'     => $f->created_at,
        ];
    }
}
