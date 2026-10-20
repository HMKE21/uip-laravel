<?php

namespace App\Repositories;

use App\Models\ApiFile;

/**
 * نسخة طبق الأصل (منطقيًا) من app/Repositories/ApiFileRepository.php
 * القديمة — نفس شكل search()/findOwned()/incrementDownloadCount()/
 * softDelete()، بس باستخدام Eloquent query builder بدل Core\Database
 * الخام (نفس الفلاتر، نفس الـ default sort 'newest'، نفس pagination).
 */
class ApiFileRepository
{
    public function create(array $data): ApiFile
    {
        return ApiFile::create($data);
    }

    /** بيرجع الملف بس لو ملك $userId ومش soft-deleted — وإلا null. */
    public function findOwned($id, $userId): ?ApiFile
    {
        return ApiFile::where('id', $id)
            ->where('uploaded_by', $userId)
            ->where('is_deleted', 0)
            ->first();
    }

    /**
     * @param array<string,mixed> $filters category, search, date_from,
     *   date_to ('Y-m-d'), sort ('newest'|'oldest'|'name'|'size'), page, per_page
     * @return array{items:\Illuminate\Support\Collection<int,ApiFile>,total:int,page:int,per_page:int}
     */
    public function search($userId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        $query = ApiFile::where('uploaded_by', $userId)->where('is_deleted', 0);

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'name'   => $query->orderBy('original_name', 'asc'),
            'size'   => $query->orderBy('size_bytes', 'desc'),
            default  => $query->orderBy('created_at', 'desc'),
        };

        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function incrementDownloadCount($id): void
    {
        ApiFile::where('id', $id)->increment('download_count');
    }

    /** Soft delete — reversible in principle، بس مفيش restore() لسه (زي القديم بالظبط). */
    public function softDelete($id, $userId): bool
    {
        $file = $this->findOwned($id, $userId);
        if (!$file) {
            return false;
        }
        return $file->update(['is_deleted' => 1, 'deleted_at' => now()]);
    }
}
