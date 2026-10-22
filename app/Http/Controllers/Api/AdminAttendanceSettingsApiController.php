<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SettingRepository;
use App\Services\AuditLogService;
use App\Services\CourseAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin → إعدادات الحضور والغياب. كل القيم تتعدّل من الداشبورد من غير deploy.
 *
 *   GET    /api/v1/admin/attendance-settings                  القيم الحالية + الافتراضي + مواقع كل الكليات
 *   PUT    /api/v1/admin/attendance-settings                  { values: { <key>: number|bool|null } }  (null = رجّع الافتراضي)
 *   DELETE /api/v1/admin/attendance-settings                  رجّع كل القيم للافتراضي (.env)
 *   PUT    /api/v1/admin/attendance-settings/faculties/{id}   { lat, lng, radius_m } (كلهم null = امسح الموقع)
 *
 * التخزين في جدول settings بمفاتيح attendance_<key>؛ كل تغيير بيتسجّل في الـ audit log.
 */
class AdminAttendanceSettingsApiController extends Controller
{
    private const PREFIX = 'attendance_';

    public function __construct(private SettingRepository $settings, private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        return $request->attributes->get('uip_role') === 'admin'
            ? null
            : $this->apiError('Only admin accounts can manage attendance settings.', null, 403);
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** @return array<string, mixed> القيم الحالية (بعد الـ override). */
    private function currentValues(): array
    {
        $svc = app(CourseAttendanceService::class);
        $out = [];
        foreach (array_keys(CourseAttendanceService::SETTINGS) as $k) {
            $out[$k] = $svc->setting($k);
        }
        return $out;
    }

    private function snapshot(): array
    {
        $stored = $this->settings->allFor('global');
        $svc = app(CourseAttendanceService::class);
        $values = [];
        foreach (CourseAttendanceService::SETTINGS as $k => $def) {
            $values[$k] = [
                'value'      => $svc->setting($k),
                'default'    => $def['type'] === 'bool' ? (bool) config('attendance.' . $k, true) : ($def['type'] === 'float' ? (float) config('attendance.' . $k) : (int) config('attendance.' . $k)),
                'overridden' => isset($stored[self::PREFIX . $k]) && $stored[self::PREFIX . $k] !== '',
                'type'       => $def['type'],
                'min'        => $def['min'],
                'max'        => $def['max'],
            ];
        }

        $faculties = DB::table('faculties as f')
            ->leftJoin('universities as u', 'u.id', '=', 'f.university_id')
            ->orderBy('u.official_name_ar')->orderBy('f.name_ar')
            ->get(['f.id', 'f.name_ar', 'f.name_en', 'f.campus_lat', 'f.campus_lng', 'f.campus_radius_m', 'u.official_name_ar as uni_ar', 'u.official_name_en as uni_en'])
            ->map(fn ($f) => [
                'id'         => (int) $f->id,
                'name_ar'    => $f->name_ar,
                'name_en'    => $f->name_en,
                'university_ar' => $f->uni_ar,
                'university_en' => $f->uni_en,
                'lat'        => $f->campus_lat !== null ? (float) $f->campus_lat : null,
                'lng'        => $f->campus_lng !== null ? (float) $f->campus_lng : null,
                'radius_m'   => $f->campus_radius_m !== null ? (int) $f->campus_radius_m : null,
            ])->all();

        return ['values' => $values, 'faculties' => $faculties];
    }

    public function show(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        return $this->apiSuccess($this->snapshot());
    }

    public function update(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $in = $request->input('values');
        if (!is_array($in) || !$in) {
            return $this->apiError('No values sent.', null, 422);
        }

        $before = $this->currentValues();
        $clean = [];
        $errors = [];
        foreach ($in as $k => $v) {
            $def = CourseAttendanceService::SETTINGS[$k] ?? null;
            if (!$def) {
                $errors[$k] = 'unknown setting';
                continue;
            }
            if ($v === null || $v === '') {
                $clean[$k] = null;                                     // رجّع الافتراضي
                continue;
            }
            if ($def['type'] === 'bool') {
                $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    $errors[$k] = 'must be true or false';
                    continue;
                }
                $clean[$k] = $b ? '1' : '0';
                continue;
            }
            if (!is_numeric($v) || (float) $v < $def['min'] || (float) $v > $def['max']) {
                $errors[$k] = "must be a number between {$def['min']} and {$def['max']}";
                continue;
            }
            $clean[$k] = $def['type'] === 'int' ? (string) (int) round((float) $v) : (string) round((float) $v, 1);
        }
        if ($errors) {
            return $this->apiError('Validation failed.', $errors, 422);
        }

        // الحدود المتداخلة لازم تفضل منطقية.
        $merged = $before;
        foreach ($clean as $k => $v) {
            $merged[$k] = $v === null ? config('attendance.' . $k) : $v;
        }
        $cross = [];
        if ((float) $merged['warn_absence_percent'] > (float) $merged['max_absence_percent']) {
            $cross['warn_absence_percent'] = 'must not exceed the deprivation limit';
        }
        if ((int) $merged['default_minutes'] > (int) $merged['max_minutes']) {
            $cross['default_minutes'] = 'must not exceed the maximum minutes';
        }
        if ($cross) {
            return $this->apiError('Validation failed.', $cross, 422);
        }

        foreach ($clean as $k => $v) {
            if ($v === null) {
                DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', self::PREFIX . $k)->delete();
            } else {
                $this->settings->set(self::PREFIX . $k, $v, 'global', null);
            }
        }

        $after = $this->currentValues();
        $changed = $before != $after;
        if ($changed) {
            $this->auditLog->record($this->adminId($request), 'attendance.settings_update', 'Setting', null, $before, $after, $request->ip());
        }
        return $this->apiSuccess($this->snapshot(), $changed ? 'Attendance settings saved.' : 'Nothing changed.');
    }

    public function reset(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $before = $this->currentValues();
        DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', 'like', self::PREFIX . '%')->delete();
        $this->auditLog->record($this->adminId($request), 'attendance.settings_reset', 'Setting', null, $before, $this->currentValues(), $request->ip());
        return $this->apiSuccess($this->snapshot(), 'Attendance settings reset to defaults.');
    }

    public function setFaculty(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }
        $f = DB::table('faculties')->where('id', (int) $id)->first();
        if (!$f) {
            return $this->apiError('Faculty not found.', null, 404);
        }
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        $radius = $request->input('radius_m');
        $clear = ($lat === null || $lat === '') && ($lng === null || $lng === '');
        if (!$clear) {
            if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
                return $this->apiError('Validation failed.', ['lat' => 'must be between -90 and 90'], 422);
            }
            if (!is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
                return $this->apiError('Validation failed.', ['lng' => 'must be between -180 and 180'], 422);
            }
        }
        if ($radius !== null && $radius !== '' && (!is_numeric($radius) || (int) $radius < 50 || (int) $radius > 5000)) {
            return $this->apiError('Validation failed.', ['radius_m' => 'must be between 50 and 5000'], 422);
        }

        $old = ['lat' => $f->campus_lat, 'lng' => $f->campus_lng, 'radius_m' => $f->campus_radius_m];
        $new = $clear
            ? ['lat' => null, 'lng' => null, 'radius_m' => null]
            : ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'radius_m' => ($radius === null || $radius === '') ? null : (int) $radius];
        DB::table('faculties')->where('id', $f->id)->update([
            'campus_lat' => $new['lat'], 'campus_lng' => $new['lng'], 'campus_radius_m' => $new['radius_m'], 'updated_at' => now(),
        ]);
        $this->auditLog->record($this->adminId($request), 'attendance.campus_update', 'Faculty', (int) $f->id, $old, $new, $request->ip());
        return $this->apiSuccess($this->snapshot(), $clear ? 'Campus location cleared.' : 'Campus location saved.');
    }
}
