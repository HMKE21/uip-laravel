<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Converted from the original SQL seed file: 03_organizations_seeder.sql
 * Ported as raw SQL statements (executed via DB::unprepared) to preserve the
 * original idempotency guards (INSERT IGNORE / ON DUPLICATE KEY UPDATE /
 * WHERE NOT EXISTS), so this seeder is safe to run more than once.
 */
class OrganizationsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array
    {
        return [
            <<<'SQL'
-- organizations_seeder.sql
-- The `universities` table previously had no seed rows —
-- UniversityRepository::getOrCreate() only creates an EMPTY row the first
-- time that portal is visited (name/location all blank), which is why a
-- fresh install shows an empty Settings form. This gives the demo account
-- (university@uip.demo, see users_seeder.sql) real
-- sample data, verified and public, so Settings, the new Portfolio
-- page, and the public share link (/u/{uuid}) all render
-- something meaningful immediately. Depends on users_seeder.sql having
-- run first, and must run BEFORE projects_seeder.sql — that seeder links
-- its one published demo project to this seeded university by lookup, so
-- the university row needs to already exist. Requires migration 040
-- (adds universities.is_public) to already be
-- applied.

INSERT INTO universities (user_id, official_name_ar, official_name_en, country, city, website, verification_status, verified_at, is_public)
SELECT u.id, 'جامعة UIP التجريبية', 'UIP Demo University', 'Egypt', 'Cairo',
       'https://uip-demo-university.example.com',
       'verified', NOW(), 1
FROM users u WHERE u.email = 'university@uip.demo'
ON DUPLICATE KEY UPDATE official_name_en = VALUES(official_name_en), is_public = VALUES(is_public);
SQL,
            // Unique-name keys (see UniversityName) for the row inserted above.
            "UPDATE universities SET name_key_ar = '" . \App\Support\UniversityName::normalize('جامعة UIP التجريبية')
                . "', name_key_en = '" . \App\Support\UniversityName::normalize('UIP Demo University')
                . "' WHERE official_name_en = 'UIP Demo University' AND deleted_at IS NULL",

        ];
    }
}
