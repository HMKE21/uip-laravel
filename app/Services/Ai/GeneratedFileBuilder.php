<?php

namespace App\Services\Ai;

/**
 * بيحوّل محتوى كتبه الموديل (Markdown / CSV / كود) لملف حقيقي للتحميل:
 *   pdf  (mPDF — عربي RTL حقيقي)   docx (WordprocessingML بالإيد، من غير مكتبات)   xlsx (SpreadsheetML بالإيد)
 *   zip  (أرشيف فيه ملفات متعددة — كل ملف بعنوان "=== path/to/file.ext ===")
 *   csv md txt json html xml sql وملفات الكود → ملف نصي.
 * مفيش تنفيذ كود ولا شبكة: HTML الخام بيتشال، والصور بتتشال، والفورمولات الخطيرة بتتحوّل لنص.
 */
class GeneratedFileBuilder
{
    public const MAX_CHARS = 400000;
    private const ZIP_MAX_FILES = 150;

    /** ext => mime. النصي دايمًا text/plain (حتى html) عشان ماينفتحش كصفحة من نفس الأوريجن. */
    private const TYPES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'  => 'application/zip',
        'csv'  => 'text/csv', 'md' => 'text/markdown', 'json' => 'application/json',
        'txt' => 'text/plain', 'html' => 'text/plain', 'xml' => 'text/plain', 'sql' => 'text/plain', 'log' => 'text/plain',
        'tsv' => 'text/plain', 'toml' => 'text/plain', 'ini' => 'text/plain', 'env' => 'text/plain',
        'py' => 'text/plain', 'js' => 'text/plain', 'jsx' => 'text/plain', 'ts' => 'text/plain', 'tsx' => 'text/plain', 'vue' => 'text/plain',
        'php' => 'text/plain', 'java' => 'text/plain', 'kt' => 'text/plain', 'swift' => 'text/plain', 'rs' => 'text/plain',
        'c' => 'text/plain', 'cpp' => 'text/plain', 'cs' => 'text/plain', 'go' => 'text/plain', 'rb' => 'text/plain',
        'css' => 'text/plain', 'sh' => 'text/plain', 'yml' => 'text/plain', 'yaml' => 'text/plain',
    ];
    private const CODE = ['sql', 'py', 'js', 'jsx', 'ts', 'tsx', 'vue', 'php', 'java', 'kt', 'swift', 'rs', 'c', 'cpp', 'cs', 'go', 'rb', 'css', 'sh', 'yml', 'yaml', 'html', 'xml', 'json', 'toml', 'ini'];

    public static function supportedExtensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return array{0:string,1:string} [اسم بدون امتداد، امتداد] */
    public static function sanitizeName(string $name): array
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        if (!isset(self::TYPES[$ext])) {
            $base = $ext === '' ? $base : $base . '_' . $ext;
            $ext = 'md';
        }
        $base = trim((string) preg_replace('/[^\p{L}\p{N}\-_. ()]+/u', '_', $base), " ._");
        $base = mb_substr($base, 0, 80);
        return [$base !== '' ? $base : 'file', $ext];
    }

    public static function kindFor(string $ext): string
    {
        if ($ext === 'zip') {
            return 'archive';
        }
        return in_array($ext, self::CODE, true) ? 'code' : 'document';
    }

    /** لو المحتوى كله جوّه fence واحد (```lang … ```) نشيله — الموديل أحيانًا بيلف الملف بيه. */
    private static function unfence(string $content): string
    {
        if (preg_match('/^\s*```[^\n]*\n(.*)\n```\s*$/s', $content, $m) && !str_contains($m[1], "\n```")) {
            return $m[1];
        }
        return $content;
    }

    /** @return array{bytes:string,mime:string,ext:string,name:string,kind:string} */
    public static function build(string $name, string $content): array
    {
        [$base, $ext] = self::sanitizeName($name);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = self::unfence(trim($content, "\n"));
        $content = mb_substr($content, 0, self::MAX_CHARS);

        switch ($ext) {
            case 'docx':
                $bytes = self::htmlToDocx(self::markdownToHtml($content));
                break;
            case 'pdf':
                $bytes = self::markdownToPdf($content);
                break;
            case 'xlsx':
                $bytes = self::buildXlsx($content);
                break;
            case 'zip':
                $bytes = self::buildZipArchive($content);
                break;
            case 'csv':
                $bytes = (str_starts_with($content, "\xEF\xBB\xBF") ? '' : "\xEF\xBB\xBF") . $content; // BOM عشان Excel يقرا العربي
                break;
            default:
                $bytes = $content . "\n";
        }
        return ['bytes' => $bytes, 'mime' => self::TYPES[$ext], 'ext' => $ext, 'name' => $base . '.' . $ext, 'kind' => self::kindFor($ext)];
    }

    // ------------------------------------------------------------------ ZIP

    /**
     * محتوى الـ zip: كل ملف يبدأ بسطر عنوان "=== path/to/file.ext ===" وبعده محتواه.
     * docx/pdf/xlsx جوّه الأرشيف بتتبني كملفات حقيقية؛ الباقي نص خام. المسارات بتتنضّف (مفيش .. ولا / في الأول).
     */
    private static function buildZipArchive(string $content): string
    {
        $entries = [];
        $cur = null;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^={3,}\s*(.+?)\s*={3,}\s*$/u', $line, $m)) {
                if ($cur !== null) {
                    $entries[] = $cur;
                }
                $cur = ['path' => $m[1], 'lines' => []];
            } elseif ($cur !== null) {
                $cur['lines'][] = $line;
            }
        }
        if ($cur !== null) {
            $entries[] = $cur;
        }
        if (!$entries) {
            $entries[] = ['path' => 'README.txt', 'lines' => explode("\n", $content)];
        }

        $files = [];
        foreach (array_slice($entries, 0, self::ZIP_MAX_FILES) as $e) {
            $clean = [];
            foreach (explode('/', str_replace('\\', '/', (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $e['path']))) as $seg) {
                $seg = trim($seg);
                if ($seg === '' || $seg === '.' || $seg === '..') {
                    continue;
                }
                $clean[] = $seg;
            }
            $path = mb_substr(implode('/', $clean), 0, 150);
            if ($path === '' || isset($files[$path])) {
                continue;
            }
            $text = self::unfence(trim(implode("\n", $e['lines']), "\n"));
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['docx', 'pdf', 'xlsx'], true)) {
                try {
                    $files[$path] = self::build(basename($path), $text)['bytes'];
                    continue;
                } catch (\Throwable $ex) {
                    // نخزّنه كنص لو البناء فشل
                }
            }
            $files[$path] = $text . "\n";
        }
        return self::zip($files);
    }

    // ------------------------------------------------------------------ Markdown / HTML

    public static function markdownToHtml(string $md): string
    {
        $html = null;
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            try {
                $conv = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
                $out = $conv->convert($md);
                $html = method_exists($out, 'getContent') ? $out->getContent() : (string) $out;
            } catch (\Throwable $e) {
                $html = null;
            }
        }
        if ($html === null) {
            $paras = preg_split('/\n{2,}/', htmlspecialchars($md, ENT_QUOTES, 'UTF-8')) ?: [];
            $html = '<p>' . implode('</p><p>', array_map(fn ($p) => nl2br(trim($p)), $paras)) . '</p>';
        }
        return (string) preg_replace('#<(img|link|script|style|iframe|object|embed)\b[^>]*>(</\1>)?#is', '', $html);
    }

    private static function isRtl(string $text): bool
    {
        $ar = preg_match_all('/[\p{Arabic}\p{Hebrew}]/u', $text);
        $lat = preg_match_all('/[A-Za-z]/', $text);
        return $ar > 0 && $ar >= $lat;
    }

    private static function xmlText(string $s): string
    {
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------ PDF

    private static function markdownToPdf(string $md): string
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new \RuntimeException('mPDF is not installed.');
        }
        $html = self::markdownToHtml($md);
        $rtl = self::isRtl(strip_tags($html));
        $title = preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : 'Document';
        $tmp = sys_get_temp_dir() . '/mpdf_ai';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 18, 'margin_bottom' => 18,
            'default_font' => 'dejavusans', 'default_font_size' => 10.5,
            'tempDir' => $tmp, 'autoScriptToLang' => true, 'autoLangToFont' => true, 'useSubstitutions' => true,
        ]);
        if ($rtl) {
            $mpdf->SetDirectionality('rtl');
        }
        $mpdf->SetTitle(mb_substr($title, 0, 120));
        $mpdf->SetCreator('UIPE AI Assistant');
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $css = 'body{font-family:dejavusans;line-height:1.5;color:#1f2937;' . ($rtl ? 'direction:rtl;text-align:right;' : '') . '}'
            . 'h1{font-size:19pt;color:#1f3864;border-bottom:1px solid #cbd5e1;padding-bottom:3px}h2{font-size:15pt;color:#1f3864}h3{font-size:12.5pt;color:#1f3864}'
            . 'table{border-collapse:collapse;width:100%;margin:6px 0}th{background:#e8eefb;font-weight:bold}th,td{border:0.5pt solid #94a3b8;padding:4px 6px;font-size:9.5pt}'
            . 'pre,code{font-family:dejavusansmono;font-size:8.5pt;background:#f3f4f6}pre{padding:6px;border:0.4pt solid #e5e7eb;direction:ltr;text-align:left}'
            . 'blockquote{border-left:3px solid #94a3b8;margin-left:0;padding-left:10px;color:#475569}hr{border:0;border-top:0.5pt solid #cbd5e1}';
        $mpdf->WriteHTML($css, 1);
        $mpdf->WriteHTML($html, 2);
        return (string) $mpdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ DOCX

    public static function htmlToDocx(string $html): string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $dom->getElementsByTagName('body')->item(0);

        $xml = '';
        if ($body) {
            self::blocks($body, ['indent' => 0, 'quote' => false], $xml);
        }
        if ($xml === '') {
            $xml = self::para([['t' => '']], []);
        }

        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $xml
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Arial"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-US" w:eastAsia="en-US" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . self::headingStyle(1, 36) . self::headingStyle(2, 30) . self::headingStyle(3, 26) . self::headingStyle(4, 24) . self::headingStyle(5, 22) . self::headingStyle(6, 22)
            . '</w:styles>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => $styles,
        ]);
    }

    private static function headingStyle(int $n, int $size): string
    {
        return '<w:style w:type="paragraph" w:styleId="Heading' . $n . '"><w:name w:val="heading ' . $n . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:spacing w:before="' . ($n === 1 ? 360 : 240) . '" w:after="120"/><w:outlineLvl w:val="' . ($n - 1) . '"/></w:pPr>'
            . '<w:rPr><w:b/><w:bCs/><w:color w:val="1F3864"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr></w:style>';
    }

    /** @param array{indent:int,quote:bool} $ctx */
    private static function blocks(\DOMNode $parent, array $ctx, string &$out): void
    {
        foreach ($parent->childNodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE) {
                $t = trim($n->textContent);
                if ($t !== '') {
                    $out .= self::para([['t' => $t]], $ctx);
                }
                continue;
            }
            if ($n->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($n->nodeName);
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $out .= self::para(self::inline($n, []), $ctx + ['style' => 'Heading' . $m[1]]);
            } elseif ($tag === 'p') {
                $out .= self::para(self::inline($n, []), $ctx);
            } elseif ($tag === 'ul' || $tag === 'ol') {
                self::listBlock($n, $ctx, $out, 0);
            } elseif ($tag === 'pre') {
                foreach (explode("\n", rtrim($n->textContent, "\n")) as $line) {
                    $out .= self::para([['t' => $line, 'code' => true]], $ctx + ['shade' => true, 'ltr' => true, 'tight' => true]);
                }
                $out .= self::para([['t' => '']], ['tight' => true]);
            } elseif ($tag === 'blockquote') {
                self::blocks($n, ['indent' => $ctx['indent'] + 360, 'quote' => true], $out);
            } elseif ($tag === 'hr') {
                $out .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="BBBBBB"/></w:pBdr></w:pPr></w:p>';
            } elseif ($tag === 'table') {
                $out .= self::table($n);
            } elseif (in_array($tag, ['div', 'section', 'article', 'body'], true)) {
                self::blocks($n, $ctx, $out);
            } else {
                $runs = self::inline($n, []);
                if ($runs) {
                    $out .= self::para($runs, $ctx);
                }
            }
        }
    }

    private static function listBlock(\DOMNode $list, array $ctx, string &$out, int $level): void
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $i = 0;
        foreach ($list->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $i++;
            $prefix = $ordered ? $i . '. ' : ($level === 0 ? '• ' : '– ');
            $runs = [['t' => $prefix]];
            $nested = [];
            foreach ($li->childNodes as $c) {
                $cn = strtolower($c->nodeName);
                if ($cn === 'ul' || $cn === 'ol') {
                    $nested[] = $c;
                } elseif ($cn === 'p') {
                    $runs = array_merge($runs, self::inline($c, []), [['br' => true]]);
                } else {
                    $runs = array_merge($runs, self::inline($c, []));
                }
            }
            while ($runs && !empty(end($runs)['br'])) {
                array_pop($runs);
            }
            $out .= self::para($runs, ['indent' => $ctx['indent'] + 360 * ($level + 1), 'hang' => 260, 'quote' => $ctx['quote'] ?? false, 'tight' => true]);
            foreach ($nested as $nl) {
                self::listBlock($nl, $ctx, $out, $level + 1);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function inline(\DOMNode $node, array $fmt): array
    {
        $runs = [];
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = str_replace(["\n", "\t"], ' ', $c->textContent);
                if ($t !== '') {
                    $runs[] = ['t' => $t] + $fmt;
                }
            } elseif ($c->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($c->nodeName);
                if ($tag === 'br') {
                    $runs[] = ['br' => true];
                    continue;
                }
                $f = $fmt;
                if ($tag === 'strong' || $tag === 'b') {
                    $f['b'] = true;
                } elseif ($tag === 'em' || $tag === 'i') {
                    $f['i'] = true;
                } elseif ($tag === 'code') {
                    $f['code'] = true;
                } elseif ($tag === 'del' || $tag === 's') {
                    $f['s'] = true;
                } elseif ($tag === 'a') {
                    $f['link'] = true;
                } elseif (in_array($tag, ['ul', 'ol', 'table', 'pre'], true)) {
                    continue;
                }
                $runs = array_merge($runs, self::inline($c, $f));
            }
        }
        return $runs;
    }

    /** @param array<int,array<string,mixed>> $runs @param array<string,mixed> $ctx */
    private static function para(array $runs, array $ctx): string
    {
        $plain = '';
        foreach ($runs as $r) {
            $plain .= $r['t'] ?? '';
        }
        $rtl = empty($ctx['ltr']) && self::isRtl($plain);

        $ppr = '';
        if (!empty($ctx['style'])) {
            $ppr .= '<w:pStyle w:val="' . $ctx['style'] . '"/>';
        }
        $bdr = '';
        if (!empty($ctx['quote'])) {
            $bdr .= '<w:left w:val="single" w:sz="18" w:space="8" w:color="94A3B8"/>';
        }
        if ($bdr !== '') {
            $ppr .= '<w:pBdr>' . $bdr . '</w:pBdr>';
        }
        if (!empty($ctx['shade'])) {
            $ppr .= '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>';
        }
        if ($rtl) {
            $ppr .= '<w:bidi/>';
        }
        if (!empty($ctx['tight'])) {
            $ppr .= '<w:spacing w:after="' . (!empty($ctx['shade']) ? 0 : 40) . '"/>';
        }
        $indent = (int) ($ctx['indent'] ?? 0);
        if ($indent > 0 || !empty($ctx['hang'])) {
            $ppr .= '<w:ind w:left="' . $indent . '"' . (!empty($ctx['hang']) ? ' w:hanging="' . (int) $ctx['hang'] . '"' : '') . '/>';
        }

        $x = '';
        foreach ($runs as $r) {
            if (!empty($r['br'])) {
                $x .= '<w:r><w:br/></w:r>';
                continue;
            }
            $rpr = '';
            $mono = !empty($r['code']);
            $rpr .= $mono ? '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/>' : '';
            if (!empty($r['b'])) {
                $rpr .= '<w:b/><w:bCs/>';
            }
            if (!empty($r['i'])) {
                $rpr .= '<w:i/><w:iCs/>';
            }
            if (!empty($r['s'])) {
                $rpr .= '<w:strike/>';
            }
            if (!empty($r['link'])) {
                $rpr .= '<w:color w:val="1D4ED8"/>';
            }
            if ($mono) {
                $rpr .= '<w:sz w:val="19"/><w:szCs w:val="19"/>';
            }
            if (!empty($r['link'])) {
                $rpr .= '<w:u w:val="single"/>';
            }
            if ($rtl) {
                $rpr .= '<w:rtl/>';
            }
            $x .= '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . self::xmlText((string) ($r['t'] ?? '')) . '</w:t></w:r>';
        }
        return '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $x . '</w:p>';
    }

    private static function table(\DOMNode $table): string
    {
        $rows = [];
        foreach ((new \DOMXPath($table->ownerDocument))->query('.//tr', $table) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    $cells[] = ['runs' => self::inline($td, strtolower($td->nodeName) === 'th' ? ['b' => true] : []), 'th' => strtolower($td->nodeName) === 'th'];
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        if (!$rows) {
            return '';
        }
        $cols = max(array_map('count', $rows));
        $all = '';
        foreach ($rows as $r) {
            foreach ($r as $c) {
                foreach ($c['runs'] as $run) {
                    $all .= $run['t'] ?? '';
                }
            }
        }
        $rtl = self::isRtl($all);
        $w = (int) floor(9600 / max(1, $cols));
        $b = fn (string $side) => '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="94A3B8"/>';
        $x = '<w:tbl><w:tblPr>' . ($rtl ? '<w:bidiVisual/>' : '') . '<w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $b('top') . $b('left') . $b('bottom') . $b('right') . $b('insideH') . $b('insideV') . '</w:tblBorders><w:tblLook w:val="04A0"/></w:tblPr><w:tblGrid>'
            . str_repeat('<w:gridCol w:w="' . $w . '"/>', $cols) . '</w:tblGrid>';
        foreach ($rows as $r) {
            $isHead = $r[0]['th'];
            $x .= '<w:tr>' . ($isHead ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            for ($i = 0; $i < $cols; $i++) {
                $c = $r[$i] ?? ['runs' => [['t' => '']], 'th' => false];
                $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($c['th'] ? '<w:shd w:val="clear" w:color="auto" w:fill="E8EEFB"/>' : '') . '</w:tcPr>'
                    . self::para($c['runs'] ?: [['t' => '']], ['tight' => true]) . '</w:tc>';
            }
            $x .= '</w:tr>';
        }
        return $x . '</w:tbl>' . self::para([['t' => '']], ['tight' => true]);
    }

    // ------------------------------------------------------------------ XLSX

    /** @return array<int,array{name:string,rows:array<int,array<int,string>>}> */
    public static function parseSheets(string $content): array
    {
        $lines = explode("\n", $content);
        $sheets = [];
        $cur = null;
        $lastText = '';
        $flush = function () use (&$cur, &$sheets) {
            if ($cur && $cur['rows']) {
                $sheets[] = $cur;
            }
            $cur = null;
        };
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '' && $t[0] === '|') {
                if ($cur === null) {
                    $cur = ['name' => trim((string) preg_replace('/[#*_`|]+/', '', $lastText)), 'rows' => []];
                }
                if (preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $t)) {
                    continue; // سطر الفاصل بتاع الجدول
                }
                $t = preg_replace('/^\||\|$/', '', $t);
                $cells = array_map(fn ($c) => trim(str_replace('\|', '|', $c)), preg_split('/(?<!\\\\)\|/', (string) $t) ?: []);
                $cur['rows'][] = $cells;
            } else {
                $flush();
                if ($t !== '') {
                    $lastText = mb_strlen($t) <= 60 ? $t : '';
                }
            }
        }
        $flush();
        if ($sheets) {
            return array_slice($sheets, 0, 10);
        }
        // CSV
        $content = trim(str_replace("\xEF\xBB\xBF", '', $content));
        if ($content === '') {
            return [['name' => 'Sheet1', 'rows' => [['']]]];
        }
        $first = strtok($content, "\n") ?: '';
        $best = ',';
        $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $bestN) {
                $bestN = $n;
                $best = $d;
            }
        }
        $rows = [];
        foreach (explode("\n", $content) as $line) {
            if (trim($line) !== '') {
                $rows[] = array_map('strval', str_getcsv($line, $best, '"', '\\'));
            }
        }
        return [['name' => 'Sheet1', 'rows' => $rows]];
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function safeFormula(string $v): bool
    {
        return (bool) preg_match('/^=[A-Za-z0-9_(),.:+\-*\/\s$"<>=&%;]+$/', $v)
            && !preg_match('/HYPERLINK|WEBSERVICE|FILTERXML|EXEC|CALL|REGISTER|RTD|DDE|IMPORT|INDIRECT|\bCMD\b/i', $v);
    }

    public static function buildXlsx(string $content): string
    {
        $sheets = self::parseSheets($content);
        $used = [];
        $sheetXml = [];
        $names = [];
        foreach ($sheets as $idx => $sh) {
            $name = trim((string) preg_replace('/[\\\\\/*?:\[\]]+/', ' ', $sh['name']));
            $name = mb_substr($name !== '' ? $name : 'Sheet' . ($idx + 1), 0, 31);
            $orig = $name;
            for ($k = 2; in_array(mb_strtolower($name), $used, true); $k++) {
                $name = mb_substr($orig, 0, 28) . ' ' . $k;
            }
            $used[] = mb_strtolower($name);
            $names[] = $name;

            $rows = array_slice($sh['rows'], 0, 5000);
            $ncols = min(100, max(1, ...array_map('count', $rows ?: [['']])));
            $widths = array_fill(0, $ncols, 8);
            $all = '';
            $data = '';
            foreach ($rows as $r => $cells) {
                $data .= '<row r="' . ($r + 1) . '">';
                for ($c = 0; $c < $ncols; $c++) {
                    $v = (string) ($cells[$c] ?? '');
                    $all .= $v;
                    $widths[$c] = max($widths[$c], min(60, mb_strlen($v) + 3));
                    $ref = self::colName($c) . ($r + 1);
                    $s = $r === 0 ? 1 : 2;
                    if ($v === '') {
                        $data .= '<c r="' . $ref . '" s="' . $s . '"/>';
                    } elseif (preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v)) {
                        $data .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                    } elseif ($v[0] === '=' && self::safeFormula($v)) {
                        $data .= '<c r="' . $ref . '" s="' . $s . '"><f>' . self::xmlText(substr($v, 1)) . '</f></c>';
                    } else {
                        $data .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::xmlText($v) . '</t></is></c>';
                    }
                }
                $data .= '</row>';
            }
            $cols = '';
            foreach ($widths as $c => $w) {
                $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $sheetXml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"'
                . (self::isRtl($all) ? ' rightToLeft="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>' . $cols . '</cols><sheetData>' . $data . '</sheetData></worksheet>';
        }

        $files = [];
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        foreach ($sheetXml as $i => $xml) {
            $n = $i + 1;
            $files["xl/worksheets/sheet{$n}.xml"] = $xml;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::xmlText($names[$i]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $sn = count($sheetXml) + 1;
        $wbRels .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files['[Content_Types].xml'] = $ct . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets><calcPr fullCalcOnLoad="1"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>';
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9E2F3"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return self::zip($files);
    }

    /** @param array<string,string> $files */
    private static function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uipz');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Could not create the archive.');
        }
        foreach ($files as $name => $data) {
            $zip->addFromString((string) $name, $data);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
