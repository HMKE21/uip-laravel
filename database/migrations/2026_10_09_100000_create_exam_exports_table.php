<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التصدير لعضو هيئة التدريس: كل تصدير (نتائج امتحان / نتائج الطلبة) بيتحفظ كملف على
 * السيرفر + صف هنا، فيتعرض في صفحة Exports مع Preview وتنزيل وحذف بدل ما يتنزّل ويضيع.
 * preview_json = {header, rows (أول 100 صف), total, truncated, meta} لحظة التصدير.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('exam_exports')) {
            return;
        }
        Schema::create('exam_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('academic_staff_id')->index();
            $table->string('export_type', 30);               // exam_results | students_results
            $table->unsignedBigInteger('exam_id')->nullable();
            $table->string('title', 255);
            $table->string('format', 10);                    // csv | xlsx | pdf | json
            $table->string('file_path', 500);                // نسبي لـ storage/app
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->longText('preview_json')->nullable();
            $table->timestamps();
            $table->index(['academic_staff_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_exports');
    }
};
