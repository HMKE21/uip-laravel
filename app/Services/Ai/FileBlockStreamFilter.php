<?php

namespace App\Services\Ai;

/**
 * بيحجب بلوكات <uipe-file name="…">…</uipe-file> من ستريم الرد (المحتوى بيتحوّل لملف للتحميل، مش بيتعرض كنص خام).
 * النص خارج البلوك بيعدّي عادي. لو بداية/نهاية العلامة اتقسمت بين chunk وchunk بنستنى باقيها. pure PHP.
 */
class FileBlockStreamFilter
{
    private const OPEN = '<uipe-file';
    private const CLOSE = '</uipe-file>';

    private string $buf = '';
    private bool $inside = false;

    /** @var callable(string):void */
    private $emit;
    /** @var (callable(string):void)|null */
    private $onStart;

    /** @param callable(string):void $emit @param (callable(string):void)|null $onStart بيتنادى باسم الملف أول ما البلوك يبدأ */
    public function __construct(callable $emit, ?callable $onStart = null)
    {
        $this->emit = $emit;
        $this->onStart = $onStart;
    }

    public function push(string $delta): void
    {
        $this->buf .= $delta;
        while (true) {
            if (!$this->inside) {
                $pos = stripos($this->buf, self::OPEN);
                if ($pos === false) {
                    $keep = $this->partialSuffixLen($this->buf, self::OPEN);
                    $out = substr($this->buf, 0, strlen($this->buf) - $keep);
                    $this->buf = substr($this->buf, strlen($out));
                    if ($out !== '') {
                        ($this->emit)($out);
                    }
                    return;
                }
                if ($pos > 0) {
                    ($this->emit)(substr($this->buf, 0, $pos));
                }
                $this->buf = substr($this->buf, $pos);
                $gt = strpos($this->buf, '>');
                if ($gt === false) {
                    return; // العلامة لسه ماكملتش
                }
                $tag = substr($this->buf, 0, $gt + 1);
                $this->buf = substr($this->buf, $gt + 1);
                $this->inside = true;
                if ($this->onStart) {
                    $name = preg_match('/name\s*=\s*"([^"]*)"/i', $tag, $m) ? $m[1] : '';
                    ($this->onStart)($name);
                }
            } else {
                $end = stripos($this->buf, self::CLOSE);
                if ($end === false) {
                    $keep = $this->partialSuffixLen($this->buf, self::CLOSE);
                    $this->buf = $keep > 0 ? substr($this->buf, -$keep) : '';
                    return;
                }
                $this->buf = substr($this->buf, $end + strlen(self::CLOSE));
                $this->inside = false;
            }
        }
    }

    /** نهاية الستريم: أي نص عادي متبقّي يتطلّع (البلوك الناقص بيتتجاهل). */
    public function finish(): void
    {
        if (!$this->inside && $this->buf !== '') {
            ($this->emit)($this->buf);
        }
        $this->reset();
    }

    /** الفرونت مسح النص (reset event بعد أداة) — نبدأ من الأول. */
    public function reset(): void
    {
        $this->buf = '';
        $this->inside = false;
    }

    private function partialSuffixLen(string $buf, string $needle): int
    {
        $max = min(strlen($buf), strlen($needle) - 1);
        for ($k = $max; $k > 0; $k--) {
            if (strcasecmp(substr($buf, -$k), substr($needle, 0, $k)) === 0) {
                return $k;
            }
        }
        return 0;
    }
}
