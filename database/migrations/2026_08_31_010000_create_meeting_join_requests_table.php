<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند "UIP Meetings" — Round 2 (Lobby & Access): بند 3 (Join Meeting
 * Experience / Pre-Join Screen)، بند 22 (Waiting Room)، بند 23 (Guest
 * Access).
 *
 * جدول واحد جديد بس، `meeting_join_requests` — additive بالكامل، مفيش
 * أي تعديل على جداول Round 1 (meetings/meeting_participants/
 * meeting_invitations) نهائيًا، عمدًا للأسباب دي:
 *
 *   - اختبار الكاميرا/المايك واختيار الجهاز (بند 3) منطقه كله على
 *     المتصفح (getUserMedia) — مفيش أي حاجة backend فيه أصلًا.
 *   - كان ممكن نخلي user_id على meeting_participants قابل لل-null
 *     ونستخدمها للضيوف كمان، لكن ده يعني تعديل عمود على جدول شغال
 *     بالفعل (FK + unique(meeting_id,user_id)) — مخاطرة عبثًا بينما
 *     مفيش doctrine/dbal مثبت (اتفحص في Round 1)، وده بالظبط النوع من
 *     الـ ALTER اللي بيختلف سلوكه بين MySQL وSQLite. البديل الأسلم:
 *     meeting_join_requests نفسها بقت مصدر الحقيقة لحضور الضيف
 *     (status=admitted + user_id=null = "الضيف ده داخل الاجتماع دلوقتي")،
 *     وتفضل meeting_participants مقصورة على المستخدمين المسجلين بس زي
 *     ما اتصمم من الأول في Round 1.
 *
 * status الصف الواحد بيمثل مرحلتين مختلفتين حسب وقته (راجع docblock
 * App\Models\MeetingJoinRequest):
 *   - قبل القرار (pending): طلب في الـ waiting room مستني قرار الهوست.
 *   - بعد القبول لو user_id=null (admitted): ده *هو* سجل حضور الضيف
 *     نفسه، مفيش صف موازي له في meeting_participants.
 *   - 'left' متسجلة من دلوقتي (مش مستخدمة في الـ Round ده) عشان Round 4
 *     يقدر يعلّم خروج الضيف الفعلي من غير ما يحتاج migration جديدة.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            // null = ضيف (بند 23) — مفيش حساب UIP. لو موجود، ده مستخدم
            // مسجّل والصف هنا سجل تاريخي لطلب الدخول بس (الحضور الفعلي
            // بتاعه في meeting_participants زي Round 1 بالظبط).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name', 100)->nullable();

            $table->enum('status', ['pending', 'admitted', 'rejected', 'cancelled', 'left'])->default('pending');

            // بيتأكد من كلمة السر وقت الطلب نفسه (بند 3: "Enter meeting
            // password" جوه نفس شاشة الـ pre-join)، مش خطوة verify
            // منفصلة إجبارية قبلها.
            $table->boolean('password_verified')->default(false);

            // اختيارات الجهاز من شاشة الـ pre-join (بند 3: مايك/كاميرا/
            // سماعة مختارة + مشغّلين ولا لأ) — بتتحفظ هنا عشان تتنقل
            // لغرفة الاجتماع الفعلية في Round 4 من غير ما اليوزر يعيد
            // اختيارها تاني.
            $table->json('device_preferences')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'status']);
            $table->index(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_join_requests');
    }
};
