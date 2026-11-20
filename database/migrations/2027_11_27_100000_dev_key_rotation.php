<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Developer Center — Rotate لمفتاح الـ API.
 *  - dev_api_keys.rotated_from_id: المفتاح القديم اللي المفتاح ده خلفه.
 *  - dev_api_keys.rotated_at: وقت ما المفتاح ده اتبدّل بمفتاح جديد (لو لسه مبطلش، يبقى في فترة السماح وexpires_at هو نهايتها).
 * مبيمسحش حاجة، وتقدر تشغّله أكتر من مرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dev_api_keys')) {
            return;
        }
        Schema::table('dev_api_keys', function (Blueprint $t) {
            if (!Schema::hasColumn('dev_api_keys', 'rotated_from_id')) {
                $t->unsignedBigInteger('rotated_from_id')->nullable()->index('idx_dev_keys_rotated_from');
            }
            if (!Schema::hasColumn('dev_api_keys', 'rotated_at')) {
                $t->dateTime('rotated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('dev_api_keys')) {
            return;
        }
        Schema::table('dev_api_keys', function (Blueprint $t) {
            if (Schema::hasColumn('dev_api_keys', 'rotated_from_id')) {
                $t->dropIndex('idx_dev_keys_rotated_from');
                $t->dropColumn('rotated_from_id');
            }
            if (Schema::hasColumn('dev_api_keys', 'rotated_at')) {
                $t->dropColumn('rotated_at');
            }
        });
    }
};
