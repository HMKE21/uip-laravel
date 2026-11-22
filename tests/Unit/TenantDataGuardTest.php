<?php

namespace Tests\Unit;

use App\Services\Tenancy\TenantDataGuard;
use PHPUnit\Framework\TestCase;

/** حارس مدير البيانات: بيتختبر لوحده (من غير DB). */
class TenantDataGuardTest extends TestCase
{
    private const TABLES = ['users', 'posts', 'courses', 'sessions', 'user_sessions', 'dev_api_keys', 'meetings', 'mail_accounts'];
    private const HIDDEN = ['sessions', 'user_sessions', 'dev_api_keys', 'mail_accounts', '*_tokens', 'oauth_*'];
    private const SENS = ['*password*', '*token*', '*secret*', 'hash', '*_hash', '*_enc', 'otp', '*api_key*', '*credential*'];

    private function check(string $sql): array
    {
        return TenantDataGuard::checkSql($sql, self::TABLES, self::HIDDEN, self::SENS);
    }

    /** @return array<string,array{0:string}> */
    public static function allowed(): array
    {
        return [
            'plain' => ['select * from users'],
            'alias + order' => ['SELECT u.id, u.full_name FROM users u WHERE u.id > 5 ORDER BY u.id DESC LIMIT 10'],
            'semicolon inside text' => ["select count(*) from posts p join users u on u.id=p.user_id where p.title like '%a;b%'"],
            'cte' => ['with x as (select id from users) select * from x'],
            'derived table' => ['select * from (select id from users) t where t.id = 1'],
            'backticks + trailing ;' => ['select `id` from `users`;'],
            'left join' => ['select u.id, c.title from users u left join courses c on c.owner_id = u.id'],
            'number' => ['select 1.5 + 2'],
            'as alias star' => ['select p.* from posts as p'],
        ];
    }

    /** @dataProvider allowed */
    public function test_allows_safe_selects(string $sql): void
    {
        [$clean] = $this->check($sql);
        $this->assertNotSame('', $clean);
    }

    /** @return array<string,array{0:string}> */
    public static function blocked(): array
    {
        return [
            'second statement' => ['select * from users; drop table users'],
            'line comment' => ['select * from users -- x'],
            'executable comment' => ['select * from users /*!50000 union select 1*/'],
            'password column' => ['select password from users'],
            'token hash column' => ['select token_hash from users'],
            'hidden table' => ['select * from sessions'],
            'information_schema' => ['select * from information_schema.tables'],
            'other database' => ['select * from otherdb.users'],
            'comma join other db' => ['select * from users, otherdb.secrets_x'],
            'join other db' => ['select a.id from users u join otherdb.t a on 1=1'],
            'mysql schema' => ['select * from users u, mysql.user'],
            'sleep' => ['select sleep(5)'],
            'outfile' => ["select * from users into outfile '/tmp/x'"],
            'update' => ['update users set x=1'],
            'delete' => ['delete from users'],
            'system var' => ['select @@version'],
            'for update' => ['select * from users for update'],
            'load_file' => ["select load_file('/etc/passwd')"],
            'unknown table' => ['select * from ghost'],
            'backslash' => ["select * from users where name='x\\' ; drop table users; --'"],
            'double quotes' => ['select "password" from users'],
            'unclosed quote' => ["SELECT * FROM users WHERE x = 'unclosed"],
            'union hash' => ['select * from users where 1=1 union select hash from users'],
            'recursive info schema' => ['with recursive r as (select 1) select * from information_schema.x'],
            'database()' => ['select database()'],
            'oauth table' => ['select * from oauth_clients'],
            'empty' => ['   '],
        ];
    }

    /** @dataProvider blocked */
    public function test_blocks_dangerous_queries(string $sql): void
    {
        $this->expectException(\DomainException::class);
        $this->check($sql);
    }

    public function test_masks_sensitive_columns_in_rows(): void
    {
        $row = TenantDataGuard::maskRow(['id' => 1, 'email' => 'a@b.c', 'password' => 'x', 'api_key_hint' => 'y', 'refresh_token' => null], ['*password*', '*api_key*', '*token*']);
        $this->assertSame(1, $row['id']);
        $this->assertSame('a@b.c', $row['email']);
        $this->assertSame(TenantDataGuard::MASK, $row['password']);
        $this->assertSame(TenantDataGuard::MASK, $row['api_key_hint']);
        $this->assertNull($row['refresh_token']);
    }

    public function test_log_copy_has_no_text_literals(): void
    {
        [, $log] = $this->check("select * from users where email = 'secret@x.com'");
        $this->assertStringNotContainsString('secret@x.com', $log);
    }
}
