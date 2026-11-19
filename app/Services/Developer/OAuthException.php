<?php

namespace App\Services\Developer;

/** أخطاء OAuth 2.0 بأكواد RFC 6749 (invalid_request, invalid_client, invalid_grant, invalid_scope, access_denied …). */
class OAuthException extends \DomainException
{
    public string $oauthError = 'invalid_request';

    /** لو الخطأ بعد ما اتأكدنا من redirect_uri: الرابط اللي المتصفح يتوجّه له بالخطأ (مع state). */
    public ?string $redirectTo = null;

    public static function make(string $error, string $description, int $status = 400, ?string $redirectTo = null): self
    {
        $e = new self($description, $status);
        $e->oauthError = $error;
        $e->redirectTo = $redirectTo;
        return $e;
    }
}
