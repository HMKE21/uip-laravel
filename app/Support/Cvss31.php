<?php

namespace App\Support;

/**
 * حاسبة CVSS v3.1 (Base score). بتاخد vector زي
 * "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H" وترجّع الدرجة والـ severity.
 * نفس المعادلات الرسمية من FIRST (مع roundup بتاعة 3.1).
 */
final class Cvss31
{
    private const AV = ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2];
    private const AC = ['L' => 0.77, 'H' => 0.44];
    private const UI = ['N' => 0.85, 'R' => 0.62];
    private const CIA = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0];

    /** @return array{score:float,severity:string,vector:string}|null  null لو الـ vector غير صالح */
    public static function parse(?string $vector): ?array
    {
        $vector = trim((string) $vector);
        if ($vector === '') {
            return null;
        }

        $parts = explode('/', $vector);
        $head = array_shift($parts);
        if (!in_array($head, ['CVSS:3.1', 'CVSS:3.0'], true)) {
            return null;
        }

        $m = [];
        foreach ($parts as $p) {
            $kv = explode(':', $p);
            if (count($kv) !== 2) {
                return null;
            }
            $m[$kv[0]] = $kv[1];
        }

        foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $k) {
            if (!isset($m[$k])) {
                return null;
            }
        }

        if (!isset(self::AV[$m['AV']], self::AC[$m['AC']], self::UI[$m['UI']], self::CIA[$m['C']], self::CIA[$m['I']], self::CIA[$m['A']])) {
            return null;
        }
        if (!in_array($m['S'], ['U', 'C'], true) || !in_array($m['PR'], ['N', 'L', 'H'], true)) {
            return null;
        }

        $changed = $m['S'] === 'C';
        $pr = ['N' => 0.85, 'L' => $changed ? 0.68 : 0.62, 'H' => $changed ? 0.5 : 0.27][$m['PR']];

        $iss = 1 - ((1 - self::CIA[$m['C']]) * (1 - self::CIA[$m['I']]) * (1 - self::CIA[$m['A']]));
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * pow($iss - 0.02, 15) : 6.42 * $iss;
        $expl = 8.22 * self::AV[$m['AV']] * self::AC[$m['AC']] * $pr * self::UI[$m['UI']];

        if ($impact <= 0) {
            $score = 0.0;
        } else {
            $raw = $changed ? min(1.08 * ($impact + $expl), 10) : min($impact + $expl, 10);
            $score = self::roundUp($raw);
        }

        return ['score' => $score, 'severity' => self::severity($score), 'vector' => $vector];
    }

    public static function severity(float $score): string
    {
        return $score >= 9.0 ? 'critical' : ($score >= 7.0 ? 'high' : ($score >= 4.0 ? 'medium' : 'low'));
    }

    private static function roundUp(float $x): float
    {
        $i = (int) round($x * 100000);

        return $i % 10000 === 0 ? $i / 100000 : (floor($i / 10000) + 1) / 10;
    }
}
