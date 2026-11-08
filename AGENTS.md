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

## Agreed next implementation order (2026-11-08)
- When work resumes, consult the ordered TODO in `docs/PROGRESS.md` under "Ordinea următoarelor module" before starting a new module.
- The agreed order is: purchase invoices + NIR/stock entry as one stage; outgoing invoices; stock/product ledger; exports; returns/cancellations/fiscal rules; card payments; product SEO and internal search; Google Merchant with automatic Merchant API synchronization; then the Stripe product catalog. Card payment work precedes SEO/catalog integrations, after the operational and fiscal stages.
- The later product stage must preserve manual/automatic meta titles and descriptions, validate Product structured data, maintain the public-product sitemap, check Google Search Console eligibility, and reflect product additions/edits in internal search and external catalogs. Do not promise guaranteed Google indexing or rich-result display.
- This is a saved roadmap, not authorization to start these modules automatically or deploy/configure live payment services. Later explicit user instructions can revise the order.

## Google OAuth deployment memory
- Before any production upload or domain cutover, read and execute `docs/GOOGLE_OAUTH_CHECKLIST.md`.
- Never commit, print, log or place the Google client JSON, client secret or production `.env` under `public/`; the standard private location is `storage/private/google-oauth-client.json`.
- Production must use `APP_URL=https://la-mariuca.ro`, `APP_ENV=production`, `SESSION_SECURE=true`, `GOOGLE_OAUTH_ENABLED=true` and the exact callback `https://la-mariuca.ro/cont/google/retur/`.
- Apply migrations `013_google_oauth_identities.sql` and `014_user_phone.sql` before testing login.
- Verify the Google Audience/test-user configuration, finalized public legal pages, the real callback and a complete login/logout/login cycle on the live HTTPS domain.
- Do not claim Google OAuth production-verified until the live-domain checklist has actually passed; local redirect tests are not production verification.

## Email CTA and push deployment memory
- Keep localhost URLs during local development and testing. Before production upload or domain cutover, follow the email/push checklist in `docs/DEPLOYMENT.md`.
- Production uses `APP_ENV=production`, `APP_URL=https://la-mariuca.ro`, an empty `APP_BASE_PATH` for the domain root, and `SESSION_SECURE=true`. Never copy the local `.env` or private QA routes to the server.
- Restart email workers after changing the public URL. Verify client tracking links, internal admin CTAs, email images, and cancellation notifications on the live domain; preserve `APP_KEY` so existing signed tracking links remain valid.
- Register each internal device for push on the live HTTPS admin origin; localhost subscriptions do not transfer. Preserve private VAPID keys, configure the push worker, and verify notification clicks after deployment.
- These deployment checks do not authorize deployment or real notification sends by themselves; report only checks actually performed.
