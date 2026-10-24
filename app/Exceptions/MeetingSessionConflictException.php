<?php

namespace App\Exceptions;

/**
 * نفس الشخص (نفس الـ actor key) بيحاول يدخل نفس الاجتماع من تابة/متصفح/جهاز تاني
 * وهو أصلًا جوه الاجتماع من جلسة تانية شغالة. بنرفض الجلسة الجديدة (HTTP 409).
 */
class MeetingSessionConflictException extends \RuntimeException
{
}
