<?php

namespace App\Services;

use App\Support\SecurityLog;
use App\Support\SvgGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * نسخة طبق الأصل (منطقيًا) من app/Services/FileUploadService.php القديمة
 * — نفس ترتيب الفاليديشن، نفس رسائل الأخطاء، نفس content-sniffing
 * (assertContentMatchesExtension) ونفس ClamAV hook (scanForMalware) حرف
 * بحرف. الفرق الوحيد: بياخد Illuminate\Http\UploadedFile بدل الـ
 * $_FILES-array القديم (Laravel مالوش $_FILES خام في الكنترولر)،
 * وbase_path('public/'...) بقت public_path(...).
 *
 * بند 25 batch 3 (Security Portal) قفل الفجوة الموثّقة هنا سابقًا: الحد
 * الأقصى للحجم والـ allow-list دلوقتي بييجوا من FileUploadPolicyService
 * (سياسة أدمن قابلة للتعديل، `upload.policy`) لو الكولر ميحددش override
 * صراحة، بدل config('upload.*') الثابت لوحده. effectiveAllowed() بتضيّق
 * allow-list الفئة بس (تقاطع)، ميقدرش يوسّعها، فسلوك أي كولر معندوش
 * override ولا سياسة مفعّلة يفضل زي الأول بالظبط.
 */
class FileUploadService
{
    public function __construct(
        private FileUploadPolicyService $uploadPolicy
    ) {
    }

    /**
     * @throws \RuntimeException on validation failure (message is safe to show the user)
     * @return array{stored_path:string,original_name:string,mime_type:?string,size_bytes:int,extension:string}
     */
    public function store(
        ?UploadedFile $file,
        string $category,
        string $subfolder = '',
        ?int $maxKbOverride = null,
        ?array $allowedOverride = null,
        ?int $absoluteMaxKb = null
    ): array {
        if (!$file) {
            throw new \RuntimeException('No file was uploaded.');
        }

        if (!$file->isValid()) {
            throw new \RuntimeException($this->errorMessage($file->getError()));
        }

        // السياسة سقف عام: الـ override الخاص بكل فئة ممكن يضيّق بس مش يوسّع فوق سقف الأدمن.
        $policyMaxKb = $this->uploadPolicy->defaultMaxKb();
        $maxKb = $maxKbOverride !== null ? min($maxKbOverride, $policyMaxKb) : $policyMaxKb;
        // ملفات الرفع بالأجزاء ليها سقفها الخاص (config messaging.chunked.max_bytes) بدل سقف السياسة العام.
        if ($absoluteMaxKb !== null) {
            $maxKb = $absoluteMaxKb;
        }
        $maxBytes = $maxKb * 1024;
        // مُتسجّل هنا قبل move() — UploadedFile::move() بينقل ملف الـ tmp
        // فعليًا (rename) وبيرجّع كائن File جديد للمسار الجديد، من غير ما
        // يعدّل $file نفسها. فأي getSize()/getRealPath() على $file بعد
        // move() بيعمل stat() على مسار tmp اتشال بالفعل ويطلع
        // "SplFileInfo::getSize(): stat failed for ...php####.tmp".
        $sizeBytes = $file->getSize();
        if ($sizeBytes > $maxBytes) {
            throw new \RuntimeException('File exceeds the maximum allowed size of ' . $maxKb . ' KB.');
        }

        $categoryAllowed = $allowedOverride ?? (array) config("upload.allowed.{$category}", []);
        $allowed = $this->uploadPolicy->effectiveAllowed($categoryAllowed);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!$extension || !in_array($extension, $allowed, true)) {
            throw new \RuntimeException('File type not allowed. Accepted: ' . implode(', ', $allowed));
        }

        $this->assertContentMatchesExtension($file->getRealPath(), $extension);
        $this->assertPdfHasNoActiveContent($file->getRealPath(), $extension, $file->getClientOriginalName());
        $this->scanForMalware($file->getRealPath(), $file->getClientOriginalName(), $category);

        $basePath = (string) config("upload.paths.{$category}", 'uploads/' . $category);
        $relativeDir = $basePath . ($subfolder !== '' ? '/' . trim($subfolder, '/') : '');
        $targetDir = public_path($relativeDir);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Could not prepare the upload destination.');
        }

        // الامتداد المنفّذ/المعروض كصفحة بيتخزّن بسوفكس .txt (شوف storedExtension) — الاسم الأصلي بيتحفظ في original_name.
        $storedName = bin2hex(random_bytes(16)) . '.' . self::storedExtension($extension);

        if (!$file->move($targetDir, $storedName)) {
            throw new \RuntimeException('Failed to save the uploaded file.');
        }

        return [
            'stored_path'   => $relativeDir . '/' . $storedName,
            'original_name' => basename($file->getClientOriginalName()),
            'mime_type'     => $file->getClientMimeType() ?: null,
            'size_bytes'    => (int) $sizeBytes,
            'extension'     => $extension,
        ];
    }

    /**
     * امتدادات السيرفر ممكن ينفّذها (php وأخواتها: FrankenPHP/Caddy بيشغّل أي .php تحت public/ عبر php_server)
     * أو يعرضها كصفحة من نفس الأوريجن (html/htm/xhtml/shtml => XSS). فئات messages/ai_assistant/general
     * بتسمح برفعها كملفات مشاركة، فبنخزّنها بسوفكس .txt: بتتخدم text/plain ومبتتنفّذش أبدًا.
     */
    private const NEUTRALIZED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif',
        'html', 'htm', 'xhtml', 'shtml', 'cgi', 'pl',
        'xml', // application/xml بيتعرض في المتصفح وممكن يشغّل xhtml/xslt من نفس الأوريجن
    ];

    /** الامتداد اللي بيتحط فعليًا في اسم الملف على الديسك. */
    public static function storedExtension(string $extension): string
    {
        $extension = strtolower($extension);
        return in_array($extension, self::NEUTRALIZED_EXTENSIONS, true) ? $extension . '.txt' : $extension;
    }

    /** Best-effort delete of a previously stored file (paths are relative to public/). */
    public function delete(string $relativePath): void
    {
        $full = public_path(ltrim($relativePath, '/'));
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /** نفس assertContentMatchesExtension() القديمة حرف بحرف — magic-byte sniffing، مش ثقة في الامتداد بس. */
    private function assertContentMatchesExtension(string $tmpPath, string $extension): void
    {
        $extension = strtolower($extension);

        $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'tiff'];
        if (in_array($extension, $imageExtensions, true)) {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                throw new \RuntimeException('That file does not look like a valid image. It may be corrupted or mislabeled.');
            }
            return;
        }

        if ($extension === 'svg') {
            $head = @file_get_contents($tmpPath, false, null, 0, 512);
            if ($head === false || !preg_match('/<\?xml|<svg/i', $head)) {
                throw new \RuntimeException('That file does not look like a valid SVG.');
            }
            // SVG بيتخدم من نفس الأوريجن: أي محتوى نشط (script/on*/javascript:/روابط خارجية...) بيترفض (XSS).
            SvgGuard::assertSafe((string) @file_get_contents($tmpPath));
            return;
        }

        if ($extension === 'pdf') {
            $head = @file_get_contents($tmpPath, false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new \RuntimeException('That file does not look like a valid PDF.');
            }
            return;
        }

        if (in_array($extension, ['docx', 'pptx', 'zip', 'sketch'], true)) {
            $head = @file_get_contents($tmpPath, false, null, 0, 4);
            $validZipStart = $head !== false && in_array(substr($head, 0, 2), ["PK"], true);
            if (!$validZipStart) {
                throw new \RuntimeException('That file does not look like a valid ' . strtoupper($extension) . ' file.');
            }
            return;
        }

        if ($extension === 'rar') {
            $head = @file_get_contents($tmpPath, false, null, 0, 4);
            if ($head === false || substr($head, 0, 4) !== "Rar!") {
                throw new \RuntimeException('That file does not look like a valid RAR archive.');
            }
            return;
        }

        if (in_array($extension, ['ttf', 'otf'], true)) {
            $head = @file_get_contents($tmpPath, false, null, 0, 4);
            $validSignatures = ["\x00\x01\x00\x00", "true", "OTTO"];
            if ($head === false || !in_array($head, $validSignatures, true)) {
                throw new \RuntimeException('That file does not look like a valid font.');
            }
            return;
        }

        if ($extension === 'woff') {
            $head = @file_get_contents($tmpPath, false, null, 0, 4);
            if ($head !== 'wOFF') {
                throw new \RuntimeException('That file does not look like a valid WOFF font.');
            }
            return;
        }

        if ($extension === 'woff2') {
            $head = @file_get_contents($tmpPath, false, null, 0, 4);
            if ($head !== 'wOF2') {
                throw new \RuntimeException('That file does not look like a valid WOFF2 font.');
            }
            return;
        }

        if ($extension === 'json') {
            $head = @file_get_contents($tmpPath);
            if ($head === false || json_decode($head) === null && trim($head) !== 'null') {
                throw new \RuntimeException('That file does not look like valid JSON.');
            }
            return;
        }

        if (in_array($extension, ['ai', 'eps'], true)) {
            $head = @file_get_contents($tmpPath, false, null, 0, 5);
            if ($head === false || (!str_starts_with($head, '%!PS') && $head !== '%PDF-')) {
                throw new \RuntimeException('That file does not look like a valid ' . strtoupper($extension) . ' file.');
            }
            return;
        }

        // doc/ppt/fig/xd: مفيش magic-byte signature عامة موثوقة بدون parser
        // كامل — نفس الاستثناء الموثّق في القديم. scanForMalware() تحت
        // بتغطي المحتوى/السلوك بغض النظر عن الفجوة دي.
    }

    /**
     * نسخة طبق الأصل من scanForMalware() القديمة — بتشغّل ClamAV CLI
     * (config/upload.php 'antivirus') على الملف المؤقت قبل ما يتحرك
     * لـ public/uploads. Logger::security() القديمة -> App\Support\
     * SecurityLog::write() (بند 25 batch 4 — نفس الفجوة اللي اتقفلت في
     * AuthService/AccountLockoutService/إلخ)، مع الاحتفاظ بـ Log::warning()
     * زي ما هي (قناة Laravel العادية، لسجلات الأخطاء التشغيلية).
     */
    private function scanForMalware(string $tmpPath, string $originalName, string $category): void
    {
        if (!config('upload.antivirus.enabled', true)) {
            return;
        }

        $binary = (string) config('upload.antivirus.binary', 'clamscan');
        $timeout = (int) config('upload.antivirus.timeout', 30);
        $required = (bool) config('upload.antivirus.required', false);

        if (!$this->commandExists($binary)) {
            SecurityLog::write("Malware scan skipped for {$originalName} — scanner binary not found on this server", [
                'binary' => $binary, 'file' => $originalName, 'category' => $category,
            ]);
            Log::warning("Malware scan skipped for {$originalName} — scanner binary not found on this server", [
                'binary' => $binary, 'file' => $originalName, 'category' => $category,
            ]);

            if ($required) {
                throw new \RuntimeException('Uploads are temporarily unavailable (malware scanner not configured). Please contact an administrator.');
            }
            return;
        }

        $command = sprintf(
            'timeout %d %s --no-summary %s 2>&1',
            max(1, $timeout),
            escapeshellcmd($binary),
            escapeshellarg($tmpPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode === 1) {
            SecurityLog::write("Malware detected in uploaded file: {$originalName} — upload rejected", [
                'file' => $originalName, 'category' => $category, 'scanner_output' => implode(' | ', $output),
            ]);
            Log::warning("Malware detected in uploaded file: {$originalName} — upload rejected", [
                'file' => $originalName, 'category' => $category, 'scanner_output' => implode(' | ', $output),
            ]);
            throw new \RuntimeException('This file was rejected by the security scanner and could not be uploaded.');
        }

        if ($exitCode !== 0) {
            SecurityLog::write("Malware scan could not complete for {$originalName} — scanner returned an error", [
                'file' => $originalName, 'category' => $category, 'exit_code' => $exitCode, 'scanner_output' => implode(' | ', $output),
            ]);
            Log::warning("Malware scan could not complete for {$originalName} — scanner returned an error", [
                'file' => $originalName, 'category' => $category, 'exit_code' => $exitCode, 'scanner_output' => implode(' | ', $output),
            ]);

            if ($required) {
                throw new \RuntimeException('The security scan could not be completed. Please try again or contact an administrator.');
            }
        }
    }

    /**
     * PDF فيه JavaScript / تشغيل برامج / ملفات مضمّنة بيتشغّل جوه الـ viewer بتاع المتصفح على نفس الأوريجن (XSS)،
     * فبيتم رفضه. upload.pdf_active_content = reject (الافتراضي) | allow.
     */
    private function assertPdfHasNoActiveContent(string $tmpPath, string $extension, string $originalName): void
    {
        if ($extension !== 'pdf' || config('upload.pdf_active_content', 'reject') === 'allow') {
            return;
        }
        $findings = \App\Support\PdfActiveContentScanner::findings($tmpPath);
        if ($findings === []) {
            return;
        }
        SecurityLog::write("PDF with active content rejected: {$originalName}", ['file' => $originalName, 'findings' => implode(',', $findings)]);

        throw new \RuntimeException('This PDF contains scripts or active content (' . implode(', ', array_slice($findings, 0, 3)) . ') and was rejected for security reasons.');
    }

    /**
     * فحص ملف متخزّن فعلًا (مش tmp upload) لحظة ما حد يفتحه/ينزّله — طبقة حماية تانية بعد فحص الرفع.
     * النتيجة بتتكاش 12 ساعة بمفتاح (المسار + حجم + وقت التعديل) فمفيش إعادة فحص تقيل في كل فتح.
     *
     * @return array{status:string, findings:array<int,string>}
     *   status: clean | infected (ClamAV لقى فيروس) | active_content (PDF فيه سكربتات) | unscanned (الماسح مش متاح/فشل)
     */
    public function inspectStoredFile(string $path, string $displayName): array
    {
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path) ?: 0;
        $cacheKey = 'file_inspect:' . md5($path . '|' . $mtime . '|' . $size);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addHours(12), function () use ($path, $displayName) {
            $findings = [];

            if (strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) === 'pdf') {
                $findings = \App\Support\PdfActiveContentScanner::findings($path);
            }

            if (config('upload.antivirus.enabled', true) && function_exists('exec')) {
                $binary = (string) config('upload.antivirus.binary', 'clamscan');
                if ($this->commandExists($binary)) {
                    $timeout = max(1, (int) config('upload.antivirus.timeout', 30));
                    $out = [];
                    $code = 0;
                    exec(sprintf('timeout %d %s --no-summary %s 2>&1', $timeout, escapeshellcmd($binary), escapeshellarg($path)), $out, $code);
                    if ($code === 1) {
                        SecurityLog::write("Malware detected when opening stored file: {$displayName} — access blocked", [
                            'file' => $displayName, 'scanner_output' => implode(' | ', $out),
                        ]);
                        return ['status' => 'infected', 'findings' => array_slice($out, 0, 3)];
                    }
                    if ($code !== 0) {
                        return $findings !== []
                            ? ['status' => 'active_content', 'findings' => $findings]
                            : ['status' => 'unscanned', 'findings' => []];
                    }
                } elseif ($findings === []) {
                    return ['status' => 'unscanned', 'findings' => []];
                }
            }

            if ($findings !== []) {
                SecurityLog::write("PDF with active content blocked when opening: {$displayName}", ['file' => $displayName, 'findings' => implode(',', $findings)]);
                return ['status' => 'active_content', 'findings' => $findings];
            }

            return ['status' => 'clean', 'findings' => []];
        });
    }

    /** @return array{enabled:bool, binary:string, available:bool, required:bool} */
    public static function scannerStatus(): array
    {
        $enabled = (bool) config('upload.antivirus.enabled', true);
        $binary = (string) config('upload.antivirus.binary', 'clamscan');
        $required = (bool) config('upload.antivirus.required', false);

        $available = false;
        if ($enabled && function_exists('exec')) {
            $checkCmd = DIRECTORY_SEPARATOR === '\\'
                ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
                : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
            @exec($checkCmd, $output, $exitCode);
            $available = $exitCode === 0 && !empty($output);
        }

        return ['enabled' => $enabled, 'binary' => $binary, 'available' => $available, 'required' => $required];
    }

    private function commandExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $checkCmd = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';

        @exec($checkCmd, $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    private function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload was blocked by a server extension.',
            default => 'Upload failed. Please try again.',
        };
    }
}
