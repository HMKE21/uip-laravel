<?php

namespace App\Support;

/**
 * فحص SVG وقت الرفع. الـ SVG بيتخدم من نفس الأوريجن، وممكن يحمل كود (script، on*=، javascript:،
 * foreignObject، animate/set بتغيّر href، روابط خارجية...) بيتنفّذ لما حد يفتح الرابط مباشرة = XSS.
 * بنفحصه بـ DOM parser (مش regex على النص الخام) وبنرفض أي محتوى نشط بدل ما نحاول ننضّفه:
 * السلوك الواضح أأمن من تنظيف ممكن يفوّت حالة.
 *
 * مسموح: أشكال/ألوان/gradients/filters/نص/روابط داخلية (#id)/صور data:image/png|jpeg|gif|webp.
 */
final class SvgGuard
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'frame', 'object', 'embed', 'applet', 'audio', 'video', 'canvas',
        'animate', 'set', 'animatemotion', 'animatetransform', 'handler', 'listener', 'meta', 'link', 'base',
        'html', 'body', 'form', 'input', 'button', 'textarea', 'select', 'portal', 'fetch',
    ];

    /** @throws \RuntimeException برسالة آمنة للعرض */
    public static function assertSafe(string $content): void
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            self::fail('The SVG is empty or larger than 2MB.');
        }
        // بنقبل UTF-8 بس (UTF-16/BOM tricks بتخبّي المحتوى عن أي فحص نصي).
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF") || str_contains(substr($content, 0, 4), "\0")) {
            self::fail('Only UTF-8 SVG files are accepted.');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            self::fail('Only UTF-8 SVG files are accepted.');
        }
        // DOCTYPE/ENTITY = XXE و billion-laughs؛ SVG سليم مش محتاجهم.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY|ELEMENT|ATTLIST)/i', $content)) {
            self::fail('SVG files with a DOCTYPE or entity declarations are not accepted.');
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // من غير LIBXML_NOENT ولا LIBXML_DTDLOAD: مفيش قراءة لملفات/شبكة.
        $ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->documentElement;
        if (!$ok || !$root || strtolower($root->localName) !== 'svg') {
            self::fail('That file is not a well-formed SVG.');
        }

        foreach ($doc->childNodes as $n) {
            if ($n instanceof \DOMProcessingInstruction) {
                self::fail('SVG processing instructions (e.g. xml-stylesheet) are not accepted.');
            }
        }

        self::walk($root);
    }

    public static function isSafe(string $content): bool
    {
        try {
            self::assertSafe($content);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function walk(\DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                self::fail('SVG processing instructions are not accepted.');
            }
            if ($child instanceof \DOMCdataSection || $child instanceof \DOMText) {
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue;
            }

            $name = strtolower($child->localName);
            if (in_array($name, self::FORBIDDEN_ELEMENTS, true)) {
                self::fail("SVG contains active content (<{$name}>), which is not allowed.");
            }
            // عناصر من namespace غير SVG (xhtml مثلًا) = طريق لتنفيذ HTML جوه SVG.
            $ns = (string) $child->namespaceURI;
            if ($ns !== '' && $ns !== 'http://www.w3.org/2000/svg' && !self::isHarmlessNamespace($ns)) {
                self::fail('SVG contains elements from a foreign namespace, which is not allowed.');
            }

            if ($name === 'style') {
                self::checkCss($child->textContent);
            }

            foreach ($child->attributes as $attr) {
                self::checkAttribute($name, strtolower($attr->nodeName), (string) $attr->nodeValue);
            }

            self::walk($child);
        }

        if ($node instanceof \DOMElement) {
            foreach ($node->attributes as $attr) {
                self::checkAttribute(strtolower($node->localName), strtolower($attr->nodeName), (string) $attr->nodeValue);
            }
        }
    }

    /** namespaces بتطلع من محررات (Inkscape/Illustrator) ومش بتنفّذ حاجة. */
    private static function isHarmlessNamespace(string $ns): bool
    {
        foreach (['inkscape.org', 'sodipodi.sourceforge.net', 'ns.adobe.com', 'purl.org/dc', 'creativecommons.org', 'w3.org/1999/02/22-rdf', 'w3.org/1999/xlink'] as $ok) {
            if (str_contains($ns, $ok)) {
                return true;
            }
        }
        return false;
    }

    private static function checkAttribute(string $element, string $attr, string $value): void
    {
        $local = str_contains($attr, ':') ? substr($attr, strrpos($attr, ':') + 1) : $attr;

        if (str_starts_with($local, 'on')) {
            self::fail('SVG event-handler attributes (on*) are not allowed.');
        }

        $clean = self::normalize($value);

        if ($local === 'href' || $local === 'src') {
            $isData = (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $clean);
            // مرجع داخلي (#id) أو صورة data: مضمّنة بس — أي رابط خارجي أو javascript: مرفوض.
            if (!($clean === '' || str_starts_with($clean, '#') || ($isData && $element === 'image'))) {
                self::fail('SVG links must point inside the file (#id) — external or script links are not allowed.');
            }
        }

        if ($local === 'style') {
            self::checkCss($value);
        }

        if (preg_match('/javascript:|vbscript:|data:text|data:application|data:image\/svg/i', $clean)) {
            self::fail('SVG contains a script URL, which is not allowed.');
        }
        if (str_contains($clean, 'url(') && self::hasExternalUrl($clean)) {
            self::fail('SVG references an external resource, which is not allowed.');
        }
    }

    private static function checkCss(string $css): void
    {
        $c = self::normalize($css);
        if (preg_match('/@import|expression\(|behavior\s*:|-moz-binding|javascript:|vbscript:/i', $c)) {
            self::fail('SVG styles contain active or external content, which is not allowed.');
        }
        if (self::hasExternalUrl($c)) {
            self::fail('SVG styles reference an external resource, which is not allowed.');
        }
    }

    /** أي url(...) مش بيشاور على #fragment أو data:image/(png|jpeg|gif|webp). */
    private static function hasExternalUrl(string $s): bool
    {
        if (!preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $s, $m)) {
            return false;
        }
        foreach ($m[2] as $target) {
            $t = trim($target);
            if (str_starts_with($t, '#') || preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $t)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** بيشيل المسافات/control chars (java\tscript:) وبيفك CSS escapes الشائعة قبل المطابقة. */
    private static function normalize(string $v): string
    {
        $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = preg_replace_callback('/\\\\([0-9a-f]{1,6})\s?/i', fn ($m) => mb_chr(hexdec($m[1]) ?: 0x20, 'UTF-8'), $v) ?? $v;
        $v = preg_replace('/[\x00-\x20\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]+/u', '', $v) ?? $v;
        return strtolower($v);
    }

    private static function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
