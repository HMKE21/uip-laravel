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
    public const PAGE_KINDS = ['custom', 'about', 'contact'];
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
            'layout' => ['login_style' => 'enum:split|centered|card', 'nav_style' => 'enum:top|side', 'show_footer' => 'bool', 'hero_image_url' => 'img'],
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
            'layout' => ['login_style' => 'split', 'nav_style' => 'top', 'show_footer' => true, 'hero_image_url' => null],
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

    /** @return array<string,array{key:string,name:array,description:array,kind:string,preview:array,settings:array}> */
    public static function builtin(): array
    {
        $features = fn (string $id = 'features') => self::sec($id, 'features', ['title' => self::i('Everything in one place', 'كل حاجة في مكان واحد'), 'items' => [
            ['icon' => 'video', 'title' => self::i('Meetings', 'اجتماعات'), 'text' => self::i('HD video meetings with screen sharing.', 'اجتماعات فيديو بجودة عالية ومشاركة شاشة.')],
            ['icon' => 'message', 'title' => self::i('Chat', 'محادثات'), 'text' => self::i('Direct and group conversations.', 'محادثات فردية وجماعية.')],
            ['icon' => 'shield', 'title' => self::i('Secure', 'آمن'), 'text' => self::i('Your data is isolated and protected.', 'بياناتك معزولة ومحمية.')],
        ]]);

        $list = [
            'classic' => [
                'name' => self::i('Classic', 'كلاسيك'), 'description' => self::i('Split login with a clean landing page.', 'تسجيل دخول مقسوم مع صفحة رئيسية نظيفة.'), 'kind' => 'any',
                'preview' => ['primary' => '#4F46E5', 'accent' => '#F59E0B', 'background' => '#FFFFFF'],
                'settings' => self::make(['theme' => ['primary' => '#4F46E5', 'accent' => '#F59E0B', 'font' => 'inter', 'radius' => 'md'], 'layout' => ['login_style' => 'split'],
                    'texts' => ['login_title' => self::i('Welcome back', 'أهلًا بعودتك'), 'login_subtitle' => self::i('Sign in to continue', 'سجّل دخولك للمتابعة')],
                    'home' => ['sections' => [
                        self::sec('hero', 'hero', ['title' => self::i('Work together, anywhere', 'اشتغلوا مع بعض من أي مكان'), 'subtitle' => self::i('Meetings, chat and files in one workspace.', 'اجتماعات ومحادثات وملفات في مساحة واحدة.'), 'cta_label' => self::i('Get started', 'ابدأ دلوقتي'), 'cta_url' => '/login', 'align' => 'start']),
                        $features(), self::sec('cta', 'cta', ['title' => self::i('Ready to join?', 'جاهز تنضم؟'), 'button_label' => self::i('Sign in', 'تسجيل الدخول'), 'button_url' => '/login']),
                    ]]]),
            ],
            'minimal' => [
                'name' => self::i('Minimal', 'بسيط'), 'description' => self::i('Centered login, calm colours, almost no extras.', 'تسجيل دخول في النص وألوان هادية من غير زحمة.'), 'kind' => 'any',
                'preview' => ['primary' => '#111827', 'accent' => '#10B981', 'background' => '#FAFAFA'],
                'settings' => self::make(['theme' => ['primary' => '#111827', 'accent' => '#10B981', 'background' => '#FAFAFA', 'font' => 'system', 'radius' => 'sm', 'density' => 'spacious'], 'layout' => ['login_style' => 'centered', 'show_footer' => false],
                    'home' => ['sections' => [self::sec('hero', 'hero', ['title' => self::i('Welcome', 'أهلًا بيك'), 'subtitle' => self::i('Sign in to your workspace.', 'سجّل دخولك لمساحة العمل.'), 'align' => 'center'])]]]),
            ],
            'bold' => [
                'name' => self::i('Bold dark', 'داكن جريء'), 'description' => self::i('Dark theme, large hero, vivid accent.', 'ثيم داكن وهيرو كبير ولون مميز.'), 'kind' => 'company',
                'preview' => ['primary' => '#8B5CF6', 'accent' => '#22D3EE', 'background' => '#0B1020'],
                'settings' => self::make(['theme' => ['primary' => '#8B5CF6', 'accent' => '#22D3EE', 'background' => '#0B1020', 'surface' => '#141B34', 'text' => '#F3F4F6', 'font' => 'poppins', 'radius' => 'xl', 'mode' => 'dark'], 'layout' => ['login_style' => 'card'],
                    'home' => ['sections' => [
                        self::sec('hero', 'hero', ['title' => self::i('Meet without limits', 'اجتماعات من غير حدود'), 'subtitle' => self::i('Fast, private and built for your team.', 'سريعة وخاصة ومصممة لفريقك.'), 'cta_label' => self::i('Start now', 'ابدأ الآن'), 'cta_url' => '/login', 'align' => 'center']),
                        self::sec('stats', 'stats', ['items' => [['value' => '99.9%', 'label' => self::i('Uptime', 'جاهزية')], ['value' => '24/7', 'label' => self::i('Support', 'دعم')], ['value' => 'HD', 'label' => self::i('Video', 'فيديو')]]]),
                        $features(),
                    ]]]),
            ],
            'campus' => [
                'name' => self::i('Campus', 'جامعة'), 'description' => self::i('For universities: stats, steps and FAQ.', 'للجامعات: أرقام وخطوات وأسئلة شائعة.'), 'kind' => 'university',
                'preview' => ['primary' => '#0F766E', 'accent' => '#EAB308', 'background' => '#F8FAFC'],
                'settings' => self::make(['theme' => ['primary' => '#0F766E', 'accent' => '#EAB308', 'background' => '#F8FAFC', 'font' => 'cairo', 'radius' => 'lg'], 'layout' => ['login_style' => 'split'],
                    'texts' => ['login_title' => self::i('Student & staff portal', 'بوابة الطلاب والهيئة'), 'login_subtitle' => self::i('Use your university account', 'استخدم حسابك الجامعي')],
                    'home' => ['sections' => [
                        self::sec('hero', 'hero', ['title' => self::i('Your campus, online', 'جامعتك أونلاين'), 'subtitle' => self::i('Lectures, assignments and meetings in one place.', 'محاضرات وتكليفات واجتماعات في مكان واحد.'), 'cta_label' => self::i('Sign in', 'تسجيل الدخول'), 'cta_url' => '/login', 'align' => 'start']),
                        self::sec('stats', 'stats', ['items' => [['value' => '12', 'label' => self::i('Faculties', 'كلية')], ['value' => '20k', 'label' => self::i('Students', 'طالب')], ['value' => '1k', 'label' => self::i('Staff', 'عضو هيئة')]]]),
                        self::sec('steps', 'steps', ['title' => self::i('How to start', 'إزاي تبدأ'), 'items' => [['title' => self::i('Sign in', 'سجّل دخولك'), 'text' => self::i('Use the account from your university.', 'بحساب جامعتك.')], ['title' => self::i('Join your courses', 'انضم لموادك'), 'text' => self::i('Everything is listed for you.', 'كل المواد قدامك.')]]]),
                        self::sec('faq', 'faq', ['title' => self::i('Questions', 'أسئلة شائعة'), 'items' => [['q' => self::i('I forgot my password', 'نسيت كلمة السر'), 'a' => self::i('Use "Forgot password" on the sign-in page.', 'استخدم "نسيت كلمة السر" من صفحة الدخول.')]]]),
                    ]]]),
            ],
            'business' => [
                'name' => self::i('Business', 'شركات'), 'description' => self::i('Features, partner logos, call to action and contact.', 'مميزات وشعارات شركاء ودعوة للإجراء وتواصل.'), 'kind' => 'company',
                'preview' => ['primary' => '#2563EB', 'accent' => '#EF4444', 'background' => '#FFFFFF'],
                'settings' => self::make(['theme' => ['primary' => '#2563EB', 'accent' => '#EF4444', 'font' => 'ibm-plex-sans-arabic', 'radius' => 'md'], 'layout' => ['login_style' => 'split'],
                    'nav' => [['label' => self::i('Contact', 'تواصل'), 'url' => '/login', 'new_tab' => false]],
                    'home' => ['sections' => [
                        self::sec('hero', 'hero', ['title' => self::i('One place for your whole team', 'مكان واحد لفريقك كله'), 'subtitle' => self::i('Secure meetings and collaboration.', 'اجتماعات وتعاون آمن.'), 'cta_label' => self::i('Sign in', 'تسجيل الدخول'), 'cta_url' => '/login', 'align' => 'start']),
                        $features(), self::sec('logos', 'logos', ['title' => self::i('Trusted by', 'موثوق من'), 'items' => []]),
                        self::sec('contact', 'contact', ['title' => self::i('Get in touch', 'تواصل معنا'), 'email' => '', 'phone' => '']),
                    ]]]),
            ],
        ];
        // صفحات جاهزة (من نحن / تواصل) بتتضاف لبعض القوالب. العميل يعدّلها أو يحذفها.
        $about = fn () => ['id' => 'about', 'slug' => 'about', 'kind' => 'about', 'enabled' => true, 'show_in_nav' => true, 'title' => self::i('About us', 'من نحن'), 'seo' => [],
            'sections' => [
                self::sec('intro', 'text', ['title' => self::i('Who we are', 'احنا مين'), 'body' => self::i('Tell visitors about your story, mission and values.', 'احكي لزوارك عن قصتكم ورسالتكم وقيمكم.')]),
                self::sec('team', 'team', ['title' => self::i('Our team', 'فريقنا'), 'items' => []]),
            ]];
        $contact = fn () => ['id' => 'contact', 'slug' => 'contact', 'kind' => 'contact', 'enabled' => true, 'show_in_nav' => true, 'title' => self::i('Contact', 'تواصل معنا'), 'seo' => [],
            'sections' => [self::sec('contact', 'contact', ['title' => self::i('Send us a message', 'ابعتلنا رسالة'), 'email' => '', 'phone' => '', 'form' => true])]];
        foreach (['classic', 'bold', 'campus', 'business'] as $k) {
            $list[$k]['settings']['pages'] = [$about(), $contact()];
        }

        $list['startup'] = [
            'name' => self::i('Startup', 'ستارت أب'), 'description' => self::i('Modern product landing with steps and logos.', 'صفحة منتج عصرية مع خطوات وشعارات.'), 'kind' => 'company',
            'preview' => ['primary' => '#0EA5E9', 'accent' => '#F97316', 'background' => '#FFFFFF'],
            'settings' => self::make(['theme' => ['primary' => '#0EA5E9', 'accent' => '#F97316', 'font' => 'dm-sans', 'font_heading' => 'space-grotesk', 'radius' => 'lg', 'heading_weight' => 'extrabold'], 'layout' => ['login_style' => 'card'],
                'home' => ['sections' => [
                    self::sec('hero', 'hero', ['title' => self::i('Ship faster together', 'اشتغلوا أسرع مع بعض'), 'subtitle' => self::i('Meetings, chat and files without the clutter.', 'اجتماعات ومحادثات وملفات من غير زحمة.'), 'cta_label' => self::i('Get started', 'ابدأ دلوقتي'), 'cta_url' => '/login', 'align' => 'center']),
                    self::sec('logos', 'logos', ['title' => self::i('Teams that trust us', 'فرق بتثق فينا'), 'items' => []]),
                    $features(),
                    self::sec('steps', 'steps', ['title' => self::i('Up and running in minutes', 'شغّال في دقايق'), 'items' => [['title' => self::i('Sign in', 'سجّل دخولك'), 'text' => self::i('Use your work account.', 'بحساب الشغل.')], ['title' => self::i('Invite your team', 'ادعي فريقك'), 'text' => self::i('Everyone joins in one click.', 'الكل ينضم بضغطة.')]]]),
                    self::sec('cta', 'cta', ['title' => self::i('Ready to start?', 'جاهز تبدأ؟'), 'button_label' => self::i('Sign in', 'تسجيل الدخول'), 'button_url' => '/login']),
                ]], 'pages' => [$about(), $contact()]]),
        ];
        $list['academy'] = [
            'name' => self::i('Academy', 'أكاديمية'), 'description' => self::i('Training centres and schools: numbers, questions and contact.', 'مراكز التدريب والمدارس: أرقام وأسئلة وتواصل.'), 'kind' => 'university',
            'preview' => ['primary' => '#1D4ED8', 'accent' => '#F59E0B', 'background' => '#F8FAFC'],
            'settings' => self::make(['theme' => ['primary' => '#1D4ED8', 'accent' => '#F59E0B', 'background' => '#F8FAFC', 'font' => 'almarai', 'font_heading' => 'el-messiri', 'radius' => 'lg'], 'layout' => ['login_style' => 'split'],
                'texts' => ['login_title' => self::i('Learner portal', 'بوابة الدارسين'), 'login_subtitle' => self::i('Sign in with your academy account', 'سجّل بحساب الأكاديمية')],
                'home' => ['sections' => [
                    self::sec('hero', 'hero', ['title' => self::i('Learn at your own pace', 'اتعلّم بسرعتك'), 'subtitle' => self::i('Live classes, recordings and assignments in one place.', 'حصص مباشرة وتسجيلات وتكليفات في مكان واحد.'), 'cta_label' => self::i('Sign in', 'تسجيل الدخول'), 'cta_url' => '/login', 'align' => 'start']),
                    self::sec('stats', 'stats', ['items' => [['value' => '50+', 'label' => self::i('Courses', 'كورس')], ['value' => '5k', 'label' => self::i('Learners', 'دارس')], ['value' => '4.8', 'label' => self::i('Rating', 'تقييم')]]]),
                    $features(),
                    self::sec('faq', 'faq', ['title' => self::i('Questions', 'أسئلة شائعة'), 'items' => [['q' => self::i('How do I join a class?', 'إزاي أدخل الحصة؟'), 'a' => self::i('Open your schedule and press Join.', 'افتح جدولك واضغط انضمام.')]]]),
                    self::sec('contact', 'contact', ['title' => self::i('Need help?', 'محتاج مساعدة؟'), 'email' => '', 'phone' => '', 'form' => true]),
                ]], 'pages' => [$about(), $contact()], 'blog' => ['enabled' => true]]),
        ];
        $list['elegant'] = [
            'name' => self::i('Elegant', 'أنيق'), 'description' => self::i('Warm serif look for clinics, studios and boutiques.', 'شكل دافئ بخطوط serif للعيادات والاستوديوهات والبوتيكات.'), 'kind' => 'any',
            'preview' => ['primary' => '#7C2D12', 'accent' => '#B45309', 'background' => '#FFFBEB'],
            'settings' => self::make(['theme' => ['primary' => '#7C2D12', 'accent' => '#B45309', 'background' => '#FFFBEB', 'surface' => '#FFFFFF', 'text' => '#1C1917', 'font' => 'lora', 'font_heading' => 'playfair-display', 'radius' => 'sm', 'density' => 'spacious', 'mode' => 'light'],
                'layout' => ['login_style' => 'centered'],
                'home' => ['sections' => [
                    self::sec('hero', 'hero', ['title' => self::i('Quietly better', 'أهدى وأحسن'), 'subtitle' => self::i('A calm workspace for people who value details.', 'مساحة هادية لناس بتهتم بالتفاصيل.'), 'cta_label' => self::i('Sign in', 'تسجيل الدخول'), 'cta_url' => '/login', 'align' => 'center']),
                    self::sec('text', 'text', ['title' => self::i('Our story', 'قصتنا'), 'body' => self::i('Share what makes you different.', 'احكي إيه اللي مميزك.')]),
                    self::sec('gallery', 'gallery', ['title' => self::i('Gallery', 'معرض الصور'), 'items' => []]),
                    self::sec('contact', 'contact', ['title' => self::i('Visit or write to us', 'زورنا أو اكتبلنا'), 'email' => '', 'phone' => '', 'form' => true]),
                ]], 'pages' => [$about()]]),
        ];

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
