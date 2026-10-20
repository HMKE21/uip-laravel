<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/FileUploadPolicyService.php القديمة بالكامل —
 * بند 25 batch 3. سياسة واحدة قابلة للتعديل من شاشة Security Policies
 * (max_size_kb + allow-list اختياري)، defaultMaxKb()/effectiveAllowed()
 * بيستخدمهم FileUploadService::store() دلوقتي بدل config('upload.*')
 * الثابت بس — القيد الموثّق في docblock FileUploadService بند 2 ("لحد
 * ما بند 25 يتعمل") اتقفل هنا.
 */
class FileUploadPolicyService
{
    private const POLICY_KEY = 'upload.policy';

    private const DEFAULTS = [
        'max_size_kb'         => 51200,
        'restrict_extensions' => false,
        'allowed_extensions'  => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,gif,svg,zip,rar,csv,json,xml',
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{max_size_kb:int,restrict_extensions:bool,allowed_extensions:string} */
    public function getPolicy(): array
    {
        $row = $this->policies->findByKey(self::POLICY_KEY);
        $stored = [];
        if ($row) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        return array_merge(self::DEFAULTS, $stored);
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $maxKb = (int) ($input['max_size_kb'] ?? 0);
        if ($maxKb < 1 || $maxKb > 512000) {
            throw new \InvalidArgumentException('Maximum upload size must be between 1 KB and 512000 KB (500 MB).');
        }

        $extensionsRaw = trim((string) ($input['allowed_extensions'] ?? ''));
        $extensions = array_values(array_filter(array_map(
            static fn ($e) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim($e))),
            explode(',', $extensionsRaw)
        )));
        $extensions = array_values(array_unique($extensions));

        $restrict = !empty($input['restrict_extensions']);
        if ($restrict && !$extensions) {
            throw new \InvalidArgumentException('Add at least one allowed extension before enabling the extension restriction.');
        }

        $policy = [
            'max_size_kb'         => $maxKb,
            'restrict_extensions' => $restrict,
            'allowed_extensions'  => implode(',', $extensions),
        ];

        $encoded = json_encode($policy, JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 255) {
            throw new \InvalidArgumentException('Too many allowed extensions — shorten the list.');
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, $encoded, $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the upload restrictions policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.upload_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    /** الحد الأقصى الافتراضي (كيلوبايت) — بيستخدمه FileUploadService::store() لما الكولر ميحددش override. */
    public function defaultMaxKb(): int
    {
        return (int) $this->getPolicy()['max_size_kb'];
    }

    /**
     * بيطبّق قيد الامتدادات العام (لو مفعّل) على allow-list الفئة نفسها،
     * بالتقاطع فقط — ميقدرش يوسّع القائمة، بس يضيّقها.
     * @param string[] $categoryAllowed
     * @return string[]
     */
    public function effectiveAllowed(array $categoryAllowed): array
    {
        $p = $this->getPolicy();
        if (empty($p['restrict_extensions']) || $p['allowed_extensions'] === '') {
            return $categoryAllowed;
        }

        $globalAllowed = array_filter(array_map('trim', explode(',', $p['allowed_extensions'])));
        $intersected = array_values(array_intersect($categoryAllowed, $globalAllowed));

        // تقاطع صارم: لو مفيش تقاطع، يبقى مفيش نوع مسموح (قبل كده كان بيرجع لقائمة الفئة كلها فيبطل التقييد).
        return $intersected;
    }
}
