<?php

namespace App\Services\Tenancy;

/**
 * القوالب الجاهزة + مخطط (schema) الإعدادات المسموحة. كل اللي العميل يقدر يغيّره معرّف هنا — مفيش HTML ولا CSS حر ولا سكربت.
 * لغة المخطط: 't:N' نص عادي · 'i18n:N' {en,ar} · 'link' · 'img' (https) · 'email' · 'bool' · 'color' · 'enum:a|b' · ['list', max, [مفتاح => نوع]].
 */
final class TenantSiteTemplates
{
    public const ICONS = ['video', 'message', 'shield', 'users', 'calendar', 'file', 'chart', 'bolt', 'globe', 'lock', 'star', 'heart', 'book', 'cap'];
    /** الخطوط المسموحة: المفتاح => [الاسم، المجموعة، يدعم عربي، اسم العائلة في Google Fonts (null = خط النظام)]. الفرونت بيحمّل العائلة من Google Fonts. */
    public const FONT_META = [
        'system' => ['System default', 'sans', true, null],
        'inter' => ['Inter', 'sans', false, 'Inter'],
        'cairo' => ['Cairo', 'sans', true, 'Cairo'],
        'tajawal' => ['Tajawal', 'sans', true, 'Tajawal'],
        'poppins' => ['Poppins', 'sans', false, 'Poppins'],
        'ibm-plex-sans-arabic' => ['IBM Plex Sans Arabic', 'sans', true, 'IBM Plex Sans Arabic'],
        'merriweather' => ['Merriweather', 'serif', false, 'Merriweather'],
        'almarai' => ['Almarai', 'sans', true, 'Almarai'],
        'noto-sans-arabic' => ['Noto Sans Arabic', 'sans', true, 'Noto Sans Arabic'],
        'noto-kufi-arabic' => ['Noto Kufi Arabic', 'display', true, 'Noto Kufi Arabic'],
        'readex-pro' => ['Readex Pro', 'sans', true, 'Readex Pro'],
        'changa' => ['Changa', 'display', true, 'Changa'],
        'el-messiri' => ['El Messiri', 'display', true, 'El Messiri'],
        'amiri' => ['Amiri', 'serif', true, 'Amiri'],
        'montserrat' => ['Montserrat', 'sans', false, 'Montserrat'],
        'open-sans' => ['Open Sans', 'sans', false, 'Open Sans'],
        'roboto' => ['Roboto', 'sans', false, 'Roboto'],
        'lato' => ['Lato', 'sans', false, 'Lato'],
        'nunito' => ['Nunito', 'sans', false, 'Nunito'],
        'dm-sans' => ['DM Sans', 'sans', false, 'DM Sans'],
        'space-grotesk' => ['Space Grotesk', 'display', false, 'Space Grotesk'],
        'playfair-display' => ['Playfair Display', 'serif', false, 'Playfair Display'],
        'lora' => ['Lora', 'serif', false, 'Lora'],
    ];
    public const FONTS = ['system', 'inter', 'cairo', 'tajawal', 'poppins', 'ibm-plex-sans-arabic', 'merriweather', 'almarai', 'noto-sans-arabic', 'noto-kufi-arabic', 'readex-pro', 'changa', 'el-messiri', 'amiri', 'montserrat', 'open-sans', 'roboto', 'lato', 'nunito', 'dm-sans', 'space-grotesk', 'playfair-display', 'lora'];
    public const PAGE_KINDS = ['custom', 'about', 'contact', 'privacy', 'terms'];
    /** صفحات قانونية: كل مساحة ليها واحدة من كل نوع، وعنوانها ثابت (/p/privacy و /p/terms). */
    public const LEGAL_KINDS = ['privacy', 'terms'];
    /** أنواع النشاط اللي القوالب بتتصنّف بيها في المعرض. */
    /** الميزات اللي القالب بيعتمد عليها (بتتطلب تلقائيًا لما القالب يتطبّق، والأدمن بيوافق). */
    public const FEATURES = [
        'lecturer' => ['academic', 'meetings'],
        'campus'   => ['academic', 'meetings'],
        'academy'  => ['academic', 'meetings'],
    ];

    /** @return string[] */
    public static function featuresFor(string $key): array
    {
        return self::FEATURES[$key] ?? [];
    }

    public const TEMPLATE_KINDS = ['any', 'company', 'tech', 'university', 'training', 'school', 'health', 'fitness', 'retail', 'food', 'hospitality', 'realestate', 'finance', 'legal', 'nonprofit', 'government', 'events', 'media', 'agency'];
    /** slugs صفحات محجوزة لمسارات الموقع نفسها. */
    public const RESERVED_PAGE_SLUGS = ['login', 'blog', 'p', 'w', 'api', 'admin', 'register', 'signup', 'preview', 'sitemap', 'robots'];

    public static function fontCatalog(): array
    {
        $out = [];
        foreach (self::FONT_META as $key => [$label, $group, $arabic, $family]) {
            $out[] = ['key' => $key, 'label' => $label, 'group' => $group, 'arabic' => $arabic, 'family' => $family];
        }
        return $out;
    }

    /** نوع القسم => مخطط props. */
    public static function sectionSpecs(): array
    {
        $icons = implode('|', self::ICONS);
        return [
            'hero' => ['title' => 'i18n:90', 'subtitle' => 'i18n:220', 'cta_label' => 'i18n:30', 'cta_url' => 'link', 'image_url' => 'img', 'align' => 'enum:start|center'],
            'features' => ['title' => 'i18n:80', 'items' => ['list', 6, ['icon' => "enum:{$icons}", 'title' => 'i18n:60', 'text' => 'i18n:200']]],
            'stats' => ['title' => 'i18n:80', 'items' => ['list', 4, ['value' => 't:12', 'label' => 'i18n:40']]],
            'steps' => ['title' => 'i18n:80', 'items' => ['list', 6, ['title' => 'i18n:60', 'text' => 'i18n:200']]],
            'cta' => ['title' => 'i18n:90', 'text' => 'i18n:220', 'button_label' => 'i18n:30', 'button_url' => 'link'],
            'text' => ['title' => 'i18n:80', 'body' => 'i18n:1500'],
            'faq' => ['title' => 'i18n:80', 'items' => ['list', 10, ['q' => 'i18n:140', 'a' => 'i18n:500']]],
            'contact' => ['title' => 'i18n:80', 'email' => 'email', 'phone' => 't:30', 'address' => 'i18n:160', 'form' => 'bool'],
            'team' => ['title' => 'i18n:80', 'items' => ['list', 8, ['name' => 't:60', 'role' => 'i18n:60', 'image_url' => 'img']]],
            'gallery' => ['title' => 'i18n:80', 'items' => ['list', 12, ['image_url' => 'img', 'caption' => 'i18n:80']]],
            'logos' => ['title' => 'i18n:80', 'items' => ['list', 8, ['name' => 't:40', 'image_url' => 'img', 'url' => 'link']]],
        ];
    }

    /** مخطط الإعدادات كلها (باستثناء home.sections اللي ليها مخطط حسب النوع). */
    public static function rootSpec(): array
    {
        return [
            'theme' => [
                'primary' => 'color', 'accent' => 'color', 'background' => 'color', 'surface' => 'color', 'text' => 'color',
                'font' => 'enum:' . implode('|', self::FONTS), 'radius' => 'enum:none|sm|md|lg|xl', 'mode' => 'enum:light|dark|auto',
                'density' => 'enum:compact|comfortable|spacious',
                // الخطوط: font = خط النص. font_heading = خط العناوين (same = نفس خط النص).
                'font_heading' => 'enum:same|' . implode('|', self::FONTS), 'font_scale' => 'enum:md|sm|lg|xl', 'heading_weight' => 'enum:bold|medium|semibold|extrabold',
            ],
            'layout' => ['login_style' => 'enum:split|centered|card', 'nav_style' => 'enum:top|centered|side|burger', 'nav_scroll' => 'enum:sticky|static', 'nav_button' => 'enum:show|hide', 'show_footer' => 'bool', 'hero_image_url' => 'img'],
            'texts' => ['login_title' => 'i18n:80', 'login_subtitle' => 'i18n:160', 'welcome' => 'i18n:120'],
            'nav' => ['list', (int) config('tenancy.site.max_nav', 8), ['label' => 'i18n:30', 'url' => 'link', 'new_tab' => 'bool']],
            'footer' => ['text' => 'i18n:200', 'links' => ['list', 6, ['label' => 'i18n:30', 'url' => 'link']]],
            'seo' => self::seoSpec() + ['favicon' => 'img', 'robots' => 'enum:index|noindex'],
            'blog' => ['enabled' => 'bool', 'title' => 'i18n:60', 'intro' => 'i18n:220', 'show_in_nav' => 'bool'],
        ];
    }

    /** SEO: عنوان (≤70) ووصف (≤160) وصورة مشاركة (https). بتتكرر على مستوى الموقع والصفحة والمقال. */
    public static function seoSpec(): array
    {
        return ['title' => 'i18n:70', 'description' => 'i18n:160', 'share_image' => 'img'];
    }

    /** مخطط صفحة إضافية (من نحن / تواصل / مخصصة). sections حسب sectionSpecs. */
    public static function pageSpec(): array
    {
        return ['kind' => 'enum:' . implode('|', self::PAGE_KINDS), 'enabled' => 'bool', 'show_in_nav' => 'bool', 'title' => 'i18n:60', 'seo' => self::seoSpec()];
    }

    /** مخطط مقال المدونة. */
    public static function postSpec(): array
    {
        return ['published' => 'bool', 'title' => 'i18n:120', 'excerpt' => 'i18n:240', 'body' => 'i18n:4000', 'cover' => 'img', 'author' => 't:60', 'seo' => self::seoSpec()];
    }

    public static function emptySettings(): array
    {
        return [
            'theme' => ['primary' => null, 'accent' => null, 'background' => null, 'surface' => null, 'text' => null, 'font' => 'system', 'radius' => 'md', 'mode' => 'auto', 'density' => 'comfortable', 'font_heading' => 'same', 'font_scale' => 'md', 'heading_weight' => 'bold'],
            'layout' => ['login_style' => 'split', 'nav_style' => 'top', 'nav_scroll' => 'sticky', 'nav_button' => 'show', 'show_footer' => true, 'hero_image_url' => null],
            'texts' => ['login_title' => ['en' => '', 'ar' => ''], 'login_subtitle' => ['en' => '', 'ar' => ''], 'welcome' => ['en' => '', 'ar' => '']],
            'home' => ['sections' => []],
            'nav' => [],
            'footer' => ['text' => ['en' => '', 'ar' => ''], 'links' => []],
            'seo' => ['title' => ['en' => '', 'ar' => ''], 'description' => ['en' => '', 'ar' => ''], 'share_image' => null, 'favicon' => null, 'robots' => 'index'],
            'blog' => ['enabled' => false, 'title' => ['en' => 'Blog', 'ar' => 'المدونة'], 'intro' => ['en' => '', 'ar' => ''], 'show_in_nav' => true],
            'pages' => [],
            'posts' => [],
        ];
    }

    private static function i(string $en, string $ar): array
    {
        return ['en' => $en, 'ar' => $ar];
    }

    private static function sec(string $id, string $type, array $props, bool $on = true): array
    {
        return ['id' => $id, 'type' => $type, 'enabled' => $on, 'props' => $props];
    }

    private static function make(array $over): array
    {
        $b = self::emptySettings();
        foreach ($over as $k => $v) {
            $b[$k] = is_array($v) && isset($b[$k]) && is_array($b[$k]) && !array_is_list($v) ? array_replace($b[$k], $v) : $v;
        }
        return $b;
    }

    /* ------------------------------------------------------------------ بناء القوالب */

    /** @param list<array{0:string,1:string,2:string,3:string,4:string}> $rows [أيقونة, عنوان en, عنوان ar, نص en, نص ar] */
    private static function features(string $en, string $ar, array $rows, string $id = 'features'): array
    {
        return self::sec($id, 'features', ['title' => self::i($en, $ar), 'items' => array_map(fn ($r) => ['icon' => $r[0], 'title' => self::i($r[1], $r[2]), 'text' => self::i($r[3], $r[4])], $rows)]);
    }

    /** @param list<array{0:string,1:string,2:string}> $rows [قيمة, label en, label ar] */
    private static function stats(array $rows): array
    {
        return self::sec('stats', 'stats', ['items' => array_map(fn ($r) => ['value' => $r[0], 'label' => self::i($r[1], $r[2])], $rows)]);
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $rows [عنوان en, عنوان ar, نص en, نص ar] */
    private static function steps(string $en, string $ar, array $rows): array
    {
        return self::sec('steps', 'steps', ['title' => self::i($en, $ar), 'items' => array_map(fn ($r) => ['title' => self::i($r[0], $r[1]), 'text' => self::i($r[2], $r[3])], $rows)]);
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $rows [سؤال en, سؤال ar, جواب en, جواب ar] */
    private static function faq(array $rows, string $en = 'Frequently asked questions', string $ar = 'أسئلة شائعة'): array
    {
        return self::sec('faq', 'faq', ['title' => self::i($en, $ar), 'items' => array_map(fn ($r) => ['q' => self::i($r[0], $r[1]), 'a' => self::i($r[2], $r[3])], $rows)]);
    }

    private static function hero(array $title, array $sub, array $cta, string $align = 'start'): array
    {
        return self::sec('hero', 'hero', ['title' => self::i(...$title), 'subtitle' => self::i(...$sub), 'cta_label' => self::i(...$cta), 'cta_url' => '/login', 'align' => $align]);
    }

    private static function cta(array $title, array $text, array $btn): array
    {
        return self::sec('cta', 'cta', ['title' => self::i(...$title), 'text' => self::i(...$text), 'button_label' => self::i(...$btn), 'button_url' => '/login']);
    }

    private static function contactSec(string $en = 'Get in touch', string $ar = 'تواصل معنا'): array
    {
        return self::sec('contact', 'contact', ['title' => self::i($en, $ar), 'email' => '', 'phone' => '', 'form' => true]);
    }

    private static function textSec(string $id, array $title, array $body): array
    {
        return self::sec($id, 'text', ['title' => self::i(...$title), 'body' => self::i(...$body)]);
    }

    private static function legalPage(string $kind): array
    {
        $t = $kind === 'privacy' ? self::i('Privacy Policy', 'سياسة الخصوصية') : self::i('Terms of Use', 'شروط الاستخدام');
        // من غير أقسام: السيرفر بيملاها بالنص الافتراضي باسم المساحة وإيميل الدعم (TenantSiteLegal::fill).
        return ['id' => $kind, 'slug' => $kind, 'kind' => $kind, 'enabled' => true, 'show_in_nav' => false, 'title' => $t, 'seo' => [], 'sections' => []];
    }

    /**
     * قالب كامل: هوية + صفحة رئيسية + من نحن + تواصل + خصوصية + شروط + قايمة وفوتر وروابط قانونية + نصوص الدخول + وصف SEO.
     *
     * @param array<string,mixed>  $theme
     * @param array{0:string,1:string,2:string,3:string} $login [عنوان en, عنوان ar, فرعي en, فرعي ar]
     * @param list<array<string,mixed>> $sections
     * @param array{0:string,1:string,2:string,3:string} $about [عنوان en, عنوان ar, نص en, نص ar]
     * @param array{0:string,1:string} $tagline نص الفوتر
     * @param array{0:string,1:string} $seoDesc وصف SEO (≤160)
     */
    private static function define(string $key, array $name, array $desc, string $kind, array $theme, string $loginStyle, array $login, array $sections, array $about, array $tagline, array $seoDesc, bool $blog = false, array $extraPages = [], string $nav = 'top'): array
    {
        $aboutPage = ['id' => 'about', 'slug' => 'about', 'kind' => 'about', 'enabled' => true, 'show_in_nav' => true, 'title' => self::i('About us', 'من نحن'), 'seo' => [],
            'sections' => [self::textSec('intro', [$about[0], $about[1]], [$about[2], $about[3]])]];
        $contactPage = ['id' => 'contact', 'slug' => 'contact', 'kind' => 'contact', 'enabled' => true, 'show_in_nav' => true, 'title' => self::i('Contact', 'تواصل معنا'), 'seo' => [],
            'sections' => [self::contactSec('Send us a message', 'أرسل لنا رسالة')]];
        return [
            'name' => self::i(...$name), 'description' => self::i(...$desc), 'kind' => $kind,
            'preview' => ['primary' => $theme['primary'], 'accent' => $theme['accent'], 'background' => $theme['background'] ?? '#FFFFFF'],
            'settings' => self::make([
                'theme' => $theme + ['font' => 'system', 'radius' => 'md'],
                'layout' => ['login_style' => $loginStyle, 'nav_style' => $nav, 'show_footer' => true],
                'texts' => ['login_title' => self::i($login[0], $login[1]), 'login_subtitle' => self::i($login[2], $login[3])],
                'home' => ['sections' => $sections],
                'nav' => [],
                'footer' => ['text' => self::i(...$tagline), 'links' => [
                    ['label' => self::i('Privacy Policy', 'سياسة الخصوصية'), 'url' => '/p/privacy'],
                    ['label' => self::i('Terms of Use', 'شروط الاستخدام'), 'url' => '/p/terms'],
                ]],
                'seo' => ['description' => self::i(...$seoDesc)],
                'blog' => ['enabled' => $blog],
                'pages' => array_merge([$aboutPage], $extraPages, [$contactPage, self::legalPage('privacy'), self::legalPage('terms')]),
            ]),
        ];
    }

    private static function extraPage(string $slug, array $title, array $sections): array
    {
        return ['id' => $slug, 'slug' => $slug, 'kind' => 'custom', 'enabled' => true, 'show_in_nav' => true, 'title' => self::i(...$title), 'seo' => [], 'sections' => $sections];
    }

    /** @return array<string,array{key:string,name:array,description:array,kind:string,preview:array,settings:array}> */
    public static function builtin(): array
    {
        $L = [];

        /* ---------- عام ---------- */
        $L['classic'] = self::define('classic', ['Classic', 'كلاسيك'], ['A clean landing page with a split sign-in. Fits any activity.', 'صفحة رئيسية نظيفة مع تسجيل دخول مقسوم. تناسب أي نشاط.'], 'any',
            ['primary' => '#4F46E5', 'accent' => '#F59E0B', 'font' => 'inter', 'radius' => 'md'], 'split',
            ['Welcome back', 'أهلًا بعودتك', 'Sign in to continue', 'سجّل دخولك للمتابعة'],
            [
                self::hero(['Work together, from anywhere', 'اعملوا معًا من أي مكان'], ['Meetings, chat and files in one secure workspace.', 'اجتماعات ومحادثات وملفات في مساحة واحدة وآمنة.'], ['Get started', 'ابدأ الآن']),
                self::features('Everything in one place', 'كل ما تحتاجونه في مكان واحد', [
                    ['video', 'Meetings', 'اجتماعات', 'HD video meetings with screen sharing.', 'اجتماعات فيديو بجودة عالية ومشاركة للشاشة.'],
                    ['message', 'Chat', 'محادثات', 'Direct and group conversations.', 'محادثات فردية وجماعية.'],
                    ['file', 'Files', 'ملفات', 'Share and organise documents together.', 'شاركوا مستنداتكم ونظّموها معًا.'],
                    ['shield', 'Secure', 'آمن', 'Your data is isolated and protected.', 'بياناتكم معزولة ومحمية.'],
                ]),
                self::steps('Start in three steps', 'ابدأ في ثلاث خطوات', [
                    ['Sign in', 'سجّل الدخول', 'Use the account created for you.', 'بالحساب الذي أُنشئ لك.'],
                    ['Join your space', 'انضم إلى مساحتك', 'Find your teams and conversations.', 'ستجد فرقك ومحادثاتك جاهزة.'],
                    ['Collaborate', 'تعاون', 'Meet, chat and share files.', 'اجتمع وتحادث وشارك الملفات.'],
                ]),
                self::cta(['Ready to join?', 'جاهز للانضمام؟'], ['Sign in with your workspace account.', 'سجّل الدخول بحساب المساحة.'], ['Sign in', 'تسجيل الدخول']),
            ],
            ['Who we are', 'من نحن', 'We bring our people together in one place to meet, talk and share work, with privacy and security built in.', 'نجمع فريقنا في مكان واحد للاجتماع والتواصل ومشاركة العمل، مع خصوصية وأمان مدمجين.'],
            ['Meet, chat and collaborate securely.', 'اجتماعات ومحادثات وتعاون بأمان.'],
            ['A secure online workspace for meetings, chat and file sharing.', 'مساحة عمل إلكترونية آمنة للاجتماعات والمحادثات ومشاركة الملفات.']);

        $L['minimal'] = self::define('minimal', ['Minimal', 'بسيط'], ['Calm colours and generous spacing, with only what matters.', 'ألوان هادئة ومساحات واسعة، وفقط ما يهم.'], 'any',
            ['primary' => '#111827', 'accent' => '#10B981', 'background' => '#FAFAFA', 'font' => 'system', 'radius' => 'sm', 'density' => 'spacious'], 'centered',
            ['Welcome', 'أهلًا بك', 'Sign in to your workspace.', 'سجّل دخولك إلى مساحة العمل.'],
            [
                self::hero(['A calmer way to work together', 'طريقة أهدأ للعمل معًا'], ['Messages, meetings and files without the noise.', 'رسائل واجتماعات وملفات بلا ضجيج.'], ['Sign in', 'تسجيل الدخول'], 'center'),
                self::features('What you get', 'ما ستجده هنا', [
                    ['message', 'Focused chat', 'محادثات مركّزة', 'Talk with your team without distractions.', 'تحدّث مع فريقك دون تشتيت.'],
                    ['video', 'Simple meetings', 'اجتماعات بسيطة', 'Join with one click, from any device.', 'انضم بنقرة واحدة من أي جهاز.'],
                    ['lock', 'Private by default', 'خصوصية افتراضية', 'Only the right people see your work.', 'لا يرى عملك سوى من يجب أن يراه.'],
                ]),
            ],
            ['Our approach', 'نهجنا', 'We keep things simple: fewer tools, clearer conversations, and your data kept private.', 'نبقي الأمور بسيطة: أدوات أقل ومحادثات أوضح وبيانات تبقى خاصة بك.'],
            ['Simple, private collaboration.', 'تعاون بسيط وخاص.'],
            ['A simple, private workspace for messages, meetings and files.', 'مساحة عمل بسيطة وخاصة للرسائل والاجتماعات والملفات.']);

        $L['bold'] = self::define('bold', ['Bold dark', 'داكن جريء'], ['Dark theme, large hero and a vivid accent. Great for tech and media.', 'ثيم داكن وواجهة كبيرة ولون مميز. مناسب للتقنية والإعلام.'], 'tech',
            ['primary' => '#8B5CF6', 'accent' => '#22D3EE', 'background' => '#0B1020', 'surface' => '#141B34', 'text' => '#F3F4F6', 'font' => 'poppins', 'radius' => 'xl', 'mode' => 'dark'], 'card',
            ['Welcome back', 'أهلًا بعودتك', 'Sign in to your team space.', 'سجّل دخولك إلى مساحة فريقك.'],
            [
                self::hero(['Meet without limits', 'اجتماعات بلا حدود'], ['Fast, private and built for your team.', 'سريعة وخاصة ومصمّمة لفريقك.'], ['Start now', 'ابدأ الآن'], 'center'),
                self::stats([['99.9%', 'Uptime', 'جاهزية'], ['24/7', 'Support', 'دعم'], ['HD', 'Video', 'فيديو']]),
                self::features('Built for speed', 'مصمّم للسرعة', [
                    ['bolt', 'Instant', 'فوري', 'Join meetings and open chats in a blink.', 'انضم للاجتماعات وافتح المحادثات في لحظة.'],
                    ['shield', 'Protected', 'محمي', 'Strong access controls and activity logs.', 'ضوابط وصول قوية وسجلات نشاط.'],
                    ['users', 'Team-first', 'للفريق أولًا', 'Channels, roles and shared files.', 'قنوات وصلاحيات وملفات مشتركة.'],
                ]),
                self::cta(['Jump in', 'انضم الآن'], ['Your team is already inside.', 'فريقك بالفعل بالداخل.'], ['Sign in', 'تسجيل الدخول']),
            ],
            ['About us', 'من نحن', 'We build fast, reliable tools and run our team the same way: open, quick and secure.', 'نبني أدوات سريعة وموثوقة، وندير فريقنا بالأسلوب نفسه: منفتح وسريع وآمن.'],
            ['Fast, private team collaboration.', 'تعاون سريع وخاص للفرق.'],
            ['A fast, private workspace for team meetings, chat and files.', 'مساحة سريعة وخاصة لاجتماعات الفريق والمحادثات والملفات.']);

        $L['elegant'] = self::define('elegant', ['Elegant', 'أنيق'], ['A warm serif look for boutiques, studios and personal brands.', 'مظهر دافئ بخطوط serif للبوتيكات والاستوديوهات والعلامات الشخصية.'], 'any',
            ['primary' => '#7C2D12', 'accent' => '#B45309', 'background' => '#FFFBEB', 'surface' => '#FFFFFF', 'text' => '#1C1917', 'font' => 'lora', 'font_heading' => 'playfair-display', 'radius' => 'sm', 'density' => 'spacious', 'mode' => 'light'], 'centered',
            ['Welcome', 'أهلًا بك', 'Sign in to your private space.', 'سجّل دخولك إلى مساحتك الخاصة.'],
            [
                self::hero(['Crafted with care', 'صُنع بعناية'], ['A calm space for people who value the details.', 'مساحة هادئة لمن يهتمون بالتفاصيل.'], ['Sign in', 'تسجيل الدخول'], 'center'),
                self::textSec('story', ['Our story', 'قصتنا'], ['We started with a simple idea: do fewer things, and do them beautifully. Every client and every project gets our full attention.', 'بدأنا بفكرة بسيطة: نفعل أشياء أقل ونتقنها. كل عميل وكل مشروع يحظى باهتمامنا الكامل.']),
                self::features('What we offer', 'ما نقدمه', [
                    ['heart', 'Personal service', 'خدمة شخصية', 'One-to-one attention at every step.', 'اهتمام فردي في كل خطوة.'],
                    ['calendar', 'Appointments', 'مواعيد', 'Book and meet online or in person.', 'احجز والتقِ عبر الإنترنت أو حضوريًا.'],
                    ['star', 'Quality first', 'الجودة أولًا', 'Carefully chosen materials and methods.', 'مواد وأساليب مختارة بعناية.'],
                ]),
                self::contactSec('Visit or write to us', 'زرنا أو راسلنا'),
            ],
            ['Our story', 'قصتنا', 'A small team with high standards. We believe good work is quiet, careful and lasting.', 'فريق صغير بمعايير عالية. نؤمن أن العمل الجيد هادئ ودقيق ويدوم.'],
            ['Crafted with care.', 'صُنع بعناية.'],
            ['A refined, personal space for clients: appointments, messages and files.', 'مساحة راقية وشخصية للعملاء: مواعيد ورسائل وملفات.']);

        /* ---------- تعليم ---------- */
        $L['campus'] = self::define('campus', ['University', 'جامعة'], ['For universities and faculties: numbers, how to start and FAQ.', 'للجامعات والكليات: أرقام وخطوات بدء وأسئلة شائعة.'], 'university',
            ['primary' => '#0F766E', 'accent' => '#EAB308', 'background' => '#F8FAFC', 'font' => 'cairo', 'radius' => 'lg'], 'split',
            ['Student & staff portal', 'بوابة الطلاب وأعضاء الهيئة', 'Use your university account', 'استخدم حسابك الجامعي'],
            [
                self::hero(['Your campus, online', 'جامعتك على الإنترنت'], ['Lectures, assignments, exams and meetings in one place.', 'المحاضرات والتكليفات والامتحانات والاجتماعات في مكان واحد.'], ['Sign in', 'تسجيل الدخول']),
                self::stats([['12', 'Faculties', 'كلية'], ['20k', 'Students', 'طالب'], ['1k', 'Staff', 'عضو هيئة تدريس']]),
                self::features('Learn and teach online', 'تعلّم وعلّم عن بُعد', [
                    ['video', 'Live lectures', 'محاضرات مباشرة', 'Attend and record lectures from anywhere.', 'احضر المحاضرات وسجّلها من أي مكان.'],
                    ['book', 'Course material', 'مواد المقررات', 'Slides, readings and assignments per course.', 'شرائح ومراجع وتكليفات لكل مقرر.'],
                    ['calendar', 'Timetable', 'الجدول الدراسي', 'Never miss a class or an exam date.', 'لا تفوّت محاضرة أو موعد امتحان.'],
                    ['message', 'Student chat', 'محادثات الطلاب', 'Course groups and direct messages.', 'مجموعات للمقررات ومراسلة مباشرة.'],
                ]),
                self::steps('How to start', 'كيف تبدأ', [
                    ['Sign in', 'سجّل دخولك', 'Use the account issued by the university.', 'بالحساب الصادر من الجامعة.'],
                    ['Open your courses', 'افتح مقرراتك', 'Everything you are enrolled in is listed.', 'ستجد كل ما سجّلت فيه معروضًا.'],
                    ['Join and learn', 'انضم وتعلّم', 'Attend lectures and submit work.', 'احضر المحاضرات وسلّم أعمالك.'],
                ]),
                self::faq([
                    ['I forgot my password', 'نسيت كلمة المرور', 'Use "Forgot password" on the sign-in page.', 'استخدم «نسيت كلمة المرور» من صفحة الدخول.'],
                    ['I do not have an account', 'ليس لدي حساب', 'Accounts are created by the university. Contact the student affairs office.', 'تُنشأ الحسابات من الجامعة. تواصل مع شؤون الطلاب.'],
                    ['Can I join from my phone?', 'هل أستطيع الدخول من هاتفي؟', 'Yes, the workspace works on phones, tablets and computers.', 'نعم، تعمل المساحة على الهاتف واللوح والحاسوب.'],
                ]),
            ],
            ['About the university', 'عن الجامعة', 'We are committed to quality education and research. Our online workspace connects students, faculty and staff.', 'نلتزم بتعليم وبحث عالي الجودة. وتربط مساحتنا الإلكترونية الطلاب وأعضاء هيئة التدريس والموظفين.'],
            ['Education and research, connected online.', 'تعليم وبحث متصلان عبر الإنترنت.'],
            ['The university online workspace for lectures, courses, assignments and meetings.', 'مساحة الجامعة الإلكترونية للمحاضرات والمقررات والتكليفات والاجتماعات.'], true);

        $L['lecturer'] = self::define('lecturer', ['Lecturer', 'دكتور / مدرّس'], ['For university doctors and teachers: live lectures with screen sharing, explanation videos, assignments and grades.', 'لدكاترة الجامعات والمدرّسين: محاضرات مباشرة ومشاركة شاشة وفيديوهات شرح وتكليفات ودرجات.'], 'university',
            ['primary' => '#4338CA', 'accent' => '#F59E0B', 'background' => '#F8FAFC', 'font' => 'cairo', 'font_heading' => 'cairo', 'radius' => 'lg'], 'split',
            ['Class portal', 'بوابة المقرر', 'Sign in to attend, watch and submit', 'سجّل دخولك لتحضر وتشاهد وتسلّم'],
            [
                self::hero(['Your classroom, online', 'قاعتك الدراسية على الإنترنت'], ['Live lectures with screen sharing, recorded explanations you can rewatch, and assignments with grades.', 'محاضرات مباشرة مع مشاركة الشاشة، وفيديوهات شرح تعيد مشاهدتها، وتكليفات ودرجات.'], ['Sign in', 'تسجيل الدخول']),
                self::features('Everything for your course', 'كل ما يحتاجه مقررك', [
                    ['video', 'Live lectures & screen sharing', 'محاضرات مباشرة ومشاركة شاشة', 'Meet your students in HD and share your slides or screen.', 'قابل طلابك بجودة عالية وشارك شرائحك أو شاشتك.'],
                    ['book', 'Explanation videos', 'فيديوهات الشرح', 'Upload lectures once; students watch any time. Uploads resume if the internet drops.', 'ارفع المحاضرات مرة واحدة ليشاهدها الطلاب في أي وقت. ويكمل الرفع إذا انقطع الإنترنت.'],
                    ['lock', 'Private & password-protected', 'خاصة ومحمية بكلمة مرور', 'Open a video to all enrolled students or protect it with a password.', 'افتح الفيديو لكل المسجّلين أو احمه بكلمة مرور.'],
                    ['chart', 'Know who watched', 'اعرف من شاهد', 'See which accounts watched each video and how much of it.', 'اعرف أي الحسابات شاهدت كل فيديو وكم شاهدت منه.'],
                    ['file', 'Assignments & grades', 'تكليفات ودرجات', 'Collect submissions, grade them and give feedback in one place.', 'استلم التسليمات وصحّحها وأعطِ ملاحظاتك في مكان واحد.'],
                    ['calendar', 'Timetable & attendance', 'الجدول والحضور', 'Lecture times and attendance for every course.', 'مواعيد المحاضرات والحضور لكل مقرر.'],
                ]),
                self::steps('How it works', 'كيف يعمل', [
                    ['Join the lecture', 'انضم للمحاضرة', 'Attend live or open the recording later.', 'احضر مباشرة أو افتح التسجيل لاحقًا.'],
                    ['Watch the explanations', 'شاهد الشرح', 'Continue from where you stopped, on any device.', 'أكمل من حيث توقفت، من أي جهاز.'],
                    ['Submit and get graded', 'سلّم واحصل على درجتك', 'Upload your assignment and see your grade and feedback.', 'ارفع تكليفك واطّلع على درجتك وملاحظات المدرّس.'],
                ]),
                self::faq([
                    ['The internet dropped while watching', 'انقطع الإنترنت أثناء المشاهدة', 'Reopen the video and it continues from where you stopped.', 'أعد فتح الفيديو وسيكمل من حيث توقفت.'],
                    ['A video asks for a password', 'الفيديو يطلب كلمة مرور', 'Ask your lecturer for the video password.', 'اطلب كلمة مرور الفيديو من دكتور المادة.'],
                    ['Where do I submit my assignment?', 'أين أسلّم التكليف؟', 'Open the course, then Assignments, and upload your file.', 'افتح المقرر ثم التكليفات وارفع ملفك.'],
                ]),
                self::contactSec('Questions for your lecturer?', 'عندك سؤال للدكتور؟'),
            ],
            ['About the course', 'عن المقرر', 'Lectures, explanation videos, assignments and grades for the students of this course, in one private space.', 'محاضرات وفيديوهات شرح وتكليفات ودرجات لطلاب هذا المقرر في مساحة واحدة خاصة.'],
            ['Teach, share and grade online.', 'درّس وشارك وصحّح عبر الإنترنت.'],
            ['A private classroom for lecturers: live lectures, screen sharing, explanation videos, assignments and grades.', 'قاعة دراسية خاصة للمدرّسين: محاضرات مباشرة ومشاركة شاشة وفيديوهات شرح وتكليفات ودرجات.']);

        $L['academy'] = self::define('academy', ['Academy', 'أكاديمية'], ['Training centres and e-learning: courses, results and support.', 'مراكز التدريب والتعلم الإلكتروني: دورات ونتائج ودعم.'], 'training',
            ['primary' => '#1D4ED8', 'accent' => '#F59E0B', 'background' => '#F8FAFC', 'font' => 'almarai', 'font_heading' => 'el-messiri', 'radius' => 'lg'], 'split',
            ['Learner portal', 'بوابة المتعلمين', 'Sign in with your academy account', 'سجّل الدخول بحساب الأكاديمية'],
            [
                self::hero(['Learn at your own pace', 'تعلّم بالسرعة التي تناسبك'], ['Live classes, recordings and assignments in one place.', 'حصص مباشرة وتسجيلات وتكليفات في مكان واحد.'], ['Sign in', 'تسجيل الدخول']),
                self::stats([['50+', 'Courses', 'دورة'], ['5k', 'Learners', 'متعلم'], ['4.8', 'Average rating', 'متوسط التقييم']]),
                self::features('Why learn with us', 'لماذا تتعلم معنا', [
                    ['video', 'Live and recorded', 'مباشر ومسجّل', 'Join live or watch later at your own time.', 'احضر مباشرة أو شاهد لاحقًا في وقتك.'],
                    ['cap', 'Certificates', 'شهادات', 'Get a certificate when you complete a course.', 'احصل على شهادة عند إتمام الدورة.'],
                    ['users', 'Expert trainers', 'مدربون خبراء', 'Learn from practitioners with real experience.', 'تعلّم من ممارسين لديهم خبرة فعلية.'],
                    ['message', 'Support', 'دعم', 'Ask questions in the course chat.', 'اطرح أسئلتك في محادثة الدورة.'],
                ]),
                self::faq([['How do I join a class?', 'كيف أدخل الحصة؟', 'Open your schedule and press Join.', 'افتح جدولك واضغط «انضمام».'], ['Where are the recordings?', 'أين التسجيلات؟', 'Inside each course, under Recordings.', 'داخل كل دورة، في قسم التسجيلات.']]),
                self::contactSec('Need help?', 'تحتاج مساعدة؟'),
            ],
            ['About the academy', 'عن الأكاديمية', 'We train people with practical skills through live classes, projects and personal feedback.', 'ندرّب الناس على مهارات عملية عبر حصص مباشرة ومشروعات وملاحظات شخصية.'],
            ['Practical skills, taught live.', 'مهارات عملية تُدرَّس مباشرة.'],
            ['Online academy: live classes, recordings, assignments and certificates.', 'أكاديمية إلكترونية: حصص مباشرة وتسجيلات وتكليفات وشهادات.'], true);

        $L['school'] = self::define('school', ['School', 'مدرسة'], ['Schools and nurseries: parents, teachers and students in one place.', 'المدارس والحضانات: أولياء الأمور والمعلمون والطلاب في مكان واحد.'], 'school',
            ['primary' => '#2563EB', 'accent' => '#F97316', 'background' => '#F0F9FF', 'font' => 'tajawal', 'radius' => 'xl', 'font_scale' => 'lg'], 'split',
            ['School portal', 'بوابة المدرسة', 'For students, parents and teachers', 'للطلاب وأولياء الأمور والمعلمين'],
            [
                self::hero(['A better link between school and home', 'رابط أفضل بين المدرسة والبيت'], ['Timetables, homework, announcements and online classes.', 'الجداول والواجبات والإعلانات والحصص عبر الإنترنت.'], ['Sign in', 'تسجيل الدخول']),
                self::features('For everyone at school', 'لكل أسرة المدرسة', [
                    ['cap', 'Students', 'الطلاب', 'Homework, lessons and online classes.', 'الواجبات والدروس والحصص الإلكترونية.'],
                    ['heart', 'Parents', 'أولياء الأمور', 'Follow progress and read school news.', 'تابع المستوى واقرأ أخبار المدرسة.'],
                    ['book', 'Teachers', 'المعلمون', 'Share material and message classes.', 'شارك المواد وراسل الفصول.'],
                    ['calendar', 'Calendar', 'التقويم', 'Exams, events and holidays at a glance.', 'الامتحانات والفعاليات والإجازات في لمحة.'],
                ]),
                self::stats([['35', 'Teachers', 'معلمًا'], ['600', 'Students', 'طالبًا'], ['12', 'Grades', 'صفًا']]),
                self::steps('Getting started', 'البدء', [
                    ['Get your account', 'احصل على حسابك', 'The school office gives each family an account.', 'تمنح إدارة المدرسة كل أسرة حسابًا.'],
                    ['Sign in', 'سجّل الدخول', 'Use your email and password.', 'ببريدك وكلمة مرورك.'],
                    ['Stay connected', 'ابقَ على اتصال', 'Read news and message teachers.', 'اقرأ الأخبار وراسل المعلمين.'],
                ]),
                self::faq([['Who creates accounts?', 'من ينشئ الحسابات؟', 'The school administration. Contact the office if you have not received yours.', 'إدارة المدرسة. تواصل مع المكتب إن لم يصلك حسابك.'], ['Are children\'s data protected?', 'هل بيانات الأطفال محمية؟', 'Yes. Only the school and the child\'s parents can see a student\'s information.', 'نعم. لا يطّلع على بيانات الطالب سوى المدرسة وولي أمره.']]),
            ],
            ['About our school', 'عن مدرستنا', 'We nurture curious, confident learners in a safe and caring environment, working closely with every family.', 'ننمّي متعلمين فضوليين وواثقين في بيئة آمنة ومحبّة، ونعمل عن قرب مع كل أسرة.'],
            ['Safe, caring learning.', 'تعلّم آمن ومحب.'],
            ['School portal for students, parents and teachers: homework, timetables and news.', 'بوابة المدرسة للطلاب وأولياء الأمور والمعلمين: واجبات وجداول وأخبار.'], true);

        /* ---------- أعمال ---------- */
        $L['business'] = self::define('business', ['Business', 'شركات'], ['Corporate site: capabilities, process and contact.', 'موقع مؤسسي: قدرات وآلية عمل وتواصل.'], 'company',
            ['primary' => '#2563EB', 'accent' => '#EF4444', 'font' => 'ibm-plex-sans-arabic', 'radius' => 'md'], 'split',
            ['Employee portal', 'بوابة الموظفين', 'Sign in with your work account', 'سجّل الدخول بحساب العمل'],
            [
                self::hero(['One place for your whole team', 'مكان واحد لفريقك بالكامل'], ['Secure meetings, chat and document sharing.', 'اجتماعات ومحادثات ومشاركة مستندات بأمان.'], ['Sign in', 'تسجيل الدخول']),
                self::features('How we work', 'كيف نعمل', [
                    ['video', 'Meetings', 'اجتماعات', 'Internal and client meetings in HD.', 'اجتماعات داخلية ومع العملاء بجودة عالية.'],
                    ['file', 'Documents', 'مستندات', 'One source of truth for files.', 'مرجع واحد للملفات.'],
                    ['users', 'Teams', 'فرق', 'Departments and projects organised.', 'الأقسام والمشروعات منظّمة.'],
                    ['shield', 'Compliance', 'امتثال', 'Access control and audit logs.', 'ضوابط وصول وسجلات تدقيق.'],
                ]),
                self::stats([['10+', 'Years', 'سنوات خبرة'], ['200', 'Clients', 'عميل'], ['50', 'Team members', 'موظفًا']]),
                self::steps('Our process', 'آلية عملنا', [
                    ['Discover', 'نفهم', 'We listen to your goals.', 'نستمع إلى أهدافك.'],
                    ['Plan', 'نخطط', 'We agree on scope and timeline.', 'نتفق على النطاق والجدول الزمني.'],
                    ['Deliver', 'ننفذ', 'We deliver and support.', 'ننفّذ ونقدّم الدعم.'],
                ]),
                self::cta(['Let us talk', 'لنتحدث'], ['Tell us about your needs.', 'أخبرنا باحتياجاتك.'], ['Contact us', 'تواصل معنا']),
            ],
            ['About the company', 'عن الشركة', 'We help organisations work better with reliable services, clear communication and a team that cares.', 'نساعد المؤسسات على العمل بكفاءة بخدمات موثوقة وتواصل واضح وفريق يهتم.'],
            ['Reliable service, clear communication.', 'خدمة موثوقة وتواصل واضح.'],
            ['Corporate website and employee workspace: meetings, chat and documents.', 'الموقع المؤسسي ومساحة الموظفين: اجتماعات ومحادثات ومستندات.']);

        $L['startup'] = self::define('startup', ['Startup', 'شركة ناشئة'], ['Modern product page with steps and a strong call to action.', 'صفحة منتج عصرية مع خطوات ودعوة واضحة للإجراء.'], 'tech',
            ['primary' => '#0EA5E9', 'accent' => '#F97316', 'font' => 'dm-sans', 'font_heading' => 'space-grotesk', 'radius' => 'lg', 'heading_weight' => 'extrabold'], 'card',
            ['Welcome back', 'أهلًا بعودتك', 'Sign in to your team', 'سجّل الدخول إلى فريقك'],
            [
                self::hero(['Ship faster together', 'أنجزوا أسرع معًا'], ['Meetings, chat and files without the clutter.', 'اجتماعات ومحادثات وملفات بلا فوضى.'], ['Get started', 'ابدأ الآن'], 'center'),
                self::features('Everything your team needs', 'كل ما يحتاجه فريقك', [
                    ['bolt', 'Fast', 'سريع', 'No setup. Your team is up in minutes.', 'دون إعداد معقّد. فريقك جاهز في دقائق.'],
                    ['message', 'Chat', 'محادثات', 'Channels and threads that stay organised.', 'قنوات ونقاشات تبقى منظّمة.'],
                    ['video', 'Meetings', 'اجتماعات', 'One-click calls with screen sharing.', 'مكالمات بنقرة واحدة مع مشاركة الشاشة.'],
                    ['chart', 'Insight', 'رؤية', 'See activity across projects.', 'تابع النشاط عبر المشروعات.'],
                ]),
                self::steps('Up and running in minutes', 'جاهز للعمل في دقائق', [
                    ['Sign in', 'سجّل الدخول', 'Use your work account.', 'بحساب العمل.'],
                    ['Invite your team', 'ادعُ فريقك', 'Everyone joins in one click.', 'ينضم الجميع بنقرة.'],
                    ['Start shipping', 'ابدأ الإنجاز', 'Meet, decide and move on.', 'اجتمعوا وقرّروا وانطلقوا.'],
                ]),
                self::faq([['Is my data safe?', 'هل بياناتي آمنة؟', 'Yes. Your workspace is isolated from others and protected by access controls.', 'نعم. مساحتك معزولة عن غيرها ومحمية بضوابط وصول.'], ['Can I invite guests?', 'هل يمكنني دعوة ضيوف؟', 'Yes, with limited access that you control.', 'نعم، بصلاحيات محدودة تتحكم بها.']]),
                self::cta(['Ready to start?', 'جاهز للبدء؟'], ['Your team is one sign-in away.', 'فريقك على بعد تسجيل دخول واحد.'], ['Sign in', 'تسجيل الدخول']),
            ],
            ['Our mission', 'مهمتنا', 'We build tools that help small teams do big things, and we work in the open with our customers.', 'نبني أدوات تساعد الفرق الصغيرة على إنجاز أشياء كبيرة، ونعمل بشفافية مع عملائنا.'],
            ['Small team, big ideas.', 'فريق صغير وأفكار كبيرة.'],
            ['Modern team workspace: fast meetings, organised chat and shared files.', 'مساحة عمل عصرية للفرق: اجتماعات سريعة ومحادثات منظّمة وملفات مشتركة.'], true);

        $L['agency'] = self::define('agency', ['Creative agency', 'وكالة إبداعية'], ['Design, marketing and media agencies: services, process and clients.', 'وكالات التصميم والتسويق والإعلام: خدمات وآلية عمل وعملاء.'], 'agency',
            ['primary' => '#DB2777', 'accent' => '#7C3AED', 'background' => '#FFFFFF', 'font' => 'readex-pro', 'font_heading' => 'space-grotesk', 'radius' => 'xl', 'heading_weight' => 'extrabold'], 'split',
            ['Client area', 'منطقة العملاء', 'Sign in to review your projects', 'سجّل الدخول لمراجعة مشروعاتك'],
            [
                self::hero(['Ideas that get noticed', 'أفكار تلفت الأنظار'], ['Brand, design and marketing, with a client area for feedback and files.', 'هوية وتصميم وتسويق، مع منطقة للعملاء للملاحظات والملفات.'], ['Client sign-in', 'دخول العملاء']),
                self::features('What we do', 'ماذا نقدم', [
                    ['star', 'Branding', 'هوية بصرية', 'Names, logos and guidelines that last.', 'أسماء وشعارات ودلائل تدوم.'],
                    ['globe', 'Digital marketing', 'تسويق رقمي', 'Campaigns that reach the right people.', 'حملات تصل إلى الجمهور المناسب.'],
                    ['video', 'Content and video', 'محتوى وفيديو', 'Stories told in motion and in words.', 'قصص تُروى بالحركة والكلمة.'],
                    ['chart', 'Reporting', 'تقارير', 'Clear results every month.', 'نتائج واضحة كل شهر.'],
                ]),
                self::steps('How we work with you', 'كيف نعمل معك', [
                    ['Brief', 'الموجز', 'We agree on goals and audience.', 'نتفق على الأهداف والجمهور.'],
                    ['Create', 'الإبداع', 'We share work in progress with you.', 'نشاركك العمل أثناء تنفيذه.'],
                    ['Review', 'المراجعة', 'You comment, we refine.', 'تعلّق ونحن نحسّن.'],
                    ['Launch', 'الإطلاق', 'We deliver and measure.', 'نسلّم ونقيس النتائج.'],
                ]),
                self::stats([['120', 'Projects', 'مشروعًا'], ['40', 'Brands', 'علامة تجارية'], ['8', 'Years', 'سنوات']]),
                self::cta(['Have a project in mind?', 'لديك مشروع؟'], ['Tell us about it and we will reply within a day.', 'أخبرنا عنه وسنرد خلال يوم.'], ['Contact us', 'تواصل معنا']),
            ],
            ['About the agency', 'عن الوكالة', 'We are a team of designers, writers and strategists who turn ideas into work people remember.', 'نحن فريق من المصممين والكتّاب والاستراتيجيين نحوّل الأفكار إلى أعمال لا تُنسى.'],
            ['Ideas worth remembering.', 'أفكار تستحق أن تُذكر.'],
            ['Creative agency website with a private client area for reviews and files.', 'موقع وكالة إبداعية مع منطقة خاصة للعملاء للمراجعات والملفات.'], true);

        $L['bank'] = self::define('bank', ['Finance & insurance', 'خدمات مالية وتأمين'], ['Financial services and insurance: trust, security and clear steps.', 'الخدمات المالية والتأمين: ثقة وأمان وخطوات واضحة.'], 'finance',
            ['primary' => '#0B3B6F', 'accent' => '#0EA5A4', 'background' => '#F8FAFC', 'font' => 'ibm-plex-sans-arabic', 'radius' => 'sm'], 'split',
            ['Secure client portal', 'بوابة العملاء الآمنة', 'Sign in to your account', 'سجّل الدخول إلى حسابك'],
            [
                self::hero(['Your finances, handled with care', 'شؤونك المالية بين أيدٍ أمينة'], ['Meet your advisor online and keep your documents in one secure place.', 'قابل مستشارك عبر الإنترنت واحفظ مستنداتك في مكان آمن.'], ['Client sign-in', 'دخول العملاء']),
                self::stats([['25+', 'Years of experience', 'عامًا من الخبرة'], ['15k', 'Clients', 'عميل'], ['100%', 'Encrypted', 'اتصالات مشفّرة']]),
                self::features('Services', 'خدماتنا', [
                    ['chart', 'Advisory', 'استشارات', 'Plans built around your goals.', 'خطط مبنية على أهدافك.'],
                    ['shield', 'Insurance', 'تأمين', 'Cover for people, property and business.', 'تغطية للأفراد والممتلكات والأعمال.'],
                    ['file', 'Secure documents', 'مستندات آمنة', 'Sign and store paperwork online.', 'وقّع واحفظ أوراقك عبر الإنترنت.'],
                    ['video', 'Online meetings', 'اجتماعات عن بُعد', 'Talk to your advisor from anywhere.', 'تحدّث مع مستشارك من أي مكان.'],
                ]),
                self::steps('How it works', 'كيف يتم الأمر', [
                    ['Sign in securely', 'ادخل بأمان', 'With two-step verification.', 'مع التحقق بخطوتين.'],
                    ['Meet your advisor', 'قابل مستشارك', 'Book an online or in-person meeting.', 'احجز اجتماعًا إلكترونيًا أو حضوريًا.'],
                    ['Track and sign', 'تابع ووقّع', 'Review documents in one place.', 'راجع مستنداتك في مكان واحد.'],
                ]),
                self::faq([['How is my information protected?', 'كيف تُحمى معلوماتي؟', 'Connections are encrypted and access is limited to you and your advisor.', 'الاتصالات مشفّرة والوصول مقصور عليك وعلى مستشارك.'], ['Who do I contact for help?', 'بمن أتصل للمساعدة؟', 'Use the contact page and we will reply within one business day.', 'استخدم صفحة التواصل وسنرد خلال يوم عمل.']]),
            ],
            ['About us', 'من نحن', 'We provide independent, transparent financial and insurance services, guided by trust and long-term relationships.', 'نقدّم خدمات مالية وتأمينية مستقلة وشفافة، تقودها الثقة والعلاقات طويلة الأمد.'],
            ['Trust, security and transparency.', 'ثقة وأمان وشفافية.'],
            ['Secure client portal for financial advice, insurance and document signing.', 'بوابة عملاء آمنة للاستشارات المالية والتأمين وتوقيع المستندات.']);

        $L['lawfirm'] = self::define('lawfirm', ['Law firm', 'مكتب محاماة'], ['Law firms and consultancies: practice areas, team approach and confidentiality.', 'مكاتب المحاماة والاستشارات: مجالات العمل والأسلوب والسرية.'], 'legal',
            ['primary' => '#1F2937', 'accent' => '#B45309', 'background' => '#FAFAF9', 'surface' => '#FFFFFF', 'font' => 'lora', 'font_heading' => 'playfair-display', 'radius' => 'sm', 'density' => 'spacious'], 'centered',
            ['Client portal', 'بوابة العملاء', 'Confidential access to your case files', 'دخول سري إلى ملفات قضيتك'],
            [
                self::hero(['Counsel you can trust', 'مشورة قانونية تثق بها'], ['Experienced lawyers, clear advice and a private portal for your documents.', 'محامون ذوو خبرة ونصيحة واضحة وبوابة خاصة لمستنداتك.'], ['Client portal', 'بوابة العملاء']),
                self::features('Practice areas', 'مجالات العمل', [
                    ['users', 'Corporate', 'الشركات', 'Formation, contracts and compliance.', 'التأسيس والعقود والامتثال.'],
                    ['file', 'Contracts', 'العقود', 'Drafting and review.', 'الصياغة والمراجعة.'],
                    ['shield', 'Disputes', 'المنازعات', 'Negotiation and litigation.', 'التفاوض والتقاضي.'],
                    ['heart', 'Family', 'الأحوال الشخصية', 'Sensitive matters, handled with care.', 'قضايا حساسة تُعالج بعناية.'],
                ]),
                self::steps('How we work', 'كيف نعمل', [
                    ['Consultation', 'الاستشارة', 'Meet us in person or online.', 'قابلنا حضوريًا أو عبر الإنترنت.'],
                    ['Strategy', 'الخطة', 'We explain options and costs clearly.', 'نشرح الخيارات والتكاليف بوضوح.'],
                    ['Representation', 'التمثيل', 'You follow progress in the portal.', 'تتابع التقدم من البوابة.'],
                ]),
                self::faq([['Is what I share kept confidential?', 'هل ما أشاركه سري؟', 'Yes. Your documents are visible only to you and the lawyers on your matter.', 'نعم. لا يطّلع على مستنداتك سوى أنت والمحامين المكلفين بقضيتك.'], ['Can we meet online?', 'هل يمكن الاجتماع عن بُعد؟', 'Yes, using secure video meetings.', 'نعم، عبر اجتماعات فيديو آمنة.']]),
                self::contactSec('Request a consultation', 'اطلب استشارة'),
            ],
            ['About the firm', 'عن المكتب', 'A team of lawyers committed to clear advice, integrity and the confidentiality of every client.', 'فريق من المحامين ملتزم بالنصيحة الواضحة والنزاهة وسرية كل عميل.'],
            ['Integrity. Clarity. Confidentiality.', 'نزاهة. وضوح. سرية.'],
            ['Law firm website with a confidential client portal for documents and meetings.', 'موقع مكتب محاماة مع بوابة عملاء سرية للمستندات والاجتماعات.']);

        /* ---------- صحة ورياضة ---------- */
        $L['clinic'] = self::define('clinic', ['Clinic & hospital', 'عيادة ومستشفى'], ['Clinics, hospitals and labs: services, doctors, online consultations.', 'العيادات والمستشفيات والمعامل: خدمات وأطباء واستشارات عن بُعد.'], 'health',
            ['primary' => '#0891B2', 'accent' => '#16A34A', 'background' => '#F0FDFA', 'font' => 'cairo', 'radius' => 'lg'], 'split',
            ['Patient & staff portal', 'بوابة المرضى والعاملين', 'Sign in securely', 'سجّل الدخول بأمان'],
            [
                self::hero(['Caring for you, wherever you are', 'نعتني بك أينما كنت'], ['Book appointments, consult your doctor online and keep your records private.', 'احجز مواعيدك واستشر طبيبك عن بُعد واحفظ سجلاتك بخصوصية.'], ['Patient sign-in', 'دخول المرضى']),
                self::features('Our services', 'خدماتنا', [
                    ['heart', 'Consultations', 'الاستشارات', 'In the clinic or by secure video call.', 'في العيادة أو عبر مكالمة فيديو آمنة.'],
                    ['calendar', 'Appointments', 'المواعيد', 'Book, change and get reminders.', 'احجز وعدّل واستلم التذكيرات.'],
                    ['file', 'Reports and records', 'التقارير والسجلات', 'Results shared privately with you.', 'نتائجك تصلك بخصوصية.'],
                    ['message', 'Follow-up', 'المتابعة', 'Message your care team.', 'راسل فريق رعايتك.'],
                ]),
                self::stats([['20+', 'Specialties', 'تخصصًا'], ['60', 'Doctors', 'طبيبًا'], ['24/7', 'Emergency', 'طوارئ']]),
                self::steps('Your visit, simplified', 'زيارتك أسهل', [
                    ['Book', 'احجز', 'Choose a doctor and a time.', 'اختر الطبيب والموعد.'],
                    ['Consult', 'استشر', 'Visit us or join online.', 'زرنا أو انضم عن بُعد.'],
                    ['Follow up', 'تابع', 'Get results and advice in your portal.', 'استلم النتائج والنصائح في بوابتك.'],
                ]),
                self::faq([['Are my medical details private?', 'هل بياناتي الطبية خاصة؟', 'Yes. Only you and your care team can see your records.', 'نعم. لا يراها سواك وفريق رعايتك.'], ['Is the online consultation a replacement for emergency care?', 'هل الاستشارة عن بُعد بديل للطوارئ؟', 'No. In an emergency, call your local emergency number or visit the nearest hospital.', 'لا. في الحالات الطارئة اتصل برقم الطوارئ أو توجه لأقرب مستشفى.']]),
                self::contactSec('Book or ask a question', 'احجز أو اسأل'),
            ],
            ['About us', 'من نحن', 'Our medical team provides safe, compassionate care and respects the privacy of every patient.', 'يقدّم فريقنا الطبي رعاية آمنة ورحيمة ويحترم خصوصية كل مريض.'],
            ['Compassionate, private care.', 'رعاية رحيمة وخاصة.'],
            ['Clinic and hospital website: appointments, online consultations and private patient portal.', 'موقع عيادة ومستشفى: مواعيد واستشارات عن بُعد وبوابة مرضى خاصة.']);

        $L['fitness'] = self::define('fitness', ['Gym & fitness', 'نادٍ رياضي'], ['Gyms and studios: classes, coaches and memberships.', 'الصالات الرياضية والاستوديوهات: حصص ومدربون واشتراكات.'], 'fitness',
            ['primary' => '#EA580C', 'accent' => '#FACC15', 'background' => '#111827', 'surface' => '#1F2937', 'text' => '#F9FAFB', 'font' => 'montserrat', 'radius' => 'md', 'mode' => 'dark', 'heading_weight' => 'extrabold'], 'card',
            ['Member area', 'منطقة الأعضاء', 'Sign in to book your classes', 'سجّل الدخول لحجز حصصك'],
            [
                self::hero(['Train hard. Train together.', 'تدرّب بجد. تدرّب معنا.'], ['Group classes, personal coaching and online sessions for every level.', 'حصص جماعية وتدريب شخصي وجلسات عن بُعد لكل المستويات.'], ['Member sign-in', 'دخول الأعضاء'], 'center'),
                self::stats([['40+', 'Weekly classes', 'حصة أسبوعيًا'], ['15', 'Coaches', 'مدربًا'], ['3k', 'Members', 'عضو']]),
                self::features('Everything you need to progress', 'كل ما تحتاجه للتقدم', [
                    ['bolt', 'Group classes', 'حصص جماعية', 'Strength, cardio, yoga and more.', 'قوة وكارديو ويوغا وغيرها.'],
                    ['users', 'Personal coaching', 'تدريب شخصي', 'A plan built around your goal.', 'خطة مبنية على هدفك.'],
                    ['video', 'Online sessions', 'جلسات أونلاين', 'Train from home with a live coach.', 'تدرّب من بيتك مع مدرب مباشر.'],
                    ['calendar', 'Easy booking', 'حجز سهل', 'Reserve your spot in seconds.', 'احجز مكانك في ثوانٍ.'],
                ]),
                self::faq([['How do I book a class?', 'كيف أحجز حصة؟', 'Sign in and choose a class from the timetable.', 'سجّل الدخول واختر حصة من الجدول.'], ['Can I freeze my membership?', 'هل أستطيع تجميد اشتراكي؟', 'Yes. Contact the front desk to arrange it.', 'نعم. تواصل مع الاستقبال لترتيب ذلك.']]),
                self::cta(['Your first session is on us', 'جلستك الأولى علينا'], ['Visit, meet a coach and try a class.', 'زرنا وقابل مدربًا وجرّب حصة.'], ['Contact us', 'تواصل معنا']),
            ],
            ['About us', 'من نحن', 'We are a community of people who train, learn and celebrate progress together, whatever their starting point.', 'نحن مجتمع من الناس يتدربون ويتعلمون ويحتفلون بالتقدم معًا، أيًّا كانت نقطة البداية.'],
            ['Stronger together.', 'أقوى معًا.'],
            ['Gym and fitness studio: classes, coaching, online sessions and member area.', 'نادٍ واستوديو لياقة: حصص وتدريب وجلسات أونلاين ومنطقة أعضاء.']);

        /* ---------- تجارة وضيافة ---------- */
        $L['shop'] = self::define('shop', ['Shop & retail', 'متجر وتجزئة'], ['Stores and brands: offers, delivery, returns and support.', 'المتاجر والعلامات: عروض وتوصيل واسترجاع ودعم.'], 'retail',
            ['primary' => '#16A34A', 'accent' => '#F59E0B', 'background' => '#FFFFFF', 'font' => 'nunito', 'radius' => 'lg'], 'split',
            ['Customer & staff area', 'منطقة العملاء والموظفين', 'Sign in to your account', 'سجّل الدخول إلى حسابك'],
            [
                self::hero(['Quality you can count on', 'جودة تعتمد عليها'], ['Carefully chosen products, fast delivery and friendly support.', 'منتجات مختارة بعناية وتوصيل سريع ودعم ودود.'], ['Sign in', 'تسجيل الدخول']),
                self::features('Why shop with us', 'لماذا تتسوق معنا', [
                    ['star', 'Selected products', 'منتجات مختارة', 'Only items we would use ourselves.', 'فقط ما نستخدمه بأنفسنا.'],
                    ['bolt', 'Fast delivery', 'توصيل سريع', 'Orders leave the store the same day.', 'تغادر الطلبات المتجر في اليوم نفسه.'],
                    ['shield', 'Easy returns', 'استرجاع سهل', 'Changed your mind? Return within 14 days.', 'غيّرت رأيك؟ أعد المنتج خلال 14 يومًا.'],
                    ['message', 'Real support', 'دعم حقيقي', 'Talk to a person, not a bot.', 'تحدّث مع شخص لا مع روبوت.'],
                ]),
                self::steps('How ordering works', 'كيف يتم الطلب', [
                    ['Choose', 'اختر', 'Browse and pick what you like.', 'تصفّح واختر ما يعجبك.'],
                    ['Order', 'اطلب', 'Pay securely.', 'ادفع بأمان.'],
                    ['Receive', 'استلم', 'Track your delivery to the door.', 'تابع الشحنة حتى بابك.'],
                ]),
                self::faq([['How long does delivery take?', 'كم يستغرق التوصيل؟', 'Usually 2 to 4 working days, depending on your area.', 'عادة من 2 إلى 4 أيام عمل حسب منطقتك.'], ['How do I return an item?', 'كيف أعيد منتجًا؟', 'Contact us with your order number and we will arrange it.', 'تواصل معنا مع رقم الطلب وسنرتّب ذلك.']]),
                self::contactSec('Customer support', 'خدمة العملاء'),
            ],
            ['About the store', 'عن المتجر', 'We are a local team that cares about what we sell and who we sell to. Quality and service come first.', 'نحن فريق محلي يهتم بما يبيعه ولمن يبيع. الجودة والخدمة أولًا.'],
            ['Quality products, friendly service.', 'منتجات جيدة وخدمة ودودة.'],
            ['Store website: selected products, fast delivery, easy returns and support.', 'موقع متجر: منتجات مختارة وتوصيل سريع واسترجاع سهل ودعم.']);

        $L['restaurant'] = self::define('restaurant', ['Restaurant & café', 'مطعم وكافيه'], ['Restaurants and cafés: story, opening hours, reservations.', 'المطاعم والكافيهات: قصة ومواعيد عمل وحجوزات.'], 'food',
            ['primary' => '#B91C1C', 'accent' => '#CA8A04', 'background' => '#FFFBF5', 'surface' => '#FFFFFF', 'text' => '#292524', 'font' => 'tajawal', 'font_heading' => 'amiri', 'radius' => 'md', 'density' => 'spacious'], 'centered',
            ['Team sign-in', 'دخول الفريق', 'For staff and partners', 'للعاملين والشركاء'],
            [
                self::hero(['Good food, shared', 'طعام جيد يُشارَك'], ['Fresh ingredients, family recipes and a warm welcome.', 'مكونات طازجة ووصفات عائلية واستقبال دافئ.'], ['Reserve a table', 'احجز طاولة'], 'center'),
                self::features('What makes us different', 'ما يميزنا', [
                    ['heart', 'Family recipes', 'وصفات عائلية', 'Cooked the way it has always been.', 'تُطهى كما كانت دائمًا.'],
                    ['star', 'Fresh daily', 'طازج يوميًا', 'Ingredients delivered every morning.', 'مكونات تصل كل صباح.'],
                    ['users', 'For every table', 'لكل مناسبة', 'Dinners, birthdays and business lunches.', 'عشاء وأعياد ميلاد وغداء عمل.'],
                ]),
                self::textSec('hours', ['Opening hours', 'مواعيد العمل'], ['Every day, 12:00 to midnight.\nTell us about allergies when you book.', 'يوميًا من 12 ظهرًا حتى منتصف الليل.\nأخبرنا عن أي حساسية عند الحجز.']),
                self::faq([['Do I need to book?', 'هل أحتاج إلى حجز؟', 'Booking is recommended on weekends and for groups of 6 or more.', 'يُنصح بالحجز في عطلة الأسبوع وللمجموعات من 6 أشخاص فأكثر.'], ['Do you cater events?', 'هل تقدمون خدمة الحفلات؟', 'Yes. Send us the date and number of guests.', 'نعم. أرسل لنا التاريخ وعدد الضيوف.']]),
                self::contactSec('Reservations and enquiries', 'الحجوزات والاستفسارات'),
            ],
            ['Our story', 'قصتنا', 'What began as a family kitchen grew into a place where friends meet. We cook with care and serve with a smile.', 'بدأ كمطبخ عائلي ثم صار مكانًا يلتقي فيه الأصدقاء. نطهو بعناية ونقدّم بابتسامة.'],
            ['Good food, warm welcome.', 'طعام جيد واستقبال دافئ.'],
            ['Restaurant and café: our story, opening hours, reservations and events.', 'مطعم وكافيه: قصتنا ومواعيد العمل والحجوزات والمناسبات.']);

        $L['hotel'] = self::define('hotel', ['Hotel & travel', 'فندق وسياحة'], ['Hotels, resorts and travel agencies: rooms, experiences and booking help.', 'الفنادق والمنتجعات ووكالات السفر: غرف وتجارب ومساعدة في الحجز.'], 'hospitality',
            ['primary' => '#0E7490', 'accent' => '#D97706', 'background' => '#F8FAFC', 'font' => 'cairo', 'font_heading' => 'playfair-display', 'radius' => 'md', 'density' => 'spacious'], 'split',
            ['Guest & staff portal', 'بوابة الضيوف والعاملين', 'Sign in to manage your stay', 'سجّل الدخول لإدارة إقامتك'],
            [
                self::hero(['Stay somewhere memorable', 'أقم في مكان لا يُنسى'], ['Comfortable rooms, local experiences and a team that looks after every detail.', 'غرف مريحة وتجارب محلية وفريق يهتم بكل التفاصيل.'], ['Guest sign-in', 'دخول الضيوف']),
                self::features('Your stay', 'إقامتك', [
                    ['heart', 'Comfortable rooms', 'غرف مريحة', 'Quiet, clean and thoughtfully equipped.', 'هادئة ونظيفة ومجهّزة بعناية.'],
                    ['globe', 'Local experiences', 'تجارب محلية', 'Tours and activities arranged for you.', 'جولات وأنشطة نرتّبها لك.'],
                    ['calendar', 'Easy booking', 'حجز سهل', 'Change dates or add services online.', 'عدّل المواعيد أو أضف خدمات عبر الإنترنت.'],
                    ['message', '24/7 reception', 'استقبال على مدار الساعة', 'Message us any time.', 'راسلنا في أي وقت.'],
                ]),
                self::stats([['120', 'Rooms', 'غرفة'], ['4.7', 'Guest rating', 'تقييم الضيوف'], ['24/7', 'Reception', 'استقبال']]),
                self::faq([['What are check-in and check-out times?', 'ما مواعيد الوصول والمغادرة؟', 'Check-in from 14:00 and check-out until 12:00. Ask us about early or late arrivals.', 'الوصول من الساعة 2 ظهرًا والمغادرة حتى 12 ظهرًا. اسألنا عن الوصول المبكر أو المتأخر.'], ['Can you arrange airport transfers?', 'هل يمكنكم ترتيب التوصيل من المطار؟', 'Yes, send us your flight details in advance.', 'نعم، أرسل لنا تفاصيل رحلتك مسبقًا.']]),
                self::contactSec('Plan your stay', 'خطّط لإقامتك'),
            ],
            ['About us', 'من نحن', 'We welcome guests the way we would like to be welcomed: warmly, honestly and without fuss.', 'نستقبل ضيوفنا كما نحب أن نُستقبل: بدفء وصدق ودون تكلّف.'],
            ['Warm welcome, thoughtful service.', 'استقبال دافئ وخدمة مدروسة.'],
            ['Hotel and travel website: rooms, experiences, guest portal and booking help.', 'موقع فندق وسياحة: غرف وتجارب وبوابة ضيوف ومساعدة في الحجز.']);

        $L['realestate'] = self::define('realestate', ['Real estate', 'عقارات'], ['Agencies and developers: services, process and enquiries.', 'الوكالات العقارية والمطورون: خدمات وإجراءات واستفسارات.'], 'realestate',
            ['primary' => '#0F766E', 'accent' => '#C2410C', 'background' => '#FFFFFF', 'font' => 'ibm-plex-sans-arabic', 'radius' => 'md'], 'split',
            ['Clients & agents', 'العملاء والوسطاء', 'Sign in to follow your property file', 'سجّل الدخول لمتابعة ملف عقارك'],
            [
                self::hero(['Find the place that fits your life', 'اعثر على المكان الذي يناسب حياتك'], ['Buying, selling and renting with honest advice and a clear process.', 'بيع وشراء وإيجار بنصيحة صادقة وإجراءات واضحة.'], ['Client sign-in', 'دخول العملاء']),
                self::stats([['500+', 'Properties sold', 'عقار مباع'], ['12', 'Years', 'سنة خبرة'], ['98%', 'Happy clients', 'عملاء راضون']]),
                self::features('Our services', 'خدماتنا', [
                    ['globe', 'Buy and sell', 'بيع وشراء', 'Valuation, marketing and negotiation.', 'تقييم وتسويق وتفاوض.'],
                    ['calendar', 'Rentals', 'إيجارات', 'Viewings and contracts handled for you.', 'نتولى المعاينات والعقود.'],
                    ['file', 'Paperwork', 'الأوراق', 'Documents tracked in one place.', 'المستندات متابَعة في مكان واحد.'],
                    ['video', 'Virtual viewings', 'معاينات افتراضية', 'See properties by video call.', 'شاهد العقارات عبر مكالمة فيديو.'],
                ]),
                self::steps('Step by step', 'خطوة بخطوة', [
                    ['Tell us what you need', 'أخبرنا بما تحتاج', 'Budget, area and timeline.', 'الميزانية والمنطقة والموعد.'],
                    ['View options', 'شاهد الخيارات', 'In person or online.', 'حضوريًا أو عن بُعد.'],
                    ['Close the deal', 'أتمم الصفقة', 'We guide you through contracts.', 'نرشدك خلال العقود.'],
                ]),
                self::faq([['Do you charge for viewings?', 'هل تتقاضون أجرًا على المعاينة؟', 'No. Viewings are free and without obligation.', 'لا. المعاينات مجانية دون أي التزام.'], ['Can I follow my file online?', 'هل أتابع ملفي عبر الإنترنت؟', 'Yes, from the client portal after signing in.', 'نعم، من بوابة العملاء بعد تسجيل الدخول.']]),
                self::contactSec('Talk to an agent', 'تحدّث مع وسيط'),
            ],
            ['About the agency', 'عن الوكالة', 'We are local property specialists. We advise honestly, even when it means saying no.', 'نحن متخصصون عقاريون محليون. ننصح بصدق حتى لو اضطررنا إلى قول «لا».'],
            ['Honest advice, clear process.', 'نصيحة صادقة وإجراءات واضحة.'],
            ['Real estate agency: buying, selling, renting, virtual viewings and client portal.', 'وكالة عقارية: بيع وشراء وإيجار ومعاينات افتراضية وبوابة عملاء.']);

        /* ---------- مجتمع وخدمات عامة ---------- */
        $L['charity'] = self::define('charity', ['Non-profit', 'جمعية خيرية'], ['Charities and NGOs: mission, impact, volunteering and giving.', 'الجمعيات والمنظمات: رسالة وأثر وتطوع وتبرع.'], 'nonprofit',
            ['primary' => '#7C3AED', 'accent' => '#F43F5E', 'background' => '#FAF5FF', 'font' => 'noto-kufi-arabic', 'radius' => 'xl'], 'split',
            ['Volunteers & staff', 'المتطوعون والعاملون', 'Sign in to join your team', 'سجّل الدخول للانضمام إلى فريقك'],
            [
                self::hero(['Together we make a difference', 'معًا نصنع فرقًا'], ['Volunteer, donate and follow the impact of our work.', 'تطوّع وتبرّع وتابع أثر عملنا.'], ['Volunteer sign-in', 'دخول المتطوعين']),
                self::stats([['12k', 'People helped', 'مستفيد'], ['300', 'Volunteers', 'متطوع'], ['25', 'Programmes', 'برنامجًا']]),
                self::features('What we do', 'ماذا نفعل', [
                    ['heart', 'Relief', 'الإغاثة', 'Support for families in need.', 'دعم للأسر المحتاجة.'],
                    ['book', 'Education', 'التعليم', 'Scholarships and learning programmes.', 'منح وبرامج تعليمية.'],
                    ['users', 'Community', 'المجتمع', 'Local projects led by volunteers.', 'مشروعات محلية يقودها المتطوعون.'],
                ]),
                self::steps('How to get involved', 'كيف تشارك', [
                    ['Sign up', 'سجّل', 'Tell us your interests and availability.', 'أخبرنا باهتماماتك ووقتك.'],
                    ['Get matched', 'نختار لك دورًا', 'We pair you with a team.', 'نوجّهك إلى فريق مناسب.'],
                    ['Make an impact', 'اصنع أثرًا', 'Join activities and track hours.', 'شارك في الأنشطة وتابع ساعاتك.'],
                ]),
                self::faq([['How are donations used?', 'كيف تُستخدم التبرعات؟', 'Donations go to our programmes, and we publish yearly reports.', 'تذهب التبرعات إلى برامجنا وننشر تقارير سنوية.'], ['Can I volunteer remotely?', 'هل أستطيع التطوع عن بُعد؟', 'Yes. Many roles are online.', 'نعم. كثير من الأدوار تتم عبر الإنترنت.']]),
                self::cta(['Join us', 'انضم إلينا'], ['Every hour and every contribution counts.', 'كل ساعة وكل مساهمة لها أثر.'], ['Contact us', 'تواصل معنا']),
            ],
            ['Our mission', 'رسالتنا', 'We work with communities to meet urgent needs and build long-term opportunity, openly and with respect.', 'نعمل مع المجتمعات لتلبية الاحتياجات العاجلة وبناء فرص طويلة الأمد، بشفافية واحترام.'],
            ['Together, we make a difference.', 'معًا نصنع فرقًا.'],
            ['Non-profit organisation: mission, programmes, volunteering and volunteer portal.', 'منظمة غير ربحية: رسالة وبرامج وتطوع وبوابة للمتطوعين.'], true);

        $L['government'] = self::define('government', ['Public sector', 'جهة حكومية'], ['Authorities and municipalities: services, announcements and help.', 'الهيئات والمحليات: خدمات وإعلانات ومساعدة.'], 'government',
            ['primary' => '#1E3A8A', 'accent' => '#CA8A04', 'background' => '#F8FAFC', 'font' => 'noto-sans-arabic', 'radius' => 'sm'], 'split',
            ['Employee & services portal', 'بوابة الموظفين والخدمات', 'Sign in with your official account', 'سجّل الدخول بحسابك الرسمي'],
            [
                self::hero(['Services that serve you', 'خدمات في خدمتك'], ['Official information, online services and secure internal collaboration.', 'معلومات رسمية وخدمات إلكترونية وتعاون داخلي آمن.'], ['Sign in', 'تسجيل الدخول']),
                self::features('Online services', 'الخدمات الإلكترونية', [
                    ['file', 'Applications', 'الطلبات', 'Submit and track requests online.', 'قدّم طلباتك وتابعها إلكترونيًا.'],
                    ['calendar', 'Appointments', 'المواعيد', 'Book a visit and avoid queues.', 'احجز زيارتك وتجنّب الطوابير.'],
                    ['message', 'Enquiries', 'الاستفسارات', 'Ask and receive official answers.', 'اسأل واحصل على إجابات رسمية.'],
                    ['lock', 'Secure access', 'دخول آمن', 'Your data is protected.', 'بياناتك محمية.'],
                ]),
                self::steps('How to use the services', 'كيف تستخدم الخدمات', [
                    ['Sign in', 'سجّل الدخول', 'With your official account.', 'بحسابك الرسمي.'],
                    ['Choose a service', 'اختر خدمة', 'Find the service you need.', 'اعثر على الخدمة المطلوبة.'],
                    ['Follow up', 'تابع الطلب', 'See the status at any time.', 'اطّلع على حالته في أي وقت.'],
                ]),
                self::faq([['Who can sign in?', 'من يستطيع الدخول؟', 'Registered employees and service users with an official account.', 'الموظفون المسجّلون ومستخدمو الخدمة الحاصلون على حساب رسمي.'], ['Where do I get help?', 'أين أجد المساعدة؟', 'Use the contact page or call the help desk.', 'استخدم صفحة التواصل أو اتصل بمكتب المساعدة.']]),
                self::contactSec('Help desk', 'مكتب المساعدة'),
            ],
            ['About the authority', 'عن الجهة', 'We provide public services with transparency, accountability and respect for every citizen.', 'نقدّم الخدمات العامة بشفافية ومساءلة واحترام لكل مواطن.'],
            ['Transparent, accessible public services.', 'خدمات عامة شفافة ومتاحة.'],
            ['Public authority website: online services, announcements and secure employee portal.', 'موقع جهة حكومية: خدمات إلكترونية وإعلانات وبوابة موظفين آمنة.'], true);

        $L['events'] = self::define('events', ['Events & conferences', 'فعاليات ومؤتمرات'], ['Conferences, exhibitions and meetups: agenda, speakers and registration.', 'المؤتمرات والمعارض واللقاءات: جدول وضيوف وتسجيل.'], 'events',
            ['primary' => '#6D28D9', 'accent' => '#F59E0B', 'background' => '#0F0A1F', 'surface' => '#1B1433', 'text' => '#F5F3FF', 'font' => 'poppins', 'radius' => 'xl', 'mode' => 'dark', 'heading_weight' => 'extrabold'], 'card',
            ['Attendee area', 'منطقة الحضور', 'Sign in to join sessions', 'سجّل الدخول للانضمام إلى الجلسات'],
            [
                self::hero(['The event everyone will talk about', 'الحدث الذي سيتحدث عنه الجميع'], ['Two days of talks, workshops and meetings, in person and online.', 'يومان من الجلسات وورش العمل واللقاءات، حضوريًا وعبر الإنترنت.'], ['Attendee sign-in', 'دخول الحضور'], 'center'),
                self::stats([['2', 'Days', 'يومان'], ['40', 'Speakers', 'متحدثًا'], ['1.5k', 'Attendees', 'مشاركًا']]),
                self::features('What to expect', 'ماذا ينتظرك', [
                    ['star', 'Keynotes', 'كلمات رئيسية', 'Leaders sharing what is next.', 'قادة يشاركون ما هو قادم.'],
                    ['users', 'Workshops', 'ورش عمل', 'Hands-on sessions in small groups.', 'جلسات تطبيقية في مجموعات صغيرة.'],
                    ['video', 'Live stream', 'بث مباشر', 'Follow sessions from anywhere.', 'تابع الجلسات من أي مكان.'],
                    ['message', 'Networking', 'التواصل', 'Meet people through chat and meetings.', 'تعرّف على الناس عبر المحادثات واللقاءات.'],
                ]),
                self::steps('Take part', 'شارك', [
                    ['Register', 'سجّل', 'Get your attendee account.', 'احصل على حساب الحضور.'],
                    ['Build your agenda', 'ابنِ جدولك', 'Pick sessions and set reminders.', 'اختر الجلسات وفعّل التذكيرات.'],
                    ['Join', 'انضم', 'Attend in person or online.', 'احضر بنفسك أو عن بُعد.'],
                ]),
                self::faq([['Can I attend online?', 'هل يمكنني الحضور عن بُعد؟', 'Yes, sessions are streamed to attendees who sign in.', 'نعم، تُبث الجلسات للحضور بعد تسجيل الدخول.'], ['Will sessions be recorded?', 'هل ستُسجَّل الجلسات؟', 'Yes, recordings are shared with attendees after the event.', 'نعم، تُشارك التسجيلات مع الحضور بعد الحدث.']]),
                self::cta(['Do not miss it', 'لا تفوّت الحدث'], ['Reserve your place today.', 'احجز مقعدك اليوم.'], ['Contact us', 'تواصل معنا']),
            ],
            ['About the event', 'عن الحدث', 'A gathering for people who build, teach and lead. Come to learn, connect and be inspired.', 'ملتقى لمن يبنون ويعلّمون ويقودون. تعال لتتعلم وتتواصل وتستلهم.'],
            ['Learn. Connect. Be inspired.', 'تعلّم. تواصل. استلهم.'],
            ['Event website: agenda, speakers, online attendance and attendee area.', 'موقع فعالية: جدول وضيوف وحضور عن بُعد ومنطقة للحضور.'], true);

        $L['news'] = self::define('news', ['News & media', 'أخبار وإعلام'], ['Publishers and media: the blog is on, with a newsroom workspace for staff.', 'الناشرون والإعلام: المدونة مفعّلة مع مساحة لغرفة الأخبار.'], 'media',
            ['primary' => '#B91C1C', 'accent' => '#111827', 'background' => '#FFFFFF', 'font' => 'noto-kufi-arabic', 'font_heading' => 'merriweather', 'radius' => 'none', 'heading_weight' => 'extrabold'], 'split',
            ['Newsroom sign-in', 'دخول غرفة الأخبار', 'For editors and contributors', 'للمحررين والمساهمين'],
            [
                self::hero(['Stories that matter', 'قصص تهمّك'], ['Independent reporting, and a secure newsroom for our team.', 'تغطية مستقلة وغرفة أخبار آمنة لفريقنا.'], ['Staff sign-in', 'دخول الفريق']),
                self::features('How we work', 'كيف نعمل', [
                    ['book', 'Accuracy first', 'الدقة أولًا', 'Every claim is checked before it runs.', 'كل معلومة تُراجع قبل نشرها.'],
                    ['users', 'Independent', 'مستقلون', 'Editorial decisions are ours alone.', 'القرار التحريري لنا وحدنا.'],
                    ['message', 'Newsroom chat', 'محادثات التحرير', 'Editors and reporters in one channel.', 'المحررون والمراسلون في قناة واحدة.'],
                    ['video', 'Editorial meetings', 'اجتماعات التحرير', 'Daily meetings, online or in person.', 'اجتماعات يومية، عن بُعد أو حضوريًا.'],
                ]),
                self::textSec('policy', ['Our standards', 'معاييرنا'], ['We publish corrections promptly, name our sources whenever we can, and keep advertising separate from editorial work.', 'ننشر التصحيحات فورًا ونذكر مصادرنا كلما أمكن ونفصل الإعلان عن العمل التحريري.']),
                self::cta(['Write for us', 'اكتب معنا'], ['Send us a story idea.', 'أرسل لنا فكرة قصة.'], ['Contact us', 'تواصل معنا']),
            ],
            ['About us', 'من نحن', 'An independent newsroom serving readers with accurate, fair and timely reporting.', 'غرفة أخبار مستقلة تخدم القرّاء بتغطية دقيقة ومنصفة وفي وقتها.'],
            ['Accurate. Fair. Independent.', 'دقيقة. منصفة. مستقلة.'],
            ['News and media site with a blog and a secure newsroom workspace.', 'موقع أخبار وإعلام مع مدونة ومساحة آمنة لغرفة الأخبار.'], true);

        return self::finish($L);
    }

    /** يضيف المفتاح لكل قالب. */
    private static function finish(array $list): array
    {
        foreach ($list as $k => &$t) {
            $t['key'] = $k;
        }
        return $list;
    }

    public static function find(string $key): ?array
    {
        return self::builtin()[$key] ?? null;
    }

    /** بيانات القالب للعرض في المعرض (من غير settings الكاملة). */
    public static function card(array $t): array
    {
        return ['key' => $t['key'], 'name' => $t['name'], 'description' => $t['description'], 'kind' => $t['kind'], 'preview' => $t['preview'], 'settings' => $t['settings'] ?? null, 'builtin' => true];
    }
}
