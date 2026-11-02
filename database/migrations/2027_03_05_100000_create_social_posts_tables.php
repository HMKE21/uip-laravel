<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نظام المنشورات الاجتماعي (Social Posts) — منفصل تمامًا عن University Feed (feed_posts / FeedApiController).
 *
 *  posts                 المنشور نفسه + إعداداته (الرؤية، التعليقات، التحميل، إخفاء العدّادات، الجدولة ...)
 *  post_media            مرفقات المنشور (صورة/فيديو/صوت/PDF/مستند/كود/أرشيف). post_id = NULL يعني مرفوع ولسه مش مربوط بمنشور
 *  post_hashtags         الوسوم (للبحث والترند والاهتمامات)
 *  post_mentions         المذكورين في المنشور
 *  post_audience         لما visibility = selected: دول بس اللي يشوفوه
 *  post_reactions        تفاعل واحد لكل مستخدم (like/love/insightful/celebrate/support/funny)
 *  post_comments         تعليقات + ردود (parent_id)
 *  post_comment_likes    إعجاب على تعليق
 *  post_saves            حفظ المنشور
 *  post_shares           سجل المشاركات
 *  post_views            مشاهدة + مدة المكوث (dwell) — وقود خوارزمية الترتيب
 *  post_follows          متابعة مستخدم
 *  post_hides            "مش مهتم" — منشور مخفي عن مستخدم
 *  post_author_mutes     كتم منشورات شخص
 *  user_post_interests   اهتمامات المستخدم (وسوم بوزن) بتتحدّث من تفاعله
 *  post_reports          بلاغات (على منشور أو تعليق)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('posts')) {
            Schema::create('posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('university_id')->nullable();
                $table->unsignedBigInteger('faculty_id')->nullable();
                $table->string('kind', 12)->default('text');          // text | media | document | code | audio | mixed | link
                $table->string('title', 180)->nullable();
                $table->mediumText('body')->nullable();
                $table->string('link_url', 500)->nullable();
                $table->string('language', 5)->nullable();
                $table->string('visibility', 12)->default('public');  // public | followers | contacts | university | faculty | selected | private
                $table->string('status', 12)->default('published');   // draft | scheduled | pending | published | archived | removed
                $table->string('comments_mode', 10)->default('everyone'); // everyone | followers | contacts | none
                $table->boolean('allow_repost')->default(1);
                $table->boolean('allow_download')->default(1);
                $table->boolean('hide_counts')->default(0);
                $table->boolean('is_sensitive')->default(0);
                $table->unsignedBigInteger('repost_of_id')->nullable();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('pinned_at')->nullable();           // تثبيت في بروفايل صاحبه
                $table->dateTime('edited_at')->nullable();
                $table->string('removed_reason', 255)->nullable();
                $table->unsignedInteger('reactions_count')->default(0);
                $table->unsignedInteger('comments_count')->default(0);
                $table->unsignedInteger('reposts_count')->default(0);
                $table->unsignedInteger('saves_count')->default(0);
                $table->unsignedInteger('shares_count')->default(0);
                $table->unsignedInteger('views_count')->default(0);
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->dateTime('deleted_at')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['status', 'published_at'], 'idx_posts_status_pub');
                $table->index(['user_id', 'status', 'published_at'], 'idx_posts_user_status_pub');
                $table->index(['university_id', 'status', 'published_at'], 'idx_posts_uni_status_pub');
                $table->index(['visibility', 'status', 'published_at'], 'idx_posts_vis_status_pub');
                $table->index(['status', 'scheduled_at'], 'idx_posts_scheduled');
                $table->index(['repost_of_id'], 'idx_posts_repost_of');
            });
        }

        if (!Schema::hasTable('post_media')) {
            Schema::create('post_media', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('post_id')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 10);                            // image | video | audio | pdf | document | code | archive | file
                $table->string('stored_path', 255);
                $table->string('thumbnail_path', 255)->nullable();     // صورة/فريم مصغّر (صورة كبيرة / فيديو / أول صفحة PDF)
                $table->string('preview_path', 255)->nullable();       // نسخة PDF متحوّلة من ppt/doc عشان تتعرض كـ carousel
                $table->string('original_name', 255);
                $table->string('mime_type', 120)->nullable();
                $table->string('extension', 12)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedInteger('page_count')->nullable();     // PDF / عرض تقديمي
                $table->string('alt_text', 300)->nullable();
                $table->string('caption', 300)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->json('meta')->nullable();                      // كود: language, lines, runner ...
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['post_id', 'sort_order'], 'idx_post_media_post');
                $table->index(['user_id', 'post_id'], 'idx_post_media_user_orphan');
            });
        }

        if (!Schema::hasTable('post_hashtags')) {
            Schema::create('post_hashtags', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->string('tag', 60);
                $table->primary(['post_id', 'tag']);
                $table->index(['tag'], 'idx_post_hashtags_tag');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_mentions')) {
            Schema::create('post_mentions', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['post_id', 'user_id']);
                $table->index(['user_id'], 'idx_post_mentions_user');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_audience')) {
            Schema::create('post_audience', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['post_id', 'user_id']);
                $table->index(['user_id'], 'idx_post_audience_user');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_reactions')) {
            Schema::create('post_reactions', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->string('type', 12)->default('like');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['post_id', 'user_id']);
                $table->index(['post_id', 'type'], 'idx_post_reactions_type');
                $table->index(['user_id', 'created_at'], 'idx_post_reactions_user');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_comments')) {
            Schema::create('post_comments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->text('body');
                $table->unsignedInteger('likes_count')->default(0);
                $table->unsignedInteger('replies_count')->default(0);
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->dateTime('edited_at')->nullable();
                $table->dateTime('deleted_at')->nullable();
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['post_id', 'parent_id', 'created_at'], 'idx_post_comments_post');
                $table->index(['user_id'], 'idx_post_comments_user');
            });
        }

        if (!Schema::hasTable('post_comment_likes')) {
            Schema::create('post_comment_likes', function (Blueprint $table) {
                $table->unsignedBigInteger('comment_id');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['comment_id', 'user_id']);
                $table->foreign('comment_id')->references('id')->on('post_comments')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_saves')) {
            Schema::create('post_saves', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['post_id', 'user_id']);
                $table->index(['user_id', 'created_at'], 'idx_post_saves_user');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_shares')) {
            Schema::create('post_shares', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->string('channel', 16)->default('link');       // link | chat | external
                $table->dateTime('created_at')->useCurrent();
                $table->index(['post_id'], 'idx_post_shares_post');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_views')) {
            Schema::create('post_views', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('viewer_id');
                $table->unsignedInteger('views')->default(1);
                $table->unsignedInteger('dwell_ms')->default(0);
                $table->boolean('completed')->default(0);             // خلّص الفيديو/كل صفحات الـ PDF
                $table->dateTime('first_viewed_at')->useCurrent();
                $table->dateTime('last_viewed_at')->useCurrent();
                $table->primary(['post_id', 'viewer_id']);
                $table->index(['viewer_id', 'last_viewed_at'], 'idx_post_views_viewer');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('viewer_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_follows')) {
            Schema::create('post_follows', function (Blueprint $table) {
                $table->unsignedBigInteger('follower_id');
                $table->unsignedBigInteger('followee_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['follower_id', 'followee_id']);
                $table->index(['followee_id'], 'idx_post_follows_followee');
                $table->foreign('follower_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('followee_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_hides')) {
            Schema::create('post_hides', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('post_id');
                $table->string('reason', 20)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['user_id', 'post_id']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_author_mutes')) {
            Schema::create('post_author_mutes', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('muted_user_id');
                $table->dateTime('created_at')->useCurrent();
                $table->primary(['user_id', 'muted_user_id']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('muted_user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('user_post_interests')) {
            Schema::create('user_post_interests', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->string('tag', 60);
                $table->float('weight')->default(0);
                $table->dateTime('updated_at')->useCurrent();
                $table->primary(['user_id', 'tag']);
                $table->index(['user_id', 'weight'], 'idx_user_post_interests_weight');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('post_reports')) {
            Schema::create('post_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('comment_id')->nullable();
                $table->unsignedBigInteger('reporter_id');
                $table->string('reason', 30);
                $table->text('details')->nullable();
                $table->string('status', 12)->default('pending');     // pending | resolved | dismissed
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->string('resolution', 30)->nullable();         // dismissed | post_removed | comment_removed
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('reporter_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['status', 'created_at'], 'idx_post_reports_status');
                $table->unique(['post_id', 'comment_id', 'reporter_id'], 'uq_post_reports_once');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'post_reports', 'user_post_interests', 'post_author_mutes', 'post_hides', 'post_follows', 'post_views',
            'post_shares', 'post_saves', 'post_comment_likes', 'post_comments', 'post_reactions', 'post_audience',
            'post_mentions', 'post_hashtags', 'post_media', 'posts',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
