<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\ScimException;
use App\Services\Identity\ScimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/scim/v2 — SCIM 2.0 للـ IdP (Entra ID / Okta / Keycloak). الدخول بـ Bearer token بتاع الـ tenant
 * (بتعمله الجامعة من /api/v1/mail-admin/scim/tokens). الردود application/scim+json وأخطاء RFC 7644.
 */
class ScimController extends Controller
{
    private const LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public function __construct(private ScimService $scim)
    {
    }

    private function json(array $body, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, $headers + ['Content-Type' => 'application/scim+json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function error(ScimException $e): JsonResponse
    {
        $status = $e->status >= 400 && $e->status < 600 ? $e->status : 500;
        $body = ['schemas' => [ScimService::ERR], 'status' => (string) $status, 'detail' => $e->getMessage()];
        if ($e->scimType) {
            $body['scimType'] = $e->scimType;
        }
        return $this->json($body, $status);
    }

    private function run(Request $r, callable $fn)
    {
        try {
            $ctx = $this->scim->authenticate($r->bearerToken());
            return $fn($ctx);
        } catch (ScimException $e) {
            return $this->error($e);
        }
    }

    public function serviceProviderConfig(Request $r)
    {
        return $this->run($r, fn () => $this->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200], 'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'Per-tenant SCIM token', 'primary' => true]],
        ]));
    }

    public function resourceTypes(Request $r)
    {
        return $this->run($r, fn () => $this->json([
            'schemas' => [self::LIST], 'totalResults' => 1, 'startIndex' => 1, 'itemsPerPage' => 1,
            'Resources' => [['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => 'User', 'name' => 'User', 'endpoint' => '/Users',
                'schema' => ScimService::CORE, 'schemaExtensions' => [['schema' => ScimService::EXT, 'required' => false]]]],
        ]));
    }

    public function schemas(Request $r)
    {
        $attr = fn (string $n, string $t = 'string') => ['name' => $n, 'type' => $t, 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'none'];
        return $this->run($r, fn () => $this->json([
            'schemas' => [self::LIST], 'totalResults' => 1, 'startIndex' => 1, 'itemsPerPage' => 1,
            'Resources' => [['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'], 'id' => ScimService::EXT, 'name' => 'UIPE User extension',
                'description' => 'Bilingual names, member numbers, mailbox and membership expiry.',
                'attributes' => [$attr('nameAr'), $attr('nameEn'), $attr('studentNumber'), $attr('staffNumber'), $attr('facultyId', 'integer'), $attr('departmentId', 'integer'),
                    ['name' => 'mailAddress', 'type' => 'string', 'multiValued' => false, 'required' => false, 'mutability' => 'readOnly', 'returned' => 'default', 'uniqueness' => 'none'],
                    $attr('membershipExpiresAt', 'dateTime')]]],
        ]));
    }

    public function index(Request $r)
    {
        return $this->run($r, function ($ctx) use ($r) {
            $start = max(1, (int) $r->query('startIndex', 1));
            $res = $this->scim->list($ctx, $r->query('filter'), $start, (int) $r->query('count', 100));
            return $this->json(['schemas' => [self::LIST], 'totalResults' => $res['total'], 'startIndex' => $start, 'itemsPerPage' => count($res['items']), 'Resources' => $res['items']]);
        });
    }

    public function show(Request $r, string $id)
    {
        return $this->run($r, fn ($ctx) => $this->json($this->scim->get($ctx, (int) $id)));
    }

    public function store(Request $r)
    {
        return $this->run($r, function ($ctx) use ($r) {
            $res = $this->scim->create($ctx, (array) $r->json()->all());
            return $this->json($res, 201, ['Location' => $res['meta']['location']]);
        });
    }

    public function replace(Request $r, string $id)
    {
        return $this->run($r, function ($ctx) use ($r, $id) {
            $attrs = array_intersect_key((array) $r->json()->all(), array_flip(['externalId', 'active', ScimService::EXT]));
            return $this->json($this->scim->apply($ctx, (int) $id, $attrs));
        });
    }

    public function patch(Request $r, string $id)
    {
        return $this->run($r, function ($ctx) use ($r, $id) {
            $attrs = $this->scim->patchToAttrs((array) $r->json()->all());
            return $this->json($this->scim->apply($ctx, (int) $id, $attrs));
        });
    }

    /** DELETE = تعطيل (مش حذف): الحساب والصندوق بيتوقفوا والجلسات بتتقطع، والبيانات فاضلة. */
    public function destroy(Request $r, string $id)
    {
        return $this->run($r, function ($ctx) use ($id) {
            $this->scim->get($ctx, (int) $id);
            $this->scim->setActive($ctx, (int) $id, false);
            return response('', 204, ['Content-Type' => 'application/scim+json']);
        });
    }
}
