<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مجلدات المحفوظات (زي فيسبوك): كل مستخدم يعمل مجلدات، وكل منشور محفوظ ممكن يتحط في مجلد (أو يفضل بدون مجلد). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('post_collections')) {
            Schema::create('post_collections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name', 60);
                $table->dateTime('created_at')->useCurrent();
                $table->unique(['user_id', 'name'], 'uq_post_collections_user_name');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('post_saves') && !Schema::hasColumn('post_saves', 'collection_id')) {
            Schema::table('post_saves', function (Blueprint $table) {
                $table->unsignedBigInteger('collection_id')->nullable()->after('user_id');
                $table->index(['user_id', 'collection_id'], 'idx_post_saves_collection');
                $table->foreign('collection_id')->references('id')->on('post_collections')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('post_saves', 'collection_id')) {
            Schema::table('post_saves', function (Blueprint $table) {
                $table->dropForeign(['collection_id']);
                $table->dropIndex('idx_post_saves_collection');
                $table->dropColumn('collection_id');
            });
        }
        Schema::dropIfExists('post_collections');
    }
};
