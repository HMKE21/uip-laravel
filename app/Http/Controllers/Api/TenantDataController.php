<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RunsDomain;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantDataService;
use Illuminate\Http\Request;

/**
 * /api/v1/developer/tenants/{id}/data/* — مدير بيانات المساحة (Database Manager) لمالك المساحة المعزولة.
 * الصلاحية والعزل والحماية كلها جوه TenantDataService — الكنترولر بيمرّر هوية المستخدم وبيتحقق من شكل المدخلات بس.
 * اسم قاعدة البيانات مبيجيش من الطلب أبدًا.
 */
class TenantDataController extends Controller
{
    use RunsDomain;

    public function __construct(private TenantDataService $data)
    {
    }

    public function overview(Request $r, int $id)
    {
        return $this->dom(fn () => $this->data->overview($this->uid($r), $id));
    }

    public function schema(Request $r, int $id)
    {
        $f = $r->validate(['table' => 'required|string|max:128']);
        return $this->dom(fn () => $this->data->schema($this->uid($r), $id, $f['table']));
    }

    public function rows(Request $r, int $id)
    {
        $f = $r->validate([
            'table' => 'required|string|max:128', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|string|max:128', 'dir' => 'nullable|in:asc,desc', 'q' => 'nullable|string|max:100',
            'filters' => 'nullable|array|max:8', 'filters.*.col' => 'required|string|max:128', 'filters.*.op' => 'required|in:=,!=,>,<,>=,<=,like,null,not_null',
            'filters.*.val' => 'nullable|string|max:200',
        ]);
        return $this->dom(fn () => $this->data->rows($this->uid($r), $id, $f['table'], $f));
    }

    public function row(Request $r, int $id)
    {
        $f = $r->validate(['table' => 'required|string|max:128', 'key' => 'required|array|min:1|max:8', 'key.*' => 'nullable']);
        return $this->dom(fn () => $this->data->row($this->uid($r), $id, $f['table'], $f['key']));
    }

    public function insert(Request $r, int $id)
    {
        $f = $r->validate(['table' => 'required|string|max:128', 'values' => 'required|array|min:1|max:200']);
        return $this->dom(fn () => $this->data->insert($this->uid($r), $id, $f['table'], $f['values']), 201);
    }

    public function update(Request $r, int $id)
    {
        $f = $r->validate(['table' => 'required|string|max:128', 'key' => 'required|array|min:1|max:8', 'key.*' => 'nullable', 'values' => 'required|array|min:1|max:200']);
        return $this->dom(fn () => $this->data->update($this->uid($r), $id, $f['table'], $f['key'], $f['values']));
    }

    public function destroy(Request $r, int $id)
    {
        $f = $r->validate(['table' => 'required|string|max:128', 'key' => 'required|array|min:1|max:8', 'key.*' => 'nullable', 'confirm' => 'accepted']);
        return $this->dom(fn () => $this->data->delete($this->uid($r), $id, $f['table'], $f['key']));
    }

    public function sql(Request $r, int $id)
    {
        $f = $r->validate(['sql' => 'required|string|max:20000']);
        return $this->dom(fn () => $this->data->sql($this->uid($r), $id, $f['sql']));
    }
}
