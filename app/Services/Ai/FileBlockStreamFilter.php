<?php

namespace App\Services\Ai;

/**
 * Hides from the reply stream:
 *  - <uipe-file name="...">...</uipe-file> blocks (the content becomes a downloadable file, never shown as raw text).
 *  - tool-call blocks some models write as plain text (<tool_call>, <tool_calls>, <function_call>) - never for display.
 * Text outside the blocks passes through. A tag split between two chunks waits for the rest. Pure PHP.
 */
class FileBlockStreamFilter
{
    /** open tag prefix => [closing tag, is it a file block?] (longest first so <tool_calls wins over <tool_call) */
    private const BLOCKS = [
        '<uipe-file'     => ['</uipe-file>', true],
        '<tool_calls'    => ['</tool_calls>', false],
        '<tool_call'     => ['</tool_call>', false],
        '<function_call' => ['</function_call>', false],
    ];

    private string $buf = '';
    private bool $inside = false;
    private string $close = '';

    /** @var callable(string):void */
    private $emit;
    /** @var (callable(string):void)|null */
    private $onStart;
    /** @var (callable():void)|null */
    private $onHidden;

    /**
     * @param callable(string):void $emit
     * @param (callable(string):void)|null $onStart called with the file name when a file block starts
     * @param (callable():void)|null $onHidden called for every chunk swallowed inside a hidden block (lets the caller send a keep-alive,
     *        otherwise a long file/zip would send no bytes to the browser and the client watchdog would give up)
     */
    public function __construct(callable $emit, ?callable $onStart = null, ?callable $onHidden = null)
    {
        $this->emit = $emit;
        $this->onStart = $onStart;
        $this->onHidden = $onHidden;
    }

    public function push(string $delta): void
    {
        if ($this->inside && $this->onHidden) {
            ($this->onHidden)();
        }
        $this->buf .= $delta;
        while (true) {
            if (!$this->inside) {
                [$pos, $open] = $this->firstOpen($this->buf);
                if ($pos === null) {
                    $keep = $this->partialOpenSuffixLen($this->buf);
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
                    return; // tag not complete yet
                }
                $tag = substr($this->buf, 0, $gt + 1);
                $this->buf = substr($this->buf, $gt + 1);
                [$this->close, $isFile] = self::BLOCKS[$open];
                $this->inside = true;
                if ($isFile && $this->onStart) {
                    $name = preg_match('/name\s*=\s*"([^"]*)"/i', $tag, $m) ? $m[1] : '';
                    ($this->onStart)($name);
                }
            } else {
                $end = stripos($this->buf, $this->close);
                if ($end === false) {
                    $keep = $this->partialSuffixLen($this->buf, $this->close);
                    $this->buf = $keep > 0 ? substr($this->buf, -$keep) : '';
                    return;
                }
                $this->buf = substr($this->buf, $end + strlen($this->close));
                $this->inside = false;
                $this->close = '';
            }
        }
    }

    /** End of stream: leftover plain text is emitted (an incomplete block is dropped). */
    public function finish(): void
    {
        if (!$this->inside && $this->buf !== '') {
            $keep = $this->partialOpenSuffixLen($this->buf); // a dangling "<tool_ca" is never shown
            $out = substr($this->buf, 0, strlen($this->buf) - $keep);
            if ($out !== '') {
                ($this->emit)($out);
            }
        }
        $this->reset();
    }

    /** The front end cleared the text (reset event after a tool) - start over. */
    public function reset(): void
    {
        $this->buf = '';
        $this->inside = false;
        $this->close = '';
    }

    /** @return array{0:?int,1:?string} earliest opening tag */
    private function firstOpen(string $buf): array
    {
        $best = null;
        $bestOpen = null;
        foreach (array_keys(self::BLOCKS) as $open) {
            $p = stripos($buf, $open);
            if ($p !== false && ($best === null || $p < $best)) {
                $best = $p;
                $bestOpen = $open;
            }
        }
        return [$best, $bestOpen];
    }

    private function partialOpenSuffixLen(string $buf): int
    {
        $max = 0;
        foreach (array_keys(self::BLOCKS) as $open) {
            $max = max($max, $this->partialSuffixLen($buf, $open));
        }
        return $max;
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
