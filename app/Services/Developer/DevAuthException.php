<?php

namespace App\Services\Developer;

/** رفض مصادقة/صلاحية في الـ Developer API. $errorCode كود ثابت للمطوّر، $headers هيدرز إضافية (Retry-After / WWW-Authenticate). */
class DevAuthException extends \DomainException
{
    public string $errorCode = 'unauthorized';

    /** @var array<string,string> */
    public array $headers = [];
}
