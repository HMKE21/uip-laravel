# Security hardening (Semgrep findings)

## New helpers
- `app/Support/Cmd.php` — runs external programs (ffmpeg, ffprobe, clamscan, pdftotext, pdftoppm) via Symfony Process with an argv array: no shell, timeout inside the Process. Never throws; failure = code -1 (`timeout` flag set on timeouts).
- `app/Support/SafePath.php` — `clean()` (rejects `..`, NUL, absolute/drive paths), `publicPath()`, `storageAppPath()`, `join()`, `within()`, and `delete()` (replaces every `unlink()`; only deletes inside public/, storage/, database/, bootstrap/cache, the system temp dir and the persistent volume).
- `app/Support/SafeUrl.php` — exact-host allowlist, HTTPS/443 only, no userinfo, DNS resolved and every IP must be public, IP pinned with CURLOPT_RESOLVE, redirects off.
- `database/seeders/DemoPasswordHash.php` — demo password hash generated at seed time.

## Findings → fix
| Finding | Fix |
|---|---|
| laravel-http-client-ssrf (GoogleAuthController) | `SafeUrl::check()` before `Http::get()` |
| exec-use (AttachmentReader, AudioDenoiseService, + ContentSafety/MediaProbe/FileUpload) | all moved to `Cmd::run()` — no shell |
| laravel-path-traversal / -storage | `SafePath::publicPath()` / `FileUploadService::absolutePath()` reject traversal; guards before `response()->file/download` |
| unlink-use | every `unlink()` in `app/` → `SafePath::delete()` |
| openssl-decrypt-validate | explicit `=== false` / `is_string` handling (ResponseCryptoService, UipJwtService, DecryptSecurePayload) |
| detected-bcrypt-hash | seeders use `__DEMO_PASSWORD_HASH__` placeholder, hash built from `DEMO_ACCOUNTS_PASSWORD` (or random) |

## Not touched / to do by hand
- Delete the stale root-level `Http/` and `Services/` folders (old copies of MessagingController / MessagingService, not autoloaded) — they still carry the old findings.
- `app/Console/Commands/SeedDemoData.php` still has a hard-coded demo password constant.
- Not executed: PHP was not available when this was prepared; run `php -l`, `composer test` and re-run Semgrep.

## Round 2 (remaining Semgrep findings)
- `app/Support/SafeFile.php` (new) — single delivery point for files: `resolve()` (realpath + allowed roots public/storage/temp/volume, no NUL, no dotfiles, regular files only), `download()`, `inline()`, `storageDownload()` (disk allow-list + `SafePath::clean`). 404 JSON on refusal.
- laravel-path-traversal / -storage: all 18 flagged controller sinks (Messaging x4, AdminDriveControl, AdminRequest, Course, DataAnalysisAiInsights, DataAnalysisReportFiles, Drive x2, DriveShare, Fee, Files, FormsManage, Grading, UniversityEmail, Mail) now go through `SafeFile`.
- unlink-use: no direct `unlink()` left (`SafePath::delete()` and `bootstrap/app.php` use `Illuminate\Filesystem\Filesystem::delete()`).
- openssl-decrypt-validate: plain `=== false` check right after each `openssl_decrypt()` (DecryptSecurePayload, ResponseCryptoService x2, UipJwtService).
- exec-use: no `exec/shell_exec/system/passthru/popen/proc_open` left in app code (all via `Cmd::run()` argv, no shell).
- Removed the stale root-level `Http/` and `Services/` folders (old non-autoloaded copies that still carried the old findings).
