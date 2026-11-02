<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// المنشورات العامة: قناة مشتركة لكل مستخدم مسجّل (بتنقل ids بس — شوف App\Events\Realtime\PostsPing).
Broadcast::channel('posts.feed', function ($user) {
    return (bool) $user;
});
