<?php

namespace App\Services\Mail;

/**
 * بوابة البريد الخارجي. المرحلة 1: الإرسال داخلي بين مستخدمي المنصة بس (driver = none).
 * عند النقل لسيرفر حقيقي بنضيف تطبيق (Brevo للإرسال / Stalwart SMTP) ونسجله هنا من غير تعديل باقي النظام.
 */
interface ExternalMailGateway
{
    public function enabled(): bool;

    /** @param array{from:string,from_name:?string,to:array,cc:array,bcc:array,subject:string,html:string,text:string} $mail */
    public function send(array $mail): void;
}
