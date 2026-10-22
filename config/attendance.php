<?php

/**
 * الحضور والغياب (QR + موقع جغرافي). كل القيم قابلة للتعديل من الـ .env.
 */
return [
    // نسبة الغياب (من الحصص المسجّلة) اللي عندها الطالب بيتحرم من المادة / بيتنبّه.
    'max_absence_percent'   => (float) env('ATTENDANCE_MAX_ABSENCE_PERCENT', 25),
    'warn_absence_percent'  => (float) env('ATTENDANCE_WARN_ABSENCE_PERCENT', 15),

    // مدة فتح التحضير (دقايق) وبعد كام دقيقة الطالب يتحسب "متأخر".
    'default_minutes'       => (int) env('ATTENDANCE_DEFAULT_MINUTES', 15),
    'max_minutes'           => (int) env('ATTENDANCE_MAX_MINUTES', 120),
    'late_after_minutes'    => (int) env('ATTENDANCE_LATE_AFTER_MINUTES', 10),

    // الـ QR بيتغيّر كل qr_step ثانية، والمقبول الحالي + السابق بس (يعني صورة مسرّبة بتموت في ≤ 2×step).
    'qr_step_seconds'       => (int) env('ATTENDANCE_QR_STEP', 30),

    // الجيوفنس: نصف قطر الكلية الافتراضي، ونصف قطر حوالين مكان الدكتور وقت فتح التحضير،
    // وأقصى دقة GPS مقبولة (أكبر من كده الموقع مش موثوق).
    'default_campus_radius_m' => (int) env('ATTENDANCE_CAMPUS_RADIUS_M', 300),
    'doctor_radius_m'         => (int) env('ATTENDANCE_DOCTOR_RADIUS_M', 150),
    'max_accuracy_m'          => (int) env('ATTENDANCE_MAX_ACCURACY_M', 100),
];
