<?php

namespace Tests\Unit;

use App\Support\SvgGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SvgGuardTest extends TestCase
{
    public static function safe(): array
    {
        return [
            'plain' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#f00"/></svg>'],
            'xml header + gradient' => ['<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs><rect fill="url(#g)" width="5" height="5"/></svg>'],
            'internal use' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><g id="a"><circle r="2"/></g></defs><use xlink:href="#a"/></svg>'],
            'style block' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:red}</style><rect class="a" width="3" height="3"/></svg>'],
            'embedded png' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,iVBORw0KGgo="/></svg>'],
        ];
    }

    public static function unsafe(): array
    {
        $ns = 'xmlns="http://www.w3.org/2000/svg"';
        return [
            'script' => ["<svg $ns><script>alert(1)</script></svg>"],
            'onload' => ["<svg $ns onload=\"alert(1)\"/>"],
            'onclick child' => ["<svg $ns><rect onclick=\"x()\" width=\"1\" height=\"1\"/></svg>"],
            'javascript href' => ["<svg $ns xmlns:xlink=\"http://www.w3.org/1999/xlink\"><a xlink:href=\"javascript:alert(1)\"><text>x</text></a></svg>"],
            'obfuscated js href' => ["<svg $ns><a href=\"java&#x09;script:alert(1)\"><text>x</text></a></svg>"],
            'entity-encoded js' => ["<svg $ns><a href=\"&#106;avascript:alert(1)\"><text>x</text></a></svg>"],
            'external href' => ["<svg $ns><image href=\"https://evil.test/x.png\"/></svg>"],
            'foreignObject' => ["<svg $ns><foreignObject><div xmlns=\"http://www.w3.org/1999/xhtml\">hi</div></foreignObject></svg>"],
            'xhtml namespace' => ["<svg $ns><body xmlns=\"http://www.w3.org/1999/xhtml\"/></svg>"],
            'animate href' => ["<svg $ns><a><animate attributeName=\"href\" values=\"javascript:alert(1)\"/><text>x</text></a></svg>"],
            'set href' => ["<svg $ns><a href=\"#\"><set attributeName=\"href\" to=\"javascript:alert(1)\"/></a></svg>"],
            'css import' => ["<svg $ns><style>@import url(https://evil.test/a.css);</style></svg>"],
            'css external url' => ["<svg $ns><rect style=\"fill:url(https://evil.test/a)\" width=\"1\" height=\"1\"/></svg>"],
            'css escape js' => ["<svg $ns><rect style=\"background:\\75rl(https://e.test/a)\" width=\"1\" height=\"1\"/></svg>"],
            'data svg href' => ["<svg $ns><image href=\"data:image/svg+xml;base64,PHN2Zy8+\"/></svg>"],
            'doctype/xxe' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY x SYSTEM \"file:///etc/passwd\">]><svg $ns><text>&x;</text></svg>"],
            'stylesheet PI' => ["<?xml version=\"1.0\"?><?xml-stylesheet href=\"https://evil.test/a.xsl\" type=\"text/xsl\"?><svg $ns/>"],
            'not svg root' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body>hi</body></html>'],
            'malformed' => ["<svg $ns><rect></svg>"],
            'utf16 bom' => ["\xFF\xFE<\0s\0v\0g\0>\0"],
            'empty' => [''],
        ];
    }

    #[DataProvider('safe')]
    public function test_accepts_clean_svg(string $svg): void
    {
        SvgGuard::assertSafe($svg);
        $this->assertTrue(SvgGuard::isSafe($svg));
    }

    #[DataProvider('unsafe')]
    public function test_rejects_active_content(string $svg): void
    {
        $this->assertFalse(SvgGuard::isSafe($svg));
    }
}
