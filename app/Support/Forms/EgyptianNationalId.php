<?php

namespace App\Support\Forms;

/** 14-digit Egyptian national ID: century(2|3) YYMMDD governorate(2) serial(4, last-but-one odd = male) check digit. */
final class EgyptianNationalId
{
    private const GOVERNORATES = [
        '01' => 'Cairo', '02' => 'Alexandria', '03' => 'Port Said', '04' => 'Suez', '11' => 'Damietta', '12' => 'Dakahlia',
        '13' => 'Sharqia', '14' => 'Qalyubia', '15' => 'Kafr El Sheikh', '16' => 'Gharbia', '17' => 'Monufia', '18' => 'Beheira',
        '19' => 'Ismailia', '21' => 'Giza', '22' => 'Beni Suef', '23' => 'Fayoum', '24' => 'Minya', '25' => 'Assiut',
        '26' => 'Sohag', '27' => 'Qena', '28' => 'Aswan', '29' => 'Luxor', '31' => 'Red Sea', '32' => 'New Valley',
        '33' => 'Matrouh', '34' => 'North Sinai', '35' => 'South Sinai', '88' => 'Born abroad',
    ];

    /** @return array{birth_date:string,gender:string,governorate:string,governorate_code:string,age:int}|null */
    public static function parse(?string $raw): ?array
    {
        $d = EgyptianPhone::digits((string) $raw);
        if (!preg_match('/^[23]\d{13}$/', $d)) {
            return null;
        }
        $year = ($d[0] === '2' ? 1900 : 2000) + (int) substr($d, 1, 2);
        $m = (int) substr($d, 3, 2);
        $day = (int) substr($d, 5, 2);
        if (!checkdate($m, $day, $year)) {
            return null;
        }
        $gov = substr($d, 7, 2);
        if (!isset(self::GOVERNORATES[$gov])) {
            return null;
        }
        $birth = sprintf('%04d-%02d-%02d', $year, $m, $day);
        if ($birth > date('Y-m-d')) {
            return null;
        }
        return [
            'birth_date' => $birth,
            'gender' => ((int) $d[12]) % 2 === 1 ? 'male' : 'female',
            'governorate' => self::GOVERNORATES[$gov],
            'governorate_code' => $gov,
            'age' => (int) floor((time() - strtotime($birth)) / 31557600),
        ];
    }

    public static function normalize(?string $raw): ?string
    {
        return self::parse($raw) ? EgyptianPhone::digits((string) $raw) : null;
    }
}
