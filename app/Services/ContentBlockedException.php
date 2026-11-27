<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * محتوى ممنوع (غير لائق / حسّاس). الرسالة آمنة تتعرض للمستخدم وفيها سبب الحظر.
 *
 * Laravel بيستدعي render() تلقائيًا لو الاستثناء وصل للـ handler، وأي controller بيمسك RuntimeException
 * ممكن يرجّع $e->render() بنفس الشكل — فالواجهة دايمًا بتاخد { errors.code = 'content_not_allowed', category, kind, reason }.
 */
class ContentBlockedException extends \RuntimeException
{
    /**
     * @param string $category sexual | gore | child_safety | profanity | custom | scanner_unavailable
     * @param string $kind     text | filename | image | video | gif | gif_title | gif_query …
     */
    public function __construct(string $message, public readonly string $category = 'sexual', public readonly string $kind = 'text')
    {
        parent::__construct($message, 422);
    }

    /** @return array{code:string,category:string,kind:string,reason:string,appeal:?array} */
    public function errors(): array
    {
        try {
            $appeal = app(ContentAppealService::class)->publicInfo();   // حساب الإدارة + هل الاعتراض متاح — بتعرضه الواجهة في نافذة التحذير
        } catch (\Throwable) {
            $appeal = null;
        }

        return [
            'code' => 'content_not_allowed',
            'category' => $this->category,
            'kind' => $this->kind,
            'reason' => app(ContentSafetyService::class)->reasonLabel($this->category),
            'appeal' => $appeal,
        ];
    }

    public function render(?Request $request = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data'    => null,
            'errors'  => $this->errors(),
            'meta'    => (object) [],
        ], 422)->header('Cache-Control', 'no-store');
    }
}
