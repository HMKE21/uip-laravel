Copy over the backend (same paths). No migration.
This zip INCLUDES the previous feature-request notification fix (TenantFeatureNotifier + TenantFeatureController).
Fix the existing workspace (ws=9 / tenant id 9):
  php artisan tenant:enable-academic 9
  (optional) php artisan tenant:enable-academic 9 --features=academic,meetings,exams,drive
Then sign out and in again: the admin now has a second role "university" (switch roles from the account menu).
New university workspaces get this automatically (env TENANT_LAUNCH_UNIVERSITY_FEATURES, default academic,meetings).
