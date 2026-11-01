# UIP performance - phase 1 (changed files only)
Copy each folder's contents over the matching project (same relative paths).
Laravel: after copying run `php artisan migrate` (adds safe indexes) and `php -l` on the changed files (not syntax-tested here).
Set CACHE_STORE=redis in production (file cache is not shared between replicas).
React: no new npm packages. public/font-boot.js is new.
Note: account suspension can take up to 10s to apply (cached); session revoke is immediate.
