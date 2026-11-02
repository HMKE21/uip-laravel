<?php

namespace App\Http\Middleware;

use App\Support\Realtime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pushes a realtime ping after every successful WRITE on the route group it
 * wraps — one place instead of editing 40+ service methods:
 *
 *   uip.realtime:messaging  → every member of the conversation (send, edit, delete,
 *                             react, read, pin, vote, typing, group changes…)
 *   uip.realtime:drive      → the owner's other tabs/devices (My PC: create, save,
 *                             rename, move, delete, restore, upload…)
 *   uip.realtime:notifications → the person's other tabs/devices (read/archive/delete)
 *
 * It runs in a terminating callback: the response has already been sent to the person who
 * wrote, so the push never slows them down.
 */
class UipRealtimeMiddleware
{
    public function handle(Request $request, Closure $next, string $scope = 'messaging')
    {
        $response = $next($request);
        // Runs after the response has been sent to the writer (Laravel "terminating" callbacks).
        app()->terminating(fn () => $this->push($request, $response, $scope));

        return $response;
    }

    private function push(Request $request, $response, string $scope): void
    {
        try {
            if (!Realtime::enabled() || $request->isMethodSafe()) {
                return;
            }
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($status < 200 || $status >= 300) {
                return;
            }
            $uid = (int) $request->attributes->get('uip_user_id');
            if (!$uid) {
                return;
            }

            match ($scope) {
                'messaging'     => $this->messaging($request, $response, $uid),
                'drive'         => $this->drive($request, $uid),
                'notifications' => Realtime::toUsers([$uid], 'notification', ['sync' => true]),
                'posts'         => $this->posts($request, $response, $uid),
                default         => null,
            };
        } catch (\Throwable $e) {
            // a realtime hiccup must never surface as an error
        }
    }

    private function messaging(Request $request, $response, int $uid): void
    {
        $path = $request->path(); // api/v1/messaging/...

        // typing indicator: body.conversation_id (null = stopped typing, nothing to push)
        if (str_contains($path, 'presence/typing')) {
            $cid = (int) $request->input('conversation_id');
            if ($cid) {
                Realtime::toUsers(
                    array_diff(Realtime::conversationMembers($cid), [$uid]),
                    'messaging',
                    ['conversation_id' => $cid, 'kind' => 'typing']
                );
            }
            return;
        }
        if (str_contains($path, 'presence/')) {
            return; // heartbeat / lookup
        }

        $cid = $this->conversationId($request, $response);
        if (!$cid) {
            // something not tied to one conversation (stories, categories, privacy…): nudge the writer's other devices only
            Realtime::toUsers([$uid], 'messaging', ['kind' => 'inbox']);
            return;
        }

        $kind = 'change';
        if (str_ends_with($path, '/read')) {
            $kind = 'read';
        } elseif (str_contains($path, '/react')) {
            $kind = 'reaction';
        } elseif (str_ends_with($path, '/messages') && $request->isMethod('POST')) {
            $kind = 'message';
        }

        Realtime::toUsers(Realtime::conversationMembers($cid), 'messaging', ['conversation_id' => $cid, 'kind' => $kind]);
        // notification bell + unread badge of the others move at the same moment
        if ($kind === 'message') {
            Realtime::toUsers(array_diff(Realtime::conversationMembers($cid), [$uid]), 'notification', ['sync' => true]);
        }
    }

    /**
     * المنشورات: بعد أي كتابة (تفاعل، تعليق، رد، حفظ، مشاركة، تعديل، حذف، نشر) بنبعت ping فيه id المنشور ونوع التغيير.
     * المنشور العام → القناة المشتركة posts.feed؛ غير كده → صاحب المنشور + المعلّقين + المتفاعلين + الجمهور المحدّد بس.
     */
    private function posts(Request $request, $response, int $uid): void
    {
        $path = $request->path(); // api/v1/posts/...
        if (preg_match('#/posts/(media|collections|follow|authors|config)#', $path) || str_ends_with($path, '/view') || str_contains($path, '/hide') || str_contains($path, '/report')) {
            return; // مشاهدات، رفع ملفات، متابعة… مش بتغيّر شكل منشور قدام حد
        }
        $route = $request->route();
        $postId = null;
        $commentId = null;
        $kind = 'change';

        if (str_contains($path, '/comments/') && $route && $route->parameter('commentId') !== null) {
            $commentId = (int) $route->parameter('commentId');
            $row = DB::table('post_comments')->where('id', $commentId)->first(['post_id', 'parent_id']);
            $postId = $row ? (int) $row->post_id : null;
            $parentId = $row && $row->parent_id ? (int) $row->parent_id : null;
            $kind = (str_ends_with($path, '/like') || str_ends_with($path, '/react')) ? 'comment_react' : ($request->isMethod('DELETE') ? 'comment_delete' : 'comment_edit');
        } elseif ($route && $route->parameter('id') !== null) {
            $postId = (int) $route->parameter('id');
            if (str_ends_with($path, '/react')) {
                $kind = 'react';
            } elseif (str_ends_with($path, '/comments') && $request->isMethod('POST')) {
                $kind = 'comment';
                $parentId = (int) $request->input('parent_id') ?: null;
            } elseif (str_ends_with($path, '/save')) {
                $kind = 'save';
            } elseif (str_ends_with($path, '/publish')) {
                $kind = 'new';
            } elseif ($request->isMethod('DELETE') && !str_contains($path, '/media/')) {
                $kind = 'removed';
            } elseif ($request->isMethod('PATCH')) {
                $kind = 'edit';
            }
        } elseif ($request->isMethod('POST') && preg_match('#/posts$#', $path) && method_exists($response, 'getContent')) {
            // منشور جديد: الـ id في رد الـ API
            $json = json_decode((string) $response->getContent(), true);
            $d = is_array($json) ? ($json['data'] ?? []) : [];
            $postId = isset($d['id']) ? (int) $d['id'] : null;
            $kind = (($d['status'] ?? 'published') === 'published') ? 'new' : 'change';
        }
        if (!$postId) {
            return;
        }
        $post = DB::table('posts')->where('id', $postId)->first(['id', 'user_id', 'visibility', 'status']);
        if (!$post) {
            // اتحذف: لسه محتاجين نبلّغ اللي شايفينه
            \App\Support\PostsRealtime::publish($postId, $kind, ['actor' => $uid, 'comment_id' => $commentId, 'parent_id' => $parentId ?? null], null);
            return;
        }
        \App\Support\PostsRealtime::publish($postId, $kind, ['actor' => $uid, 'comment_id' => $commentId, 'parent_id' => $parentId ?? null], $post);
    }

    private function conversationId(Request $request, $response): ?int
    {
        $route = $request->route();
        $path = $request->path();

        if (str_contains($path, '/conversations/') && $route && $route->parameter('id') !== null) {
            return (int) $route->parameter('id');
        }
        if (str_contains($path, '/messages/') && $route && $route->parameter('id') !== null) {
            $cid = DB::table('messages')->where('id', (int) $route->parameter('id'))->value('conversation_id');
            return $cid ? (int) $cid : null;
        }
        // POST /conversations/direct|group → the new conversation id is in the JSON answer
        if (preg_match('#/conversations/(direct|group)$#', $path) && method_exists($response, 'getContent')) {
            $json = json_decode((string) $response->getContent(), true);
            $d = is_array($json) ? ($json['data'] ?? []) : [];
            $cid = $d['conversation_id'] ?? $d['id'] ?? ($d['conversation']['id'] ?? null);
            if ($cid) {
                return (int) $cid;
            }
        }
        return null;
    }

    private function drive(Request $request, int $uid): void
    {
        $route = $request->route();
        $nodeId = $route && $route->parameter('id') !== null ? (int) $route->parameter('id') : null;
        $content = $nodeId && (str_contains($request->path(), '/content') || str_contains($request->path(), '/replace'));
        Realtime::toUsers([$uid], 'drive', array_filter([
            'node_id' => $nodeId,
            'kind'    => $content ? 'content' : 'tree',
        ], fn ($v) => $v !== null));
    }
}
