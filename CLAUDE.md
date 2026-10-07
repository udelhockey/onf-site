# onf-site — Open Net Foundation redesign

Read `docs/plan.md` first. It holds every decision, the data model, open items and phases. Keep it current: when a decision lands, update it in the same session.

## What this repo is
- `onf-theme/` — custom **block theme**. `theme.json` is the single source of design truth. No inline or hard-coded styles in content.
- `onf-core/` — ONF plugin: players, events + event series, funds, entries, gifts, donors, Stripe Checkout + webhook, receipts (PDF) and emails, GiveWP import, Totals + PDF report, migration tools, login branding. The donations system is built here (design: `docs/custom-donations-design.md`); GiveWP Essentials is the fallback if it isn't ready by cutover.
- `docs/` — plan, Phase 0 audit, brand guide, donations evaluation and design.

## Environments
- Staging: https://staging2.opennetfoundation.org (WordPress 7.1, PHP 8.2, SiteGround). Reached through the WPVibe MCP server (free plan: ~100 calls per rolling 24 h — batch SQL into few calls).
- Live: https://opennetfoundation.org — do not change live until cutover (Phase 5).
- Staging safety (keep it this way): GiveWP test mode ON, WP Mail SMTP "Do Not Send" ON, all Ninja Forms Zapier actions inactive. onf-core Stripe is in TEST mode (live mode is impossible off opennetfoundation.org).
- Staging holds a copy of LIVE Stripe keys inside GiveWP settings (copied from live). Never print or use them. Also never print the `onf_settings` option (it holds the Stripe test keys) — read individual non-secret keys only.

## Working rules
- Bob is the human in the loop; he builds player pages and approves anything that goes live. Bob uploads zips and clicks Run buttons on staging; Claude verifies on staging with read-only WPVibe queries.
- Bob is new to git. Commit with clear messages and push to `origin main` when a piece of work is done; explain git steps in plain words.
- Bump the plugin/theme `Version:` header (and `ONF_CORE_VERSION`) on every change.
- To install on staging: build the zip from the committed code — `git archive --format=zip --prefix=onf-core/ -o onf-core-X.Y.Z.zip HEAD:onf-core` (zips are git-ignored) — and Bob uploads it in wp-admin (Plugins → Add New → Upload → Replace current with uploaded).
- Test before handing over: lint with `docker run --rm -v "$PWD/onf-core":/app -w /app php:8.2-cli php -l <file>`, and smoke-test in a throwaway local WordPress (Docker: mariadb + wordpress:php8.2 + wordpress:cli, plugin mounted read-only; scratchpad has a docker-compose.yml). Test admin pages through the real menu (a submenu under Gifts must be registered at admin_menu priority > 10, after the Gifts menu exists). Render PDFs to PNG and look at them.
- Brand: navy **#0C2A3B** (brand guide "Deep Net Navy", decided 2026-10-07) primary; accent **Ice Blue #6EC1E4** (decided 2026-10-07, follow the brand guide). See `docs/brand-guide.html`. Madkel logo (report footer): `onf-core/assets/madkel-logo.jpg`.
- Registration stays on Ninja Forms → Zapier → client's Google Sheet. Don't replace that workflow.
