<?php

namespace App\Services\Export;

/**
 * كاتب تقارير "مستندية" بصيغ زيادة على الأربعة الأساسية (csv/xlsx/pdf/json):
 * docx (Word حقيقي عبر ZipArchive — من غير مكتبات)، html، md، txt، xml.
 * نفس شكل الداتا بتاع PdfWriter::writeReport(): $title, $meta (key => value),
 * $sections [['title','header','rows']]. $rtl = true لما اللغة عربي.
 */
final class ReportDocumentWriter
{
    public const FORMATS = ['docx', 'html', 'md', 'txt', 'xml'];

    public static function mime(string $format): string
    {
        return [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'html' => 'text/html; charset=utf-8',
            'md'   => 'text/markdown; charset=utf-8',
            'txt'  => 'text/plain; charset=utf-8',
            'xml'  => 'application/xml; charset=utf-8',
        ][$format] ?? 'application/octet-stream';
    }

    public static function write(string $format, string $path, string $title, array $meta, array $sections, bool $rtl): void
    {
        switch ($format) {
            case 'docx':
                self::docx($path, $title, $meta, $sections, $rtl);
                return;
            case 'html':
                file_put_contents($path, self::html($title, $meta, $sections, $rtl));
                return;
            case 'md':
                file_put_contents($path, self::markdown($title, $meta, $sections));
                return;
            case 'txt':
                file_put_contents($path, "\xEF\xBB\xBF" . self::text($title, $meta, $sections));
                return;
            case 'xml':
                file_put_contents($path, self::xml($title, $meta, $sections));
                return;
        }
        throw new \RuntimeException('Unsupported report format.');
    }

    private static function s($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        // شيل محارف التحكم اللي بتبوّظ XML.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v) ?? '';
    }

    private static function esc($v): string
    {
        return htmlspecialchars(self::s($v), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // -- HTML ------------------------------------------------------------

    private static function html(string $title, array $meta, array $sections, bool $rtl): string
    {
        $dir = $rtl ? 'rtl' : 'ltr';
        $lang = $rtl ? 'ar' : 'en';
        $L = $rtl
            ? ['search' => 'ابحث داخل التقرير…', 'print' => 'طباعة', 'theme' => 'الوضع', 'sections' => 'الأقسام', 'rows' => 'صف', 'none' => 'لا توجد نتائج مطابقة.', 'empty' => 'لا توجد بيانات.', 'top' => 'للأعلى', 'info' => 'معلومات التقرير']
            : ['search' => 'Search in report…', 'print' => 'Print', 'theme' => 'Theme', 'sections' => 'Sections', 'rows' => 'rows', 'none' => 'No matching results.', 'empty' => 'No data.', 'top' => 'Top', 'info' => 'Report details'];

        $css = <<<'CSS'
:root{color-scheme:light;--bg:#f5f6f8;--card:#fff;--ink:#111827;--mut:#6b7280;--line:#e3e6ea;--th:#f3f4f6;--zebra:#fafafb;--pri:#1f4e79;--ring:rgba(31,78,121,.20);--hero:#14202f;--sb:rgba(17,24,39,.22);--sbh:rgba(17,24,39,.42);--mark:#fde68a;--markink:#111}
:root[data-theme=dark]{color-scheme:dark;--bg:#0e1116;--card:#161b22;--ink:#e6e9ee;--mut:#8b949e;--line:#262d36;--th:#1b222b;--zebra:#12171d;--pri:#7fb0e6;--ring:rgba(127,176,230,.28);--hero:#161b22;--sb:rgba(230,233,238,.24);--sbh:rgba(230,233,238,.44);--mark:rgba(250,204,21,.32);--markink:#fff}
*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:96px}
body{margin:0;background:var(--bg);color:var(--ink);font-family:"Segoe UI",system-ui,-apple-system,Tahoma,Arial,sans-serif;line-height:1.6;-webkit-font-smoothing:antialiased}
html::-webkit-scrollbar,*::-webkit-scrollbar{width:12px;height:12px;background:transparent}
*::-webkit-scrollbar-track{background:transparent}*::-webkit-scrollbar-corner{background:transparent}
*::-webkit-scrollbar-button{display:none;width:0;height:0}
*::-webkit-scrollbar-thumb{background:var(--sb);background-clip:content-box;border:3px solid transparent;border-radius:999px;min-height:40px;min-width:40px}
*::-webkit-scrollbar-thumb:hover{background:var(--sbh);background-clip:content-box;border-width:2px}
@supports not selector(::-webkit-scrollbar){*{scrollbar-width:thin;scrollbar-color:var(--sb) transparent}}
.hero{background:var(--hero);color:#fff;padding:26px 20px 24px;border-bottom:1px solid var(--line)}
:root[data-theme=dark] .hero{color:var(--ink)}
.wrap{max-width:1080px;margin:0 auto;padding:0 18px}
.hero .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.hero h1{margin:0;font-size:clamp(20px,3.4vw,26px);line-height:1.3;font-weight:700;letter-spacing:-.005em}
.hero small{display:block;opacity:.7;margin-top:4px;font-size:12.5px;font-variant-numeric:tabular-nums}
.tools{display:flex;gap:8px;flex-wrap:wrap}
.btn{cursor:pointer;font:600 12.5px/1 inherit;color:inherit;background:transparent;border:1px solid rgba(255,255,255,.28);padding:9px 14px;border-radius:8px;transition:background .15s,border-color .15s}
:root[data-theme=dark] .btn{border-color:var(--line)}
.btn:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.5)}.btn:active{transform:scale(.98)}
main.wrap{padding-top:18px;padding-bottom:48px}
.bar{position:sticky;top:0;z-index:20;margin:0 -18px 18px;padding:10px 18px;background:var(--bg);border-bottom:1px solid var(--line)}
.bar .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.search{flex:1 1 220px;min-width:0;padding:10px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:14px;outline:0}
.search:focus{border-color:var(--pri);box-shadow:0 0 0 3px var(--ring)}
.toc{display:flex;gap:6px;overflow-x:auto;padding:2px 0;scrollbar-width:none}.toc::-webkit-scrollbar{display:none}
.toc a{white-space:nowrap;font-size:12.5px;font-weight:600;color:var(--mut);text-decoration:none;padding:6px 11px;border-radius:6px;background:var(--card);border:1px solid var(--line)}
.toc a:hover,.toc a.on{color:var(--pri);border-color:var(--pri)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;margin-bottom:16px;overflow:clip}
.card>header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 18px;border-bottom:1px solid var(--line)}
.card h2{margin:0;font-size:15px;font-weight:650;letter-spacing:.005em}
.badge{font-size:12px;font-weight:600;color:var(--mut);background:var(--th);border:1px solid var(--line);padding:2px 9px;border-radius:6px;white-space:nowrap}
.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1px;background:var(--line)}
.meta div{background:var(--card);padding:12px 18px}.meta span{display:block;font-size:12px;color:var(--mut);font-weight:600}.meta b{display:block;font-size:14.5px;font-weight:650;word-break:break-word}
table{border-collapse:separate;border-spacing:0;width:100%;font-size:14px}
th,td{padding:10px 16px;text-align:start;vertical-align:top;border-bottom:1px solid var(--line)}
th{position:sticky;top:var(--bh,0px);z-index:2;background:var(--th);font-size:12px;font-weight:650;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;cursor:pointer;user-select:none;white-space:nowrap}
th:hover{color:var(--pri)}th.asc::after{content:" \25B2";font-size:8px}th.desc::after{content:" \25BC";font-size:8px}
tbody tr:nth-child(even) td{background:var(--zebra)}tbody tr:hover td{background:var(--th)}
td.n{text-align:end;font-variant-numeric:tabular-nums;font-weight:600}
tr:last-child td{border-bottom:0}
td.k{font-weight:600;color:var(--mut);width:34%}
.empty,.noresult{padding:22px;text-align:center;color:var(--mut);font-size:14px}.noresult{display:none}
mark{background:var(--mark);color:var(--markink);border-radius:3px;padding:0 2px}
.top{position:fixed;inset-block-end:18px;inset-inline-end:18px;width:38px;height:38px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-size:16px;cursor:pointer;opacity:0;pointer-events:none;transition:opacity .2s}.top.show{opacity:1;pointer-events:auto}.top:hover{border-color:var(--pri);color:var(--pri)}
footer{text-align:center;color:var(--mut);font-size:12px;padding:6px 0 28px}
pre,code{white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left}
@media(max-width:640px){th,td{padding:9px 11px;font-size:13px}.tw{overflow-x:auto}}
@media print{.hero{background:none!important;color:#000;padding:0 0 12px;border:0}.tools,.bar,.top{display:none!important}main.wrap{padding-top:0}.card{break-inside:avoid-page;overflow:visible}body{background:#fff}th{position:static}}
CSS;

        $js = <<<'JS'
(function(){
var d=document,r=d.documentElement,$=function(s,c){return(c||d).querySelector(s)},$$=function(s,c){return[].slice.call((c||d).querySelectorAll(s))};
function store(k,v){try{if(v===undefined)return localStorage.getItem(k);localStorage.setItem(k,v)}catch(e){return null}}
var t=store('rpt-theme')||(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
r.setAttribute('data-theme',t);
$('#th').onclick=function(){t=t==='dark'?'light':'dark';r.setAttribute('data-theme',t);store('rpt-theme',t)};
$('#pr').onclick=function(){window.print()};
/* sortable tables */
$$('table.s').forEach(function(tb){var ths=$$('th',tb);ths.forEach(function(th,i){th.onclick=function(){
var asc=!th.classList.contains('asc');ths.forEach(function(x){x.classList.remove('asc','desc')});th.classList.add(asc?'asc':'desc');
var body=$('tbody',tb),rows=$$('tr',body);
rows.sort(function(a,b){var x=a.cells[i].textContent.trim(),y=b.cells[i].textContent.trim(),nx=parseFloat(x.replace(/[,\s%]/g,'')),ny=parseFloat(y.replace(/[,\s%]/g,''));
var c=(!isNaN(nx)&&!isNaN(ny)&&/^[-+\d.,\s%]+$/.test(x)&&/^[-+\d.,\s%]+$/.test(y))?nx-ny:x.localeCompare(y,undefined,{numeric:true});return asc?c:-c});
rows.forEach(function(x){body.appendChild(x)})}})});
/* numeric column alignment */
$$('table').forEach(function(tb){var rows=$$('tbody tr',tb);if(!rows.length)return;var cols=rows[0].cells.length;
for(var c=0;c<cols;c++){var ok=rows.every(function(x){var v=(x.cells[c]||{}).textContent;return v&&/^[-+\d.,\s%]+$/.test(v.trim())&&v.trim()!==''});
if(ok&&!(tb.classList.contains('kv')&&c===0))rows.forEach(function(x){x.cells[c].classList.add('n')})}});
/* search */
var q=$('#q'),secs=$$('section.card[data-s]');
function esc(s){return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}
function clear(){$$('mark').forEach(function(m){m.replaceWith(d.createTextNode(m.textContent))});}
q.oninput=function(){var v=q.value.trim().toLowerCase();clear();
secs.forEach(function(s){var rows=$$('tbody tr',s),hit=0;rows.forEach(function(x){var m=!v||x.textContent.toLowerCase().indexOf(v)>-1;x.style.display=m?'':'none';if(m)hit++;
if(m&&v){$$('td',x).forEach(function(td){if(td.children.length)return;var tx=td.textContent,i=tx.toLowerCase().indexOf(v);if(i>-1){td.innerHTML='';td.append(tx.slice(0,i));var mk=d.createElement('mark');mk.textContent=tx.slice(i,i+v.length);td.append(mk,tx.slice(i+v.length))}})}});
var nr=$('.noresult',s);if(nr)nr.style.display=(rows.length&&!hit)?'block':'none';s.style.display=(!v||hit||!rows.length)?'':'none'})};
/* toc highlight + back to top */
var links=$$('.toc a'),tp=$('#tp');
function spy(){tp.classList.toggle('show',scrollY>500);var cur=null;secs.forEach(function(s){if(s.getBoundingClientRect().top<140)cur=s.id});links.forEach(function(a){a.classList.toggle('on',a.getAttribute('href')==='#'+cur)})}
addEventListener('scroll',spy,{passive:true});spy();tp.onclick=function(){scrollTo({top:0})};
function bh(){var b=$('.bar');if(b)r.style.setProperty('--bh',b.offsetHeight+'px')}bh();addEventListener('resize',bh);addEventListener('load',bh);
})();
JS;

        $h = '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::esc($title) . '</title>'
            . '<style>' . $css . '</style></head><body>';

        $h .= '<div class="hero"><div class="wrap"><div><h1>' . self::esc($title) . '</h1>';
        $gen = '';
        foreach ($meta as $k => $v) {
            if (preg_match('/generated|created|أنشئ|تاريخ|date/iu', (string) $k)) {
                $gen = (string) $v;
                break;
            }
        }
        if ($gen !== '') {
            $h .= '<small>' . self::esc($gen) . '</small>';
        }
        $h .= '</div><div class="tools"><button class="btn" id="th" type="button">' . $L['theme'] . '</button>'
            . '<button class="btn" id="pr" type="button">' . $L['print'] . '</button></div></div></div>';

        $h .= '<main class="wrap"><div class="bar"><div class="row"><input id="q" class="search" type="search" placeholder="' . $L['search'] . '" autocomplete="off"></div>';
        if (count($sections) > 1) {
            $h .= '<div class="toc" style="margin-top:8px">';
            foreach ($sections as $i => $sec) {
                $h .= '<a href="#s' . $i . '">' . self::esc($sec['title'] ?? '') . '</a>';
            }
            $h .= '</div>';
        }
        $h .= '</div>';

        if ($meta) {
            $h .= '<section class="card"><header><h2>' . $L['info'] . '</h2></header><div class="meta">';
            foreach ($meta as $k => $v) {
                $h .= '<div><span>' . self::esc($k) . '</span><b>' . nl2br(self::esc($v)) . '</b></div>';
            }
            $h .= '</div></section>';
        }

        foreach ($sections as $i => $sec) {
            $rows = $sec['rows'] ?? [];
            $h .= '<section class="card" id="s' . $i . '" data-s="1"><header><h2>' . self::esc($sec['title'] ?? '') . '</h2>';
            if ($rows) {
                $h .= '<span class="badge">' . count($rows) . ' ' . $L['rows'] . '</span>';
            }
            $h .= '</header>';
            if (!$rows) {
                $h .= '<div class="empty">' . $L['empty'] . '</div></section>';
                continue;
            }
            $hasHead = !empty($sec['header']);
            $h .= '<div class="tw"><table class="' . ($hasHead ? 's' : 'kv') . '">';
            if ($hasHead) {
                $h .= '<thead><tr>' . implode('', array_map(fn ($c) => '<th>' . self::esc($c) . '</th>', $sec['header'])) . '</tr></thead>';
            }
            $h .= '<tbody>';
            foreach ($rows as $r) {
                $cells = array_values($r);
                $h .= '<tr>';
                foreach ($cells as $ci => $c) {
                    $h .= '<td' . (!$hasHead && $ci === 0 ? ' class="k"' : '') . '>' . nl2br(self::esc($c)) . '</td>';
                }
                $h .= '</tr>';
            }
            $h .= '</tbody></table></div><div class="noresult">' . $L['none'] . '</div></section>';
        }

        $h .= '<footer>' . self::esc($title) . '</footer></main><button class="top" id="tp" type="button" aria-label="' . $L['top'] . '">↑</button>'
            . '<script>' . $js . '</script></body></html>';
        return $h;
    }

    // -- Markdown --------------------------------------------------------

    private static function mdCell($v): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', '<br>', '<br>', '<br>'], self::s($v));
    }

    private static function markdown(string $title, array $meta, array $sections): string
    {
        $o = '# ' . self::s($title) . "\n\n";
        foreach ($meta as $k => $v) {
            $o .= '- **' . self::s($k) . ':** ' . str_replace(["\r\n", "\n"], ' ', self::s($v)) . "\n";
        }
        foreach ($sections as $sec) {
            $o .= "\n## " . self::s($sec['title'] ?? '') . "\n\n";
            $rows = $sec['rows'] ?? [];
            $header = $sec['header'] ?? [];
            if (!$rows) {
                $o .= "—\n";
                continue;
            }
            $cols = max(count($header), ...array_map('count', $rows));
            $header = array_pad($header ?: array_fill(0, $cols, ' '), $cols, ' ');
            $o .= '| ' . implode(' | ', array_map([self::class, 'mdCell'], $header)) . " |\n";
            $o .= '|' . str_repeat(' --- |', $cols) . "\n";
            foreach ($rows as $r) {
                $r = array_pad(array_values($r), $cols, '');
                $o .= '| ' . implode(' | ', array_map([self::class, 'mdCell'], $r)) . " |\n";
            }
        }
        return $o;
    }

    // -- Plain text ------------------------------------------------------

    private static function text(string $title, array $meta, array $sections): string
    {
        $o = self::s($title) . "\n" . str_repeat('=', max(10, mb_strlen(self::s($title)))) . "\n\n";
        foreach ($meta as $k => $v) {
            $o .= self::s($k) . ': ' . str_replace(["\r\n", "\n"], ' ', self::s($v)) . "\n";
        }
        foreach ($sections as $sec) {
            $t = self::s($sec['title'] ?? '');
            $o .= "\n" . $t . "\n" . str_repeat('-', max(5, mb_strlen($t))) . "\n";
            $rows = $sec['rows'] ?? [];
            $header = $sec['header'] ?? [];
            if (!$rows) {
                $o .= "—\n";
                continue;
            }
            foreach ($rows as $i => $r) {
                $r = array_values($r);
                $parts = [];
                foreach ($r as $j => $cell) {
                    $label = $header[$j] ?? '';
                    $parts[] = ($label !== '' && count($r) > 2 ? self::s($label) . ': ' : '') . str_replace(["\r\n", "\n"], ' ', self::s($cell));
                }
                $o .= (count($r) > 2 ? ($i + 1) . '. ' : '') . implode(count($r) > 2 ? "\n   " : ': ', $parts) . "\n";
            }
        }
        return $o;
    }

    // -- XML -------------------------------------------------------------

    private static function xml(string $title, array $meta, array $sections): string
    {
        $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<report>\n  <title>" . self::esc($title) . "</title>\n  <meta>\n";
        foreach ($meta as $k => $v) {
            $o .= '    <item name="' . self::esc($k) . '">' . self::esc($v) . "</item>\n";
        }
        $o .= "  </meta>\n";
        foreach ($sections as $sec) {
            $header = $sec['header'] ?? [];
            $o .= '  <section title="' . self::esc($sec['title'] ?? '') . "\">\n";
            foreach (($sec['rows'] ?? []) as $r) {
                $o .= "    <row>\n";
                foreach (array_values($r) as $j => $cell) {
                    $o .= '      <cell column="' . self::esc($header[$j] ?? ('c' . ($j + 1))) . '">' . self::esc($cell) . "</cell>\n";
                }
                $o .= "    </row>\n";
            }
            $o .= "  </section>\n";
        }
        return $o . "</report>\n";
    }

    // -- DOCX ------------------------------------------------------------

    private static function docx(string $path, string $title, array $meta, array $sections, bool $rtl): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for Word exports.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the Word file.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $body = self::dPara($title, $rtl, true, 36);
        if ($meta) {
            $rows = [];
            foreach ($meta as $k => $v) {
                $rows[] = [$k, $v];
            }
            $body .= self::dTable([], $rows, $rtl, [3000, 6360]);
        }
        foreach ($sections as $sec) {
            $body .= self::dPara((string) ($sec['title'] ?? ''), $rtl, true, 28, 240);
            $rows = $sec['rows'] ?? [];
            if (!$rows) {
                $body .= self::dPara('—', $rtl);
                continue;
            }
            $body .= self::dTable($sec['header'] ?? [], $rows, $rtl);
        }
        $body .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="900" w:bottom="1000" w:left="900" w:header="708" w:footer="708" w:gutter="0"/>'
            . ($rtl ? '<w:bidi/>' : '') . '</w:sectPr>';

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();
    }

    private static function dRun(string $text, bool $rtl, bool $bold = false, int $size = 21, ?string $color = null): string
    {
        $lines = preg_split('/\r\n|\n|\r/', self::s($text)) ?: [''];
        $out = '';
        foreach ($lines as $i => $line) {
            $rpr = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Arial"/>' . ($bold ? '<w:b/><w:bCs/>' : '')
                . ($color ? '<w:color w:val="' . $color . '"/>' : '')
                . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>' . ($rtl ? '<w:rtl/>' : '') . '</w:rPr>';
            $out .= '<w:r>' . $rpr . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . self::esc($line) . '</w:t></w:r>';
        }
        return $out;
    }

    private static function dPara(string $text, bool $rtl, bool $bold = false, int $size = 21, int $before = 0): string
    {
        return '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:before="' . $before . '" w:after="100"/>'
            . ($rtl ? '<w:jc w:val="left"/>' : '') . '</w:pPr>' . self::dRun($text, $rtl, $bold, $size) . '</w:p>';
    }

    /** @param string[] $header @param array<int,array<int,mixed>> $rows @param int[]|null $widths */
    private static function dTable(array $header, array $rows, bool $rtl, ?array $widths = null): string
    {
        $cols = max(count($header), ...array_map('count', $rows));
        $cols = max(1, $cols);
        $total = 9360;
        $widths = $widths ?: array_fill(0, $cols, (int) floor($total / $cols));
        $border = '<w:tblBorders>' . implode('', array_map(
            fn ($b) => '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>',
            ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']
        )) . '</w:tblBorders>';
        $t = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/>' . ($rtl ? '<w:bidiVisual/>' : '') . $border
            . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
            . implode('', array_map(fn ($w) => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';

        $cell = function ($v, int $w, bool $head) use ($rtl) {
            return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($head ? '<w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>' : '') . '</w:tcPr>'
                . '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:before="40" w:after="40"/>' . ($rtl ? '<w:jc w:val="left"/>' : '') . '</w:pPr>'
                . self::dRun(self::s($v), $rtl, $head, 19) . '</w:p></w:tc>';
        };

        if ($header) {
            $header = array_pad(array_values($header), $cols, '');
            $t .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($header as $i => $c) {
                $t .= $cell($c, $widths[$i] ?? $widths[0], true);
            }
            $t .= '</w:tr>';
        }
        foreach ($rows as $r) {
            $r = array_pad(array_values($r), $cols, '');
            $t .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($r as $i => $c) {
                $t .= $cell($c, $widths[$i] ?? $widths[0], false);
            }
            $t .= '</w:tr>';
        }
        return $t . '</w:tbl>' . '<w:p/>';
    }
}
