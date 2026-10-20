<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة على `academic_staff` (additive بالكامل، نفس نمط
 * add_round8_invitations_calendar_columns.php فوق جدول `meetings`
 * الموجود مسبقًا خارج هذا الباتش): password_encrypted تخزن كلمة مرور
 * الدعوة الحالية مشفّرة بـ Laravel Crypt (reversible، مبني على APP_KEY —
 * مش password_hash العادي اللي في `users` وعمره ما يتفك). الهدف إن
 * الجامعة/الكلية تقدر "تشوف" كلمة مرور عضو هيئة تدريس وقت الحاجة (مثلاً
 * لو محتاجة تقولها له تليفونيًا) أو تحددها هي بنفسها بدل ما تفضل عشوائية
 * دايمًا — راجع AcademicStaffManagementService::setPassword()/
 * revealPassword() لتفاصيل الـ audit logging على كل reveal.
 *
 * عمدًا نص (Crypt::encryptString) مش عمود منفصل معمول له index — الحقل
 * ده أبدًا ميترجعش في القوائم العامة (AcademicStaffRepository يختار
 * أعمدة صريحة، والموديل بيعمله hidden)، بس عبر endpoint مخصص للـ reveal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('academic_staff', function (Blueprint $table) {
            if (!Schema::hasColumn('academic_staff', 'password_encrypted')) {
                $table->text('password_encrypted')->nullable()->after('staff_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('academic_staff', function (Blueprint $table) {
            if (Schema::hasColumn('academic_staff', 'password_encrypted')) {
                $table->dropColumn('password_encrypted');
            }
        });
    }
};
