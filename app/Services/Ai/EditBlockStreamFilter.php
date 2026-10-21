<?php

namespace App\Services\Ai;

/**
 * بيحجب بلوكات SEARCH/REPLACE من الستريم (الطالب يشوفها كـ diff بعد التحقق مش كنص خام).
 * مستخرج من AiCodeAssistService::fix() عشان fix() و assist() يستعملوه سوا. pure PHP.
 *
 * $emit يتنادى بـ ['type' => 'token', 'delta' => '…'].
 */
class EditBlockStreamFilter
{
    private string $pending = '';
    private bool $blocked = false;

    /** @var callable */
    private $emit;

    public function __construct(callable $emit)
    {
        $this->emit = $emit;
    }

    public function push(string $delta): void
    {
        if ($this->blocked) {
            return;
        }
        $this->pending .= str_replace("\r", '', $delta);
        $nl = strrpos($this->pending, "\n");
        $ready = '';
        if ($nl !== false) {
            $ready = substr($this->pending, 0, $nl + 1);
            $this->pending = substr($this->pending, $nl + 1);
        }
        // الجزء الناقص من السطر الأخير: لو بيبدأ بـ "<" ممكن يبقى بداية علامة بلوك — نستنى.
        if ($this->pending !== '' && $this->pending[0] !== '<') {
            $ready .= $this->pending;
            $this->pending = '';
        }
        $this->emitSafe($ready);
    }

    public function finish(): void
    {
        if (!$this->blocked && $this->pending !== '' && !str_starts_with($this->pending, '<<<<<<<')) {
            ($this->emit)(['type' => 'token', 'delta' => $this->pending]);
        }
        $this->pending = '';
    }

    private function emitSafe(string $text): void
    {
        if ($text === '') {
            return;
        }
        if (preg_match('/^<{7} SEARCH/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            $this->blocked = true;
            $text = rtrim(substr($text, 0, $m[0][1]));
        }
        if ($text !== '') {
            ($this->emit)(['type' => 'token', 'delta' => $text]);
        }
    }
}
