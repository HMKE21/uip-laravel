<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 5 (Live Collaboration): بند 7 (Meeting
 * Chat)، بند 8 (Private Chat). جدولين جداد بالكامل (مش عمود إضافي على
 * جدول موجود زي باقي migrations الموديول ده) لأن الرسالة كيان جديد
 * مالوش وجود قبل كده.
 *
 * ليه جدول منفصل عن message_reactions/message_mentions/... الموجودين
 * أصلًا (موديول Messaging العام): الجداول دي مبنية على users.id مباشرة
 * (foreignId user_id)، لكن شات الاجتماع لازم يستوعب الضيوف كمان (بند
 * 23) اللي مالهمش صف في users أصلًا — فبنستخدم نفس نمط "actor موحّد"
 * اللي MeetingSignalingService بنى عليه كل حاجة (key نصي زي 'user:5'
 * أو 'guest:12' بدل foreign key حقيقي)، تمامًا زي عمود connection_state
 * على meeting_participants/meeting_join_requests. sender_key هنا نفس
 * $actor['key'] بالظبط.
 *
 * public/private في نفس الجدول (مش جدولين): recipient_key=null يعني
 * رسالة عامة (تظهر لكل الحاضرين)، recipient_key=<key> يعني رسالة خاصة
 * (بند 8 — "Private messages must not appear in the public meeting
 * chat") ميظهرش إلا لصاحبها والمُرسِل بس. راجع docblock
 * MeetingChatService::visibleTo() للفلترة الفعلية، وdocblock
 * MeetingSignalingService::personalChannelName() ليه التوصيل اللحظي
 * لرسالة خاصة بيعدي على قناة شخصية منفصلة مش الـ presence channel العام
 * (عشان الداتا الخاصة ميوصلش أصلًا لسوكيت حد تاني، مش بس "يتفلتر" في
 * الفرونت).
 *
 * type='system': رسائل تلقائية زي "Ahmed joined the meeting"/"Sara
 * raised her hand"/"Mohamed started sharing his screen" (أمثلة بند 7
 * بالظبط) — sender_key='system' ثابت، مفيش actor حقيقي وراها. مخزّنة
 * في نفس جدول الرسائل (مش جدول منفصل) عشان تظهر في نفس الـ timeline
 * بالترتيب الصحيح من غير merge لسيتين مختلفتين في الفرونت.
 *
 * reply_to_message_id: self-reference nullable بس (بند 7 — "Reply"،
 * thread بسيط مستوى واحد مش متداخل).
 *
 * mentioned_keys: JSON array من actor keys (بند 7 — "Mentions") —
 * مش جدول pivot منفصل زي message_mentions لأن مفيش استعلام "كل
 * الرسائل اللي فيها منشن ليّا" مطلوب هنا (chat الاجتماع مؤقت بعمر
 * الاجتماع نفسه، مش feed دائم زي الموديول العام)، الفرونت بيقرا العمود
 * ده بس عشان يهايلايت اسم المستخدم جوه نص الرسالة.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            $table->string('sender_key', 40);
            $table->enum('sender_type', ['participant', 'guest', 'system'])->default('participant');
            $table->string('sender_display_name', 150);

            // null = رسالة عامة. غير null = رسالة خاصة لهذا الـ actor key بس (بند 8).
            $table->string('recipient_key', 40)->nullable();

            $table->enum('type', ['text', 'system'])->default('text');
            $table->text('body');

            $table->foreignId('reply_to_message_id')->nullable()
                ->constrained('meeting_chat_messages')->nullOnDelete();

            $table->json('mentioned_keys')->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'recipient_key', 'id']);
        });

        Schema::create('meeting_chat_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('meeting_chat_messages')->cascadeOnDelete();

            $table->string('actor_key', 40);
            $table->string('actor_display_name', 150);
            $table->string('emoji', 32);

            $table->timestamp('created_at')->nullable();

            // ريأكشن واحد بس لكل (رسالة، شخص، إيموجي) — إعادة إرسال نفس
            // الإيموجي هي toggle (شيل)، مش تكرار. راجع docblock
            // MeetingChatService::toggleReaction().
            $table->unique(['message_id', 'actor_key', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_chat_message_reactions');
        Schema::dropIfExists('meeting_chat_messages');
    }
};
