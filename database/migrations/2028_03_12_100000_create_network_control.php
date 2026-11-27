<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network Control (بند 4 من تطوير بورتال الأمان):
 *  - network_trusted_ips : قائمة "موثوق / لا تحظر أبدًا" (IP أو CIDR) — بتتخطّى الحظر اليدوي/التلقائي، حظر الدولة،
 *                          سياسة IP، حد المعدل العام وقواعد المعدل. ممكن تنتهي (expires_at) ولها تسمية.
 *  - network_rate_rules  : قواعد حد معدل لمسارات محددة (مثلًا api/v1/auth/*) — حد في الدقيقة، نطاق (IP أو المستخدم)،
 *                          وإجراء (429 بس، أو حظر الـ IP لمدة).
 *  - blocked_ips         : بدون تغيير في الشكل — بقى بيقبل CIDR في ip_address (فيه '/') و source = manual | auto | import | rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('network_trusted_ips')) {
            Schema::create('network_trusted_ips', function (Blueprint $t) {
                $t->id();
                $t->string('ip_address', 64);              // IP أو CIDR
                $t->string('label', 160)->nullable();
                $t->unsignedBigInteger('added_by')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->unique('ip_address', 'uq_net_trusted_ip');
                $t->index('expires_at', 'idx_net_trusted_exp');
            });
        }

        if (!Schema::hasTable('network_rate_rules')) {
            Schema::create('network_rate_rules', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->string('path_pattern', 190);           // نمط Laravel is() — مثال: api/v1/auth/*
                $t->string('method', 8)->default('ANY');  // ANY | GET | POST | PUT | PATCH | DELETE
                $t->unsignedInteger('limit_per_min');
                $t->string('scope', 8)->default('ip');     // ip | user
                $t->string('action', 8)->default('throttle'); // throttle | block
                $t->unsignedInteger('block_minutes')->nullable(); // مع action=block (null/0 = دائم)
                $t->boolean('enabled')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index('enabled', 'idx_net_rate_enabled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_rate_rules');
        Schema::dropIfExists('network_trusted_ips');
    }
};
