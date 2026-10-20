<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\RoleService;

/** يطابق RoleSelectionController::index() القديم بالظبط. */
class RoleSelectionController extends Controller
{
    public function __construct(private RoleService $roles)
    {
    }

    public function index()
    {
        $data = array_map(
            static fn (string $slug, array $labels) => ['slug' => $slug, 'label' => $labels],
            array_keys($this->roles->roleLabels()),
            $this->roles->roleLabels()
        );

        return $this->apiSuccess($data, 'Available roles retrieved successfully.');
    }
}
