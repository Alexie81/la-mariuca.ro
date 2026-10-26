# La Măriuca Boutique

## Scope
- Modular PHP storefront and administration for children aged 0–8.
- Romanian UI, RON money stored as integer bani, UTC persistence, Europe/Bucharest display.
- Apache/shared-hosting target; production webroot is `public/` only.
- Do not deploy to production or configure live Stripe/SMTP without explicit approval.
- User images under `logo-uri/` are source assets: never overwrite them.

## Structure
- `public/`: front controller and public assets/media derivatives.
- `app/Shared`: configuration, HTTP, database, security and support code.
- `app/Modules`: business modules, controllers, repositories and services.
- `resources/views`: Twig storefront, admin and email templates.
- `database/migrations`: versioned SQLite/MySQL schemas; `database/seeders`: explicit demo data.
- `storage/`: private runtime data, cache and logs (ignored except `.gitkeep`).
- `bin/`: CLI entry points; `tests/`: PHPUnit unit/integration tests.
- `docs/`: requirements, architecture, progress, design handoff and deployment.

## Conventions
- Controllers adapt HTTP; services own business rules; repositories own SQL.
- Use explicit dependency injection. Never query the database from Twig.
- Use prepared statements; allowlist dynamic SQL identifiers and sort clauses.
- Every state-changing browser request requires CSRF, except signed provider webhooks.
- Admin authorization is enforced server-side for every `/admin` route.
- Variant IDs, server prices and server stock are authoritative for cart/checkout.
- Use transactions for stock/order/coupon changes; never hold one across SMTP/Stripe calls.
- Escape Twig by default; sanitize any rich HTML before persistence.
- Public URLs come from `APP_URL` + `APP_BASE_PATH`; never hardcode a host or folder.
- Main content works without JavaScript. JS only enhances navigation/forms.
- Keep storefront styling in `public/assets/css/storefront`; admin styling separate.

## Commands
- Install: `php composer.phar install`
- Configure: copy `.env.example` to `.env`, then `php bin/generate-key.php`
- Migrate: `php bin/console.php migrate`
- Demo seed: `php bin/console.php seed:demo`
- Admin: `php bin/console.php admin:create`
- Dev server: `php -S 127.0.0.1:8080 -t public public/router.php`
- Lint: `php composer.phar lint`
- Static analysis: `php composer.phar analyse`
- Tests: `php composer.phar test`
- Audit: `php composer.phar audit`

## Change discipline
- Preserve unrelated user changes and existing Git history.
- Add migrations; do not edit a deployed migration in place.
- Update `docs/PROGRESS.md` with real checks and remaining external blockers.
- Never claim Apache, MySQL concurrency, Stripe, SMTP or hosting verified unless actually run.

## Google OAuth deployment memory
- Before any production upload or domain cutover, read and execute `docs/GOOGLE_OAUTH_CHECKLIST.md`.
- Never commit, print, log or place the Google client JSON, client secret or production `.env` under `public/`; the standard private location is `storage/private/google-oauth-client.json`.
- Production must use `APP_URL=https://la-mariuca.ro`, `APP_ENV=production`, `SESSION_SECURE=true`, `GOOGLE_OAUTH_ENABLED=true` and the exact callback `https://la-mariuca.ro/cont/google/retur/`.
- Apply migrations `013_google_oauth_identities.sql` and `014_user_phone.sql` before testing login.
- Verify the Google Audience/test-user configuration, finalized public legal pages, the real callback and a complete login/logout/login cycle on the live HTTPS domain.
- Do not claim Google OAuth production-verified until the live-domain checklist has actually passed; local redirect tests are not production verification.
