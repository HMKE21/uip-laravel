<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هذا الباتش (laravel_exam_targets_fix.zip) بيفترض إنه شغال فوق تطبيق
 * جامعي أكبر عنده جداول users/faculties/departments/
 * student_groups/students/universities/academic_staff أصلًا. الباتش
 * مبيجيبش الـ migrations بتاعت الجداول دي لأنها مش جزء منه.
 *
 * عشان نقدر نشغّل اختبارات آلية حقيقية على eligibility logic هنا في
 * بيئة معزولة (sqlite :memory:)، الملف ده بيعمل نسخة مصغّرة من نفس
 * الجداول تحتوي بالظبط على الأعمدة اللي ExamTargetRepository وباقي
 * كود الامتحانات بيعتمد عليها. لو الباتش ده اتدمج في التطبيق الحقيقي،
 * الملف ده المفروض ميتشالش/يتشال، لأن الجداول الحقيقية هتكون موجودة
 * أصلًا من الـ migrations الأساسية.
 */
return new class extends Migration {
    public function up(): void
    {
        // حماية مهمة: الملف ده اتصمم يشتغل بس جوه بيئة الاختبارات المعزولة
        // (APP_ENV=testing + sqlite :memory: زي ما محدد في phpunit.xml).
        // لو حد شغّل `php artisan migrate` العادي على مشروعه الحقيقي (زي
        // اللي بيحصل هنا) والملف ده حصل ونزل جوه database/migrations بتاعت
        // المشروع الحقيقي، لازم يبقى no-op تمامًا وميحاولش يعدّل جداول
        // زي users/universities اللي أصلًا موجودة وعندها الأعمدة دي فعلًا —
        // عشان كده منعمل حاجة خالص لو مكناش جوه بيئة testing.
        if (! app()->environment('testing')) {
            return;
        }

        // الـ migration الافتراضية 0001_01_01_000000_create_users_table.php
        // لسه على شكل Laravel scaffold القديم (name/email/password) — الجدول
        // الحقيقي في الإنتاج عنده أعمدة تانية غير كده (uuid/full_name/
        // password_hash/status...، راجع تعليق App\Models\User). بما إن الباتش
        // مبيجيبش migration محدّثة للجدول ده، بنضيف الأعمدة الناقصة هنا فقط
        // عشان اختبارات هذا الباتش تقدر تتعامل مع users بنفس شكل الموديل.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('full_name')->nullable()->after('uuid');
            $table->string('name_ar', 150)->nullable();
            $table->string('name_en', 150)->nullable();
            $table->string('password_hash')->nullable()->after('full_name');
            $table->string('status')->default('active')->after('password_hash');
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('university_id')->nullable();
            // password_encrypted: مرآة sandbox لـ migration
            // 2026_09_04_000000_add_password_recovery_to_academic_staff.php
            // الحقيقية (set/reveal password، بند "استيراد + كلمة مرور
            // مخصصة" فوق روستر أعضاء هيئة التدريس).
            $table->text('password_encrypted')->nullable();
            $table->timestamps();
        });

        // ExamGradingService يبني AiExamGradingService عبر الـ container حتى
        // لو مش هيستخدمه فعليًا (ai_grading_enabled=false في السؤال) — وده
        // بيقرأ من App\Repositories\SettingRepository اللي بيستعلم جدول
        // `settings` مباشرة. الجدول ده مش جزء من الباتش (موجود في التطبيق
        // الأصلي)، فبنعمل نسخة مصغّرة منه هنا برضه.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->default('global');
            $table->string('key');
            $table->foreignId('user_id')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('university_id')->nullable();
            $table->string('student_number')->nullable();
            $table->string('faculty')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('faculty_id')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->date('study_start_date')->nullable();
            $table->date('expected_graduation_date')->nullable();
            $table->unsignedTinyInteger('academic_year')->nullable();
            $table->string('current_semester')->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->text('bio')->nullable();
            $table->json('skills')->nullable();
            $table->json('social_links')->nullable();
            $table->foreignId('group_id')->nullable();
            $table->string('invitation_status')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::dropIfExists('students');
        Schema::dropIfExists('academic_staff');
        Schema::dropIfExists('student_groups');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('faculties');
        Schema::dropIfExists('universities');
    }
};
