<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Sign in with UIPE Identity (Keycloak). الـ JWKS متبدّل بمفتاح محلي (Http::fake) فالتحقق بيتجرّب فعليًا. */
class KeycloakAuthTest extends TestCase
{
    use DatabaseTransactions;

    private const ISS = 'https://uipe-identity.test/realms/uipe';
    private $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.keycloak.base_url' => 'https://uipe-identity.test', 'services.keycloak.realm' => 'uipe',
            'services.keycloak.client_id' => 'uipe-web', 'services.keycloak.issuer' => '', 'services.keycloak.jwks_url' => '']);
        config(['security.jwt.secret' => 'test-secret-test-secret-test-secret-123456']);
        Cache::flush();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048]);
        $d = openssl_pkey_get_details($this->key)['rsa'];
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        Http::fake([self::ISS . '/protocol/openid-connect/certs' => Http::response(['keys' => [
            ['kid' => 'k1', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b($d['n']), 'e' => $b($d['e'])],
        ]])]);
        // phpunit.xml = sqlite :memory: من غير migrations: ننشئ الجداول الصغيرة اللي المسار محتاجها لو ناقصة
        // (على قاعدة حقيقية فيها الجداول دي مبنلمسهاش). DatabaseTransactions بيلفّ الاختبار في transaction.
        // الاختبار بيغطي الـ controller والـ verifier بس؛ طبقات الـ middleware (التشفير 428 / rate limit / توكن التطبيق / الجهاز) مالهاش علاقة بيه.
        $this->withoutMiddleware();
        config(['secure_transport.enabled' => false]);
        $this->ensureSchema();
        if (!DB::table('roles')->where('slug', 'student')->exists()) {
            DB::table('roles')->insert(['slug' => 'student', 'name' => 'Student']);
        }
    }

    private function ensureSchema(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->id(); $t->string('uuid')->nullable(); $t->string('full_name')->nullable(); $t->string('email')->nullable();
                $t->string('original_email')->nullable(); $t->string('password_hash')->nullable(); $t->string('status')->default('active');
                $t->timestamp('email_verified_at')->nullable(); $t->unsignedBigInteger('merged_into_user_id')->nullable();
                $t->timestamp('deleted_at')->nullable(); $t->timestamps();
            });
        }
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('slug'); $t->string('name'); });
        }
        if (!Schema::hasTable('user_social_accounts')) {
            Schema::create('user_social_accounts', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('user_id'); $t->string('provider'); $t->string('provider_user_id'); $t->string('email')->nullable(); $t->timestamps();
            });
        }
        if (!Schema::hasTable('security_policies')) {
            Schema::create('security_policies', function (Blueprint $t) {
                $t->increments('id'); $t->string('policy_key', 80)->unique(); $t->string('category', 60)->default('');
                $t->string('name_ar', 150)->default(''); $t->string('name_en', 150)->default(''); $t->text('value');
                $t->boolean('is_active')->default(1); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
            });
        }
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $t) {
                $t->id(); $t->string('scope')->default('global'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('key'); $t->text('value')->nullable();
            });
        }
    }

    private function token(array $over = [], string $alg = 'RS256'): string
    {
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $p = array_merge(['iss' => self::ISS, 'aud' => 'uipe-web', 'azp' => 'uipe-web', 'sub' => 'kc-' . Str::random(8),
            'email' => 'kc_' . Str::random(6) . '@example.com', 'email_verified' => true, 'name' => 'Ahmed Ali',
            'iat' => time(), 'exp' => time() + 600], $over);
        $head = $b(json_encode(['alg' => $alg, 'kid' => 'k1'])) . '.' . $b(json_encode($p));
        openssl_sign($head, $sig, $this->key, OPENSSL_ALGO_SHA256);
        return $head . '.' . $b($sig);
    }

    #[Test]
    public function valid_token_for_new_email_starts_registration(): void
    {
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token()])->assertOk()->assertJsonPath('data.needs_registration', true);
    }

    #[Test]
    public function rejects_wrong_audience_issuer_unverified_email_and_hs256(): void
    {
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token(['aud' => 'other'])])->assertStatus(401);
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token(['iss' => 'https://evil/realms/uipe'])])->assertStatus(401);
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token(['exp' => time() - 600])])->assertStatus(401);
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token(['email_verified' => false])])
            ->assertStatus(422)->assertJsonPath('errors.code', 'email_unverified');
        $this->postJson('/api/v1/auth/keycloak', ['id_token' => $this->token([], 'HS256')])->assertStatus(401);
    }

    #[Test]
    public function config_is_public_and_has_no_secret(): void
    {
        $this->getJson('/api/v1/auth/keycloak/config')->assertOk()->assertJsonPath('data.client_id', 'uipe-web')->assertJsonMissingPath('data.secret');
    }
}
