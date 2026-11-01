# Production checklist for ~10k concurrent users

1. Add a Redis service and set CACHE_STORE=redis (see .env.production.example). Phase-1 caches depend on a shared store.
2. Run at least 2-3 API replicas; keep Reverb (`php artisan reverb:start`) as its own service.
3. Run a queue worker and `php artisan schedule:work` as separate services (none is defined in the repo today).
4. On deploy: `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan event:cache`.
5. MySQL: enable slow query log (>200ms), watch connections (max_connections vs replicas x workers), consider a read replica later.
6. Load-test before launch (k6 or Artillery) on login, /app/summary, messaging inbox, exam attempt save.

Not changed yet (needs a worker first, otherwise data would be lost):
- UipActivityLogMiddleware already writes after the response (terminating); moving it to a queue is optional.
- RealtimePing uses ShouldBroadcastNow; switch to ShouldBroadcast only once a queue worker is running.
