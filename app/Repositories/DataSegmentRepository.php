<?php

namespace App\Repositories;

use App\Models\DataSegment;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/DataSegmentRepository.php القديمة — بند 24
 * batch 2. Data access لـ `data_segments` (migration 042). `criteria`
 * عمود JSON، بيتعامل معاه هنا يدويًا encode/decode.
 */
class DataSegmentRepository
{
    /** الأدوار القابلة للاختيار في فلتر "role" بتاع Segments — نفس الأدوار اللي مولّد الديمو بيزرعها بالجملة. */
    public const USER_ROLE_SLUGS = ['student', 'university'];

    /** قيم users.status (migration 001) — لازم criteria الـ segment تطابقها بالظبط، مش label مترجم. */
    public const USER_STATUS_VALUES = ['active', 'pending', 'suspended', 'banned'];

    public function create($userId, string $name, string $entity, array $criteria): DataSegment
    {
        return DataSegment::create([
            'user_id'  => $userId,
            'name'     => $name,
            'entity'   => $entity,
            'criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<int,array<string,mixed>> مع `criteria` مفكوكة ومعاها matched_count حي */
    public function forUser($userId): array
    {
        $rows = DB::table('data_segments')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        return $rows->map(function ($row) {
            $row = (array) $row;
            $row['criteria'] = json_decode($row['criteria'] ?? '{}', true) ?: [];
            $row['matched_count'] = $this->countMatches($row['entity'], $row['criteria']);
            return $row;
        })->all();
    }

    public function findOwned($id, $userId): ?DataSegment
    {
        $s = DataSegment::find($id);
        if ($s && (string) $s->user_id === (string) $userId) {
            return $s;
        }
        return null;
    }

    public function delete($id, $userId): bool
    {
        $s = $this->findOwned($id, $userId);
        if (!$s) {
            return false;
        }
        return (bool) $s->delete();
    }

    /**
     * بتقيّم criteria الـ segment المحفوظة على الجدول الحقيقي دلوقتي، بدل
     * ما تكاش عدد قديم — الـ segments المفروض تكون فلاتر حية. بس مجموعة
     * محدودة allow-listed من معايير equality/range مدعومة لكل entity، عشان
     * نتجنب بناء query builder عام (وقابل للحقن) هنا.
     */
    private function countMatches(string $entity, array $criteria): int
    {
        if ($entity === 'projects') {
            $query = DB::table('projects');
            if (!empty($criteria['category'])) {
                $query->where('category', $criteria['category']);
            }
            if (!empty($criteria['status'])) {
                $query->where('status', $criteria['status']);
            }
            return $query->count();
        }

        if ($entity === 'users') {
            $query = DB::table('users as u');
            if (!empty($criteria['status'])) {
                $query->where('u.status', $criteria['status']);
            }
            if (!empty($criteria['role'])) {
                $query->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->where('r.slug', $criteria['role']);
            }
            return $query->count();
        }

        return 0;
    }
}