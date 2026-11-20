<?php

namespace App\Services\Mail;

use App\Services\MailConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * إرسال UIPE Mail لعناوين خارجية (Gmail/Outlook...) عن طريق Brevo HTTP API (إرسال فقط — مفيش استقبال).
 * المفتاح: من Admin → Settings → Mail (mail_brevo_api_key، مشفّر) أو BREVO_API_KEY في .env.
 * المُرسِل الفعلي = UIPE_MAIL_EXTERNAL_FROM (لازم Sender/Domain متأكَّد في Brevo)، واسم الظاهر = اسم صاحب الصندوق.
 * UIPE_MAIL_EXTERNAL_REPLY_TO اختياري: ردود الخارج هتروح له (عنوان UIPE مفيهوش MX لحد ما نركّب Stalwart).
 */
class BrevoExternalMailGateway implements ExternalMailGateway
{
    private ?string $key = null;

    private function apiKey(): string
    {
        if ($this->key === null) {
            $stored = '';
            try {
                $stored = (string) DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', 'mail_brevo_api_key')->value('value');
            } catch (\Throwable $e) { /* جدول الإعدادات مش متاح: نكمّل بالـ .env */ }
            $this->key = MailConfigService::decryptSecret($stored) ?: trim((string) config('mail.mailers.brevo.key', ''));
        }
        return $this->key;
    }

    private function from(): string
    {
        return trim((string) config('mailbox.external_from', '')) ?: trim((string) config('mail.from.address', ''));
    }

    public function enabled(): bool
    {
        return config('mailbox.external_driver') === 'brevo' && $this->apiKey() !== '' && $this->from() !== '';
    }

    public function send(array $mail): void
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('External mail delivery is not enabled.');
        }
        config(['mail.mailers.brevo.key' => $this->apiKey()]);
        Mail::purge('brevo');

        $html = trim((string) ($mail['html'] ?? '')) !== '' ? (string) $mail['html'] : nl2br(e((string) ($mail['text'] ?? ' ')));
        $replyTo = trim((string) config('mailbox.external_reply_to', ''));

        Mail::mailer('brevo')->html($html, function ($m) use ($mail, $replyTo) {
            $m->from($this->from(), (string) ($mail['from_name'] ?? '') ?: null);
            foreach (['to', 'cc', 'bcc'] as $kind) {
                foreach ((array) ($mail[$kind] ?? []) as $r) {
                    $m->{$kind}((string) $r['address'], ($r['name'] ?? null) ?: null);
                }
            }
            $m->subject((string) ($mail['subject'] ?? ''));
            if (!empty($mail['message_id'])) { // Tag لربط أحداث الـ webhook (bounce/delivered) بالرسالة
                $m->getSymfonyMessage()->getHeaders()->addTextHeader('X-Mailin-Tag', MailBounceService::tagFor((int) $mail['message_id']));
            }
            if ($replyTo !== '') {
                $m->replyTo($replyTo);
            }
            if (trim((string) ($mail['text'] ?? '')) !== '') {
                $m->getSymfonyMessage()->text((string) $mail['text']);
            }
            foreach ((array) ($mail['attachments'] ?? []) as $a) {
                $bytes = Storage::disk((string) $a['disk'])->get((string) $a['path']);
                if ($bytes !== null) {
                    $m->attachData($bytes, (string) $a['name'], ['mime' => ($a['mime'] ?? null) ?: 'application/octet-stream']);
                }
            }
        });
    }
}
