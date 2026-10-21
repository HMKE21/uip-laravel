<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/desktop/widgets — الملاحظات اللاصقة والويدجت على سطح المكتب، بتتابع الطالب على أي جهاز.
 *
 *  GET  widgets   { exists, updatedAt, items }
 *  PUT  widgets   { updatedAt, items } — الأحدث بيكسب؛ لو الجهاز قديم بيرجع stale:true ومعاه اللي على السيرفر.
 *
 * كل عنصر بيتنضّف هنا (whitelist للحقول + حدود للأحجام) — مفيش HTML: النص بيتعرض كنص في الفرونت.
 */
class DesktopWidgetsApiController extends Controller
{
    private const MAX_ITEMS = 60;
    private const TYPES = ['note', 'clock', 'calendar', 'todo'];

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function row(int $uid): object
    {
        $row = DB::table('desktop_widgets')->where('user_id', $uid)->first();
        if (!$row) {
            DB::table('desktop_widgets')->insertOrIgnore(['user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('desktop_widgets')->where('user_id', $uid)->first();
        }

        return $row;
    }

    private function payload(object $row): array
    {
        $items = $row->items ? json_decode($row->items, true) : null;

        return ['exists' => (int) $row->updated_ms > 0, 'updatedAt' => (int) $row->updated_ms, 'items' => is_array($items) ? $items : []];
    }

    private static function str($v, int $max): string
    {
        return mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
    }

    private static function int($v, int $min, int $max, int $def): int
    {
        return is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : $def;
    }

    private function clean(array $raw): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice($raw, 0, self::MAX_ITEMS) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (string) ($it['id'] ?? '');
            $type = (string) ($it['type'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,24}$/', $id) || isset($seen[$id]) || !in_array($type, self::TYPES, true)) {
                continue;
            }
            $seen[$id] = true;
            $o = [
                'id' => $id, 'type' => $type,
                'x' => self::int($it['x'] ?? 0, 0, 20000, 40), 'y' => self::int($it['y'] ?? 0, 0, 20000, 40),
                'w' => self::int($it['w'] ?? 220, 120, 1200, 220), 'h' => self::int($it['h'] ?? 220, 80, 1200, 220),
                'z' => self::int($it['z'] ?? 1, 0, 1000000, 1),
            ];
            $color = (string) ($it['color'] ?? '');
            if (preg_match('/^[a-z]{1,12}$/', $color)) {
                $o['color'] = $color;
            }
            if ($type === 'note') {
                $o['text'] = self::str($it['text'] ?? '', 5000);
            } elseif ($type === 'clock') {
                $o['h24'] = (bool) ($it['h24'] ?? false);
            } elseif ($type === 'todo') {
                $o['title'] = self::str($it['title'] ?? '', 60);
                $todos = [];
                foreach (array_slice(is_array($it['todos'] ?? null) ? $it['todos'] : [], 0, 50) as $t) {
                    if (is_array($t) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', (string) ($t['id'] ?? ''))) {
                        $todos[] = ['id' => (string) $t['id'], 't' => self::str($t['t'] ?? '', 200), 'done' => (bool) ($t['done'] ?? false)];
                    }
                }
                $o['todos'] = $todos;
            }
            $out[] = $o;
        }

        return $out;
    }

    public function show(Request $request): JsonResponse
    {
        return $this->apiSuccess($this->payload($this->row($this->uid($request))), 'Desktop widgets retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $uid = $this->uid($request);
        $request->validate(['updatedAt' => 'required|numeric|min:0', 'items' => 'present|array|max:' . self::MAX_ITEMS]);
        $row = $this->row($uid);
        $at = min((int) $request->input('updatedAt'), (int) (microtime(true) * 1000) + 300_000);
        if ($at < (int) $row->updated_ms) {
            return $this->apiSuccess($this->payload($row) + ['stale' => true], 'A newer version exists.');
        }
        DB::table('desktop_widgets')->where('user_id', $uid)->update([
            'items' => json_encode($this->clean((array) $request->input('items', [])), JSON_UNESCAPED_UNICODE),
            'updated_ms' => max($at, 1), 'updated_at' => now(),
        ]);

        return $this->apiSuccess($this->payload($this->row($uid)), 'Desktop widgets saved.');
    }
}
