<?php

namespace Tests\Feature;

use App\Services\Developer\DevAppReview;
use App\Services\Developer\DevScopes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** App Review — اختبارات بدون DB: الوضع الأشد، الـ scopes الحساسة، البصمة، وتغطية الموافقة. */
class DevAppReviewTest extends TestCase
{
    private function sensitive(): string
    {
        foreach (DevScopes::all() as $name => $d) {
            if (!empty($d['sensitive'])) {
                return $name;
            }
        }
        $this->markTestSkipped('No sensitive scope defined.');
    }

    #[Test]
    public function mode_takes_the_stricter_level_and_defaults_to_sensitive(): void
    {
        $this->assertSame('sensitive', DevAppReview::mode(null, null));
        $this->assertSame('sensitive', DevAppReview::mode('garbage', null));
        $this->assertSame('all', DevAppReview::mode('off', 'all'));
        $this->assertSame('all', DevAppReview::mode('all', 'off'));
        $this->assertSame('off', DevAppReview::mode('off', 'off'));
        $this->assertSame('off', DevAppReview::mode(null, 'off'));
    }

    #[Test]
    public function required_depends_on_mode_and_scopes(): void
    {
        $s = $this->sensitive();
        $this->assertFalse(DevAppReview::required('off', [$s]));
        $this->assertTrue(DevAppReview::required('sensitive', [$s]));
        $this->assertFalse(DevAppReview::required('sensitive', []));
        $this->assertTrue(DevAppReview::required('all', []));
    }

    #[Test]
    public function fingerprint_ignores_order_but_not_content(): void
    {
        $a = DevAppReview::fingerprint(['mail.read', 'mail.send'], ['https://a.test/cb', 'https://b.test/cb']);
        $this->assertSame($a, DevAppReview::fingerprint(['mail.send', 'mail.read'], ['https://b.test/cb', 'https://a.test/cb']));
        $this->assertNotSame($a, DevAppReview::fingerprint(['mail.read'], ['https://a.test/cb', 'https://b.test/cb']));
        $this->assertNotSame($a, DevAppReview::fingerprint(['mail.read', 'mail.send'], ['https://evil.test/cb']));
    }

    #[Test]
    public function approval_is_invalidated_by_new_scope_or_redirect(): void
    {
        $s = $this->sensitive();
        $app = ['review_status' => 'approved', 'scopes' => [$s], 'redirects' => ['https://a.test/cb'],
            'review_scopes' => [$s], 'review_redirects_hash' => DevAppReview::redirectsHash(['https://a.test/cb'])];
        $this->assertSame('approved', DevAppReview::evaluate($app, 'sensitive')['state']);
        $this->assertTrue(DevAppReview::evaluate($app, 'sensitive')['allows']);

        $extra = current(array_diff(DevScopes::names(), [$s])); // scope مختلف عن اللي اتوافق عليه
        $moreScopes = ['scopes' => [$s, $extra]] + $app;
        $r = DevAppReview::evaluate($moreScopes, 'sensitive');
        $this->assertFalse($r['allows']);
        $this->assertSame('needs_review', $r['state']);

        $otherRedirect = ['redirects' => ['https://evil.test/cb']] + $app;
        $this->assertFalse(DevAppReview::evaluate($otherRedirect, 'sensitive')['allows']);

        $fewer = ['scopes' => []] + $app; // تقليل الصلاحيات مش بيبطل الموافقة
        $this->assertTrue(DevAppReview::evaluate($fewer, 'sensitive')['allows']);
    }

    #[Test]
    public function states_for_pending_rejected_and_not_required(): void
    {
        $s = $this->sensitive();
        $base = ['scopes' => [$s], 'redirects' => ['https://a.test/cb']];
        $this->assertSame('needs_review', DevAppReview::evaluate($base + ['review_status' => 'none'], 'sensitive')['state']);
        $this->assertSame('pending', DevAppReview::evaluate($base + ['review_status' => 'pending'], 'sensitive')['state']);
        $this->assertSame('rejected', DevAppReview::evaluate($base + ['review_status' => 'rejected'], 'sensitive')['state']);
        $this->assertSame('not_required', DevAppReview::evaluate($base + ['review_status' => 'none'], 'off')['state']);
        $this->assertTrue(DevAppReview::evaluate($base + ['review_status' => 'none'], 'off')['allows']);
        $this->assertFalse(DevAppReview::evaluate($base + ['review_status' => 'pending'], 'sensitive')['allows']);
    }
}
