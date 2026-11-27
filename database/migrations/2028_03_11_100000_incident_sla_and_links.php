<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incident Response (بند 3): مهلة SLA حسب الخطورة + ربط الحادثة بالتنبيهات والسجلات والمستخدمين.
 *  - security_incidents.sla_due_at  : موعد استحقاق الحل (detected_at + ساعات الخطورة من config/security.php).
 *  - security_incident_links        : (incident, type alert|log|user, ref) — ref رقم تنبيه/سجل/مستخدم، أو نص حر لمرجع سجل ملفّي.
 * الحوادث القديمة بتتملّى بأثر رجعي، وأعمدة affected_user_id / related_log_ref القديمة بتتنسخ كروابط.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_incidents') && !Schema::hasColumn('security_incidents', 'sla_due_at')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->dateTime('sla_due_at')->nullable()->after('resolved_at');
                $table->index(['sla_due_at'], 'idx_incidents_sla_due');
            });
        }

        if (!Schema::hasTable('security_incident_links')) {
            Schema::create('security_incident_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('incident_id');
                $table->string('link_type', 10); // alert | log | user
                $table->string('ref', 64);
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('incident_id')->references('id')->on('security_incidents')->onDelete('cascade');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                $table->unique(['incident_id', 'link_type', 'ref'], 'uq_incident_link');
                $table->index(['link_type', 'ref'], 'idx_incident_link_ref');
            });
        }

        if (!Schema::hasTable('security_incidents')) {
            return;
        }

        // backfill SLA
        $hours = (array) config('security.incident_sla_hours', ['critical' => 4, 'high' => 24, 'medium' => 72, 'low' => 168]);
        DB::table('security_incidents')->whereNull('sla_due_at')->orderBy('id')->chunkById(200, function ($rows) use ($hours) {
            foreach ($rows as $r) {
                $h = (int) ($hours[$r->severity] ?? 72);
                $base = strtotime((string) ($r->detected_at ?: $r->created_at)) ?: time();
                DB::table('security_incidents')->where('id', $r->id)->update(['sla_due_at' => date('Y-m-d H:i:s', $base + $h * 3600)]);
            }
        });

        // legacy single columns -> links
        foreach (DB::table('security_incidents')->whereNotNull('affected_user_id')->get(['id', 'affected_user_id', 'created_by']) as $r) {
            DB::table('security_incident_links')->insertOrIgnore([
                'incident_id' => $r->id, 'link_type' => 'user', 'ref' => (string) $r->affected_user_id, 'created_by' => $r->created_by, 'created_at' => now(),
            ]);
        }
        foreach (DB::table('security_incidents')->whereNotNull('related_log_ref')->where('related_log_ref', '!=', '')->get(['id', 'related_log_ref', 'created_by']) as $r) {
            DB::table('security_incident_links')->insertOrIgnore([
                'incident_id' => $r->id, 'link_type' => 'log', 'ref' => (string) $r->related_log_ref, 'created_by' => $r->created_by, 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_links');
        if (Schema::hasTable('security_incidents') && Schema::hasColumn('security_incidents', 'sla_due_at')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->dropIndex('idx_incidents_sla_due');
                $table->dropColumn('sla_due_at');
            });
        }
    }
};
