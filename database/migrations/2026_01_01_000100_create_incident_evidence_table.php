<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('original_filename', 255);
            $table->string('stored_path', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('incident_id')->references('id')->on('security_incidents')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['incident_id'], 'idx_incident_evidence_incident');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
    }
};
