<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تشخيص طلبات التطبيق (صفحة /admin/mobile/{id}):
 *  - request_bytes / duration_ms / content_type: حجم الطلب ومدة المعالجة ونوع المحتوى (multipart = رفع ملف).
 *  - source = 'server' (طلب وصل للسيرفر) | 'client' (التطبيق نفسه بلّغ إن عملية فشلت قبل ما توصل للسيرفر، مثلًا رفع فيديو مات).
 *  - meta = تفاصيل إضافية (JSON) من التطبيق: حجم/نوع الملف، وصل كام بايت، المرحلة، الشبكة...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_token_usages', function (Blueprint $table) {
            if (!Schema::hasColumn('api_token_usages', 'source')) {
                $table->unsignedBigInteger('request_bytes')->nullable()->after('status');
                $table->unsignedInteger('duration_ms')->nullable()->after('request_bytes');
                $table->string('content_type', 80)->nullable()->after('duration_ms');
                $table->string('source', 10)->default('server')->after('outcome');
                $table->text('meta')->nullable()->after('error_detail');
                $table->index(['api_token_id', 'source'], 'idx_api_token_usages_token_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_token_usages', function (Blueprint $table) {
            $table->dropIndex('idx_api_token_usages_token_source');
            $table->dropColumn(['request_bytes', 'duration_ms', 'content_type', 'source', 'meta']);
        });
    }
};
