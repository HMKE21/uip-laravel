<?php

namespace App\Services;

use App\Services\Export\PdfWriter;
use Illuminate\Support\Facades\DB;

/**
 * المصاريف: بنود رسوم (جامعة/كلية) ← تتصدر لكل طلاب النطاق ← الطالب يبعت إخطار دفع (مرجع تحويل) ← الإدارة تأكّد/ترفض
 * أو تسجّل دفعة مباشرة ← إيصال PDF لكل دفعة مؤكَّدة. المبالغ بتتحسب بالقروش (int) لتفادي أخطاء الـ float.
 * `$scope` هو نفس مصفوفة CourseApiController::scope().
 */
class FeeService
{
    public const KINDS = ['tuition', 'registration', 'service', 'other'];
    public const METHODS = ['cash', 'bank_transfer', 'card', 'other'];

    public function __construct(private NotificationService $notifications)
    {
    }

    private function ar(string $l): bool
    {
        return $l === 'ar';
    }

    private function fail(string $l, string $ar, string $en, int $code = 422): array
    {
        return ['success' => false, 'message' => $this->ar($l) ? $ar : $en, 'code' => $code];
    }

    private function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function isManager(array $scope): bool
    {
        return ($scope['role'] ?? null) === 'university' || (($scope['role'] ?? null) === 'faculty' && !empty($scope['facultyId']));
    }

    private function items(array $scope)
    {
        $q = DB::table('fee_items')->where('university_id', $scope['universityId']);
        return $scope['role'] === 'faculty' ? $q->where('faculty_id', (int) $scope['facultyId']) : $q;
    }

    /** مستحقات الطلاب اللي الإدارة تشوفها. */
    private function ledgerQuery(array $scope)
    {
        $q = DB::table('student_fees as f')
            ->join('fee_items as i', 'i.id', '=', 'f.fee_item_id')
            ->join('students as s', 's.id', '=', 'f.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('i.university_id', $scope['universityId']);
        return $scope['role'] === 'faculty' ? $q->where('s.faculty_id', (int) $scope['facultyId']) : $q;
    }

    private function recompute(int $studentFeeId): void
    {
        $f = DB::table('student_fees')->where('id', $studentFeeId)->first();
        if (!$f || $f->status === 'waived') {
            return;
        }
        $paid = $this->cents(DB::table('fee_payments')->where('student_fee_id', $studentFeeId)->where('status', 'confirmed')->sum('amount'));
        $total = $this->cents($f->amount);
        $status = $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial');
        DB::table('student_fees')->where('id', $studentFeeId)->update(['paid_amount' => $this->money($paid), 'status' => $status, 'updated_at' => now()]);
    }

    private function presentFee(object $f, string $l): array
    {
        $balance = max(0, $this->cents($f->amount) - $this->cents($f->paid_amount));
        $overdue = in_array($f->status, ['unpaid', 'partial'], true) && $f->due_on && $f->due_on < date('Y-m-d');
        return [
            'id' => (int) $f->id, 'item_id' => (int) $f->fee_item_id, 'name' => $f->item_name, 'kind' => $f->item_kind, 'currency' => $f->currency,
            'amount' => (float) $f->amount, 'paid' => (float) $f->paid_amount, 'balance' => $f->status === 'waived' ? 0.0 : $this->money($balance),
            'due_on' => $f->due_on, 'status' => $f->status, 'overdue' => $overdue, 'waive_note' => $f->waive_note,
        ];
    }

    // ---------------------------------------------------------------- management: items

    public function manage(array $scope, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $items = $this->items($scope)->orderByDesc('id')->get();
        $stats = DB::table('student_fees')->whereIn('fee_item_id', $items->pluck('id'))
            ->selectRaw("fee_item_id, count(*) as n, sum(amount) as total, sum(paid_amount) as paid, sum(status = 'paid') as paid_n")
            ->groupBy('fee_item_id')->get()->keyBy('fee_item_id');
        return ['success' => true, 'message' => 'OK', 'data' => $items->map(function ($i) use ($stats) {
            $s = $stats->get($i->id);
            return [
                'id' => (int) $i->id, 'name' => $i->name, 'kind' => $i->kind, 'amount' => (float) $i->amount, 'currency' => $i->currency,
                'due_on' => $i->due_on, 'faculty_id' => $i->faculty_id ? (int) $i->faculty_id : null,
                'issued' => $s ? (int) $s->n : 0, 'paid_students' => $s ? (int) $s->paid_n : 0,
                'total' => $s ? (float) $s->total : 0.0, 'collected' => $s ? (float) $s->paid : 0.0,
            ];
        })->values()->all()];
    }

    public function saveItem(array $scope, ?int $id, array $d, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $row = [
            'name' => trim($d['name']), 'kind' => in_array($d['kind'] ?? 'tuition', self::KINDS, true) ? ($d['kind'] ?? 'tuition') : 'other',
            'amount' => round((float) $d['amount'], 2), 'due_on' => $d['due_on'] ?: null, 'updated_at' => now(),
        ];
        if ($row['amount'] <= 0) {
            return $this->fail($l, 'المبلغ لازم يكون أكبر من صفر.', 'The amount must be greater than zero.');
        }
        if ($id) {
            if (!$this->items($scope)->where('id', $id)->exists()) {
                return $this->fail($l, 'البند غير موجود.', 'Fee item not found.', 404);
            }
            DB::table('fee_items')->where('id', $id)->update($row);
            $msg = $this->ar($l) ? 'تم تعديل البند (المستحقات المُصدَرة بتحتفظ بمبلغها).' : 'Item updated (already-issued fees keep their amount).';
        } else {
            DB::table('fee_items')->insert($row + [
                'university_id' => $scope['universityId'], 'faculty_id' => $scope['role'] === 'faculty' ? (int) $scope['facultyId'] : null,
                'created_by_user_id' => $scope['userId'], 'created_at' => now(),
            ]);
            $msg = $this->ar($l) ? 'تم إضافة البند.' : 'Fee item added.';
        }
        return $this->manage($scope, $l) + ['message' => $msg];
    }

    /** يصدر المستحق لكل طلاب النطاق اللي ماعندهمش البند ده. */
    public function issue(array $scope, int $id, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $item = $this->items($scope)->where('id', $id)->first();
        if (!$item) {
            return $this->fail($l, 'البند غير موجود.', 'Fee item not found.', 404);
        }
        $students = DB::table('students')->where('university_id', $item->university_id)
            ->when($item->faculty_id, fn ($q) => $q->where('faculty_id', $item->faculty_id))
            ->whereNotIn('id', DB::table('student_fees')->where('fee_item_id', $item->id)->select('student_id'))
            ->get(['id', 'user_id']);
        $now = now();
        foreach ($students->chunk(500) as $chunk) {
            DB::table('student_fees')->insert($chunk->map(fn ($s) => [
                'fee_item_id' => $item->id, 'student_id' => $s->id, 'amount' => $item->amount, 'paid_amount' => 0,
                'due_on' => $item->due_on, 'status' => 'unpaid', 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
        foreach ($students as $s) {
            try {
                $this->notifications->notify((int) $s->user_id, 'fee_issued', 'مصروفات جديدة: ' . $item->name, $item->amount . ' ' . $item->currency . ($item->due_on ? ' — ' . $item->due_on : ''), '/student/fees', 'normal');
            } catch (\Throwable $e) {
            }
        }
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? "تم إصدار المصروفات لـ {$students->count()} طالب." : "Issued to {$students->count()} students."];
    }

    public function deleteItem(array $scope, int $id, string $l): array
    {
        if (!$this->isManager($scope) || !$this->items($scope)->where('id', $id)->exists()) {
            return $this->fail($l, 'البند غير موجود.', 'Fee item not found.', 404);
        }
        $feeIds = DB::table('student_fees')->where('fee_item_id', $id)->pluck('id');
        if (DB::table('fee_payments')->whereIn('student_fee_id', $feeIds)->where('status', 'confirmed')->exists()) {
            return $this->fail($l, 'مش ممكن تحذف بند عليه دفعات مؤكَّدة.', 'An item with confirmed payments cannot be deleted.', 409);
        }
        DB::transaction(function () use ($id, $feeIds) {
            DB::table('fee_payments')->whereIn('student_fee_id', $feeIds)->delete();
            DB::table('student_fees')->where('fee_item_id', $id)->delete();
            DB::table('fee_items')->where('id', $id)->delete();
        });
        return $this->manage($scope, $l) + ['message' => $this->ar($l) ? 'تم حذف البند.' : 'Item deleted.'];
    }

    // ---------------------------------------------------------------- management: ledger + payments

    public function ledger(array $scope, array $f, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $q = $this->ledgerQuery($scope);
        if (!empty($f['status']) && in_array($f['status'], ['unpaid', 'partial', 'paid', 'waived'], true)) {
            $q->where('f.status', $f['status']);
        }
        if (!empty($f['item_id'])) {
            $q->where('f.fee_item_id', (int) $f['item_id']);
        }
        if (!empty($f['overdue'])) {
            $q->whereIn('f.status', ['unpaid', 'partial'])->whereNotNull('f.due_on')->where('f.due_on', '<', date('Y-m-d'));
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($f['q'])) . '%';
            $q->where(fn ($w) => $w->where('u.full_name', 'like', $like)->orWhere('s.student_number', 'like', $like));
        }
        $rows = $q->orderByDesc('f.id')->limit(300)->get(['f.*', 'i.name as item_name', 'i.kind as item_kind', 'i.currency', 'u.full_name as student_name', 's.student_number']);
        $pending = DB::table('fee_payments')->whereIn('student_fee_id', $rows->pluck('id'))->where('status', 'pending')->get()->groupBy('student_fee_id');
        return ['success' => true, 'message' => 'OK', 'data' => $rows->map(fn ($r) => $this->presentFee($r, $l) + [
            'student' => ['name' => $r->student_name, 'number' => $r->student_number],
            'pending_payments' => collect($pending->get($r->id, []))->map(fn ($p) => $this->presentPayment($p))->values()->all(),
        ])->values()->all()];
    }

    private function presentPayment(object $p): array
    {
        return [
            'id' => (int) $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note,
            'status' => $p->status, 'receipt_no' => $p->receipt_no, 'decision_note' => $p->decision_note, 'created_at' => $p->created_at,
        ];
    }

    /** إدارة بتسجّل دفعة مؤكَّدة مباشرة (كاش / تحويل استلمته). */
    public function recordPayment(array $scope, $feeId, array $d, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        if (!$this->ledgerQuery($scope)->where('f.id', (int) $feeId)->exists()) {
            return $this->fail($l, 'المستحق غير موجود.', 'Fee not found.', 404);
        }
        return DB::transaction(function () use ($scope, $feeId, $d, $l) {
            $f = DB::table('student_fees')->where('id', (int) $feeId)->lockForUpdate()->first();
            if ($f->status === 'waived') {
                return $this->fail($l, 'المستحق ده معفي.', 'This fee is waived.', 409);
            }
            $amount = $this->cents($d['amount'] ?? 0);
            $remaining = $this->cents($f->amount) - $this->cents($f->paid_amount);
            if ($amount <= 0 || $amount > $remaining) {
                return $this->fail($l, 'المبلغ لازم يكون أكبر من صفر ولا يتعدّى المتبقي (' . $this->money($remaining) . ').', 'The amount must be above zero and not exceed the balance (' . $this->money($remaining) . ').');
            }
            $id = DB::table('fee_payments')->insertGetId([
                'student_fee_id' => $f->id, 'student_id' => $f->student_id, 'amount' => $this->money($amount),
                'method' => in_array($d['method'] ?? '', self::METHODS, true) ? $d['method'] : 'cash', 'reference' => $d['reference'] ?? null, 'note' => $d['note'] ?? null,
                'status' => 'confirmed', 'recorded_by_user_id' => $scope['userId'], 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->stampReceipt($id);
            $this->recompute((int) $f->id);
            $this->notifyStudent((int) $f->student_id, 'تم تسجيل دفعة', $this->money($amount) . '', '/student/fees');
            return ['success' => true, 'message' => $this->ar($l) ? 'تم تسجيل الدفعة.' : 'Payment recorded.', 'data' => ['payment_id' => $id]];
        });
    }

    private function stampReceipt(int $paymentId): void
    {
        DB::table('fee_payments')->where('id', $paymentId)->update(['receipt_no' => 'RC-' . date('Y') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT)]);
    }

    public function confirm(array $scope, $paymentId, string $l): array
    {
        return $this->decidePayment($scope, $paymentId, true, null, $l);
    }

    public function reject(array $scope, $paymentId, ?string $note, string $l): array
    {
        return $this->decidePayment($scope, $paymentId, false, $note, $l);
    }

    private function decidePayment(array $scope, $paymentId, bool $ok, ?string $note, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if (!$ok && !$note) {
            return $this->fail($l, 'اكتب سبب الرفض.', 'Please give a reason for the rejection.');
        }
        $p0 = DB::table('fee_payments')->where('id', (int) $paymentId)->first();
        if (!$p0 || !$this->ledgerQuery($scope)->where('f.id', $p0->student_fee_id)->exists()) {
            return $this->fail($l, 'الدفعة غير موجودة.', 'Payment not found.', 404);
        }
        return DB::transaction(function () use ($scope, $paymentId, $ok, $note, $l) {
            $p = DB::table('fee_payments')->where('id', (int) $paymentId)->lockForUpdate()->first();
            if ($p->status !== 'pending') {
                return $this->fail($l, 'تم البتّ في هذه الدفعة بالفعل.', 'This payment was already handled.', 409);
            }
            $f = DB::table('student_fees')->where('id', $p->student_fee_id)->lockForUpdate()->first();
            if ($ok) {
                $remaining = $this->cents($f->amount) - $this->cents($f->paid_amount);
                if ($f->status === 'waived' || $this->cents($p->amount) > $remaining) {
                    return $this->fail($l, 'المبلغ يتعدّى المتبقي على المستحق.', 'The amount exceeds the remaining balance.', 409);
                }
            }
            DB::table('fee_payments')->where('id', $p->id)->update([
                'status' => $ok ? 'confirmed' : 'rejected', 'decision_note' => $note, 'recorded_by_user_id' => $scope['userId'], 'decided_at' => now(), 'updated_at' => now(),
            ]);
            if ($ok) {
                $this->stampReceipt((int) $p->id);
                $this->recompute((int) $f->id);
            }
            $this->notifyStudent((int) $p->student_id, $ok ? 'تم تأكيد دفعتك' : 'تم رفض إخطار الدفع', ($note ?: (string) $p->amount), '/student/fees');
            return ['success' => true, 'message' => $ok ? ($this->ar($l) ? 'تم تأكيد الدفعة.' : 'Payment confirmed.') : ($this->ar($l) ? 'تم رفض الدفعة.' : 'Payment rejected.')];
        });
    }

    public function waive(array $scope, $feeId, ?string $note, string $l): array
    {
        if (!$this->isManager($scope)) {
            return $this->fail($l, 'للجامعة والكلية بس.', 'University and faculty only.', 403);
        }
        $note = $note !== null ? trim($note) : '';
        if ($note === '') {
            return $this->fail($l, 'اكتب سبب الإعفاء.', 'Please give a reason for the waiver.');
        }
        if (!$this->ledgerQuery($scope)->where('f.id', (int) $feeId)->exists()) {
            return $this->fail($l, 'المستحق غير موجود.', 'Fee not found.', 404);
        }
        DB::transaction(function () use ($feeId, $note) {
            $f = DB::table('student_fees')->where('id', (int) $feeId)->lockForUpdate()->first();
            if ($f && $f->status !== 'paid') {
                DB::table('student_fees')->where('id', $f->id)->update(['status' => 'waived', 'waive_note' => mb_substr($note, 0, 500), 'updated_at' => now()]);
                DB::table('fee_payments')->where('student_fee_id', $f->id)->where('status', 'pending')->update(['status' => 'rejected', 'decision_note' => 'waived', 'decided_at' => now(), 'updated_at' => now()]);
            }
        });
        return ['success' => true, 'message' => $this->ar($l) ? 'تم الإعفاء.' : 'Fee waived.'];
    }

    // ---------------------------------------------------------------- student

    public function mine(array $scope, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $rows = DB::table('student_fees as f')->join('fee_items as i', 'i.id', '=', 'f.fee_item_id')
            ->where('f.student_id', (int) $scope['studentId'])->orderByRaw("f.status in ('paid','waived')")->orderBy('f.due_on')
            ->get(['f.*', 'i.name as item_name', 'i.kind as item_kind', 'i.currency']);
        $pay = DB::table('fee_payments')->whereIn('student_fee_id', $rows->pluck('id'))->orderByDesc('id')->get()->groupBy('student_fee_id');
        $fees = $rows->map(fn ($r) => $this->presentFee($r, $l) + [
            'payments' => collect($pay->get($r->id, []))->map(fn ($p) => $this->presentPayment($p))->values()->all(),
            'has_pending' => collect($pay->get($r->id, []))->contains(fn ($p) => $p->status === 'pending'),
        ])->values()->all();
        $due = array_sum(array_column($fees, 'balance'));
        return ['success' => true, 'message' => 'OK', 'data' => [
            'summary' => [
                'total_due' => round($due, 2), 'paid' => round(array_sum(array_column($fees, 'paid')), 2),
                'overdue' => count(array_filter($fees, fn ($f) => $f['overdue'])),
            ],
            'fees' => $fees,
        ]];
    }

    /** الطالب بيبعت إخطار دفع (مرجع التحويل) — بيتأكّد من الإدارة. */
    public function notice(array $scope, $feeId, array $d, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student' || empty($scope['studentId'])) {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        return DB::transaction(function () use ($scope, $feeId, $d, $l) {
            $f = DB::table('student_fees')->where('id', (int) $feeId)->where('student_id', $scope['studentId'])->lockForUpdate()->first();
            if (!$f) {
                return $this->fail($l, 'المستحق غير موجود.', 'Fee not found.', 404);
            }
            if (in_array($f->status, ['paid', 'waived'], true)) {
                return $this->fail($l, 'المستحق ده مسدّد أو معفي.', 'This fee is already settled.', 409);
            }
            $reference = isset($d['reference']) ? trim($d['reference']) : '';
            if ($reference === '') {
                return $this->fail($l, 'اكتب رقم مرجع التحويل أو الإيصال.', 'Enter the transfer or receipt reference.');
            }
            $amount = $this->cents($d['amount'] ?? 0);
            $pending = $this->cents(DB::table('fee_payments')->where('student_fee_id', $f->id)->where('status', 'pending')->sum('amount'));
            $remaining = $this->cents($f->amount) - $this->cents($f->paid_amount) - $pending;
            if ($amount <= 0 || $amount > $remaining) {
                return $this->fail($l, 'المبلغ لازم يكون أكبر من صفر ولا يتعدّى المتبقي (' . $this->money(max(0, $remaining)) . ').', 'The amount must be above zero and not exceed the balance (' . $this->money(max(0, $remaining)) . ').');
            }
            $id = DB::table('fee_payments')->insertGetId([
                'student_fee_id' => $f->id, 'student_id' => $f->student_id, 'amount' => $this->money($amount),
                'method' => in_array($d['method'] ?? '', self::METHODS, true) ? $d['method'] : 'bank_transfer', 'reference' => mb_substr($reference, 0, 120),
                'note' => isset($d['note']) ? mb_substr(trim($d['note']), 0, 2000) : null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifyManagers($f, (int) $scope['userId'], (int) $scope['facultyId']);
            return ['success' => true, 'message' => $this->ar($l) ? 'تم إرسال إخطار الدفع للمراجعة.' : 'Payment notice sent for review.', 'data' => ['id' => $id]];
        });
    }

    public function cancelNotice(array $scope, $paymentId, string $l): array
    {
        if (($scope['role'] ?? null) !== 'student') {
            return $this->fail($l, 'للطلاب بس.', 'Students only.', 403);
        }
        $n = DB::table('fee_payments')->where('id', (int) $paymentId)->where('student_id', $scope['studentId'])->where('status', 'pending')->delete();
        return $n ? ['success' => true, 'message' => $this->ar($l) ? 'تم سحب الإخطار.' : 'Notice withdrawn.'] : $this->fail($l, 'الإخطار غير موجود أو تم البتّ فيه.', 'Notice not found or already handled.', 404);
    }

    // ---------------------------------------------------------------- receipt

    public function receipt(array $scope, $paymentId, string $l): array
    {
        $p = DB::table('fee_payments as p')
            ->join('student_fees as f', 'f.id', '=', 'p.student_fee_id')->join('fee_items as i', 'i.id', '=', 'f.fee_item_id')
            ->join('students as s', 's.id', '=', 'p.student_id')->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('p.id', (int) $paymentId)->where('p.status', 'confirmed')
            ->first(['p.*', 'i.name as item_name', 'i.currency', 'i.university_id', 's.faculty_id', 's.student_number', 'u.full_name as student_name', 'f.amount as fee_amount', 'f.paid_amount']);
        $allowed = $p && match ($scope['role'] ?? null) {
            'student' => (int) $p->student_id === (int) ($scope['studentId'] ?? 0),
            'university' => (int) $p->university_id === (int) $scope['universityId'],
            'faculty' => (int) $p->university_id === (int) $scope['universityId'] && (int) $p->faculty_id === (int) $scope['facultyId'],
            default => false,
        };
        if (!$allowed) {
            return $this->fail($l, 'الإيصال غير موجود.', 'Receipt not found.', 404);
        }
        $ar = $this->ar($l);
        $labels = ['cash' => ['نقدي', 'Cash'], 'bank_transfer' => ['تحويل بنكي', 'Bank transfer'], 'card' => ['بطاقة', 'Card'], 'other' => ['أخرى', 'Other']];
        $rows = [
            [$ar ? 'رقم الإيصال' : 'Receipt no.', $p->receipt_no],
            [$ar ? 'الطالب' : 'Student', $p->student_name],
            [$ar ? 'الرقم الجامعي' : 'Student number', $p->student_number],
            [$ar ? 'البند' : 'Item', $p->item_name],
            [$ar ? 'المبلغ المدفوع' : 'Amount paid', number_format((float) $p->amount, 2) . ' ' . $p->currency],
            [$ar ? 'طريقة الدفع' : 'Method', $labels[$p->method][$ar ? 0 : 1] ?? $p->method],
            [$ar ? 'المرجع' : 'Reference', $p->reference ?: '—'],
            [$ar ? 'إجمالي المستحق' : 'Fee total', number_format((float) $p->fee_amount, 2) . ' ' . $p->currency],
            [$ar ? 'إجمالي المدفوع حتى الآن' : 'Total paid to date', number_format((float) $p->paid_amount, 2) . ' ' . $p->currency],
            [$ar ? 'التاريخ' : 'Date', date('Y-m-d', strtotime((string) ($p->decided_at ?: $p->created_at)))],
        ];
        $dir = storage_path('app/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/receipt_' . (int) $p->id . '_' . date('Ymd_His') . '.pdf';
        PdfWriter::writeTable($path, $ar ? 'إيصال سداد' : 'Payment receipt', [$ar ? 'البند' : 'Item', $ar ? 'القيمة' : 'Value'], $rows, $ar);
        return ['success' => true, 'data' => ['path' => $path, 'filename' => $p->receipt_no . '.pdf']];
    }

    // ---------------------------------------------------------------- notifications

    private function notifyStudent(int $studentId, string $title, ?string $body, string $link): void
    {
        try {
            $uid = (int) DB::table('students')->where('id', $studentId)->value('user_id');
            $this->notifications->notify($uid, 'fee_payment', $title, $body, $link, 'normal');
        } catch (\Throwable $e) {
        }
    }

    private function notifyManagers(object $fee, int $studentUserId, int $studentFacultyId): void
    {
        try {
            $item = DB::table('fee_items')->where('id', $fee->fee_item_id)->first();
            $name = (string) DB::table('users')->where('id', $studentUserId)->value('full_name');
            $targets = [];
            if ($uid = DB::table('universities')->where('id', $item->university_id)->value('user_id')) {
                $targets[(int) $uid] = '/university/fees';
            }
            $fid = $item->faculty_id ?: $studentFacultyId;
            if ($fid && ($fu = DB::table('faculties')->where('id', $fid)->value('user_id'))) {
                $targets[(int) $fu] = '/faculty/fees';
            }
            foreach ($targets as $uid => $link) {
                $this->notifications->notify($uid, 'fee_notice', 'إخطار دفع جديد', "الطالب {$name} — {$item->name}", $link, 'normal');
            }
        } catch (\Throwable $e) {
        }
    }
}
