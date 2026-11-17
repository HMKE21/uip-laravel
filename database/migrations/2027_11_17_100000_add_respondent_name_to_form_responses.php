<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('form_responses') && !Schema::hasColumn('form_responses', 'respondent_name')) {
            Schema::table('form_responses', function (Blueprint $t) {
                $t->string('respondent_name', 190)->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('form_responses', 'respondent_name')) {
            Schema::table('form_responses', function (Blueprint $t) {
                $t->dropColumn('respondent_name');
            });
        }
    }
};
