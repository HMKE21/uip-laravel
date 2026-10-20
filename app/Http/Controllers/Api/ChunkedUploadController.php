<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChunkedUploadService;
use Illuminate\Http\Request;

/** رفع ملفات الشات الكبيرة على أجزاء — شوف ChunkedUploadService. كلها تحت /api/v1/messaging/uploads. */
class ChunkedUploadController extends Controller
{
    public function __construct(private ChunkedUploadService $chunks)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function handle(\Closure $action)
    {
        try {
            return $action();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }

    /** POST /messaging/uploads — body: name, size, mime */
    public function init(Request $request)
    {
        return $this->handle(fn () => $this->apiSuccess(
            $this->chunks->init($this->uid($request), (string) $request->input('name', ''), (int) $request->input('size', 0), $request->input('mime')),
            'Upload started.', 201
        ));
    }

    /** GET /messaging/uploads/{id} */
    public function status(Request $request, string $id)
    {
        return $this->handle(fn () => $this->apiSuccess($this->chunks->status($this->uid($request), $id), 'Upload status.'));
    }

    /** POST /messaging/uploads/{id}/chunk — multipart: index, chunk (file) */
    public function chunk(Request $request, string $id)
    {
        return $this->handle(function () use ($request, $id) {
            $file = $request->file('chunk');
            if (!$file || !$file->isValid()) {
                throw new \RuntimeException('The chunk did not arrive. Please retry.');
            }
            return $this->apiSuccess($this->chunks->putChunk($this->uid($request), $id, (int) $request->input('index', -1), $file->getRealPath()), 'Chunk stored.');
        });
    }

    /** DELETE /messaging/uploads/{id} — المستخدم لغى الرفع */
    public function cancel(Request $request, string $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->chunks->discard($this->uid($request), $id);
            return $this->apiSuccess(null, 'Upload cancelled.');
        });
    }
}
