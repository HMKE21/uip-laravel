<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * تنضيف HTML الرسائل على السيرفر (allow-list). بيشيل script/style/iframe/form وأي on* وروابط javascript:، والصور بتتسمح
 * فقط لـ http(s) و data:image (من غير svg). الفرونت لازم يعرض النتيجة جوه iframe sandbox برضه (دفاع على طبقتين).
 */
class MailHtmlSanitizer
{
    private const TAGS = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'td', 'th'];
    private const ATTRS = ['a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'], '*' => ['dir', 'style']];
    private const STYLE_OK = '/^(?:\s*(?:color|background-color|font-weight|font-style|text-decoration|text-align|font-size|direction|margin|padding|border|width|height)\s*:\s*[#\w\s.,%()-]+;?)+\s*$/i';

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        self::walk($body);
        $out = '';
        foreach (iterator_to_array($body->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }
        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::TAGS, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template'], true)) {
                        $node->removeChild($child);
                    } else {
                        self::walk($child);                     // tag مش مسموح: نشيله ونبقي المحتوى
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                    }
                    continue;
                }
                self::cleanAttrs($child, $tag);
                if ($tag === 'img' && !$child->getAttribute('src')) {
                    $node->removeChild($child);
                    continue;
                }
                self::walk($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttrs(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::ATTRS[$tag] ?? [], self::ATTRS['*']);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $val = trim($attr->value);
            if (!in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }
            if ($name === 'href' && !preg_match('#^(https?:|mailto:|tel:|\#)#i', $val)) {
                $el->removeAttribute($attr->name);
            } elseif ($name === 'src' && !preg_match('#^(https?://|data:image/(png|jpe?g|gif|webp);base64,)#i', $val)) {
                $el->removeAttribute($attr->name);
            } elseif ($name === 'style' && (!preg_match(self::STYLE_OK, $val) || preg_match('/url\s*\(|expression|javascript|@import/i', $val))) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
            $el->setAttribute('target', '_blank');
        }
    }
}
