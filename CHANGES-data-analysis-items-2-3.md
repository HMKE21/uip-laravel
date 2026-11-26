# Data Analysis portal — items 2 & 3 (registration/fees/summer + academic structure/graduation)

Backend only (Laravel). No React changes: the Explorer / Query Builder / Advanced
Analytics / Data Quality pages are catalog-driven and pick the new datasets up
automatically.

## Files changed
- `config/data_explorer_datasets.php` — 13 new datasets (36 -> 49) + reverse/forward
  relationships appended to `students` and `courses`.
- `app/Services/QueryBuilderService.php` — 21 new curated SQL templates
  (registration, fees, summer, structure, graduation).
- `tests/Unit/DataExplorerCatalogTest.php` — 5 new tests.

## New datasets
Item 2: registration_periods, registration_policies, fee_items, student_fees,
fee_payments, summer_terms*, summer_offerings*, summer_registrations
Item 3: universities, faculties, departments, student_groups, graduation_records
(*added because summer_registrations references them)

## Hidden columns (blocked in Explorer, Query Builder, Advanced Analytics, Data Quality)
- student_fees.waive_note
- fee_payments.reference
- universities: logo_path, signature_path, stamp_path, dean_signature_path
- faculties: logo_path, cover_path, contact_email, contact_phone, campus_lat, campus_lng
- graduation_records.certificate_number

Note: the Query Builder rejects raw SQL that mentions a sensitive column name from
ANY dataset, so `reference` can no longer appear as a token in analyst SQL.

## Soft deletes
universities and departments are flagged `soft_deletes` (they have deleted_at).
Explorer + Query Builder exclude deleted rows automatically.

## Known limitation (pre-existing, not changed here)
DataQualityRepository and AdvancedAnalyticsRepository do not filter deleted_at, so
Data Quality scores and Advanced Analytics include soft-deleted rows for
universities, departments (and the already-existing exams/questions/question_banks).
