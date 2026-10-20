<?php

namespace App\Repositories;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/SettingRepository.php القديمة. مخزن key/value
 * عام (scope='global') أو خاص بيوزر (scope='user'). raw query بدل
 * Model::where() عشان بايند NULL مبيطابقش عمود NULL في SQL — لازم IS NULL
 * صراحة لحالة user_id=NULL (global) اللي هي الغالبة هنا.
 */
class SettingRepository
{
    public function get(string $key, string $scope = 'global', $userId = null, $default = null)
    {
        $query = DB::table('settings')->where('scope', $scope)->where('key', $key);
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);

        $value = $query->value('value');
        return $value !== null ? $value : $default;
    }

    /** @return array<string,string|null> كل الـ key => value لـ scope واحد */
    public function allFor(string $scope = 'global', $userId = null): array
    {
        $query = DB::table('settings')->where('scope', $scope);
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);

        $out = [];
        foreach ($query->get(['key', 'value']) as $row) {
            $out[$row->key] = $row->value;
        }
        return $out;
    }

    /** Upsert — الإعدادات بتتعدل أكتر ما بتتعمل، فده المسار الشائع. */
    public function set(string $key, $value, string $scope = 'global', $userId = null): void
    {
        $query = DB::table('settings')->where('scope', $scope)->where('key', $key);
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);

        $existingId = $query->value('id');

        if ($existingId) {
            Setting::find($existingId)->fill(['value' => $value])->save();
            return;
        }

        Setting::create(['scope' => $scope, 'user_id' => $userId, 'key' => $key, 'value' => $value]);
    }
}
