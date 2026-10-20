# Exam & Assessment System — API Documentation

All routes are prefixed `/api/v1/exam-system/*`, sit behind the `uip.auth` middleware
(a valid session/token is required for every endpoint below), and follow the
project-wide response envelope:

```json
{ "success": true|false, "message": "...", "data": ..., "errors": ..., "meta": {} }
```

Role checks are enforced in the controller for every endpoint (not just at the route
level) — see the "Role" column. `academic_staff` endpoints are additionally
ownership-scoped: an instructor can only read/modify banks, questions, exams, pools,
attempts, and grades that belong to them.

This file is new — the project did not previously have a central Exam System API
reference (`README.md`/`AUTH_API_CONTRACT.md` do not cover it). It documents every
exam-system endpoint across all 8 build rounds, added at Phase 44 (Round 8) alongside
the analytics/dashboards/export work.

---

## Round 1 — Question Banks, Questions, Exams (Foundation)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `question-banks` | List the instructor's question banks (with question counts). |
| POST | `question-banks` | Create a question bank. |
| GET | `question-banks/{id}` | Show one bank (owned). |
| PATCH | `question-banks/{id}` | Update a bank (owned). |
| DELETE | `question-banks/{id}` | Delete a bank (owned). |
| POST | `question-banks/{bankId}/questions` | Add a question (mcq/true_false in Round 1; short_answer/essay from Round 6) to a bank. |
| PATCH | `questions/{id}` | Update a question (owned via its bank). |
| DELETE | `questions/{id}` | Delete a question (owned via its bank). |
| GET | `exams` | List the instructor's exams. |
| POST | `exams` | Create an exam (starts as `draft`). |
| GET | `exams/{id}` | Show one exam (owned), with manual questions + pool configs. |
| PATCH | `exams/{id}` | Update exam metadata (owned). |
| DELETE | `exams/{id}` | Delete an exam (owned). |
| POST | `exams/{id}/questions` | Attach a manual question to an exam. |
| PATCH | `exams/{id}/questions/reorder` | Reorder an exam's manual questions. |
| DELETE | `exams/{id}/questions/{examQuestionId}` | Detach a manual question from an exam. |
| POST | `exams/{id}/questions/bulk-remove` | Detach many manual questions at once. Body: `exam_question_ids[]`. Questions stay in the bank. |

## Round 6 — Rubrics (Question-level, AI-gradable types only)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `questions/{questionId}/rubric` | Show the rubric + model answer for a short_answer/essay question. |
| PUT | `questions/{questionId}/rubric` | Create/replace the rubric for a question. |
| DELETE | `questions/{questionId}/rubric` | Remove a question's rubric. |

## Round 7 — Question Pools + Randomization

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `question-banks/{bankId}/pools` | List pools in a bank. |
| POST | `question-banks/{bankId}/pools` | Create a pool. |
| GET | `pools/{id}` | Show one pool + its member question IDs. |
| PATCH | `pools/{id}` | Update a pool. |
| DELETE | `pools/{id}` | Delete a pool. |
| PUT | `pools/{id}/questions` | Sync a pool's full question membership. |
| GET | `exams/{id}/pools` | List an exam's pool configurations. |
| POST | `exams/{id}/pools` | Attach a pool to an exam (`questions_to_select`, optional `distribution`). |
| PATCH | `exams/{id}/pools/{configId}` | Update an exam's pool configuration. |
| DELETE | `exams/{id}/pools/{configId}` | Detach a pool from an exam. |

## Round 2 — Targeting + Publish

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `exams/{id}/targets` | Show an exam's saved targeting rules. |
| PUT | `exams/{id}/targets` | Replace targeting rules. **Round 8:** if the exam is already `published`/`scheduled`, students newly made eligible are notified `exam_assigned`. |
| POST | `exams/{id}/targets/preview` | Live count of students matching unsaved targeting rules. |
| GET | `exams/{id}/targets/students` | Sample (up to 200) of students matching saved targeting rules. |
| POST | `exams/{id}/publish` | Publish (`draft`/`scheduled` → `published` or `scheduled`, depending on `start_at`). **Round 8:** notifies every eligible student `exam_scheduled` or `exam_available`. |
| POST | `exams/{id}/unpublish` | Revert to `draft`. |

## Round 2 — Student "My Exams"

Role: `student`

| Method | Path | Description |
|---|---|---|
| GET | `my-exams` | List published/scheduled exams the student is eligible for. |
| GET | `my-exams/{id}` | Show one exam's metadata (no question content) if eligible. |

## Round 3 — Attempts, Timer, Auto-save

Role: `student`

| Method | Path | Description |
|---|---|---|
| GET | `my-exams/{id}/attempts` | List the student's own attempts on an exam. |
| POST | `my-exams/{id}/attempts` | Start (or resume) an attempt — draws pool questions if configured. |
| GET | `attempts/{id}` | Show attempt detail (questions for *this* attempt, server-computed remaining time). |
| PUT | `attempts/{id}/answers/{examQuestionId}` | Save/auto-save an answer. |
| DELETE | `attempts/{id}/answers/{examQuestionId}` | Clear a saved answer. |
| POST | `attempts/{id}/submit` | Submit the attempt (manual or auto). Triggers grading pipeline. |
| GET | `attempts/{id}/result` | Student's own result view, respecting `result_visibility`. |

## Round 5 — Secure Exam Mode / Security Events

| Method | Path | Role | Description |
|---|---|---|---|
| POST | `attempts/{id}/security-events` | `student` | Record a security violation (tab exit, copy/paste, fullscreen exit, etc.). Auto-submits on exceeding `max_violations`. |
| GET | `exams/{id}/attempts/{attemptId}/security-events` | `academic_staff` | Instructor's timeline of an attempt's security events. |

## Round 4/6 — Grading (Manual + Automatic + AI)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `exams/{id}/attempts` | List all attempts on an exam, with grading progress. |
| GET | `exams/{id}/attempts/{attemptId}` | Full attempt detail for grading (questions, answers, grades). |
| POST | `exams/{id}/attempts/{attemptId}/auto-grade` | Re-run automatic grading for objective questions only. |
| PUT | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}` | Manual grade / override. **Round 8:** logs `grade_changed`/`grade_overridden` to the audit trail; notifies the student `exam_grade_changed` if this overrides an existing score *and* results are already published to them. |
| GET | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/history` | Full grade-change history for one question. |
| POST | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/accept-ai` | Accept an AI-suggested grade as-is. **Round 8:** logs `ai_grade_accepted`. |
| POST | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/ai-regrade` | Re-queue AI grading for one question. **Round 8:** logs `exam_regraded`. |
| POST | `exams/{id}/publish-results` | Publish results (for `result_visibility=manual` exams). **Round 8:** notifies every student with a graded attempt `exam_result_published`. |
| POST | `exams/{id}/unpublish-results` | Revoke published results. |

---

## Round 8 — Analytics, Dashboards, Notifications, Export

### Analytics & Dashboards (Phases 25–28)

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `exams/{id}/analytics` | `academic_staff` | Exam-level stats (total/started/submitted/graded/average/median/highest/lowest/pass rate/failure rate/average time) + question-level stats (correct %, incorrect %, average score %, hardest/easiest question). Owned exams only. |
| GET | `dashboard/instructor` | `academic_staff` | My Exams / Active Exams / Pending Grading / Recent Results. |
| GET | `dashboard/student` | `student` | Upcoming/Active/Completed exams, recent results, average score. Recent results respect `result_visibility` exactly as `attempts/{id}/result` does. |
| GET | `faculty/exams` | `faculty` | Every exam in the caller's own faculty, with a quick summary per exam. `faculty_id` is resolved from the authenticated account, never from client input. |
| GET | `faculty/dashboard` | `faculty` | Total Exams / Total Students / Total Attempts / Average Performance / Pass Rate, scoped to the caller's faculty. |
| GET | `university/exams` | `university` | Every exam across the caller's own university (all faculties). |
| GET | `university/dashboard` | `university` | Same aggregate widgets as the faculty dashboard, scoped to the whole university. |

Only metrics reliably computable from real recorded data are exposed — e.g.
`correct_pct`/`incorrect_pct` are `null` for non-objective questions (essay/short_answer),
since "correct/incorrect" has no meaning for AI/manually-graded free text; `average_score_pct`
(marks/max_marks) is the unified difficulty metric used for both types.

### Notifications (Phase 29)

Integrated into the existing `Notification`/`NotificationService` system — no new
notification architecture. Triggered directly from the service layer at the point the
underlying fact becomes true (not re-derived in a controller):

| Type | Triggered by | Recipient(s) |
|---|---|---|
| `exam_scheduled` | `ExamSystemService::publishExam()`, when `start_at` is in the future | All eligible students |
| `exam_available` | `ExamSystemService::publishExam()`, when the exam is open immediately | All eligible students |
| `exam_assigned` | `ExamSystemService::replaceExamTargets()`, on an already-published/scheduled exam | Newly-eligible students only |
| `exam_result_published` | `ExamGradingService::publishResults()` | Students with a `graded` attempt |
| `exam_grade_changed` | `ExamGradingService::gradeManually()`, on a genuine override with results already visible | The affected student |
| `exam_starts_soon` | `ExamReminderService::sweep()` via `php artisan exams:notify-upcoming` — 24h and 1h before `start_at` | All eligible students |
| `exam_deadline_approaching` | same sweep — 24h and 1h before `end_at` | Eligible students who still have attempts left and no attempt in progress |

`exams:notify-upcoming` is scheduled in `routes/console.php` (every 5 minutes), so the server
needs only the standard Laravel cron entry: `* * * * * php artisan schedule:run`. Each
(exam, student, reminder, open/close time) is sent once (`exam_reminders_sent`); changing an
exam's start/end time allows a fresh reminder. Only the closest stage is sent when a sweep runs late.
Notification `link_url` is `/student/my-exams/{examId}`.

### Audit Trail (Phase 42)

Uses the existing `AuditLogService` — no new audit architecture. Newly-recorded actions
this round: `academic_staff.exam_system.grade_changed`, `...grade_overridden`,
`...ai_grade_accepted`, `...exam_regraded`, `...results_exported` (all previous Rounds
1/2/6/7 actions — bank/question/exam/pool/target/publish CRUD — were already audited).

"Exam Closed" and "Exam Archived" from Phase 42's suggested action list are not
implemented: the exam system has no close/archive action anywhere in the product (only
`draft` ⇄ `scheduled`/`published`, handled by publish/unpublish above) — nothing to
audit that doesn't exist as a real, callable operation.

### Export (Phase 43)

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `exams/{id}/results/export?format=csv\|xlsx\|pdf\|json` | `academic_staff` | Per-student results export (Student, Exam, Score, Percentage, Status, Submission Time) for one owned exam. Defaults to `csv`. |

Formats match exactly what the rest of the project already supports (see
`DataExportService::ALLOWED_FORMATS` and `AdminAiCodeReviewExportController`) — no new
export formats were introduced. Every export call is audit-logged
(`academic_staff.exam_system.results_exported`).

## Attempt management (instructor) — cancel attempt / allow retake

All routes: `academic_staff` only, exam must belong to the instructor.

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/exam-system/exams/{id}/attempts/{attemptId}/cancel` | `reason?`, `allow_retake?` (bool), `available_until?` (datetime) | Sets status `cancelled`. Kept in history and still counts as a used attempt unless `allow_retake=true` (grants +1). In-progress attempts are cut off immediately. |
| POST | `/exam-system/exams/{id}/students/{studentId}/retake` | `extra_attempts?` (1–10, default 1), `available_until?`, `reason?` | Adds attempts on top of `max_attempts` (cumulative). `available_until` opens a private window for that student even after the exam closed. |
| DELETE | `/exam-system/exams/{id}/students/{studentId}/retake` | — | Withdraws unused extra attempts only. |

Attempt rows in `GET /exams/{id}/attempts` now include `cancelled_at`, `cancel_reason`, `student_attempts_used`, `student_extra_attempts`, `student_available_until`.
Student `my-exams` payloads now include `extra_attempts`, `max_attempts_effective`, `retake_allowed`, `retake_available_until`.
Audit actions: `exam.attempt_cancelled`, `exam.retake_granted`, `exam.retake_revoked`. Student notifications: `exam_attempt_cancelled`, `exam_retake_granted`, `exam_retake_revoked`.
Run `php artisan migrate` (new migration `2026_10_05_200000_...`).

---

## تحديث: التسليم المتأخر، الوقت الإضافي، الإجراءات الجماعية، بحث الطلاب الغايبين

### سياسة التسليم المتأخر (يحددها الدكتور وقت إنشاء/تعديل الامتحان)
حقول جديدة في `POST /exams` و`PATCH /exams/{id}`:

| الحقل | النوع | ملاحظات |
|---|---|---|
| `late_grace_minutes` | integer 0..120 | فترة السماح بعد انتهاء الوقت. `0` = الامتحان بيقفل فجأة (السلوك القديم). |
| `late_penalty_percent` | numeric 0..100 | نسبة الخصم من الدرجة لو التسليم جوه فترة السماح. بتتصفّر لو `late_grace_minutes = 0`. |

- جوه فترة السماح المحاولة بتفضل `in_progress`، الطالب يقدر يحفظ ويسلّم. أي تسليم بعد `expires_at` بيتعلّم `is_late = true` وبيتاخد snapshot من نسبة الخصم في `exam_attempts.late_penalty_percent`.
- الخصم بيتطبق في `ExamGradingService::recomputeAttemptTotals()` من الدرجة الخام كل مرة (تعديل درجة سؤال بعد كده مش بيخصم مرتين). الدرجة الخام في `score_before_penalty`.
- بعد انتهاء فترة السماح: `enforceTimer()` / أمر `exams:auto-submit-expired` بيسلّمها أوتوماتيك. لو الطالب كان شغال جوه فترة السماح بتتعلّم متأخرة، غير كده تسليم عادي على وقت الانتهاء.
- `GET attempts/{id}` بيرجّع: `in_grace`, `grace_remaining_seconds`, `grace_ends_at`, `extra_time_minutes`, `is_late`. نتيجة الطالب فيها `is_late`, `late_penalty_percent`, `score_before_penalty`. قائمة المدرس فيها `is_late`, `late_penalty_percent`, `score_before_penalty`, `extra_time_minutes`.

### وقت إضافي لمحاولة شغالة
`POST /exams/{id}/attempts/{attemptId}/extra-time` — body: `minutes` (1..240، مطلوب), `reason?`. بتزوّد `expires_at`، حد أقصى 600 دقيقة إجمالًا للمحاولة. بترفض المحاولة المخلصة أو اللي وقتها (وسماحها) خلص. بتتسجل في audit وبتوصل للطالب كإشعار.

### إجراءات جماعية (حد أقصى 200 عنصر في الطلب)
كلها بترجّع `{ succeeded: [ids], failed: [{ id, error }] }` — فشل عنصر لا يوقف الباقي. `200` لو في نجاح واحد على الأقل، `422` (والبنية نفسها تحت `errors`) لو كله فشل.

| Endpoint | Body |
|---|---|
| `POST /exams/{id}/attempts/bulk-cancel` | `attempt_ids[]`, `reason?`, `allow_retake?`, `available_until?` |
| `POST /exams/{id}/retake/bulk` | `student_ids[]`, `extra_attempts?`, `available_until?`, `reason?` |
| `POST /exams/{id}/attempts/bulk-extra-time` | `attempt_ids[]`, `minutes`, `reason?` |

محاولة تابعة لامتحان تاني بتظهر في `failed` كـ `Attempt not found.` ومبتتلمسش.

### بحث الطلاب المؤهلين (إدّي فرصة لطالب ماجاش)
`GET /exams/{id}/eligible-students?q=&without_attempts=1&limit=50` — بحث بالاسم/الإيميل/الرقم الجامعي، و`without_attempts=1` بيرجّع الغايبين بس (من غير أي محاولة). كل صف: `id, student_number, name, email, attempts_used, extra_attempts, available_until`. بعدها `retake/bulk` مع `available_until` بيفتح الامتحان للطالب حتى بعد إغلاقه.

> كل الـ endpoints دي للمدرس صاحب الامتحان فقط: دور غلط → `403`، امتحان مش بتاعه → `404`.

---

## جلسة واحدة للطالب + مراقبة الكاميرا/تحقق الهوية

### إعدادات الامتحان (`POST /exams` و `PATCH /exams/{id}`)
| الحقل | النوع | ملاحظات |
|---|---|---|
| `single_session_enabled` | boolean (افتراضي `true`) | منع فتح نفس المحاولة من جهازين في نفس الوقت. |
| `proctoring_mode` | `off` \| `optional` \| `required` (افتراضي `off`) | لقطات الكاميرا. `required` = لقطة إجبارية عند البدء + لقطات دورية، ورفض/إيقاف الكاميرا مخالفة. |
| `identity_check_required` | boolean | سيلفي (+ كارنيه اختياري) قبل البدء، والمدرس بيراجعها. بيرفع `proctoring_mode` لـ `required` تلقائيًا. |
| `snapshot_interval_seconds` | integer 15..600 (افتراضي 60) | الفاصل بين اللقطات الدورية. |

### الجلسة الواحدة (الطالب)
- `POST my-exams/{id}/attempts` بيرجّع جوه `data.session`: `{ enforced, token, heartbeat_seconds, takeover }`. الـ `token` بيظهر مرة واحدة (السيرفر بيخزّن hash).
- كل request بعد كده على المحاولة (`GET attempts/{id}`, `PUT/DELETE answers`, `submit`, `security-events`, `heartbeat`, `snapshots`) لازم يبعت `X-Exam-Session: <token>` و`X-Device-Id: <معرّف ثابت للمتصفح>`.
- `POST attempts/{id}/heartbeat` (كل ~30 ثانية). الجلسة تعتبر ميتة بعد 90 ثانية من غير نشاط.
- أخطاء `409` بتحمل `data.code`:
  - `session_conflict` (عند `POST my-exams/{id}/attempts`): مفتوحة على جهاز تاني حي. الرد فيه `can_takeover: true` ومفيش أسئلة. إعادة الطلب بـ `takeover=true` بتاخد الجلسة **وبتتسجل مخالفة** `session_takeover` تتحسب في `max_violations`.
  - `session_required` / `session_replaced`: الـ token ناقص أو اتبدّل. الحل: `POST my-exams/{id}/attempts` تاني (بيكمل نفس المحاولة).
- نفس `X-Device-Id` بيكمل بدون حجب؛ جهاز مختلف بعد انقطاع أكتر من 90 ثانية بياخد الجلسة بدون مخالفة.
- المحاولات الخالصة مابتتفحصش. محاولة اتبدأت قبل التحديث (من غير جلسة مسجلة) مابتتحجبش لحد أول claim.
- السجل: `exam_attempt_sessions` + `exam_attempts.session_claims_count`.

### الكاميرا (الطالب)
- `POST my-exams/{id}/attempts` بـ `multipart/form-data` لما الكاميرا إجبارية: `start_photo` (jpeg/png/webp ≤ 1.5MB)، `id_card?`، `photo_flags?` (JSON array). من غير `start_photo`: `422` و`data.code = start_photo_required` ومفيش محاولة بتتخلق.
- الرد فيه `proctoring: { mode, snapshot_interval_seconds, identity_check_required, waived }` و`identity_status`.
- `POST attempts/{id}/snapshots` (multipart: `photo`, `flags?`) — فاصل أدنى نص الـ interval، حد أقصى 500 لقطة؛ الرد `{ stored, throttled, flagged }`.
- `flags`: `too_dark`, `blank`, `no_face`, `multiple_faces` (بتتحسب في المتصفح — **مؤشر مساعد مش دليل**، ومابتتحسبش مخالفة تلقائيًا).
- أحداث `security-events` الجديدة: `camera_denied`, `camera_stopped`, `camera_started` — مخالفة بس لو الكاميرا `required` على الطالب.
- أحداث نظامية (السيرفر بس): `session_takeover` (مخالفة), `session_conflict`, `proctoring_flag`, `proctoring_gap`, `identity_submitted/approved/rejected`.

### سطح المدرس (صاحب الامتحان فقط — دور غلط `403`، مش بتاعه `404`)
| Endpoint | الوصف |
|---|---|
| `GET exams/{id}/attempts/{attemptId}/integrity` | الجلسة (IP/جهاز/سجل) + الهوية + قايمة اللقطات (metadata). |
| `GET exams/{id}/attempts/{attemptId}/snapshots/{snapshotId}` | الصورة (binary، `Cache-Control: private, no-store`). |
| `POST exams/{id}/attempts/{attemptId}/identity-review` | `decision: approve\|reject`, `note?` (إجباري مع reject). |
| `PUT exams/{id}/students/{studentId}/proctoring-waiver` | `waived: bool`, `reason?` — إعفاء من الكاميرا، مابيمنحش محاولات إضافية. |

قائمة محاولات المدرس بقت فيها: `identity_status`, `proctoring_flags_count`, `session_claims_count`, `student_proctoring_waived`.

### حدود صريحة
- `X-Device-Id` بييجي من المتصفح (localStorage) — مش دليل قاطع على الجهاز.
- مفيش مطابقة وجه بين السيلفي والكارنيه: المراجعة بشرية. رفض الهوية بيسجّل الحالة بس؛ الإلغاء/الإعادة قرار المدرس.
- الصور على disk `local` الخاص ومابتتقدّمش إلا عبر endpoint المدرس صاحب الامتحان. لسه مفيش سياسة احتفاظ/حذف (محتاجة قرار حسب لوائح الجامعة).

## Exam access password (optional) + DevTools guard

- `POST/PATCH /api/v1/exam-system/exams` accept `access_password` (4–64 chars, stored hashed) and `remove_access_password` (bool). Omit both on update to keep the current password. The hash is never returned; exams expose `has_access_password`, and the student `my-exams/{id}` payload exposes `requires_password`.
- `POST /my-exams/{id}/attempts` accepts `access_password`. A new attempt on a protected exam fails with 422 `exam_password_required` / `exam_password_invalid`; 5 wrong tries per student+exam lock it for 5 min (429 `exam_password_locked`). Resuming a running attempt does not ask for the password again. Duplicating an exam does not copy the password.
- New client security events: `devtools_attempt` (shortcut blocked, informational) and `devtools_opened` (counts as a violation).
