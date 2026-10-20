<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_intents', function (Blueprint $table) {
            $table->id();
            $table->string('intent_key', 80);
            $table->string('category', 60)->default('general');
            $table->string('role', 255)->nullable();
            $table->string('question_ar', 500)->default('');
            $table->string('question_en', 500)->default('');
            $table->text('answer_ar');
            $table->text('answer_en');
            $table->json('aliases_ar')->nullable();
            $table->json('aliases_en')->nullable();
            $table->json('aliases_mixed')->nullable();
            $table->json('keywords')->nullable();
            $table->integer('priority')->default(0);
            $table->decimal('confidence_threshold', 3, 2)->default(0.75);
            $table->boolean('is_active')->default(1);
            $table->unsignedInteger('hit_count')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['intent_key'], 'uq_faq_intents_key');
            $table->index(['is_active', 'category'], 'idx_faq_intents_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_intents');
    }
};
