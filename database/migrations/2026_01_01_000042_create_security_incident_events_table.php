<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incident_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event_type', 60);
            $table->text('note')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->foreign('incident_id')->references('id')->on('security_incidents')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_events');
    }
};
