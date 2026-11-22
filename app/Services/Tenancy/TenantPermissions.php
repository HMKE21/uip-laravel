<?php

namespace App\Services\Tenancy;

/**
 * مصفوفة صلاحيات الأدوار المخصصة. لكل قسم مستوى: none (مخفي) · view (يشوف) · edit (يشوف ويعدّل).
 * الأقسام اللي ليها "ability" بتتحوّل لـ ability في TenantService::ABILITIES عند مستوى edit.
 * مش متاح لدور مخصص أبدًا: حذف المساحة، وإدارة الأدوار (owner/admin بس).
 */
final class TenantPermissions
{
    public const LEVELS = ['none', 'view', 'edit'];

    /** @return array<string,array{label_en:string,label_ar:string,max:string,ability:?string,hint_en:string,hint_ar:string}> */
    public static function sections(): array
    {
        return [
            'members' => ['label_en' => 'Members', 'label_ar' => 'الأعضاء', 'max' => 'edit', 'ability' => 'members', 'hint_en' => 'See the team; add, remove and change roles.', 'hint_ar' => 'يشوف الفريق ويضيف ويشيل ويغيّر الأدوار.'],
            'teams' => ['label_en' => 'Teams & departments', 'label_ar' => 'الأقسام والفرق', 'max' => 'edit', 'ability' => 'teams', 'hint_en' => 'See and manage departments (sales, support…).', 'hint_ar' => 'يشوف ويدير الأقسام (مبيعات، دعم…).'],
            'branding' => ['label_en' => 'Branding', 'label_ar' => 'الهوية', 'max' => 'edit', 'ability' => 'branding', 'hint_en' => 'Name, logo and colours.', 'hint_ar' => 'الاسم واللوجو والألوان.'],
            'site' => ['label_en' => 'Website & sign-in page', 'label_ar' => 'الموقع وصفحة الدخول', 'max' => 'edit', 'ability' => 'site', 'hint_en' => 'Design and publish the website.', 'hint_ar' => 'تصميم الموقع ونشره.'],
            'features' => ['label_en' => 'Features', 'label_ar' => 'الميزات', 'max' => 'edit', 'ability' => 'features', 'hint_en' => 'Choose which features are on.', 'hint_ar' => 'اختيار الميزات المفعّلة.'],
            'domains' => ['label_en' => 'Domains', 'label_ar' => 'الدومينات', 'max' => 'edit', 'ability' => 'domains', 'hint_en' => 'Connect your own domain.', 'hint_ar' => 'ربط دومينك الخاص.'],
            'embed' => ['label_en' => 'Embed & participants', 'label_ar' => 'التضمين والمشاركين', 'max' => 'edit', 'ability' => 'embed', 'hint_en' => 'Allowed origins and embedded participants.', 'hint_ar' => 'المواقع المسموحة والمشاركين.'],
            'resources' => ['label_en' => 'Apps, keys & webhooks', 'label_ar' => 'التطبيقات والمفاتيح', 'max' => 'edit', 'ability' => 'resources', 'hint_en' => 'API apps, keys, webhooks, usage and logs.', 'hint_ar' => 'تطبيقات الـ API والمفاتيح والـ webhooks والاستهلاك والسجل.'],
            'activity' => ['label_en' => 'Activity log', 'label_ar' => 'سجل النشاط', 'max' => 'view', 'ability' => null, 'hint_en' => 'Who signed in and who changed what.', 'hint_ar' => 'مين دخل ومين غيّر إيه.'],
        ];
    }

    /** @param array<string,mixed> $in @return array<string,string> section => level (كل الأقسام موجودة) */
    public static function normalize(array $in): array
    {
        $out = [];
        foreach (self::sections() as $k => $s) {
            $lv = (string) ($in[$k] ?? 'none');
            $lv = in_array($lv, self::LEVELS, true) ? $lv : 'none';
            if ($s['max'] === 'view' && $lv === 'edit') {
                $lv = 'view';
            }
            $out[$k] = $lv;
        }
        return $out;
    }

    public static function level(array $perms, string $section): string
    {
        $lv = (string) ($perms[$section] ?? 'none');
        return in_array($lv, self::LEVELS, true) ? $lv : 'none';
    }

    /** الـ abilities اللي بيديها دور مخصص (+ view دايمًا: يفتح المساحة). @return string[] */
    public static function abilities(array $perms): array
    {
        $out = ['view'];
        foreach (self::sections() as $k => $s) {
            $lv = self::level($perms, $k);
            if ($k === 'activity' && $lv !== 'none') {
                $out[] = 'activity';
            } elseif ($s['ability'] && $lv === 'edit') {
                $out[] = $s['ability'];
            }
        }
        return array_values(array_unique($out));
    }
}
