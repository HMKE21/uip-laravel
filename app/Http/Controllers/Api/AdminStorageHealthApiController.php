<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorageHealth;

/** GET /api/v1/admin/system/storage-health — قيم PHP الحقيقية للويب + حالة الـ Volume. أدمن بس (uip.admin). */
class AdminStorageHealthApiController extends Controller
{
    public function show()
    {
        return $this->apiSuccess(StorageHealth::report(), 'Storage health retrieved successfully.');
    }
}
