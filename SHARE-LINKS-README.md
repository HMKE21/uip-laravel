# Backend — روابطي المشاركة (My shared links)

بعد الرفع شغّل: `php artisan migrate`  (ميجريشن جديد: 2027_01_11_100000_add_gate_and_activity_to_drive_shares)

## الجديد
- drive_shares: require_visitor_info / password_hash / gate_version / paused_at
- جدولين: drive_share_visitors (مين عدّى البوابة) + drive_share_events (سجل الفتح/المعاينة/التنزيل/باسورد غلط)
- إنشاء/تعديل الرابط: require_visitor_info (اسم+إيميل+موبايل) و password (اختياري لكل رابط)
- الإدارة:
  GET    /api/v1/drive/shares/manage          كل الروابط (شغّالة/موقوفة/منتهية/ملغية) + عدد الزوار
  GET    /api/v1/drive/shares/{id}/activity   مين فتح الرابط
  DELETE /api/v1/drive/shares/{id}/activity   مسح السجل
  POST   /api/v1/drive/shares/{id}/pause | resume
  DELETE /api/v1/drive/shares/{id}/forever    حذف نهائي (الرابط + سجله)
- الزائر: POST /api/v1/public/drive-share/{token}/enter  → session يتبعت في هيدر X-Share-Session
  الرابط اللي عليه بوابة بيرد 423 (errors.code = share_gate) من غير الجلسة؛ تغيير الباسورد/تفعيل طلب البيانات بيلغي الجلسات القديمة.
- الباسورد bcrypt ومبيترجعش أبدًا؛ محاولات الباسورد الغلط: 6 كل 15 دقيقة لكل رابط + IP؛ الـ IP بيتخزّن مخفي (41.35.12.x).
