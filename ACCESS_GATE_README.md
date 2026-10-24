# Account access gate (backend)

Run: `php artisan migrate`  (creates access_plans + account_access_grants)

Flag: Platform Policy `require_university_link` (default ON). OFF = no gating at all.

## Behaviour
- Student with students.university_id NULL => 403 `errors.code = university_approval_required` on everything
  except: auth/*, session/*, students/me*, notifications*, push, account, settings, universities.
- Opens when: university/faculty approves join request, admin links via Affiliation, OR a grant is active.
- Grant = features (messaging, meetings, projects, my_pc, learning, social, ai, all) + price + duration.
  Paid grants stay `pending` (no access) until mark-paid. Expiry is checked live on each request.
- Meetings guest routes: a restricted account is treated as a guest there.

## Endpoints (Bearer; admin | university | faculty)
GET  /api/v1/students/me/access                 (student: own status/history)
GET  /api/v1/access/features
GET|POST /api/v1/access/plans ; PATCH|DELETE /api/v1/access/plans/{id}
GET  /api/v1/access/accounts?q=                 (unlinked students)
GET  /api/v1/access/grants?status=active|pending|expired|revoked|all
POST /api/v1/access/grants   {user_id|email, plan_id | features[]+price+currency+duration_days, paid?, note?}
POST /api/v1/access/grants/{id}/mark-paid | extend {days} | revoke
GET  /api/v1/faculty/join-requests?status=pending|all
POST /api/v1/faculty/join-requests/{id}/approve | reject
Admin sees/manages all grants + admin plans; university/faculty only their own plans & grants.
