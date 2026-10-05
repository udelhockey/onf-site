# onf-site — Open Net Foundation redesign

Read `docs/plan.md` first. It holds every decision, the data model, open items and phases. Keep it current: when a decision lands, update it in the same session.

## What this repo is
- `onf-theme/` — custom **block theme**. `theme.json` is the single source of design truth. No inline or hard-coded styles in content.
- `onf-core/` — ONF plugin: players, events, registration hooks, login branding. Keeps ONF logic separate from the donation platform (under evaluation — see docs/donations-evaluation.md) so it stays replaceable.
- `docs/` — plan, Phase 0 audit, brand guide.

## Environments
- Staging: https://staging2.opennetfoundation.org (WordPress 7.1, PHP 8.2, SiteGround). Reached through the WPVibe MCP server.
- Live: https://opennetfoundation.org — do not change live until cutover (Phase 5).
- Staging safety (keep it this way): GiveWP test mode ON, WP Mail SMTP "Do Not Send" ON, all Ninja Forms Zapier actions inactive.
- Staging holds a copy of LIVE Stripe keys inside GiveWP settings (copied from live). Never print or use them; disconnect Stripe on staging and use test keys only.

## Working rules
- Bob is the human in the loop; he builds player pages and approves anything that goes live.
- Bob is new to git. Commit with clear messages and push to `origin main` when a piece of work is done; explain git steps in plain words.
- Bump the plugin/theme `Version:` header (and `ONF_CORE_VERSION`) on every change.
- To install on staging: zip `onf-core/` or `onf-theme/` as a top-level folder and upload in wp-admin, or deploy through WPVibe.
- Brand: navy #0F2A3F; accent decision pending (logo blue #29ABE2 recommended over Ice Blue #6EC1E4). See `docs/brand-guide.html`.
- Registration stays on Ninja Forms → Zapier → client's Google Sheet. Don't replace that workflow.
