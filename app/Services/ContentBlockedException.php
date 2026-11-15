<?php

namespace App\Services;

/** محتوى ممنوع (إباحي / مخلّ / حسّاس). الرسالة آمنة تتعرض للمستخدم. */
class ContentBlockedException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $category = 'sexual')
    {
        parent::__construct($message, 422);
    }
}
