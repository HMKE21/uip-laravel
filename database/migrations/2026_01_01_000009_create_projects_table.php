<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('university_id')->nullable();
            $table->string('title_ar', 255);
            $table->string('title_en', 255)->nullable();
            $table->text('summary');
            $table->longText('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->json('tags')->nullable();
            $table->string('cover_image_path', 255)->nullable();
            $table->enum('visibility', ['private', 'university_only', 'public'])->default('private');
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'published', 'archived'])->default('draft');
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('supervisor_name', 150)->nullable();
            $table->json('sdgs')->nullable();
            $table->json('technologies')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->date('timeline_start')->nullable();
            $table->date('timeline_end')->nullable();
            $table->json('keywords')->nullable();
            $table->string('slug', 255)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_featured')->default(0);
            $table->integer('featured_order')->default(0);
            $table->dateTime('featured_at')->nullable();
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('university_id')->references('id')->on('universities')->onDelete('set null');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->unique(['uuid']);
            $table->unique(['slug'], 'uq_projects_slug');
            $table->index(['status'], 'idx_projects_status');
            $table->index(['category'], 'idx_projects_category');
            $table->index(['category_id'], 'idx_projects_category_id');
            $table->index(['is_featured', 'featured_order'], 'idx_projects_featured');
            $table->fullText(['title_ar', 'title_en', 'summary'], 'ft_projects_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
