<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserChatWallpaper;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * /api/v1/account/chat-wallpapers — صور الخلفية الشخصية لمحادثات المراسلة وشات الـ AI، محفوظة مع الحساب (بتتابع المستخدم على أي جهاز).
 * متاحة لأي مستخدم مسجّل دخول؛ كل مستخدم بيشوف ويمسح صوره هو بس.
 *
 * GET    /            → { wallpapers: [{ id, url }] }  (id = uid الأقدم أولًا؛ url مسار نسبي زي avatar_path)
 * POST   /            → multipart: image (الصورة)، uid (اختياري — معرّف من الفرونت، بيخلّي الرفع idempotent عند نقل الصور القديمة)
 * DELETE /{uid}       → بيمسح الصف والملف
 */
class ChatWallpapersApiController extends Controller
{
    /** أقصى عدد صور محفوظة للمستخدم الواحد. */
    private const MAX_PER_USER = 12;

    /** أقصى حجم للصورة الواحدة بالـ KB (الفرونت بيصغّرها لـ 1920px/JPEG قبل الرفع، فالحد ده مجرد سقف أمان). */
    private const MAX_KB = 4096;

    public function __construct(private FileUploadService $uploads)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function present(UserChatWallpaper $w): array
    {
        return ['id' => $w->uid, 'url' => $w->stored_path];
    }

    public function index(Request $request)
    {
        $rows = UserChatWallpaper::where('user_id', $this->uid($request))->orderBy('id')->get();

        // الملفات على قرص الحاوية؛ لو الاستضافة صفّرت القرص (redeploy من غير Volume) الصف يفضل والصورة تبان فاضية.
        // نشيل الصفوف اللي ملفها اختفى عشان الواجهة متعرضش مربعات فاضية (والشخص يرفع صورته تاني).
        $rows = $rows->filter(function ($w) {
            $rel = ltrim(str_replace('\\', '/', (string) $w->stored_path), '/');
            if ($rel !== '' && !str_contains($rel, '..') && is_file(public_path($rel))) {
                return true;
            }
            try {
                $w->delete();
            } catch (\Throwable $e) {
                // نسيبه؛ هيتجرّب في الطلب الجاي
            }
            return false;
        })->values();

        return $this->apiSuccess(
            ['wallpapers' => $rows->map(fn ($w) => $this->present($w))->values()->all()],
            'Chat wallpapers retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file',
            'uid'   => ['nullable', 'string', 'regex:/^[a-z0-9]{6,24}$/'],
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $userId = $this->uid($request);
        $uid = (string) ($request->input('uid') ?: substr(bin2hex(random_bytes(8)), 0, 16));

        // نفس الـ uid رُفع قبل كده (مثلًا إعادة محاولة بعد انقطاع النت) -> نرجّع الموجود من غير ما نكرّره.
        $existing = UserChatWallpaper::where('user_id', $userId)->where('uid', $uid)->first();
        if ($existing) {
            return $this->apiSuccess($this->present($existing), 'Chat wallpaper saved successfully.');
        }

        if (UserChatWallpaper::where('user_id', $userId)->count() >= self::MAX_PER_USER) {
            return $this->apiError('You reached the limit of ' . self::MAX_PER_USER . ' saved backgrounds — delete one first.', null, 422);
        }

        try {
            $stored = $this->uploads->store($request->file('image'), 'chat_wallpapers', (string) $userId, self::MAX_KB);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $row = UserChatWallpaper::create([
            'user_id'     => $userId,
            'uid'         => $uid,
            'stored_path' => $stored['stored_path'],
            'size_bytes'  => $stored['size_bytes'],
        ]);

        return $this->apiSuccess($this->present($row), 'Chat wallpaper saved successfully.', 201);
    }

    public function destroy(Request $request, string $uid)
    {
        $row = UserChatWallpaper::where('user_id', $this->uid($request))->where('uid', $uid)->first();
        if (!$row) {
            return $this->apiSuccess(['deleted' => false], 'Chat wallpaper not found.'); // مسحها قبل كده من جهاز تاني: نعتبرها اتمسحت
        }

        $this->uploads->delete($row->stored_path);
        $row->delete();

        return $this->apiSuccess(['deleted' => true], 'Chat wallpaper deleted successfully.');
    }
}
