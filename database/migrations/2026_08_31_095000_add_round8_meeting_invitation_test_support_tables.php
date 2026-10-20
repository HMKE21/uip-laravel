<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة على 2020_01_01_000000_create_test_support_tables.php (نفس
 * الفكرة والحماية بالظبط — شغالة جوه بيئة testing/sqlite بس، no-op في
 * أي بيئة تانية) عشان MeetingInvitationService/MeetingAttachableService
 * (Round 8) محتاجين جداول/أعمدة مش موجودة في الشيم الأصلي: هو كان
 * مبني بس لاحتياجات exam-system (بند 4/16)، فمعندوش projects/
 * project_team_members/supervisors
 * ولا أعمدة user_id/official_name_* على universities، ولا user_id/
 * name_ar/name_en على faculties، ولا faculty_id/department_id/status
 * على academic_staff.
 *
 * ملف منفصل (مش تعديل على الشيم الأصلي القديم) عمدًا — عشان مانلمسش
 * migration مُسلّمة بالفعل ومعتمد عليها في اختبارات Round 1-7 شغالة،
 * وكل تعديل هنا بـ hasColumn/hasTable guards عشان يفضل آمن لو اتشغل
 * أكتر من مرة أو لو الشيم الأصلي اتغيّر لاحقًا وبقى فيه الأعمدة دي أصلًا.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        if (Schema::hasTable('universities')) {
            Schema::table('universities', function (Blueprint $table) {
                if (! Schema::hasColumn('universities', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (! Schema::hasColumn('universities', 'official_name_en')) {
                    $table->string('official_name_en')->nullable();
                }
                if (! Schema::hasColumn('universities', 'official_name_ar')) {
                    $table->string('official_name_ar')->nullable();
                }
            });
        }

        if (Schema::hasTable('faculties')) {
            Schema::table('faculties', function (Blueprint $table) {
                if (! Schema::hasColumn('faculties', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (! Schema::hasColumn('faculties', 'name_ar')) {
                    $table->string('name_ar')->nullable();
                }
                if (! Schema::hasColumn('faculties', 'name_en')) {
                    $table->string('name_en')->nullable();
                }
            });
        }

        if (Schema::hasTable('academic_staff')) {
            Schema::table('academic_staff', function (Blueprint $table) {
                if (! Schema::hasColumn('academic_staff', 'faculty_id')) {
                    $table->unsignedBigInteger('faculty_id')->nullable();
                }
                if (! Schema::hasColumn('academic_staff', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable();
                }
                if (! Schema::hasColumn('academic_staff', 'status')) {
                    $table->string('status')->default('active');
                }
            });
        }

        if (! Schema::hasTable('supervisors')) {
            Schema::create('supervisors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('university_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('full_name')->nullable();
                $table->string('email')->nullable();
                $table->string('department')->nullable();
                $table->string('title')->nullable();
                $table->json('permissions')->nullable();
                $table->string('invitation_status')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable();
                $table->string('slug')->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->unsignedBigInteger('university_id')->nullable();
                $table->string('title_ar')->nullable();
                $table->string('title_en')->nullable();
                $table->text('summary')->nullable();
                $table->text('description')->nullable();
                $table->string('category')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->json('tags')->nullable();
                $table->text('keywords')->nullable();
                $table->string('supervisor_name')->nullable();
                $table->string('cover_image_path')->nullable();
                $table->string('repository_url')->nullable();
                $table->string('demo_url')->nullable();
                $table->string('visibility')->default('public');
                $table->string('status')->default('draft');
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('likes_count')->default(0);
                $table->timestamp('published_at')->nullable();
                $table->json('sdgs')->nullable();
                $table->json('technologies')->nullable();
                $table->decimal('budget', 12, 2)->nullable();
                $table->date('timeline_start')->nullable();
                $table->date('timeline_end')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('project_team_members')) {
            Schema::create('project_team_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('invited_email')->nullable();
                $table->string('member_name')->nullable();
                $table->string('academic_year')->nullable();
                $table->string('student_number')->nullable();
                $table->string('role')->nullable();
                $table->string('status')->default('pending');
                $table->unsignedBigInteger('invited_by')->nullable();
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::dropIfExists('project_team_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('supervisors');
    }
};
