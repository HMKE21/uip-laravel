<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_geo_cache', function (Blueprint $table) {
            $table->string('ip_address', 45);
            $table->char('country_code', 2)->nullable();
            $table->string('country_name', 100)->nullable();
            $table->enum('lookup_status', ['ok', 'unknown', 'error'])->default('unknown');
            $table->dateTime('resolved_at')->useCurrent();
            $table->primary(['ip_address']);
            $table->index(['resolved_at'], 'idx_geo_cache_resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_geo_cache');
    }
};
