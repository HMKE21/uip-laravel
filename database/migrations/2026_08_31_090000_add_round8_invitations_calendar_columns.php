<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 8 (Invitations & Calendar): بند 26
 * (Project Integration — "Meetings should be attachable to UIP
 * entities"). additive بالكامل زي كل migration قبلها في الموديول ده.
 *
 * meetings.attachable_type/attachable_id: زوج polymorphic-style بسيط
 * (مش Eloquent morphTo فعلي عمدًا — القيم المسموحة أوسع من مجرد
 * موديولات Eloquent موجودة، راجع نقطة (2) تحت) بيربط اجتماع واحد
 * بكيان UIP واحد وقت الإنشاء (مثال المواصفة بالظبط: "Project →
 * Meetings" — كل مشروع ممكن يبقى ليه أكتر من اجتماع، فالعلاقة
 * one-attachable-to-many-meetings، مش العكس، فمفيش داعي لجدول pivot).
 *
 * نوعين من القيم المسموحة في attachable_type (راجع docblock
 * MeetingsApiController::ATTACHABLE_TYPES_WITH_VALIDATION):
 *   1) أنواع ليها موديول Eloquent فعلي في المنصة دلوقتي (project،
 *      faculty، university، student_group) — دي بتتحقق فعليًا إن
 *      attachable_id موجود ومملوك لنطاق الهوست وقت الإنشاء.
 *   2) أنواع مذكورة في نص المواصفة بند 26 (research، course،
 *      supervisor_relationship) بس مفيش لها جدول/موديول مستقل في
 *      المنصة دلوقتي (لا Research ولا Course model موجودين، supervisor
 *      relationship هو SupervisorAssignment لكن مفيش scope واضح تتحقق
 *      منه هنا) — دول بيتقبلوا كـ label حر بس (attachable_id اختياري،
 *      من غير existence check)، عشان الحقل يفضل مفيد للفرونت/التصنيف
 *      من غير ما نمنع استخدامهم لحد ما الموديولات دي توجد فعليًا في
 *      خطة الـ 26-item الأكبر.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('attachable_type', 40)->nullable()->after('settings');
            $table->unsignedBigInteger('attachable_id')->nullable()->after('attachable_type');
            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['attachable_type', 'attachable_id']);
            $table->dropColumn(['attachable_type', 'attachable_id']);
        });
    }
};
