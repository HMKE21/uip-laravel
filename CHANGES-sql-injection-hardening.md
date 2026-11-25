# إغلاق ثغرات laravel-raw-sql-injection

## ثغرة حقيقية اتقفلت
`app/Repositories/QueryBuilderRepository.php` → `validateRawSql()` (وضع الـ Raw SQL في الـ Query Builder):
- `JOIN (users)` / `FROM ((users))` (جدول بين أقواس) كان بيتخطى قايمة الجداول المسموحة.
- `FROM`users`` من غير مسافة، و`STRAIGHT_JOIN`، كانوا بيتخطوا فحص `FROM/JOIN`.
- `WITH x AS (TABLE users)` و`VALUES ROW(..)`، وقراءة `information_schema`/`mysql.*`.
- `SLEEP()` / `BENCHMARK()` / `GET_LOCK()` (إبطاء/تعليق السيرفر)، و`SELECT ... INTO @var`، والتعليق بـ `#`، ومسافات Unicode (NBSP…).
كلها دلوقتي مرفوضة، والاستعلامات السليمة (بما فيها نص عربي) شغّالة.

## التحذيرات الأربعة اللي بعتّها (تحذيرات غلط من القاعدة، اتحصّنت وعليها nosemgrep موثّق)
- AccountAccessApiController::updatePlan → أسماء الأعمدة قايمة ثابتة (`Arr::only`) والقيم bindings.
- AdminUsersApiController (update + insert لـ universities) → أعمدة literal.
- AiCodeChatHistoryController::save → أعمدة literal والقيم متنضّفة.

## اتراجع ومفيهوش مشكلة (143 موضع SQL ديناميكي)
القيم كلها `?` bindings؛ الأرقام (`LIMIT/OFFSET`) typed `int`؛ أسماء الأعمدة/الجداول بتعدّي على `quoteIdent` (بيشيل أي حرف مش `[A-Za-z0-9_]`) أو قوائم `match`/`in_array` ثابتة.
