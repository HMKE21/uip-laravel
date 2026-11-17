<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('forms')) {
            return;
        }
        DB::table('forms')->where('mode', 'exam')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $s = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
                $s = is_array($s) ? $s : [];
                $e = is_array($s['exam'] ?? null) ? $s['exam'] : [];
                $e['fullscreen_required'] = true;
                $e['block_tab_switch'] = true;
                $e['block_copy_paste'] = true;
                if (empty($e['max_violations'])) {
                    $e['max_violations'] = 3;
                }
                $s['exam'] = $e;
                DB::table('forms')->where('id', $row->id)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        // intentionally left empty: the previous per-form values are not kept
    }
};
