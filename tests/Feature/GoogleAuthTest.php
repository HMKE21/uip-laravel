<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sign in / sign up with Google. شهادات جوجل متبدّلة بشهادة محلية (Http::fake) والـ ID token
 * بيتوقّع بمفتاحها، فالتحقق (التوقيع/aud/exp) بيتجرّب فعليًا من غير أي اتصال بجوجل.
 * ⚠️ محتاج PHP 8.4 + JWT_SECRET في البيئة (زي باقي الاختبارات).
 */
class GoogleAuthTest extends TestCase
{
    use DatabaseTransactions;

    private const CLIENT = 'test-client.apps.googleusercontent.com';
    private $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_ids' => self::CLIENT]);

        Cache::forget('google_oauth_certs');
        $this->key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'test'], $this->key);
        openssl_x509_export(openssl_csr_sign($csr, null, $this->key, 1), $pem);
        Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::response(['kid1' => $pem])]);

        if (!DB::table('roles')->where('slug', 'student')->exists()) {
            DB::table('roles')->insert(['slug' => 'student', 'name' => 'Student']);
        }
    }

    private function idToken(array $over = []): string
    {
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = array_merge([
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'sub' => 'g-' . Str::random(8),
            'email' => 'new_' . Str::random(6) . '@gmail.com', 'email_verified' => true, 'name' => 'Ahmed Ali',
            'iat' => time(), 'exp' => time() + 600,
        ], $over);
        $head = $b(json_encode(['alg' => 'RS256', 'kid' => 'kid1'])) . '.' . $b(json_encode($payload));
        openssl_sign($head, $sig, $this->key, OPENSSL_ALGO_SHA256);
        return $head . '.' . $b($sig);
    }

    #[Test]
    public function rejects_a_token_for_another_client_id(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['aud' => 'someone-else'])])->assertStatus(401);
    }

    #[Test]
    public function unknown_email_gets_registration_token_and_missing_arabic_name(): void
    {
        $res = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])->assertOk();
        $res->assertJsonPath('data.needs_registration', true)
            ->assertJsonPath('data.profile.name_en', 'Ahmed Ali')
            ->assertJsonPath('data.missing', ['name_ar']);
        $this->assertNotEmpty($res->json('data.registration_token'));
    }

    #[Test]
    public function completing_signup_creates_a_student_and_signs_in(): void
    {
        $email = 'new_' . Str::random(6) . '@gmail.com';
        $reg = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['email' => $email])])->json('data.registration_token');

        $this->postJson('/api/v1/auth/google/complete', ['registration_token' => $reg, 'name_ar' => 'أحمد علي'])
            ->assertOk()->assertJsonPath('data.user.role', 'student')->assertJsonPath('data.user.email', $email);

        $user = User::where('email', $email)->first();
        $this->assertSame('أحمد علي', $user->name_ar);
        $this->assertSame('Ahmed Ali', $user->name_en);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(DB::table('students')->where('user_id', $user->id)->exists());
        $this->assertTrue(DB::table('user_social_accounts')->where('user_id', $user->id)->exists());
    }

    #[Test]
    public function completing_signup_requires_both_names_in_the_right_script(): void
    {
        $reg = $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken()])->json('data.registration_token');
        $this->postJson('/api/v1/auth/google/complete', ['registration_token' => $reg, 'name_ar' => 'Ahmed'])->assertStatus(422);
        $this->postJson('/api/v1/auth/google/complete', ['registration_token' => 'garbage', 'name_ar' => 'أحمد'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'signup_expired');
    }

    #[Test]
    public function staff_accounts_cannot_use_google(): void
    {
        $email = 'staff_' . Str::random(6) . '@gmail.com';
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'full_name' => 'Admin', 'email' => $email, 'password_hash' => bcrypt('x'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('slug', 'admin')->value('id')
            ?: DB::table('roles')->insertGetId(['slug' => 'admin', 'name' => 'Admin']);
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);

        $this->postJson('/api/v1/auth/google', ['id_token' => $this->idToken(['email' => $email])])->assertStatus(422);
    }
}
