<?php

namespace App\Http\Controllers\Api\Concerns;

/**
 * منقولة من app/Controllers/Api/Concerns/Paginates.php القديمة حرف بحرف.
 * pagination بسيط على array في الميموري (مش SQL LIMIT/OFFSET) — بيطابق
 * نفس القرار القديم بالظبط: كل repository method وراها بترجع النتيجة
 * كاملة كـ array أصلًا.
 *
 * فرق شكلي واحد فقط عن القديمة: $this->input()/$this->request القديمة
 * (Core\Request) استُبدلت بـ Illuminate\Http\Request المُمرر صراحة لكل
 * ميثود، عشان الترايت ميعتمدش على وجود property معينة في الكنترولر
 * المستخدم ليها.
 */
trait Paginates
{
    private int $paginatesDefault = 20;
    private int $paginatesMax = 100;

    /** @return array{0:int,1:int,2:int,3:array} [page, perPage, total, sliced items] */
    private function paginateArray(\Illuminate\Http\Request $request, array $rows): array
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min($this->paginatesMax, (int) $request->input('per_page', $this->paginatesDefault)));
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);
        return [$page, $perPage, $total, $items];
    }

    /**
     * مطابقة substring case-insensitive على $fields (اشتغل مع arrays
     * وModel instances عبر toArray()) باستخدام query param `search` (أو
     * `q`). لو مفيش قيمة بحث، بترجع $rows زي ما هي.
     */
    private function filterBySearch(\Illuminate\Http\Request $request, array $rows, array $fields): array
    {
        $needle = mb_strtolower(trim((string) $request->input('search', (string) $request->input('q', ''))));
        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($fields, $needle) {
            $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            foreach ($fields as $field) {
                if (isset($row[$field]) && str_contains(mb_strtolower((string) $row[$field]), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }
}
