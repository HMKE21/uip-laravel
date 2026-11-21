<?php

declare(strict_types=1);

namespace Uipe;

/**
 * In-memory "have I seen this event id?" store. Good for a single process (php-fpm workers do NOT share it).
 * With several workers keep the ids in a shared store (Redis `SET key 1 NX EX ttl`, a database unique index)
 * and pass any object with `firstSeen(string $eventId): bool`.
 */
class ReplayGuard
{
    private int $ttl;
    private int $max;
    /** @var array<string,float> */
    private array $seen = [];

    public function __construct(int $ttl = 2 * Webhooks::DEFAULT_TOLERANCE, int $maxEntries = 10000)
    {
        $this->ttl = $ttl;
        $this->max = $maxEntries;
    }

    public function firstSeen(string $eventId, ?float $now = null): bool
    {
        $now ??= microtime(true);
        if (count($this->seen) >= $this->max) {
            foreach ($this->seen as $k => $exp) {
                if ($exp <= $now) {
                    unset($this->seen[$k]);
                }
            }
            while (count($this->seen) >= $this->max) {
                unset($this->seen[array_key_first($this->seen)]);
            }
        }
        $expires = $this->seen[$eventId] ?? null;
        if ($expires !== null && $expires > $now) {
            return false;
        }
        unset($this->seen[$eventId]);
        $this->seen[$eventId] = $now + $this->ttl;
        return true;
    }

    public function size(): int
    {
        return count($this->seen);
    }
}
