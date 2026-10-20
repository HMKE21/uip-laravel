<?php

namespace App\Services\Ai;

/** المزوّد رفض طلب فيه tools (HTTP 400/404/422) — بنرجع لشات عادي بدل ما التيرن يفشل. */
class AiToolsUnsupportedException extends \RuntimeException
{
}
