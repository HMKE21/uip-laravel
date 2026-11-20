<?php

use App\Services\Developer\DevAppReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Developer Platform — App Review (موافقة الجامعة/المنصة على التطبيق قبل ما مستخدمين تانيين يوافقوا عليه).
 *  - dev_apps: review_status (none|pending|approved|rejected) + اللي اتوافق عليه بالظبط (review_scopes + review_redirects_hash)،
 *    فأي تعديل بعد الموافقة (scope جديد أو redirect مختلف) بيرجّع التطبيق "محتاج مراجعة" من غير ما حد يفتكر.
 *  - dev_tenant_policies.review_mode: off | sensitive | all (null = الافتراضي sensitive، أو سياسة المنصة لو أشد).
 *  - dev_app_review_events: سجل تدقيق (إرسال / سحب / موافقة / رفض / إبطال).
 * التطبيقات الموجودة قبل الـ migration دي بتتعلّم approved (grandfathered) عشان التكاملات الشغالة متقفش.
 * مبيمسحش حاجة، وتقدر تشغّله أكتر من مرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dev_app_review_events')) {
            Schema::create('dev_app_review_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('actor_role', 12)->nullable();                        // owner | university | admin | system
                $t->string('action', 16);                                        // submitted | withdrawn | approved | rejected | invalidated
                $t->string('note', 500)->nullable();
                $t->json('scopes')->nullable();                                  // الـ scopes وقت الحدث
                $t->dateTime('created_at')->useCurrent();
                $t->index(['app_id', 'id'], 'idx_dev_review_app');
            });
        }

        if (Schema::hasTable('dev_apps')) {
            $fresh = !Schema::hasColumn('dev_apps', 'review_status');
            Schema::table('dev_apps', function (Blueprint $t) {
                if (!Schema::hasColumn('dev_apps', 'review_status')) {
                    $t->string('review_status', 12)->default('none');            // none | pending | approved | rejected
                }
                if (!Schema::hasColumn('dev_apps', 'review_message')) {
                    $t->string('review_message', 500)->nullable();               // مبرر المطوّر وقت الإرسال
                }
                if (!Schema::hasColumn('dev_apps', 'review_note')) {
                    $t->string('review_note', 500)->nullable();                  // ملاحظة المراجع (سبب الرفض مثلًا)
                }
                if (!Schema::hasColumn('dev_apps', 'review_requested_at')) {
                    $t->dateTime('review_requested_at')->nullable();
                }
                if (!Schema::hasColumn('dev_apps', 'reviewed_at')) {
                    $t->dateTime('reviewed_at')->nullable();
                }
                if (!Schema::hasColumn('dev_apps', 'reviewed_by')) {
                    $t->unsignedBigInteger('reviewed_by')->nullable();
                }
                if (!Schema::hasColumn('dev_apps', 'reviewed_by_role')) {
                    $t->string('reviewed_by_role', 12)->nullable();              // university | admin | system
                }
                if (!Schema::hasColumn('dev_apps', 'review_scopes')) {
                    $t->json('review_scopes')->nullable();                       // الـ scopes اللي اتوافق عليها بالظبط
                }
                if (!Schema::hasColumn('dev_apps', 'review_redirects_hash')) {
                    $t->char('review_redirects_hash', 64)->nullable();           // sha256 لقائمة الـ redirect URIs اللي اتوافق عليها
                }
            });
            if (!Schema::hasIndex('dev_apps', 'idx_dev_apps_review')) {
                Schema::table('dev_apps', fn (Blueprint $t) => $t->index(['review_status', 'university_id'], 'idx_dev_apps_review'));
            }

            if ($fresh) { // Grandfather: اللي شغال دلوقتي يفضل شغال، وأي تعديل بعد كده بيتراجع.
                DB::table('dev_apps')->orderBy('id')->chunkById(200, function ($apps) {
                    foreach ($apps as $a) {
                        $scopes = $a->scopes ?: json_encode([]);
                        DB::table('dev_apps')->where('id', $a->id)->update([
                            'review_status' => 'approved', 'review_scopes' => $scopes,
                            'review_redirects_hash' => DevAppReview::redirectsHash((array) json_decode((string) $a->redirect_uris, true)),
                            'reviewed_at' => now(), 'reviewed_by' => null, 'reviewed_by_role' => 'system',
                            'review_note' => 'Approved automatically (existing app).',
                        ]);
                        DB::table('dev_app_review_events')->insert(['app_id' => $a->id, 'actor_user_id' => null, 'actor_role' => 'system', 'action' => 'approved',
                            'note' => 'Approved automatically (existing app).', 'scopes' => $scopes, 'created_at' => now()]);
                    }
                });
            }
        }

        if (Schema::hasTable('dev_tenant_policies') && !Schema::hasColumn('dev_tenant_policies', 'review_mode')) {
            Schema::table('dev_tenant_policies', function (Blueprint $t) {
                $t->string('review_mode', 10)->nullable();                       // off | sensitive | all — null = الافتراضي أو سياسة المنصة
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_app_review_events');
        if (Schema::hasTable('dev_tenant_policies') && Schema::hasColumn('dev_tenant_policies', 'review_mode')) {
            Schema::table('dev_tenant_policies', fn (Blueprint $t) => $t->dropColumn('review_mode'));
        }
        if (Schema::hasTable('dev_apps')) {
            if (Schema::hasIndex('dev_apps', 'idx_dev_apps_review')) {
                Schema::table('dev_apps', fn (Blueprint $t) => $t->dropIndex('idx_dev_apps_review'));
            }
            foreach (['review_status', 'review_message', 'review_note', 'review_requested_at', 'reviewed_at', 'reviewed_by', 'reviewed_by_role', 'review_scopes', 'review_redirects_hash'] as $c) {
                if (Schema::hasColumn('dev_apps', $c)) {
                    Schema::table('dev_apps', fn (Blueprint $t) => $t->dropColumn($c));
                }
            }
        }
    }
};
