<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 4 (WebRTC Core): بند 34 (Connection
 * Quality). additive زي كل migration قبلها في الموديول ده — عمود واحد
 * بس، مفيش doctrine/dbal.
 *
 * زي Round 3 بالظبط: نفس العمود على meeting_participants **و**
 * meeting_join_requests سوا (نفس السبب — راجع docblock migration
 * 2026_08_31_030000).
 *
 * القيمة دي "آخر تقييم وصل من الفرونت" بس — الفرونت هو اللي بيحسبها
 * فعليًا من WebRTC stats API (RTCPeerConnection.getStats(): packet
 * loss/jitter/round-trip time) عند كل peer اتصال في الـ mesh، مش
 * لارافيل (لارافيل مالوش أي رؤية على الاتصال الفعلي بين المتصفحات —
 * راجع docblock MeetingSignalingService::updateConnectionQuality()).
 * بنسجلها هنا لنفس سببي Round 3: (أ) عرضها في GET roster من غير
 * الحاجة لفتح WebSocket، (ب) استرجاعها بعد refresh.
 *
 * default='good' مش 'excellent' عمدًا — قيمة متفائلة معتدلة لحد ما أول
 * قياس فعلي يوصل من الفرونت بعد ما الـ peer connection يتأسس (فيه فرق
 * بضع ثواني بين "connected" في connection_state وأول getStats() نتيجة
 * فعلية).
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->enum('connection_quality', ['excellent', 'good', 'poor', 'reconnecting'])
                    ->default('good')->after('connection_state');
            });
        }
    }

    public function down(): void
    {
        foreach (['meeting_participants', 'meeting_join_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('connection_quality');
            });
        }
    }
};
