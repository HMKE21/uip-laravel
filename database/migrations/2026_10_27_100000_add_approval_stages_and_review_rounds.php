<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مسار الاعتماد الإجباري (دكتور مشرف ← كلية ← جامعة):
 *  - project_approvals.stage كان enum بقيمتين (university_review/admin_review) فبنحوّله string
 *    عشان supervisor_review وfaculty_review يتسجلوا كمراحل مستقلة (4.4) من غير migration كل مرة.
 *    القيم المسموحة بتتفحص في App\Support\ApprovalFlow.
 *  - project_approvals.review_round: دورة التقديم اللي القرار اتاخد فيها. إعادة التقديم بعد
 *    رفض/طلب تعديل = دورة جديدة، وموافقات الدورة القديمة مبتتحسبش فيها.
 *  - projects.review_round: الدورة الحالية. projects.approved_at: وقت الاعتماد النهائي، منفصل
 *    عن published_at (4.2: معتمد ≠ منشور).
 * الصفوف القديمة: review_round = 0 (قبل التسلسل الإجباري) ومبتتحسبش في أي دورة.
 * المشاريع المنشورة قبل كده: approved_at = published_at عشان تاريخ الاعتماد يفضل ظاهر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_approvals')) {
            if (DB::getDriverName() === 'pgsql') {
                // enum في postgres = varchar + CHECK؛ ->change() مبيشيلش الـ CHECK.
                DB::statement('ALTER TABLE project_approvals DROP CONSTRAINT IF EXISTS project_approvals_stage_check');
            }
            Schema::table('project_approvals', function (Blueprint $table) {
                $table->string('stage', 40)->change();
            });
            if (!Schema::hasColumn('project_approvals', 'review_round')) {
                Schema::table('project_approvals', function (Blueprint $table) {
                    $table->unsignedInteger('review_round')->default(0)->after('stage');
                    $table->index(['project_id', 'review_round', 'stage'], 'idx_approvals_round_stage');
                });
            }
        }

        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (!Schema::hasColumn('projects', 'review_round')) {
                    $table->unsignedInteger('review_round')->default(0);
                }
                if (!Schema::hasColumn('projects', 'approved_at')) {
                    $table->dateTime('approved_at')->nullable();
                }
            });
            DB::table('projects')->where('status', 'published')->whereNull('approved_at')
                ->whereNotNull('published_at')->update(['approved_at' => DB::raw('published_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                foreach (['review_round', 'approved_at'] as $col) {
                    if (Schema::hasColumn('projects', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('project_approvals') && Schema::hasColumn('project_approvals', 'review_round')) {
            Schema::table('project_approvals', function (Blueprint $table) {
                $table->dropIndex('idx_approvals_round_stage');
                $table->dropColumn('review_round');
            });
        }
        // stage بيفضل string عمدًا: الرجوع لـ enum مش آمن بعد ما صفوف supervisor_review/faculty_review اتسجلت.
    }
};
