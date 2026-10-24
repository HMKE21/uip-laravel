<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفحات الدليل (Portal Guides): شرح كامل لكل بوابة (كل صفحة بتعمل إيه، مكانها فين، وإزاي تنفّذ المهمة)،
 * قابل للتعديل من الأدمن (أو أي دور معاه guides.manage).
 *
 *  portal_guides           دليل واحد لكل بوابة (عنوان + مقدمة + version بيزيد مع كل تعديل).
 *  portal_guide_sections   أقسام الدليل (نظرة عامة / مجموعات الـ sidebar / مهام / أسئلة شائعة ...).
 *  portal_guide_items      عناصر القسم (صفحة / ميزة / مهمة / سؤال) — عربي + إنجليزي.
 *  portal_guide_revisions  سجل التعديلات (قبل/بعد) — للمراجعة والاسترجاع.
 *  portal_guide_views      آخر نسخة شافها كل مستخدم (عشان نعرض "جديد" لما الأدمن يعدّل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_guides', function (Blueprint $table) {
            $table->id();
            $table->string('portal', 32)->unique();
            $table->string('title_ar', 160);
            $table->string('title_en', 160);
            $table->string('subtitle_ar', 255)->nullable();
            $table->string('subtitle_en', 255)->nullable();
            $table->text('intro_ar')->nullable();
            $table->text('intro_en')->nullable();
            $table->string('icon', 48)->default('book');
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('version')->default(1);
            // مفاتيح أقسام/عناصر افتراضية الأدمن مسحها بنفسه ("section:x" / "item:y") — الـ sync ميرجّعهاش لوحده.
            $table->json('deleted_keys')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_guide_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guide_id');
            $table->string('key', 80);
            $table->string('kind', 24)->default('group'); // overview | group | tasks | faq | custom
            $table->string('title_ar', 160);
            $table->string('title_en', 160);
            $table->string('summary_ar', 500)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->string('icon', 48)->nullable();
            $table->json('audience_roles')->nullable(); // null = كل أدوار البوابة
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['guide_id', 'key']);
            $table->foreign('guide_id')->references('id')->on('portal_guides')->cascadeOnDelete();
        });

        Schema::create('portal_guide_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guide_id');
            $table->unsignedBigInteger('section_id');
            $table->string('key', 80);
            $table->string('title_ar', 160);
            $table->string('title_en', 160);
            $table->string('summary_ar', 500)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('route', 255)->nullable();       // مسار داخلي في الفرونت (يبدأ بـ /)
            $table->string('permission', 100)->nullable();  // صلاحية لازمة لاستخدام الميزة (للعرض "مقفول")
            $table->string('icon', 48)->nullable();
            $table->json('steps')->nullable();              // [{ar,en}, ...]
            $table->json('tips')->nullable();               // [{ar,en}, ...]
            $table->json('audience_roles')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['guide_id', 'key']);
            $table->index(['section_id', 'sort_order']);
            $table->foreign('guide_id')->references('id')->on('portal_guides')->cascadeOnDelete();
            $table->foreign('section_id')->references('id')->on('portal_guide_sections')->cascadeOnDelete();
        });

        Schema::create('portal_guide_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guide_id');
            $table->string('entity', 16);        // guide | section | item
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 16);        // create | update | delete | reset | restore
            $table->string('label', 200)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('restored_from')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guide_id', 'id']);
            $table->foreign('guide_id')->references('id')->on('portal_guides')->cascadeOnDelete();
        });

        Schema::create('portal_guide_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('guide_id');
            $table->unsignedInteger('seen_version')->default(0);
            $table->timestamp('seen_at')->nullable();

            $table->unique(['user_id', 'guide_id']);
            $table->foreign('guide_id')->references('id')->on('portal_guides')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_guide_views');
        Schema::dropIfExists('portal_guide_revisions');
        Schema::dropIfExists('portal_guide_items');
        Schema::dropIfExists('portal_guide_sections');
        Schema::dropIfExists('portal_guides');
    }
};
