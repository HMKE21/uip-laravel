<?php

namespace App\Repositories;

use App\Models\MessagingPermissionGrant;

/**
 * منقولة جزئيًا من app/Repositories/MessagingPermissionRepository.php
 * القديمة — hasApprovedGrant() بس (اللي MessagingService::
 * assertStudentToStudentAllowed() محتاجاها فعليًا). دورة الطلب/الموافقة/
 * الرفض الكاملة (createRequest/decide/revoke) مالهاش شاشة في الفرونت
 * الحالي (Messages.jsx) لسه — نفس نمط أي جزء تاني من الموديول لسه معندوش
 * استهلاك حقيقي، فمش منقول هنا عمدًا لحد ما يتحط له UI.
 */
class MessagingPermissionRepository
{
    /** @return array{0:int,1:int} [أصغر id, أكبر id] */
    private function sortPair($userId1, $userId2): array
    {
        $a = (int) $userId1;
        $b = (int) $userId2;
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    public function findForPair($userId1, $userId2): ?MessagingPermissionGrant
    {
        [$a, $b] = $this->sortPair($userId1, $userId2);
        return MessagingPermissionGrant::where(['user_a_id' => $a, 'user_b_id' => $b])->first();
    }

    /** صح بس لو فيه approved grant فعّال بين المستخدمين دول. */
    public function hasApprovedGrant($userId1, $userId2): bool
    {
        $grant = $this->findForPair($userId1, $userId2);
        return $grant !== null && $grant->status === 'approved';
    }
}
