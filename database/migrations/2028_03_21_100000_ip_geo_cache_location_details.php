<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Threat Monitor — تحديد مكان الـ IP بدقة المدينة (مش الدولة بس).
 * بنزوّد ip_geo_cache بالمدينة/المنطقة/الإحداثيات/دقة التقدير/المزوّد (ISP/ASN)/نوع الاتصال
 * (datacenter / VPN-proxy / mobile / broadband) ودرجة الثقة. كل الأعمدة nullable فالصفوف القديمة
 * (دولة بس) تفضل شغّالة، والـ Threat Monitor بيرقّيها تدريجيًا (detail_at فاضي = محتاج ترقية).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ip_geo_cache')) {
            return;
        }

        Schema::table('ip_geo_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('ip_geo_cache', 'region')) {
                $table->string('region', 100)->nullable()->after('country_name');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'city')) {
                $table->string('city', 100)->nullable()->after('region');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'postal')) {
                $table->string('postal', 20)->nullable()->after('city');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'latitude')) {
                $table->decimal('latitude', 9, 6)->nullable()->after('postal');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'longitude')) {
                $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'accuracy_km')) {
                $table->unsignedSmallInteger('accuracy_km')->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('accuracy_km');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'isp')) {
                $table->string('isp', 160)->nullable()->after('timezone');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'asn')) {
                $table->string('asn', 24)->nullable()->after('isp');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'connection_type')) {
                $table->string('connection_type', 16)->nullable()->after('asn');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'is_proxy')) {
                $table->boolean('is_proxy')->default(false)->after('connection_type');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'is_hosting')) {
                $table->boolean('is_hosting')->default(false)->after('is_proxy');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'is_mobile')) {
                $table->boolean('is_mobile')->default(false)->after('is_hosting');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'confidence')) {
                $table->unsignedTinyInteger('confidence')->nullable()->after('is_mobile');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'sources')) {
                $table->string('sources', 120)->nullable()->after('confidence');
            }
            if (!Schema::hasColumn('ip_geo_cache', 'detail_at')) {
                $table->dateTime('detail_at')->nullable()->after('resolved_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ip_geo_cache')) {
            return;
        }

        $cols = array_values(array_filter([
            'region', 'city', 'postal', 'latitude', 'longitude', 'accuracy_km', 'timezone', 'isp', 'asn',
            'connection_type', 'is_proxy', 'is_hosting', 'is_mobile', 'confidence', 'sources', 'detail_at',
        ], fn ($c) => Schema::hasColumn('ip_geo_cache', $c)));

        if ($cols) {
            Schema::table('ip_geo_cache', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
