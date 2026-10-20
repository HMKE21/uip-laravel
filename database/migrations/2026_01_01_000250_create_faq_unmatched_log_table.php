<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_unmatched_log', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_question', 500);
            $table->string('sample_question', 500);
            $table->string('language', 10)->default('unknown');
            $table->string('role', 60)->nullable();
            $table->string('portal', 40)->nullable();
            $table->string('best_candidate_key', 80)->nullable();
            $table->decimal('best_candidate_score', 4, 3)->nullable();
            $table->unsignedInteger('hit_count')->default(1);
            $table->dateTime('last_seen_at')->useCurrent();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['normalized_question'], 'uq_faq_unmatched_normalized');
            $table->index(['hit_count'], 'idx_faq_unmatched_hits');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_unmatched_log');
    }
};
