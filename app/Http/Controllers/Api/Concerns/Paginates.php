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
     * بحث على $fields باستخدام query param `search` (أو `q`). لو مفيش قيمة بحث، بترجع $rows زي ما هي.
     *
     * - تطبيع عربي/إنجليزي: lower-case، شيل التشكيل والتطويل، توحيد الألف (أإآ→ا)، ى→ي، ئ→ي، ؤ→و، ة→ه، أرقام هندية→لاتينية.
     * - "ال" التعريف في أول الكلمة بتتشال من كلمة البحث (الحوادث ← حوادث).
     * - كل كلمات البحث لازم تظهر (بأي ترتيب وفي أي حقل من $fields) — مش لازم الجملة كلها حرفيًا.
     */
    private function filterBySearch(\Illuminate\Http\Request $request, array $rows, array $fields): array
    {
        $raw = trim((string) $request->input('search', (string) $request->input('q', '')));
        if ($raw === '') {
            return $rows;
        }

        $tokens = array_values(array_filter(explode(' ', $this->searchNormalize($raw)), fn ($t) => $t !== ''));
        $tokens = array_map(fn ($t) => (mb_strlen($t) > 3 && str_starts_with($t, 'ال')) ? mb_substr($t, 2) : $t, $tokens);
        if (!$tokens) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($fields, $tokens) {
            $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            $parts = [];
            foreach ($fields as $field) {
                if (isset($row[$field]) && is_scalar($row[$field])) {
                    $parts[] = (string) $row[$field];
                }
            }
            $hay = $this->searchNormalize(implode(' ', $parts));
            foreach ($tokens as $tk) {
                if (!str_contains($hay, $tk)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function searchNormalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s) ?? $s;
        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
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
