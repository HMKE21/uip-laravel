<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_seed_batches', function (Blueprint $table) {
            $table->id();
            $table->char('batch_id', 36);
            $table->string('label', 150)->nullable();
            $table->unsignedInteger('students_count')->default(0);
            $table->enum('status', ['running', 'completed', 'failed', 'deleted'])->default('running');
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_seed_batches');
    }
};
