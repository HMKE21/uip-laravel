<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * نفس شكل apiSuccess()/apiError() اللي في core/Controller.php بالظبط
 * (success/message/data/errors/meta) — عشان الفرونت React ميحسش بفرق.
 */
abstract class Controller
{
    protected function apiSuccess($data = null, string $message = 'Operation completed successfully.', int $status = 200, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
            'meta'    => $meta ?? (object) [],
        ], $status);
    }

    protected function apiError(string $message, ?array $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
            'meta'    => (object) [],
        ], $status);
    }
}
