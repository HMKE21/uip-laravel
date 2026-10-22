# Terminal app (UIP Desktop)
انسخ الملفات فوق المشروعين (نفس المسارات). مفيش باكدجات جديدة (xterm و fflate موجودين).

React: src/desktop/TerminalApp.jsx (جديد) + تعديل DesktopOS, apps, prefs, shortcuts, shell, MobileShell, OsIcons, desktop.css
Laravel: POST /api/v1/drive/terminal-token (DriveApiController::terminalToken + routes/api.php)

الفتح: أيقونة في الـ Dock / Launchpad / Spotlight (اكتب terminal) / كليك يمين على سطح المكتب / Alt+T.
شرط الشغل: SANDBOX_URL و SANDBOX_JWT_SECRET متظبطين على Laravel وبوابة uip-RUN شغالة (نفس إعداد محرر الأكواد).
