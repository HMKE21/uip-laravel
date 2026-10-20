<?php

namespace Tests\Feature;

use App\Support\DisposableEmail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DisposableEmailTest extends TestCase
{
    #[Test]
    public function dot_padded_gmail_temp_addresses_are_rejected(): void
    {
        foreach ([
            'cuynhsoets.o.et.1245@gmail.com',      // the one that slipped through
            'frederickfie.lds.99.2.53@gmail.com',
            'a.b.c.d.e@googlemail.com',
            '.leadingdot@gmail.com',
            'name+tag@outlook.com',
        ] as $email) {
            $this->assertTrue(DisposableEmail::isSuspiciousMailbox($email), $email);
            $this->assertNotNull(DisposableEmail::problem($email, 'ar', false), $email);
        }
    }

    #[Test]
    public function normal_gmail_addresses_still_pass(): void
    {
        foreach ([
            'ahmed@gmail.com',
            'ahmed.mohamed@gmail.com',
            'mohamed.ahmed.ali.hassan@gmail.com', // four-part name
            'ahmed.m.hassan.2000@gmail.com',      // initial + year
            'ahmed.ali.1998@gmail.com',
        ] as $email) {
            $this->assertFalse(DisposableEmail::isSuspiciousMailbox($email), $email);
        }
    }

    #[Test]
    public function google_verified_inbox_skips_only_the_pattern_check(): void
    {
        $this->assertNull(DisposableEmail::problem('a.b.c.d.e@gmail.com', 'en', false, true));
        $this->assertNotNull(DisposableEmail::problem('someone@mailinator.com', 'en', false, true));
    }
}
