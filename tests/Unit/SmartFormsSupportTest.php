<?php

namespace Tests\Unit;

use App\Services\Forms\FormSettings;
use App\Support\Forms\EgyptianNationalId;
use App\Support\Forms\EgyptianPhone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SmartFormsSupportTest extends TestCase
{
    #[Test]
    public function accepts_real_egyptian_mobiles_in_every_format(): void
    {
        foreach (['01012345679', '+201112345679', '00201212345679', '201512345679', '0122-345-6789', '٠١٠١٢٣٤٥٦٧٩'] as $n) {
            $this->assertNotNull(EgyptianPhone::normalize($n), $n);
        }
        $this->assertSame('01012345679', EgyptianPhone::normalize('+20 101 234 5679'));
    }

    #[Test]
    public function rejects_fake_or_foreign_numbers(): void
    {
        foreach (['01312345678', '01000000000', '0101234567', '12345678901', 'abc', '01012345678', '+966501234567'] as $n) {
            $this->assertNull(EgyptianPhone::normalize($n), $n);
        }
    }

    #[Test]
    public function national_id_is_parsed_and_validated(): void
    {
        $i = EgyptianNationalId::parse('29801011234567');
        $this->assertSame('1998-01-01', $i['birth_date']);
        $this->assertSame('Dakahlia', $i['governorate']);
        $this->assertNull(EgyptianNationalId::parse('29813011234567')); // month 13
        $this->assertNull(EgyptianNationalId::parse('29801019934567')); // unknown governorate
        $this->assertNull(EgyptianNationalId::parse('12345678901234'));
    }

    #[Test]
    public function settings_normalise_and_force_login_for_user_uniqueness(): void
    {
        $s = FormSettings::normalize(['access' => 'public', 'unique_by' => ['user', 'bogus'], 'edit_after_submit' => ['allowed' => true, 'window' => 'minutes', 'minutes' => 30]], 'form');
        $this->assertSame('platform', $s['access']);
        $this->assertTrue($s['require_login']);
        $this->assertSame(['user'], $s['unique_by']);
        $this->assertSame(30, $s['edit_after_submit']['minutes']);
    }
}
