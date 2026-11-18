# Smart Forms API (`/api/v1`)

Envelope on every response: `{success, message, data, errors, meta}`. Error `errors.code` is a stable machine code.

## Owner side — Bearer token, any role (`uip.auth`)
| Method | Path | Notes |
|---|---|---|
| GET | `/forms/meta` | field types, templates, icons, colors, roles, limits |
| GET/POST | `/forms` | list (`scope=mine|shared|all`, `status`, `mode`, `search`, `page`) / create (`template=<key>` or full body) |
| GET/PUT/DELETE | `/forms/{id}` | PUT takes meta + `settings` + `fields[]` (full ordered list; send `id` to keep a question) |
| POST | `/forms/{id}/publish` `/close` `/duplicate` `/slug-check` | |
| — | `settings.password` / `settings.clear_password` | PUT: a non-empty `password` (4–100 chars) protects the public form (only a bcrypt hash is stored; GET returns `settings.has_password`, never the hash); `clear_password: true` removes it |
| — | `settings.appearance` | `{theme, accent(#rrggbb|''), font, show_progress, submit_label}`; also `closed_message`, `allow_another` |
| POST | `/f/{code}/unlock` | public, `{password}` → `{unlock_token}`; send it as header `X-Form-Unlock` on every later `/f/{code}/*` call. `GET /f/{code}` answers `state: password_required` until then |
| GET/PUT/DELETE | `/forms/{id}/collaborators[/{userId}]` | PUT `{email, permission: view|edit}` (owner only) |
| GET | `/forms/{id}/analytics` | totals, 30-day timeline, by hour, devices, browsers, roles, per-question stats, exam stats |
| GET | `/forms/{id}/responses` | `status=final|in_progress|all`, `search`, `needs_manual`, `flagged`, `sort`, `dir`, `page`, `per_page` |
| GET/DELETE | `/forms/{id}/responses/{rid}` | full answers + file previews + exam events |
| POST | `/forms/{id}/responses/{rid}/grade` | `{field_id, points, feedback}` |
| POST | `/forms/{id}/responses/{rid}/reopen` | exam: give another attempt |
| POST | `/forms/{id}/regrade` | after fixing an answer key |
| GET | `/forms/{id}/export.csv` | UTF-8 BOM, Excel-safe |
| GET | `/forms/{id}/files-archive?field_id=` | all uploads as one zip |
| GET | `/forms/{id}/files/{fileId}/preview` | text/code content or zip entries |

## Respondent side — guest or Bearer (`uip.auth.optional`), public link `{FRONTEND}/f/{code}`
| Method | Path | Notes |
|---|---|---|
| GET | `/f/{code}` | form + `state` (`open|closed|not_open_yet|full|login_required|role_not_allowed|not_invited`) |
| POST | `/f/{code}/start` | returns `response_uuid`, `edit_token`, fields (shuffled for exams), `seconds_left`, saved `answers` |
| PUT | `/f/{code}/responses/{uuid}/draft` | autosave `{answers:{fieldId:value}}` |
| POST | `/f/{code}/responses/{uuid}/events` | exam violations `{type: tab_switch|blur|fullscreen_exit|copy|paste|cut|contextmenu|devtools}` |
| POST | `/f/{code}/submit` | `{answers, response_uuid?, opened_at_ms, hp(honeypot, keep empty)}` |
| GET/PUT | `/f/{code}/responses/{uuid}` | result (score/review) / edit after submit |
| POST/DELETE | `/f/{code}/files[/{token}]` | multipart `field_id`, `file`; answer value for a file question = array of returned `token`s |

Headers: `X-Form-Token` (the `edit_token`, guests), `X-Device-Id` (stable per browser/app; needed when `one_per_device`), `X-Locale`.
File previews: every file has `url` (inline) / `download_url` (signed, relative, 10 min) and `preview`: `embed` (image/video/audio/pdf) · `text` (text/code) · `archive` (zip) · `none`.

## Answer value formats
text/email/phone/national_id/url → string · number → number · date `YYYY-MM-DD` · time `HH:MM` · single_choice/dropdown → option id (or `other:<text>`) · multi_choice → array of ids · true_false → boolean · scale/rating → int · file → array of upload tokens.

## Ops
`php artisan migrate` · cron every 5 min: `php artisan forms:maintenance` · env knobs in `config/forms.php` (`FORMS_*`). PHP upload limits (`upload_max_filesize`, `post_max_size`) and nginx `client_max_body_size` must allow the file size you want (default form cap 200MB).
