<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الإيميل الجامعي (عنوان رسمي + تحويل): الجامعة بتحدد الدومين والنمط، والمنصة بتولّد عنوان لكل طالب.
 * مفيش صندوق وارد على المنصة؛ العنوان بيتحوّل لإيميل الطالب الشخصي (الجامعة تضبط التحويل على الدومين من ملف التصدير).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('universities') && !Schema::hasColumn('universities', 'email_domain')) {
            Schema::table('universities', function (Blueprint $table) {
                $table->string('email_domain', 120)->nullable();
                $table->string('email_pattern', 16)->default('number'); // number | name
            });
        }
        if (Schema::hasTable('students') && !Schema::hasColumn('students', 'university_email')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('university_email', 190)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('students', 'university_email')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropUnique(['university_email']);
                $table->dropColumn('university_email');
            });
        }
        if (Schema::hasColumn('universities', 'email_domain')) {
            Schema::table('universities', function (Blueprint $table) {
                $table->dropColumn(['email_domain', 'email_pattern']);
            });
        }
    }
};
