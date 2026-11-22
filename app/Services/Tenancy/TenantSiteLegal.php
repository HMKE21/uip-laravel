<?php

namespace App\Services\Tenancy;

/**
 * الصفحات القانونية الخاصة بكل مساحة عمل (سياسة الخصوصية + شروط الاستخدام).
 *
 * كل مساحة ليها صفحتين بعناوين ثابتة: /p/privacy و /p/terms — مهما كان القالب أو الإعدادات — فمفيش زائر لموقع عميل
 * بيتحوّل لسياسة المنصة الأصلية (UIPE). النص الافتراضي بيتولّد باسم المساحة وإيميل الدعم بتاعها، والعميل يعدّله من الـ builder
 * أو يحط رابط خارجي في الهوية (privacy_url / terms_url).
 *
 * fill() idempotent: بتضيف الصفحة لو ناقصة، وبتملاها بالنص الافتراضي لو فاضية، ومبتلمسش نص العميل.
 */
final class TenantSiteLegal
{
    /** آخر تحديث للنص الافتراضي (بيظهر في أول كل صفحة). */
    public const UPDATED = '2026-11-22';

    /** @return array<string,array{title:array,sections:array}> نوع => عنوان + أقسام خام فيها {{name}} {{email}} {{date}} */
    private static function defaults(): array
    {
        $i = fn (string $en, string $ar) => ['en' => $en, 'ar' => $ar];
        $s = fn (string $id, array $title, array $body) => ['id' => $id, 'type' => 'text', 'enabled' => true, 'props' => ['title' => $title, 'body' => $body]];

        $privacy = [
            $s('p-intro', $i('Introduction', 'مقدمة'), $i(
                "Last updated: {{date}}\n\nThis policy explains how {{name}} (\"we\", \"the workspace\") collects, uses and protects personal information when you use this workspace's website and sign-in. It applies only to {{name}}. By signing in or using the workspace you acknowledge this policy.",
                "آخر تحديث: {{date}}\n\nتوضّح هذه السياسة كيف تجمع «{{name}}» («نحن» أو «المساحة») معلوماتك الشخصية وتستخدمها وتحميها عند استخدامك موقع المساحة وتسجيل الدخول إليها. وهي تسري على «{{name}}» وحدها. باستخدامك المساحة أو تسجيل الدخول إليها فإنك تقرّ بهذه السياسة."
            )),
            $s('p-collect', $i('Information we collect', 'المعلومات التي نجمعها'), $i(
                "• Account details: your name, email address, role and profile information.\n• Content you create or share in the workspace: messages, files, meeting activity, assignments and similar material.\n• Technical data: device and browser type, IP address, language, and sign-in and security logs.\n• Messages you send through the website's contact form (name, email and your message).",
                "• بيانات الحساب: الاسم والبريد الإلكتروني والدور والمعلومات الشخصية في ملفك.\n• المحتوى الذي تنشئه أو تشاركه داخل المساحة: الرسائل والملفات ونشاط الاجتماعات والتكليفات وما شابه.\n• بيانات تقنية: نوع الجهاز والمتصفح وعنوان IP واللغة وسجلات الدخول والأمان.\n• الرسائل التي ترسلها عبر نموذج التواصل في الموقع (الاسم والبريد ونص الرسالة)."
            )),
            $s('p-use', $i('How we use your information', 'كيف نستخدم معلوماتك'), $i(
                "• To create and manage your account and let you sign in.\n• To provide the workspace's services: messaging, meetings, files and related features.\n• To keep the workspace secure, prevent abuse and investigate incidents.\n• To send service messages such as invitations, password resets and security alerts.\n• To reply to your enquiries and improve the service.\n\nWe do not sell your personal information.",
                "• لإنشاء حسابك وإدارته والسماح لك بتسجيل الدخول.\n• لتقديم خدمات المساحة: المراسلة والاجتماعات والملفات والمزايا المرتبطة بها.\n• للحفاظ على أمان المساحة ومنع إساءة الاستخدام والتحقيق في الحوادث.\n• لإرسال رسائل الخدمة مثل الدعوات وإعادة تعيين كلمة المرور وتنبيهات الأمان.\n• للرد على استفساراتك وتحسين الخدمة.\n\nنحن لا نبيع معلوماتك الشخصية."
            )),
            $s('p-share', $i('Sharing and service providers', 'المشاركة ومزوّدو الخدمة'), $i(
                "Your information is visible to other members of the workspace only as its settings and your own privacy choices allow. The workspace runs on the UIPE platform, which processes data on our behalf as a service provider under our instructions; it is not permitted to use your data for its own purposes. We may disclose information when required by law or to protect the rights and safety of users.",
                "تظهر معلوماتك لأعضاء المساحة الآخرين بالقدر الذي تسمح به إعدادات المساحة وخياراتك الخاصة. وتعمل المساحة على منصة UIPE التي تعالج البيانات نيابةً عنا بصفتها مزوّد خدمة وفق تعليماتنا، ولا يحق لها استخدام بياناتك لأغراضها الخاصة. وقد نكشف عن معلومات عندما يقتضي القانون ذلك أو لحماية حقوق المستخدمين وسلامتهم."
            )),
            $s('p-cookies', $i('Cookies and local storage', 'ملفات تعريف الارتباط والتخزين المحلي'), $i(
                "We use only what is needed to run the workspace: a session to keep you signed in, and local settings such as your language and theme. We do not use advertising cookies. You can clear these from your browser, but you may need to sign in again.",
                "نستخدم فقط ما يلزم لتشغيل المساحة: جلسة تُبقيك مسجّلًا للدخول، وإعدادات محلية مثل اللغة والمظهر. ولا نستخدم ملفات تعريف ارتباط إعلانية. يمكنك مسحها من متصفحك، وقد تحتاج بعدها إلى تسجيل الدخول من جديد."
            )),
            $s('p-security', $i('Security and retention', 'الأمان والاحتفاظ بالبيانات'), $i(
                "We protect data with access controls, encrypted connections and activity logs, and workspace data is kept separate from other workspaces. We keep your information while your account is active and for as long as needed for the purposes above or to meet legal obligations; after that it is deleted or anonymised. No system is perfectly secure, so please use a strong password and keep it private.",
                "نحمي البيانات بضوابط وصول واتصالات مشفّرة وسجلات نشاط، وتُعزل بيانات المساحة عن بيانات المساحات الأخرى. نحتفظ بمعلوماتك ما دام حسابك نشطًا وبالقدر اللازم للأغراض المذكورة أو للوفاء بالتزامات قانونية، ثم تُحذف أو تُجهَّل هويتها. ولا يوجد نظام آمن تمامًا، لذا نرجو استخدام كلمة مرور قوية والحفاظ على سريتها."
            )),
            $s('p-rights', $i('Your rights and choices', 'حقوقك وخياراتك'), $i(
                "You can ask to access, correct, export or delete your personal information, and to object to or restrict certain uses, subject to applicable law. You can update most details from your profile. To make a request, contact us using the details below and we will respond within a reasonable time.",
                "يمكنك طلب الاطلاع على معلوماتك الشخصية أو تصحيحها أو تصديرها أو حذفها، والاعتراض على بعض الاستخدامات أو تقييدها، وذلك وفق القانون المعمول به. ويمكنك تعديل معظم بياناتك من ملفك الشخصي. لتقديم طلب تواصل معنا عبر البيانات أدناه وسنرد عليك خلال مدة معقولة."
            )),
            $s('p-contact', $i('Changes and contact', 'التغييرات والتواصل'), $i(
                "We may update this policy from time to time; the date at the top shows the latest version, and we will notify you of significant changes. Questions about this policy: {{email_en}}.",
                "قد نحدّث هذه السياسة من وقت لآخر، ويوضح التاريخ في أعلاها آخر نسخة، وسنُخطرك بالتغييرات الجوهرية. للاستفسار عن هذه السياسة: {{email_ar}}."
            )),
        ];

        $terms = [
            $s('t-accept', $i('Acceptance of terms', 'قبول الشروط'), $i(
                "Last updated: {{date}}\n\nThese terms govern your use of the {{name}} workspace and website. By signing in or using the workspace you agree to them. If you do not agree, please do not use it.",
                "آخر تحديث: {{date}}\n\nتحكم هذه الشروط استخدامك لمساحة «{{name}}» وموقعها. بتسجيل الدخول أو استخدام المساحة فإنك توافق عليها. وإن لم توافق فنرجو عدم استخدامها."
            )),
            $s('t-account', $i('Accounts', 'الحسابات'), $i(
                "Accounts are created or approved by the workspace administrators. You are responsible for keeping your password secure and for activity under your account. Give accurate information and tell us at once if you suspect unauthorised access.",
                "تُنشأ الحسابات أو تُعتمد من قِبل مسؤولي المساحة. وأنت مسؤول عن حماية كلمة مرورك وعن النشاط الذي يتم عبر حسابك. قدّم معلومات صحيحة وأبلغنا فورًا إذا اشتبهت في دخول غير مصرّح به."
            )),
            $s('t-use', $i('Acceptable use', 'الاستخدام المقبول'), $i(
                "You agree not to:\n• break the law or infringe others' rights;\n• harass, threaten or discriminate against anyone;\n• upload malware or attempt to disrupt or gain unauthorised access to the service;\n• share illegal, explicit or misleading content;\n• impersonate others or misuse other members' information.",
                "توافق على عدم:\n• مخالفة القانون أو التعدي على حقوق الآخرين؛\n• مضايقة أي شخص أو تهديده أو التمييز ضده؛\n• رفع برمجيات خبيثة أو محاولة تعطيل الخدمة أو الدخول إليها دون تصريح؛\n• مشاركة محتوى غير قانوني أو فاضح أو مضلِّل؛\n• انتحال شخصية الآخرين أو إساءة استخدام معلومات الأعضاء."
            )),
            $s('t-content', $i('Content and ownership', 'المحتوى والملكية'), $i(
                "You keep ownership of the content you create. You give the workspace the limited permission needed to store, display and share it as the service requires. The workspace's name, logo and design belong to {{name}} and may not be used without permission.",
                "تحتفظ بملكية المحتوى الذي تنشئه، وتمنح المساحة التصريح المحدود اللازم لتخزينه وعرضه ومشاركته بالقدر الذي تقتضيه الخدمة. أما اسم المساحة وشعارها وتصميمها فهي مملوكة لـ«{{name}}» ولا يجوز استخدامها دون إذن."
            )),
            $s('t-service', $i('Availability and changes', 'التوفر والتغييرات'), $i(
                "We work to keep the service available but do not guarantee it will be uninterrupted or error-free. We may change, suspend or retire features, and we may update these terms; continued use after a change means you accept it.",
                "نسعى إلى إبقاء الخدمة متاحة لكننا لا نضمن أن تكون دون انقطاع أو أخطاء. وقد نغيّر المزايا أو نوقفها مؤقتًا أو نلغيها، وقد نحدّث هذه الشروط؛ واستمرارك في الاستخدام بعد أي تغيير يعني قبولك به."
            )),
            $s('t-end', $i('Suspension and termination', 'الإيقاف والإنهاء'), $i(
                "We may suspend or close an account that breaks these terms or puts the service or other users at risk. You may stop using the workspace at any time and ask for your account to be closed.",
                "يجوز لنا إيقاف أي حساب يخالف هذه الشروط أو يعرّض الخدمة أو المستخدمين الآخرين للخطر أو إغلاقه. ويمكنك التوقف عن استخدام المساحة في أي وقت وطلب إغلاق حسابك."
            )),
            $s('t-liability', $i('Disclaimer and liability', 'إخلاء المسؤولية'), $i(
                "The service is provided \"as is\". To the extent permitted by law, {{name}} is not liable for indirect or consequential losses arising from your use of the service or from content shared by other users.",
                "تُقدَّم الخدمة «كما هي». وبالقدر الذي يسمح به القانون لا تتحمل «{{name}}» مسؤولية الخسائر غير المباشرة أو التبعية الناتجة عن استخدامك الخدمة أو عن محتوى يشاركه مستخدمون آخرون."
            )),
            $s('t-contact', $i('Contact', 'التواصل'), $i(
                "Questions about these terms: {{email_en}}. Your use of the workspace is also covered by our Privacy Policy.",
                "للاستفسار عن هذه الشروط: {{email_ar}}. ويخضع استخدامك للمساحة كذلك لسياسة الخصوصية الخاصة بنا."
            )),
        ];

        return [
            'privacy' => ['title' => $i('Privacy Policy', 'سياسة الخصوصية'), 'sections' => $privacy],
            'terms' => ['title' => $i('Terms of Use', 'شروط الاستخدام'), 'sections' => $terms],
        ];
    }

    /** يستبدل {{name}} {{email_en}} {{email_ar}} {{date}} في نص ثنائي اللغة. */
    private static function subst($v, string $name, string $email)
    {
        if (!is_array($v)) {
            return $v;
        }
        $map = [
            '{{name}}' => $name,
            '{{date}}' => self::UPDATED,
            '{{email_en}}' => $email !== '' ? $email : 'the workspace administrator',
            '{{email_ar}}' => $email !== '' ? $email : 'مسؤول المساحة',
        ];
        return array_map(fn ($s) => is_string($s) ? strtr($s, $map) : $s, $v);
    }

    /** أقسام النص الافتراضي لنوع (privacy|terms) باسم المساحة وإيميل الدعم. */
    public static function sections(string $kind, string $name, string $email): array
    {
        $d = self::defaults()[$kind] ?? null;
        if (!$d) {
            return [];
        }
        $name = $name !== '' ? $name : 'this workspace';
        $out = [];
        foreach ($d['sections'] as $sec) {
            $sec['props']['title'] = self::subst($sec['props']['title'], $name, $email);
            $sec['props']['body'] = self::subst($sec['props']['body'], $name, $email);
            $out[] = $sec;
        }
        return $out;
    }

    /** صفحة كاملة بشكل سليم (sanitised shape). */
    public static function page(string $kind, string $name, string $email): array
    {
        $d = self::defaults()[$kind];
        $empty = ['en' => '', 'ar' => ''];
        return ['id' => $kind, 'slug' => $kind, 'kind' => $kind, 'enabled' => true, 'show_in_nav' => false, 'title' => $d['title'],
            'seo' => ['title' => $empty, 'description' => $empty, 'share_image' => null], 'sections' => self::sections($kind, $name, $email)];
    }

    /**
     * يضمن إن الإعدادات فيها صفحتي الخصوصية والشروط، شغّالة وفيها نص. @param array<string,mixed> $settings إعدادات سليمة (full)
     */
    public static function fill(array $settings, string $name, string $email): array
    {
        $pages = is_array($settings['pages'] ?? null) ? array_values($settings['pages']) : [];
        foreach (TenantSiteTemplates::LEGAL_KINDS as $kind) {
            $idx = null;
            foreach ($pages as $n => $p) {
                if (($p['kind'] ?? null) === $kind) {
                    $idx = $n;
                    break;
                }
            }
            if ($idx === null) {
                $pages[] = self::page($kind, $name, $email);
                continue;
            }
            $pages[$idx]['enabled'] = true;                       // صفحة قانونية مينفعش تتخفى
            $hasText = false;
            foreach ($pages[$idx]['sections'] ?? [] as $sec) {
                if (!empty($sec['enabled'])) {
                    $hasText = true;
                    break;
                }
            }
            if (!$hasText) {
                $pages[$idx]['sections'] = self::sections($kind, $name, $email);
            }
        }
        $settings['pages'] = $pages;
        return $settings;
    }
}
