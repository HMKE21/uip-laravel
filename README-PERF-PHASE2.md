# UIP performance - phase 2 (changed files only)

Copy each folder's contents over the matching project (same relative paths), then:
`php artisan migrate --force` (adds inbox indexes, safe to re-run). PHP was not available when this was written:
run `php -l` on the changed files and your test-suite before deploying.

## Backend (Laravel)
- `ConversationRepository::inboxForUser`: participants of all conversations in ONE query (was one per conversation, on every inbox poll);
  `NOT IN (subquery)` replaced by `NOT EXISTS`.
- `MessagingService::inboxForUser`: the saved-messages check runs once a minute per user instead of 3 queries per poll;
  the avatar lookup is done once per response.
- `MessagingService::heartbeat`: writes at most once per 10s per user (online window is 30s).
- `AccountAccessGate` / `MfaPolicyService`: the "all clear" answer is cached 30s. Restricted / not-enrolled users are always re-checked,
  so approvals, grants and 2FA enforcement take effect immediately for them.
- Migration `2027_03_03_100000_add_inbox_and_lookup_indexes`: indexes for messages, hidden messages, attachments, participants, roles, students.
- `.env.production.example`: Redis for cache / sessions / queue. Cache MUST be shared (Redis) when you run more than one replica.

## Frontend (React)
- `vercel.json`: short cache for the unhashed `font-boot.js` / `theme-boot.js`.
- `AccessGate`: poll only while the tab is visible, with random spread.

## Not changed (and why)
- CSS split (1.3 MB source): the files overlap in cascade order; needs a screenshot test first.
- `RealtimePing` stays `ShouldBroadcastNow` and the activity log stays in the terminating callback until a queue worker exists.
