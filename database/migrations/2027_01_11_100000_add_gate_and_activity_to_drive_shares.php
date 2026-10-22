<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "جهازي" ▸ روابط المشاركة: بوابة قبل الفتح (باسورد + بيانات الزائر) + إيقاف مؤقت + سجل مين فتح الرابط.
 *
 * drive_shares:
 *   require_visitor_info  الزائر لازم يكتب اسمه + إيميله + موبايله قبل ما يشوف أي حاجة.
 *   password_hash         باسورد الرابط (bcrypt) — مش بيترجّع للفرونت أبدًا.
 *   gate_version          بيزيد لما الباسورد يتغيّر أو يتفعّل طلب البيانات => كل جلسات الزوار القديمة بتتلغي.
 *   paused_at             إيقاف مؤقت (يتكمّل بعدين) — غير "revoked_at" اللي هو إلغاء نهائي.
 * drive_share_visitors: جلسة زائر عدّى البوابة (الاسم/الإيميل/الموبايل اللي كتبهم + IP مخفي جزئيًا + الجهاز).
 * drive_share_events:   سجل الأحداث (فتح / معاينة ملف / تنزيل / zip / باسورد غلط / دخول من البوابة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drive_shares')) {
            Schema::table('drive_shares', function (Blueprint $table) {
                if (!Schema::hasColumn('drive_shares', 'require_visitor_info')) {
                    $table->boolean('require_visitor_info')->default(false);
                }
                if (!Schema::hasColumn('drive_shares', 'password_hash')) {
                    $table->string('password_hash', 255)->nullable();
                }
                if (!Schema::hasColumn('drive_shares', 'gate_version')) {
                    $table->unsignedInteger('gate_version')->default(1);
                }
                if (!Schema::hasColumn('drive_shares', 'paused_at')) {
                    $table->dateTime('paused_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('drive_share_visitors')) {
            Schema::create('drive_share_visitors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('share_id');
                $table->string('session_hash', 64)->unique();       // sha256 للـ session token (الأصلي عند الزائر بس)
                $table->unsignedInteger('gate_version')->default(1);
                $table->string('name', 120)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('phone', 32)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();   // لو الزائر كان مسجّل دخول في UIP
                $table->string('ip_masked', 64)->nullable();         // 41.35.12.x
                $table->string('ip_hash', 40)->nullable();
                $table->string('device', 120)->nullable();           // Chrome · Windows · Desktop
                $table->unsignedInteger('opens')->default(0);
                $table->unsignedInteger('downloads')->default(0);
                $table->dateTime('first_seen_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->timestamps();

                $table->index(['share_id', 'email'], 'dsv_share_email_idx');
            });
        }

        if (!Schema::hasTable('drive_share_events')) {
            Schema::create('drive_share_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('share_id');
                $table->unsignedBigInteger('visitor_id')->nullable();
                $table->string('type', 20);                          // open | preview | download | zip | enter | password_failed
                $table->unsignedBigInteger('node_id')->nullable();
                $table->string('node_name', 255)->nullable();
                $table->string('ip_masked', 64)->nullable();
                $table->string('ip_hash', 40)->nullable();
                $table->string('device', 120)->nullable();
                $table->dateTime('created_at')->nullable();

                $table->index(['share_id', 'id'], 'dse_share_idx');
                $table->index(['share_id', 'type'], 'dse_share_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_share_events');
        Schema::dropIfExists('drive_share_visitors');
        if (Schema::hasTable('drive_shares')) {
            Schema::table('drive_shares', function (Blueprint $table) {
                foreach (['require_visitor_info', 'password_hash', 'gate_version', 'paused_at'] as $c) {
                    if (Schema::hasColumn('drive_shares', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
