<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMessage;
use App\Services\AiAssistantService;
use App\Services\AiUserContextService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Controllers/Common/AiAssistantController.php القديمة —
 * /api/v1/ai-assistant/*، كنترولر واحد لكل بورتال (زي MessagingController
 * بالظبط)، كل قرارات RBAC/الملكية جوّه AiAssistantService. uip.auth
 * middleware بيحط uip_user_id/uip_role على الـ Request (شوف uid()/role()
 * تحت) — نفس اتفاقية MessagingController.
 *
 * الـ Streaming (بند 12 في السبك القديم): SSE حقيقي عبر
 * response()->stream()، بنفس شكل الحدث بالظبط `data: {...}\n\n` اللي
 * الفرونت React (AiAssistantWidget.jsx) بيفكّه بـ fetch()+ReadableStream
 * — الفرونت اتأكد إنه مش محتاج أي تعديل.
 */
class AiAssistantController extends Controller
{
    public function __construct(
        private AiAssistantService $assistant,
        private FileUploadService $uploads,
        private AiUserContextService $userContext
    ) {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function role(Request $request): ?string
    {
        $role = (string) $request->attributes->get('uip_role', '');
        return $role !== '' ? $role : null;
    }

    private function portalForRole(?string $role): string
    {
        $map = [
            'security_admin' => 'security', 'security_officer' => 'security',
            'data_analyst' => 'data_analysis',
        ];
        return $map[$role ?? ''] ?? ($role ?: 'student');
    }

    /** بيلف أي فعل بيرمي RuntimeException بشكل ودّي — نفس اتفاقية MessagingController::handle(). */
    private function handle(\Closure $action, int $errorStatus = 422)
    {
        try {
            return $action();
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, $errorStatus);
        }
    }

    // -- Availability / capabilities ------------------------------------------

    /** GET /api/v1/ai-assistant/status */
    public function status(Request $request)
    {
        return $this->apiSuccess([
            'available' => $this->assistant->isAvailableFor($this->uid($request)),
            'portal'    => $this->portalForRole($this->role($request)),
            'data_access' => $this->assistant->toolsEnabledFor($this->role($request)),
        ], 'Status retrieved successfully.');
    }

    // -- Conversations ----------------------------------------------------------

    /** GET /api/v1/ai-assistant/conversations — query: q, pinned, archived */
    public function conversations(Request $request)
    {
        return $this->handle(function () use ($request) {
            $rows = $this->assistant->listConversations($this->uid($request), [
                'q'        => (string) $request->query('q', ''),
                'pinned'   => $request->query('pinned') === '1',
                'archived' => $request->query('archived') === '1',
            ]);
            return $this->apiSuccess($rows, 'Conversations retrieved successfully.');
        });
    }

    /** POST /api/v1/ai-assistant/conversations — body: portal?, title? */
    public function createConversation(Request $request)
    {
        return $this->handle(function () use ($request) {
            $conversation = $this->assistant->createConversation(
                $this->uid($request),
                (string) $request->input('portal', $this->portalForRole($this->role($request))),
                $request->input('title')
            );
            return $this->apiSuccess($conversation, 'Conversation created successfully.', 201);
        });
    }

    /** GET /api/v1/ai-assistant/conversations/{id} */
    public function showConversation(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $data = $this->assistant->getConversation($this->uid($request), (int) $id);
            return $this->apiSuccess($data, 'Conversation retrieved successfully.');
        }, 404);
    }

    /** PATCH /api/v1/ai-assistant/conversations/{id} — body: title? / is_pinned? / is_archived? */
    public function updateConversation(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $userId = $this->uid($request);
            $conversationId = (int) $id;
            $data = null;

            if ($request->input('title') !== null) {
                $data = $this->assistant->renameConversation($userId, $conversationId, (string) $request->input('title'));
            }
            if ($request->input('is_pinned') !== null) {
                $data = $this->assistant->setPinned($userId, $conversationId, (bool) $request->input('is_pinned'));
            }
            if ($request->input('is_archived') !== null) {
                $data = $this->assistant->setArchived($userId, $conversationId, (bool) $request->input('is_archived'));
            }
            $data = $data ?? $this->assistant->getConversation($userId, $conversationId)['conversation'];

            return $this->apiSuccess($data, 'Conversation updated successfully.');
        });
    }

    /** DELETE /api/v1/ai-assistant/conversations/{id} */
    public function deleteConversation(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->assistant->deleteConversation($this->uid($request), (int) $id);
            return $this->apiSuccess(['deleted' => true], 'Conversation deleted successfully.');
        }, 404);
    }

    /** GET /api/v1/ai-assistant/conversations/{id}/export */
    public function exportConversation(Request $request, $id)
    {
        try {
            $export = $this->assistant->exportConversation($this->uid($request), (int) $id);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return response($export['content'], 200, [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
        ]);
    }

    /** GET /api/v1/ai-assistant/search — query: q */
    public function search(Request $request)
    {
        return $this->handle(function () use ($request) {
            $q = trim((string) $request->query('q', ''));
            $rows = $q === '' ? [] : $this->assistant->searchMessages($this->uid($request), $q);
            return $this->apiSuccess($rows, 'Search results retrieved successfully.');
        });
    }

    // -- Attachments --------------------------------------------------------------

    /** POST /api/v1/ai-assistant/conversations/{id}/attachments — multipart: file */
    public function uploadAttachment(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $conversationId = (int) $id;
            // فحص الملكية أولًا — يوزر أبدًا ميقدرش يرفع في محادثة مش بتاعته.
            $this->assistant->getConversation($this->uid($request), $conversationId);

            $stored = $this->uploads->store($request->file('file'), 'ai_assistant', 'conv_' . $conversationId);
            $extension = strtolower($stored['extension']);
            $absolutePath = \App\Support\SafePath::publicPath($stored['stored_path']);

            $extractedText = null;
            if (\App\Services\Ai\AttachmentReader::isTextExt($extension) && is_file($absolutePath) && filesize($absolutePath) < 2_000_000) {
                $raw = @file_get_contents($absolutePath);
                $extractedText = is_string($raw) && $raw !== '' ? \App\Services\Ai\AttachmentReader::toUtf8($raw) : null;
            } elseif (\App\Services\Ai\AttachmentReader::canExtract($extension) && is_file($absolutePath) && filesize($absolutePath) < 30_000_000) {
                // PDF / Word / PowerPoint / Excel / OpenDocument / RTF / ZIP → نص يتبعت للموديل. null = مفيش نص مقروء (ممسوح ضوئيًا مثلًا).
                $extractedText = \App\Services\Ai\AttachmentReader::extractText($absolutePath, $extension);
            }

            $kind = $this->classifyKind($extension, (string) $stored['original_name'], (string) ($stored['mime_type'] ?? ''));

            $attachmentId = DB::table('ai_message_attachments')->insertGetId([
                'message_id'      => null,
                'conversation_id' => $conversationId,
                'uploader_id'     => $this->uid($request),
                'kind'            => $kind,
                'stored_path'     => $stored['stored_path'],
                'original_name'   => $stored['original_name'],
                'mime_type'       => $stored['mime_type'],
                'extension'       => $extension,
                'size_bytes'      => $stored['size_bytes'],
                'extracted_text'  => $extractedText,
                'created_at'      => now(),
            ]);

            return $this->apiSuccess([
                'id'            => (int) $attachmentId,
                'kind'          => $kind,
                'original_name' => $stored['original_name'],
                'mime_type'     => $stored['mime_type'],
                'size_bytes'    => $stored['size_bytes'],
            ], 'Attachment uploaded successfully.', 201);
        });
    }

    /** GET /api/v1/ai-assistant/attachments/{id}/download */
    public function downloadAttachment(Request $request, $id)
    {
        $attachment = DB::table('ai_message_attachments AS a')
            ->join('ai_conversations AS c', 'c.id', '=', 'a.conversation_id')
            ->where('a.id', (int) $id)
            ->where('c.user_id', $this->uid($request))
            ->select('a.*')
            ->first();

        if (!$attachment) {
            return $this->apiError('Attachment not found.', null, 404);
        }

        $path = \App\Support\SafePath::publicPath($attachment->stored_path);
        if (!is_file($path)) {
            return $this->apiError('File no longer exists.', null, 404);
        }

        // response()->file() = BinaryFileResponse: streams from disk (no file_get_contents() of a whole video into PHP memory)
        // and answers HTTP Range requests, so audio / video can seek and start playing before the download ends.
        $name = (string) $attachment->original_name;
        return response()->file($path, [
            'Content-Type'           => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition'    => 'inline; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=3600',
        ]);
    }

    /**
     * POST /api/v1/ai-assistant/attachments/{id}/replace  (multipart: file, optional name)
     * Saves an edited copy of an attachment (AI-generated or user-uploaded) from the platform viewer/editor inside the AI chat.
     * The same row is updated in place (same id) and extracted_text is refreshed so the AI reads the edit in later messages.
     */
    public function replaceAttachment(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $att = DB::table('ai_message_attachments AS a')
                ->join('ai_conversations AS c', 'c.id', '=', 'a.conversation_id')
                ->where('a.id', (int) $id)
                ->where('c.user_id', $this->uid($request))
                ->select('a.*')
                ->first();
            if (!$att) {
                return $this->apiError('Attachment not found.', null, 404);
            }

            $file = $request->file('file');
            if (!$file || !$file->isValid()) {
                throw new \RuntimeException('No file was uploaded.');
            }
            if ($file->getSize() > 50 * 1024 * 1024) {
                throw new \RuntimeException('File is too large.');
            }

            $oldExt = strtolower((string) $att->extension);
            $name = trim((string) $request->input('name', ''));
            $name = $name !== '' ? mb_substr(preg_replace('/[\\\\\/\x00-\x1F]+/u', '_', $name) ?? $name, 0, 180) : (string) $att->original_name;
            $newExt = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            // Only data / document / image / media formats may change the extension; anything else keeps the stored one (never executable types).
            $safeExt = ['pdf', 'docx', 'xlsx', 'pptx', 'csv', 'tsv', 'txt', 'md', 'json', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip', 'mp4', 'webm', 'mp3', 'wav'];
            if ($newExt === '' || $newExt === $oldExt || !in_array($newExt, $safeExt, true)) {
                $newExt = $oldExt;
                if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== $oldExt) {
                    $name = (string) $att->original_name;
                }
            }

            $storedPath = (string) $att->stored_path;
            $dirRel = trim(str_replace('\\', '/', dirname($storedPath)), '/');
            if ($newExt !== $oldExt) {
                $storedPath = $dirRel . '/' . bin2hex(random_bytes(16)) . '.' . $newExt;
            }
            $absolute = \App\Support\SafePath::publicPath($storedPath);
            $root = realpath(public_path());
            $dirAbs = dirname($absolute);
            if (!is_dir($dirAbs)) {
                throw new \RuntimeException('Attachment folder not found.');
            }
            $realDir = realpath($dirAbs);
            if ($root === false || $realDir === false || !str_starts_with($realDir . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Invalid attachment path.');
            }

            $bytes = @file_get_contents($file->getRealPath());
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('The uploaded file is empty.');
            }
            if (@file_put_contents($absolute, $bytes, LOCK_EX) === false) {
                throw new \RuntimeException('Failed to save the file.');
            }
            \App\Support\UploadMirror::push($storedPath, $absolute);
            if ($storedPath !== $att->stored_path) {
                $old = \App\Support\SafePath::publicPath((string) $att->stored_path);
                if (is_file($old)) {
                    \App\Support\SafePath::delete($old);
                }
            }

            $extractedText = null;
            if (\App\Services\Ai\AttachmentReader::isTextExt($newExt) && strlen($bytes) < 2_000_000) {
                $extractedText = \App\Services\Ai\AttachmentReader::toUtf8($bytes);
                $extractedText = $extractedText !== '' ? mb_substr($extractedText, 0, 100000) : null;
            } elseif (\App\Services\Ai\AttachmentReader::canExtract($newExt) && strlen($bytes) < 30_000_000) {
                $extractedText = \App\Services\Ai\AttachmentReader::extractText($absolute, $newExt);
            }

            $mime = (string) ($file->getMimeType() ?: '');
            if ($mime === '' || $mime === 'application/octet-stream' || ($newExt === $oldExt && !empty($att->mime_type))) {
                $mime = (string) ($att->mime_type ?: $mime ?: 'application/octet-stream');
            }
            $kind = $this->classifyKind($newExt, $name, $mime);

            DB::table('ai_message_attachments')->where('id', (int) $att->id)->update([
                'stored_path'    => $storedPath,
                'original_name'  => $name,
                'mime_type'      => $mime,
                'extension'      => $newExt,
                'kind'           => $kind,
                'size_bytes'     => strlen($bytes),
                'extracted_text' => $extractedText,
            ]);

            return $this->apiSuccess([
                'id'            => (int) $att->id,
                'kind'          => $kind,
                'url'           => url('/api/v1/ai-assistant/attachments/' . (int) $att->id . '/download') . '?v=' . time(),
                'original_name' => $name,
                'mime_type'     => $mime,
                'size_bytes'    => strlen($bytes),
            ], 'Attachment saved.');
        });
    }

    private function classifyKind(string $extension, ?string $originalName = null, ?string $mime = null): string
    {
        // A recorded voice note is an audio-only .webm: by extension alone it would be classed as video.
        if (($originalName !== null && preg_match('/^voice-message-/i', $originalName)) || ($mime !== null && str_starts_with(strtolower($mime), 'audio/'))) {
            return 'audio';
        }
        $map = [
            'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'],
            'video'    => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
            'audio'    => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus', 'weba'],
            'archive'  => ['zip', 'rar', '7z'],
            'code'     => ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'java', 'kt', 'swift', 'rs', 'c', 'cpp', 'cs', 'go', 'rb', 'html', 'css', 'sql', 'sh', 'yml', 'yaml', 'toml', 'ini'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv', 'tsv', 'json', 'xml', 'txt', 'md', 'log'],
        ];
        foreach ($map as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }
        return 'file';
    }

    // -- Message actions -------------------------------------------------------------

    /** PATCH /api/v1/ai-assistant/messages/{id} — body: content */
    public function editMessage(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->assistant->editMessage($this->uid($request), (int) $id, (string) $request->input('content', ''));
            return $this->apiSuccess($message, 'Message updated successfully.');
        });
    }

    /** DELETE /api/v1/ai-assistant/messages/{id} */
    public function deleteMessage(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->assistant->deleteMessage($this->uid($request), (int) $id);
            return $this->apiSuccess(['deleted' => true], 'Message deleted successfully.');
        });
    }

    /** POST /api/v1/ai-assistant/messages/{id}/bookmark — body: bookmarked */
    public function bookmarkMessage(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->assistant->bookmarkMessage($this->uid($request), (int) $id, (bool) $request->input('bookmarked', true));
            return $this->apiSuccess($message, 'Message updated successfully.');
        });
    }

    /** POST /api/v1/ai-assistant/messages/{id}/react — body: emoji */
    public function reactToMessage(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->assistant->reactToMessage($this->uid($request), (int) $id, (string) $request->input('emoji', '👍'));
            return $this->apiSuccess($message, 'Reaction updated successfully.');
        });
    }

    // -- Sending a message (streaming) -----------------------------------------------

    /**
     * POST /api/v1/ai-assistant/conversations/{id}/messages — body: content,
     * attachment_ids[]?, portal?, page?, project?, dashboard?
     *
     * SSE: كل event سطر `data: {"type": "...", ...}\n\n` — type ∈
     * user_message|token|done|error. الفرونت React بيستهلكه عبر
     * fetch()+ReadableStream (مش EventSource) عشان الطلب POST — نفس
     * الاتفاقية القديمة بالظبط.
     */
    public function streamMessage(Request $request, $id)
    {
        $userId = $this->uid($request);
        $conversationId = (int) $id;
        $content = trim((string) $request->input('content', ''));
        $attachmentIds = array_map('intval', (array) $request->input('attachment_ids', []));
        $context = $this->buildContext($request);

        if ($content === '' && !$attachmentIds) {
            return $this->apiError('Message cannot be empty.', null, 422);
        }

        // أمر توليد صورة: /image <وصف> أو /صورة <وصف> (من غير مرفقات).
        $isImageCommand = !$attachmentIds && $this->assistant->parseImageCommand($content) !== null;
        $imagePrompt = null;
        if (!$attachmentIds) {
            // الأمر الصريح دايمًا؛ والطلب بلغة عادية ("اعمل لي صورة لوجو…") بس لو توليد الصور متظبّط.
            $imagePrompt = $isImageCommand
                ? $this->assistant->parseImageCommand($content)
                : ($this->assistant->imageGenerationConfigured() ? $this->assistant->parseImageIntent($content) : null);
        }
        if ($imagePrompt !== null) {
            if (!$this->assistant->imageGenerationConfigured()) {
                return $this->apiError('Image generation is not configured. An admin must set an Image model in Settings → AI.', null, 422);
            }
            try {
                $this->assistant->assertNotRateLimited($userId);
            } catch (\RuntimeException $e) {
                return $this->apiError($e->getMessage(), null, 429);
            }
            try {
                $prepared = $this->assistant->prepareTurn($userId, $conversationId, $content, [], $context);
            } catch (\RuntimeException $e) {
                return $this->apiError($e->getMessage(), null, 404);
            }
            return $this->sseResponse(function () use ($userId, $conversationId, $prepared, $context, $imagePrompt) {
                $send = function (array $payload): void {
                    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                    @flush();
                };
                $assistantId = (int) $prepared['assistant_message_id'];
                $send(['type' => 'user_message', 'message' => $prepared['user_message']]);
                $send(['type' => 'tool', 'label' => 'Generating image', 'kind' => 'image']);
                try {
                    $res = $this->assistant->generateImageReply($userId, $conversationId, $assistantId, $imagePrompt, (string) ($context['portal'] ?? 'general'));
                    $send(['type' => 'done', 'message_id' => $assistantId, 'content' => $res['content'], 'attachments' => $res['attachments']]);
                } catch (\Throwable $e) {
                    $send(['type' => 'error', 'message_id' => $assistantId, 'message' => $e->getMessage()]);
                }
            });
        }

        // طبقة الرد الجاهز الذكي / FAQ: بتقف قبل مسار الـ AI العادي —
        // تيرن نصي بس (بدون مرفقات) بيتفحص الأول؛ تطابق واثق بيرد فورًا
        // من غير AIClient ومن غير استهلاك rate limit؛ miss بيكمل عادي.
        if (!$attachmentIds) {
            try {
                $faqAnswer = $this->assistant->tryFaqAnswer($userId, $conversationId, $content, $context);
            } catch (\RuntimeException $e) {
                return $this->apiError($e->getMessage(), null, 404);
            }
            if ($faqAnswer !== null) {
                return $this->sseResponse(function () use ($faqAnswer) {
                    $this->emitSseFaqTurn($faqAnswer);
                });
            }
        }

        try {
            $this->assistant->assertNotRateLimited($userId);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 429);
        }

        try {
            $prepared = $this->assistant->prepareTurn($userId, $conversationId, $content, $attachmentIds, $context);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 404);
        }

        return $this->sseResponse(function () use ($userId, $conversationId, $prepared, $context) {
            $this->emitSseTurn($userId, $conversationId, $prepared['assistant_message_id'], $prepared['prompt_messages'], $context, $prepared['user_message']);
        });
    }

    /** Retry / Regenerate Response، برضه streamed. */
    public function regenerateMessage(Request $request, $id)
    {
        $userId = $this->uid($request);
        $messageId = (int) $id;
        $context = $this->buildContext($request);

        try {
            $this->assistant->assertNotRateLimited($userId);
            $message = AiMessage::find($messageId);
            if (!$message) {
                throw new \RuntimeException('Message not found.');
            }
            $conversationId = (int) $message->conversation_id;
            $prepared = $this->assistant->regenerate($userId, $conversationId, $messageId, $context);
        } catch (\RuntimeException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->sseResponse(function () use ($userId, $conversationId, $prepared, $context) {
            $this->emitSseTurn($userId, $conversationId, $prepared['assistant_message_id'], $prepared['prompt_messages'], $context, null);
        });
    }

    /** بيلف $emitter في response()->stream() بنفس هيدرز Core\Response::stream() القديمة بالظبط، عشان الفرونت ميحسش بفرق. */
    private function sseResponse(\Closure $emitter): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->stream(function () use ($emitter) {
            @set_time_limit(0); // long replies (files / zips) can outlive max_execution_time
            // بند 12: يقفل أي output buffering ممكن يمنع الـ flush لحظة بلحظة.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $emitter();
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * تيرن اتردّ من الـ FAQ، على نفس شكل event الـ AI الحقيقي بالظبط
     * (user_message -> token* -> done) عشان الفرونت مايحتاجش أي تعديل.
     * answer_source='predefined' في event الـ done — فرونت قديم بيتجاهلها ببساطة.
     */
    private function emitSseFaqTurn(array $faqAnswer): void
    {
        echo 'data: ' . json_encode(['type' => 'user_message', 'message' => $faqAnswer['user_message']], JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();

        $content = $faqAnswer['assistant_message']['content'];
        foreach ($this->chunkForStreaming($content) as $chunk) {
            echo 'data: ' . json_encode(['type' => 'token', 'delta' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        }

        echo 'data: ' . json_encode([
            'type'          => 'done',
            'message_id'    => $faqAnswer['assistant_message']['id'],
            'content'       => $content,
            'answer_source' => 'predefined',
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
    }

    /** تقطيع بالكلمة لتأثير "streaming" شكلي بس لرد الـ FAQ — أبدًا مالوش تأثير على المحتوى المخزّن. */
    private function chunkForStreaming(string $text, int $wordsPerChunk = 6): array
    {
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return [$text];
        }
        return array_map(fn (array $group) => implode('', $group), array_chunk($words, $wordsPerChunk * 2));
    }

    private function emitSseTurn(int $userId, int $conversationId, int $assistantMessageId, array $promptMessages, array $context, ?array $userMessage): void
    {
        $portal = (string) ($context['portal'] ?? 'general');
        $send = function (array $payload): void {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        if ($userMessage !== null) {
            $send(['type' => 'user_message', 'message' => $userMessage]);
        }

        try {
            $fullText = $this->assistant->streamAssistantReply(
                $userId, $conversationId, $assistantMessageId, $promptMessages, $portal,
                fn (string $delta) => $send(['type' => 'token', 'delta' => $delta]),
                // الدور من التوكن (buildContext) — مش من body الطلب.
                $context['role'] ?? null,
                // tool = "جاري قراءة بيانات…" / reset = امسح نص ما قبل الأداة. فرونت قديم بيتجاهلهم.
                fn (string $type, array $payload) => $send(['type' => $type] + $payload),
                (string) ($context['locale'] ?? 'ar')
            );
            $send(['type' => 'done', 'message_id' => $assistantMessageId, 'content' => $fullText, 'attachments' => $this->assistant->attachmentsForAssistantMessage($assistantMessageId), 'truncated' => $this->assistant->wasTruncated()]);
        } catch (\Throwable $e) {
            $send(['type' => 'error', 'message_id' => $assistantMessageId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * بيبني السياق الآمن-RBAC بس من الـ Session/Request الموثّق — role/portal
     * دايمًا من الـ middleware، أبدًا مش من الكلاينت. page/project/dashboard
     * تلميحات هيكلية اختيارية من الويدجت بتوصف الصفحة اللي هي أصلًا معروضة
     * لنفس اليوزر الموثّق ده (بيانات جالها اليوزر ده أصلًا من استدعاءات API
     * موثّقة بتاعته) — أبدًا مش بديل لفحص صلاحية server-side.
     */
    private function buildContext(Request $request): array
    {
        $role = $this->role($request);
        $portal = (string) $request->input('portal', $this->portalForRole($role));
        $page = $request->input('page');
        $route = $request->input('route');
        $locale = $request->input('locale');
        $project = $request->input('project');
        $dashboard = $request->input('dashboard');

        // بروفايل اليوزر من الداتابيز بمعرّف التوكن بس (مش من body الطلب).
        // كاش قصير عشان رسايل متتالية ماتعيدش نفس الاستعلامات.
        $userId = $this->uid($request);
        $profile = [];
        if ($userId > 0) {
            try {
                $profile = Cache::remember(
                    'ai_profile:' . $userId . ':' . ($role ?? 'none'),
                    45,
                    fn () => $this->userContext->forUser($userId, $role)
                );
            } catch (\Throwable $e) {
                Log::warning('AI profile context failed', ['error' => $e->getMessage()]);
            }
        }

        return array_filter([
            'portal'    => $portal,
            'page'      => is_string($page) ? mb_substr($page, 0, 200) : null,
            'route'     => is_string($route) ? mb_substr($route, 0, 200) : null,
            'locale'    => in_array($locale, ['ar', 'en'], true) ? $locale : null,
            'role'      => $role,
            'profile'   => $profile ?: null,
            'user_prefs' => $userId > 0 ? $this->assistant->userPromptText($userId) : null,
            'project'   => is_array($project) ? $project : null,
            'dashboard' => is_array($dashboard) ? $dashboard : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
