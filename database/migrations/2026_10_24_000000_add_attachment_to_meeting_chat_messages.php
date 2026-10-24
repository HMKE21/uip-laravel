<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting chat attachments: a message can now carry one file (any type — see config/upload.php 'meetings' => ['*']).
 * Stored as a JSON blob {original_name, stored_path, extension, mime_type, size_bytes} on the message itself, so a chat
 * attachment needs no extra table. `body` stays NOT NULL; a file-only message is stored with body = ''.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meeting_chat_messages', function (Blueprint $table) {
            $table->json('attachment')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_chat_messages', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });
    }
};
