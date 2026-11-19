<?php

namespace App\Services\Mail;

class NullExternalMailGateway implements ExternalMailGateway
{
    public function enabled(): bool
    {
        return false;
    }

    public function send(array $mail): void
    {
        throw new \RuntimeException('External mail delivery is not enabled.');
    }
}
