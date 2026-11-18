<?php

namespace Tests\Unit;

use App\Services\Forms\FormSettings;
use App\Support\Forms\EgyptianNationalId;
use App\Support\Forms\EgyptianPhone;
use App\Support\Forms\RespondentIdentity;
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

    #[Test]
    public function names_must_be_real_names_not_numbers_or_symbols(): void
    {
        foreach (['55', '١٢٣', 'ارقام 55', 'a', '!!', 'aaaa', 'احمد@'] as $bad) {
            $this->assertNull(RespondentIdentity::name($bad, 1)[0], $bad);
        }
        $this->assertSame('أحمد محمد', RespondentIdentity::name('  أحمد   محمد ', 2)[0]);
        $this->assertSame('name_short', RespondentIdentity::name('أحمد', 2)[1]);
        $this->assertSame("Sara O'Neil-Smith", RespondentIdentity::name("Sara O'Neil-Smith", 2)[0]);
    }

    #[Test]
    public function unique_by_rules_force_their_identity_field_to_required(): void
    {
        $s = FormSettings::normalize(['unique_by' => ['email', 'phone'], 'identity' => ['email' => 'off', 'phone' => 'optional']], 'form');
        $this->assertSame('required', $s['identity']['email']);
        $this->assertSame('required', $s['identity']['phone']);
        $this->assertSame('off', $s['identity']['national_id']);
    }

    #[Test]
    public function appearance_settings_are_sanitised(): void
    {
        $s = FormSettings::normalize(['appearance' => ['theme' => 'midnight', 'accent' => '#AABBCC', 'font' => 'serif', 'submit_label' => '<b>Send it</b>']], 'form');
        $this->assertSame('midnight', $s['appearance']['theme']);
        $this->assertSame('#aabbcc', $s['appearance']['accent']);
        $this->assertSame('serif', $s['appearance']['font']);
        $this->assertSame('Send it', $s['appearance']['submit_label']);

        $bad = FormSettings::normalize(['appearance' => ['theme' => 'javascript:alert(1)', 'accent' => 'red', 'font' => 'comic']], 'form');
        $this->assertSame('classic', $bad['appearance']['theme']);
        $this->assertSame('', $bad['appearance']['accent']);
        $this->assertSame('default', $bad['appearance']['font']);
    }

    #[Test]
    public function a_password_hash_can_never_come_from_the_client_and_is_kept_or_cleared_by_the_server(): void
    {
        $this->assertSame('', FormSettings::normalize(['password_hash' => 'forged'], 'form')['password_hash']);

        $current = FormSettings::defaults('form');
        $current['password_hash'] = '$2y$10$existinghash';
        $this->assertSame('$2y$10$existinghash', FormSettings::normalize(['allow_another' => true], 'form', $current)['password_hash']);
        $this->assertSame('$2y$10$existinghash', FormSettings::normalize(['password_hash' => 'forged'], 'form', $current)['password_hash']);
        $this->assertSame('', FormSettings::normalize(['clear_password' => true], 'form', $current)['password_hash']);
    }

    #[Test]
    public function the_builder_view_of_settings_never_contains_the_hash(): void
    {
        $with = FormSettings::publicView(['password_hash' => '$2y$10$secret', 'access' => 'public']);
        $this->assertTrue($with['has_password']);
        $this->assertArrayNotHasKey('password_hash', $with);
        $this->assertFalse(FormSettings::publicView(['access' => 'public'])['has_password']);
    }
}
