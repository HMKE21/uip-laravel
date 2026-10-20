<?php

namespace App\Repositories;

use App\Models\SecurityNotification;

/**
 * منقولة جزئيًا من app/Repositories/SecurityNotificationRepository.php
 * القديمة — forRole() (بند 25 batch 1، فيد داشبورد الأمان) + create()
 * (بند 25 batch 3، AccountLockoutService::registerFailedAttempt() محتاجاها
 * لتسجيل إشعار "Account locked"). باقي الميثودز (unreadCount/markRead/
 * markAllRead) هتتضاف لما صفحة Notifications بتاعة الـ Security Portal
 * ياخد سطح API حقيقي، مش قبل كده.
 */
class SecurityNotificationRepository
{
    /** بند 25 batch 3 — AccountLockoutService محتاجها لتسجيل إشعار "Account locked" داخل المنصة. */
    public function create(array $data): SecurityNotification
    {
        return SecurityNotification::create($data);
    }

    /** @return array<int,array<string,mixed>> الأحدث أولًا، لرول معيّن (أو الإدخالات العامة اللي بلا رول) */
    public function forRole(string $role, int $limit = 30): array
    {
        return SecurityNotification::where(function ($q) use ($role) {
                $q->whereNull('recipient_role')->orWhere('recipient_role', $role);
            })
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (SecurityNotification $n) => $n->toArray())
            ->all();
    }
}
