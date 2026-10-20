<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Brevo's HTTP API (https://api.brevo.com/v3/smtp/email)
 * over port 443, for hosts that block outbound SMTP (25/465/587/2525).
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $apiKey, private int $timeout = 15)
    {
        parent::__construct();
        $this->apiKey = trim($this->apiKey);
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === '') {
            throw new TransportException('BREVO_API_KEY is not set.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $fmt = fn (Address $a): array => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);

        $from = $email->getFrom()[0] ?? null;
        if (!$from) {
            throw new TransportException('Email has no From address.');
        }

        $payload = [
            'sender'  => $fmt($from),
            'to'      => array_map($fmt, $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];
        if ($cc = $email->getCc())  { $payload['cc'] = array_map($fmt, $cc); }
        if ($bcc = $email->getBcc()) { $payload['bcc'] = array_map($fmt, $bcc); }
        if ($rt = $email->getReplyTo()) { $payload['replyTo'] = $fmt($rt[0]); }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if ($html) { $payload['htmlContent'] = (string) $html; }
        if ($text) { $payload['textContent'] = (string) $text; }
        if (!$html && !$text) { $payload['textContent'] = ' '; }

        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $attachments[] = [
                'name'    => $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment',
                'content' => base64_encode($part->getBody()),
            ];
        }
        if ($attachments) { $payload['attachment'] = $attachments; }

        try {
            $response = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])
                ->timeout($this->timeout)
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Brevo API request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->status() === 401) {
            throw new TransportException(
                'Brevo rejected the API key (401). Use an API key (starts with "xkeysib-") from Brevo → SMTP & API → API Keys, '
                . 'not the SMTP key, and re-save it in Admin → Settings → Mail. Details: ' . $response->body()
            );
        }

        if ($response->failed()) {
            throw new TransportException('Brevo API error (' . $response->status() . '): ' . $response->body());
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
