<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images and dean details a university stamps on its graduation certificates:
 * the university signature, its stamp, the dean's signature, and the dean's name/title.
 * Paths are relative to public/ (same convention as logo_path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->string('signature_path', 255)->nullable()->after('logo_path');
            $table->string('stamp_path', 255)->nullable()->after('signature_path');
            $table->string('dean_signature_path', 255)->nullable()->after('stamp_path');
            $table->string('dean_name_en', 200)->nullable()->after('dean_signature_path');
            $table->string('dean_name_ar', 200)->nullable()->after('dean_name_en');
            $table->string('dean_title_en', 200)->nullable()->after('dean_name_ar');
            $table->string('dean_title_ar', 200)->nullable()->after('dean_title_en');
        });
    }

    public function down(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->dropColumn([
                'signature_path', 'stamp_path', 'dean_signature_path',
                'dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar',
            ]);
        });
    }
};
