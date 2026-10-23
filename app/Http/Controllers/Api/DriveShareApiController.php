<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DriveShareService;
use App\Support\DriveException;
use Illuminate\Http\Request;

/**
 * مشاركة ملفات "جهازي" برابط. راجع DriveShareService.
 *
 * صاحب الملف (uip.auth + نفس أدوار "جهازي"):
 *   GET    /drive/shares                  كل روابطي الحيّة
 *   GET    /drive/nodes/{id}/shares       روابط عنصر
 *   POST   /drive/nodes/{id}/shares       {expiry: 1d|7d|30d|never أو مدة مخصصة <رقم><m|h|d> زي 5m / 2h / 3d (من دقيقة لـ 365 يوم), can_download,
 *                                          require_visitor_info (اسم+إيميل+موبايل قبل الفتح), password (اختياري)}
 *   PATCH  /drive/shares/{id}             {expiry?, can_download?, require_visitor_info?, password?, remove_password?}
 *   DELETE /drive/shares/{id}             إلغاء الرابط (نهائي)
 *   GET    /drive/shares/manage           كل روابطي (شغّالة/موقوفة/منتهية/ملغية) + عدّاد الزوار — لصفحة "روابطي المشاركة"
 *   GET    /drive/shares/{id}/activity    مين فتح الرابط: ملخص + الزوار + آخر الأحداث
 *   DELETE /drive/shares/{id}/activity    مسح سجل الزيارات (الرابط يفضل شغال)
 *   POST   /drive/shares/{id}/pause       إيقاف مؤقت
 *   POST   /drive/shares/{id}/resume      استئناف
 *   DELETE /drive/shares/{id}/forever     حذف الرابط وسجله نهائيًا
 *   POST   /drive/shares/bulk             {action: pause|resume|delete, ids?: [..], all?: true} إجراء جماعي
 *
 * أي حد معاه الرابط (من غير تسجيل دخول) — /public/drive-share/{token}:
 *   POST   /enter                        البوابة: {name,email,phone,password} -> {session}. الجلسة بتتبعت بعد كده في هيدر X-Share-Session.
 *   GET    /                              بيانات + محتوى المجلد (?node= لمجلد فرعي) — 423 لو الرابط عليه بوابة والجلسة ناقصة
 *   GET    /file                          ملف (?node=, ?download=1 لو التنزيل مسموح)
 *   GET    /zip                           ZIP (?node=) لو التنزيل مسموح
 */
class DriveShareApiController extends Controller
{
    public function __construct(private DriveShareService $shares)
    {
    }

    // -- owner ------------------------------------------------------------------------------------------

    private function guard(Request $request, callable $fn)
    {
        if (!in_array((string) $request->attributes->get('uip_role'), (array) config('drive.roles'), true)) {
            return $this->apiError('My PC is not available for your account type.', null, 403);
        }
        try {
            return $fn((int) $request->attributes->get('uip_user_id'));
        } catch (DriveException $e) {
            $c = $e->getCode();
            return $this->apiError($e->getMessage(), null, $c >= 400 && $c < 600 ? $c : 422);
        }
    }

    public function index(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['items' => $this->shares->listMine($uid)], 'OK'));
    }

    public function forNode(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['items' => $this->shares->forNode($uid, (int) $id)], 'OK'));
    }

    public function create(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(
            $this->shares->create(
                $uid, (int) $id,
                $request->input('expiry') !== null ? (string) $request->input('expiry') : null,
                $request->boolean('can_download', true),
                $request->boolean('require_visitor_info', false),
                $request->filled('password') ? (string) $request->input('password') : null,
            )->toRowArray(),
            'Share link created.', 201));
    }

    public function update(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($request, $id) {
            $in = [];
            if ($request->has('can_download')) {
                $in['can_download'] = $request->boolean('can_download');
            }
            if ($request->has('expiry')) {
                $in['expiry'] = $request->input('expiry');
            }
            if ($request->has('require_visitor_info')) {
                $in['require_visitor_info'] = $request->boolean('require_visitor_info');
            }
            if ($request->boolean('remove_password')) {
                $in['remove_password'] = true;
            } elseif ($request->filled('password')) {
                $in['password'] = (string) $request->input('password');
            }
            return $this->apiSuccess($this->shares->update($uid, (int) $id, $in)->toRowArray(), 'Share link updated.');
        });
    }

    public function revoke(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($id) {
            $this->shares->revoke($uid, (int) $id);
            return $this->apiSuccess(null, 'Share link turned off.');
        });
    }

    public function manage(Request $request)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess(['items' => $this->shares->manage($uid)], 'OK'));
    }

    public function activity(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->shares->activity($uid, (int) $id), 'OK'));
    }

    public function clearActivity(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($id) {
            $this->shares->clearActivity($uid, (int) $id);
            return $this->apiSuccess(null, 'Activity cleared.');
        });
    }

    public function pause(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->shares->pause($uid, (int) $id, true)->toRowArray(), 'Share link paused.'));
    }

    public function resume(Request $request, $id)
    {
        return $this->guard($request, fn ($uid) => $this->apiSuccess($this->shares->pause($uid, (int) $id, false)->toRowArray(), 'Share link resumed.'));
    }

    /** POST /drive/shares/bulk {action: pause|resume|delete, ids?: int[], all?: bool} */
    public function bulk(Request $request)
    {
        return $this->guard($request, function ($uid) use ($request) {
            $res = $this->shares->bulk(
                $uid,
                (string) $request->input('action', ''),
                (array) $request->input('ids', []),
                $request->boolean('all', false),
            );
            return $this->apiSuccess($res, 'OK');
        });
    }

    public function forever(Request $request, $id)
    {
        return $this->guard($request, function ($uid) use ($id) {
            $this->shares->forever($uid, (int) $id);
            return $this->apiSuccess(null, 'Share link deleted.');
        });
    }

    // -- public -----------------------------------------------------------------------------------------

    /** سياق الزائر: IP / User-Agent / حساب UIP (لو مسجّل دخول وuip.auth.optional اشتغلت) / session token البوابة. */
    private function ctx(Request $r): array
    {
        $uid = $r->attributes->get('uip_user_id');
        return [
            'ip'      => $r->ip(),
            'ua'      => (string) $r->userAgent(),
            'user_id' => $uid ? (int) $uid : null,
            'session' => (string) $r->header('X-Share-Session', ''),
        ];
    }

    public function enter(Request $request, string $token)
    {
        try {
            $res = $this->shares->enter($token, [
                'name'     => $request->input('name'),
                'email'    => $request->input('email'),
                'phone'    => $request->input('phone'),
                'password' => $request->input('password'),
            ], $this->ctx($request));
            return $this->apiSuccess($res, 'OK')->header('Cache-Control', 'no-store');
        } catch (DriveException $e) {
            return $this->publicError($e);
        }
    }

    private function nodeParam(Request $r): ?int
    {
        $v = $r->query('node');
        return ($v === null || $v === '' || !ctype_digit((string) $v) || (int) $v === 0) ? null : (int) $v;
    }

    private function publicError(DriveException $e)
    {
        $c = $e->getCode();
        return $this->apiError($e->getMessage(), $e->errors(), $c >= 400 && $c < 600 ? $c : 422)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $token)
    {
        try {
            return $this->apiSuccess($this->shares->view($token, $this->nodeParam($request), $this->ctx($request)), 'OK')->header('Cache-Control', 'no-store');
        } catch (DriveException $e) {
            return $this->publicError($e);
        }
    }

    public function file(Request $request, string $token)
    {
        $download = $request->boolean('download');
        try {
            [$node, $stream] = $this->shares->openFile($token, $this->nodeParam($request), $download, $this->ctx($request));
        } catch (DriveException $e) {
            return $this->publicError($e);
        }
        $name = (string) $node->name;
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));
        $headers = [
            'Content-Type'            => $node->mime_type ?: 'application/octet-stream',
            'Content-Length'          => (string) (int) $node->size_bytes,
            'Content-Disposition'     => ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            // ملف طالب = محتوى غير موثوق (نفس حماية DriveApiController::content).
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control'           => 'private, no-store',
        ];
        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $headers);
    }

    public function zip(Request $request, string $token)
    {
        try {
            [$tmp, $name] = $this->shares->zipFor($token, $this->nodeParam($request), $this->ctx($request));
        } catch (DriveException $e) {
            return $this->publicError($e);
        }
        return response()->download($tmp, $name, [
            'Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
