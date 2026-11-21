<?php

namespace App\Services\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * المشاركين الخارجيين: المستخدم النهائي عند العميل (موظف/طالب) من غير حساب UIPE. مفتاحه `external_id` من نظام العميل، ومعزول بـ tenant_id.
 * كل دوال الخدمة بتاخد tenantId جاهز (التحقق من الصلاحية بيتم قبلها في TenantService أو من مفتاح الـ API).
 */
final class TenantParticipantService
{
    public const ID_PATTERN = '/^[A-Za-z0-9._:@\-]{1,128}$/';
    private const META_MAX_BYTES = 4096;

    private function fail(string $msg, int $code = 422): never
    {
        throw new \DomainException($msg, $code);
    }

    public static function payload(object $r): array
    {
        return [
            'external_id' => $r->external_id, 'name' => $r->name, 'email' => $r->email, 'role' => $r->role,
            'meta' => $r->meta !== null ? (json_decode((string) $r->meta, true) ?: (object) []) : null,
            'last_seen_at' => $r->last_seen_at, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
        ];
    }

    public function validExternalId(string $id): string
    {
        $id = trim($id);
        if (!preg_match(self::ID_PATTERN, $id)) {
            $this->fail('external_id must be 1 to 128 characters: letters, digits and . _ : @ -');
        }
        return $id;
    }

    public function find(int $tenantId, string $externalId): ?object
    {
        return DB::table('tenant_participants')->where('tenant_id', $tenantId)->where('external_id', $externalId)->first() ?: null;
    }

    public function get(int $tenantId, string $externalId): array
    {
        $r = $this->find($tenantId, $externalId);
        if (!$r) {
            $this->fail('Participant not found.', 404);
        }
        return self::payload($r);
    }

    /** @param array{q?:?string,role?:?string,page?:int,per_page?:int} $f */
    public function list(int $tenantId, array $f = []): array
    {
        $per = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $q = trim((string) ($f['q'] ?? ''));
        $role = (string) ($f['role'] ?? '');
        $qb = DB::table('tenant_participants')->where('tenant_id', $tenantId)
            ->when($role !== '', fn ($x) => $x->where('role', $role))
            ->when($q !== '', function ($x) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $x->where(fn ($y) => $y->where('name', 'like', $like)->orWhere('external_id', 'like', $like)->orWhere('email', 'like', $like));
            });
        $total = (clone $qb)->count();
        $items = $qb->orderByDesc('id')->forPage($page, $per)->get()->map(fn ($r) => self::payload($r))->all();
        return ['items' => $items, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per))]];
    }

    /**
     * إنشاء أو تعديل جزئي بـ external_id. على التعديل بس الحقول المبعوتة بتتغيّر. `name` مطلوب عند الإنشاء.
     * @return array{participant:array,created:bool,row:object}
     */
    public function upsert(int $tenantId, array $in, int $limit): array
    {
        $ext = $this->validExternalId((string) ($in['external_id'] ?? ''));
        $has = fn (string $k) => array_key_exists($k, $in);
        $set = [];

        if ($has('name')) {
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $in['name']));
            if ($name === '' || mb_strlen($name) > 160) {
                $this->fail('name must be 1 to 160 characters.');
            }
            $set['name'] = $name;
        }
        if ($has('email')) {
            $email = $in['email'] === null ? '' : trim((string) $in['email']);
            if ($email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $this->fail('email is not valid.');
            }
            $set['email'] = $email === '' ? null : $email;
        }
        if ($has('role')) {
            if (!in_array($in['role'], (array) config('tenancy.participant_roles'), true)) {
                $this->fail('role must be one of: ' . implode(', ', (array) config('tenancy.participant_roles')));
            }
            $set['role'] = $in['role'];
        }
        if ($has('meta')) {
            if ($in['meta'] !== null && !is_array($in['meta'])) {
                $this->fail('meta must be a JSON object.');
            }
            $json = $in['meta'] === null ? null : json_encode($in['meta'], JSON_UNESCAPED_UNICODE);
            if ($json !== null && strlen($json) > self::META_MAX_BYTES) {
                $this->fail('meta is limited to ' . self::META_MAX_BYTES . ' bytes.');
            }
            $set['meta'] = $json;
        }

        $row = $this->find($tenantId, $ext);
        if ($row) {
            if ($set) {
                DB::table('tenant_participants')->where('id', $row->id)->update($set + ['updated_at' => now()]);
            }
            return ['participant' => self::payload(DB::table('tenant_participants')->find($row->id)), 'created' => false, 'row' => DB::table('tenant_participants')->find($row->id)];
        }

        if (!isset($set['name'])) {
            $this->fail('name is required for a new participant.');
        }
        if (DB::table('tenant_participants')->where('tenant_id', $tenantId)->count() >= $limit) {
            $this->fail('This workspace reached its participant limit.', 403);
        }
        try {
            $id = DB::table('tenant_participants')->insertGetId($set + ['tenant_id' => $tenantId, 'external_id' => $ext, 'role' => $set['role'] ?? 'participant', 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException $e) {
            // طلبين متزامنين لنفس external_id: التاني بيتحول لتعديل.
            if ($this->find($tenantId, $ext)) {
                return $this->upsert($tenantId, $in, $limit);
            }
            throw $e;
        }
        $fresh = DB::table('tenant_participants')->find($id);
        return ['participant' => self::payload($fresh), 'created' => true, 'row' => $fresh];
    }

    public function delete(int $tenantId, string $externalId): void
    {
        $r = $this->find($tenantId, $externalId);
        if (!$r) {
            $this->fail('Participant not found.', 404);
        }
        DB::table('tenant_embed_sessions')->where('participant_id', $r->id)->delete();   // مفيش اعتماد على cascade (sqlite من غير FKs)
        DB::table('tenant_participants')->where('id', $r->id)->delete();
    }
}
