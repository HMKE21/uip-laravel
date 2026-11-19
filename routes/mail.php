<?php

use App\Http\Controllers\Api\MailApiController;
use Illuminate\Support\Facades\Route;

/** UIPE Mail — المرحلة 1. بيتحمّل من آخر routes/api.php (فبيورث prefix /api). */
Route::prefix('v1/mail')->middleware('uip.auth')->group(function () {
    Route::get('/account', [MailApiController::class, 'account'])->middleware('throttle:240,1');
    Route::get('/messages', [MailApiController::class, 'index'])->middleware('throttle:240,1');
    Route::get('/messages/{id}', [MailApiController::class, 'show'])->whereNumber('id')->middleware('throttle:240,1');
    Route::get('/threads/{threadId}', [MailApiController::class, 'thread'])->whereNumber('threadId')->middleware('throttle:240,1');

    Route::post('/messages', [MailApiController::class, 'send'])->middleware('throttle:30,1');
    Route::post('/messages/bulk', [MailApiController::class, 'bulk'])->middleware('throttle:120,1');
    Route::patch('/messages/{id}', [MailApiController::class, 'update'])->whereNumber('id')->middleware('throttle:240,1');
    Route::delete('/messages/{id}', [MailApiController::class, 'destroy'])->whereNumber('id')->middleware('throttle:120,1');
    Route::delete('/trash', [MailApiController::class, 'emptyTrash'])->middleware('throttle:10,1');

    Route::post('/drafts', [MailApiController::class, 'saveDraft'])->middleware('throttle:120,1');
    Route::put('/drafts/{id}', [MailApiController::class, 'updateDraft'])->whereNumber('id')->middleware('throttle:240,1');
    Route::post('/drafts/{id}/send', [MailApiController::class, 'sendDraft'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('/labels', [MailApiController::class, 'labels'])->middleware('throttle:120,1');
    Route::post('/labels', [MailApiController::class, 'storeLabel'])->middleware('throttle:30,1');
    Route::put('/labels/{id}', [MailApiController::class, 'updateLabel'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/labels/{id}', [MailApiController::class, 'destroyLabel'])->whereNumber('id')->middleware('throttle:30,1');

    Route::post('/attachments', [MailApiController::class, 'upload'])->middleware('throttle:30,1');
    Route::delete('/attachments/{id}', [MailApiController::class, 'deleteAttachment'])->whereNumber('id')->middleware('throttle:30,1');
    Route::get('/attachments/{id}/download', [MailApiController::class, 'download'])->whereNumber('id')->middleware('throttle:60,1');
});
