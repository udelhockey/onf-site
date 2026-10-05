# ONF website redesign — plan & decisions

_Last updated: 2026-10-04. Source of truth for the redesign. Update it when decisions land._

## Goal
Rebuild opennetfoundation.org as a clean, standards-driven WordPress site, and replace GiveWP before its renewal (end of Nov 2026). Work happens on a staging site first.

## Key dates
- **Nov 11, 2026** — next event. Runs on the CURRENT site + GiveWP (no platform swap before it).
- **End of Nov 2026** — GiveWP renewal; not renewing. Expired license should keep working (verify add-ons).
- **Cutover** — late Nov / Dec, after post-event donations settle.

## Decisions made
- **Full block theme** (custom `onf-theme`), `theme.json` as the single source of design truth. No inline/hard-coded styles in content.
- **Donations platform: UNDER EVALUATION** (2026-10-04) — GiveWP (stay), Charitable Pro, Mission, or build our own. Bob is open to paying; no donor-tip pressure. See `docs/donations-evaluation.md`. Data model below assumed Mission and will be revised once decided.
- **Custom plugin `onf-core`** for ONF-specific pieces, so Mission is replaceable later. Roll-our-own payments not ruled out, but not the plan.
- **Bob builds player pages** (human in the loop). Player self-service is optional, not required.
- **Fundraising is individual**; teams possible later (e.g., a tournament) — Mission supports teams.
- **Brand guide** (`docs/brand-guide.html`) is the baseline. Earlier design concepts rejected.
- **Logos** come from the original Illustrator art (white top background, Sept 2022). Text is outlined; the wordmark font is not recorded in any file (EPS/PDF/AI checked). Logo SVGs (mark, lockup, compact lockup, badge) are embedded in the brand guide and in the claude.ai project.
- **Keep plugins:** Tournament Bracket Manager, ManageWP Worker, Yoast Duplicate Post, **Ninja Forms + Zapier**.
- **Registration workflow stays:** Ninja Forms → Zapier → client's Google Sheet (+ Mailchimp). The client likes it. Alternatives are fine only if the sheet workflow is unchanged.
- **Player fields:** carry over ALL existing ACF fields (jersey, position, shot, hometown, height, weight, birthday, last team) and add favorite NHL team + sponsor/company.
- **Where the work happens (from Phase 2):** Claude Code on Bob's Mac, local session in `~/claude-code/onf-site` — writes, commits and pushes in one place. Repo: `github.com/udelhockey/onf-site` (private; independent of the udelhockey alumni + hub repos).
- **Login-page logo:** done in `onf-core` (`includes/login-branding.php` + `assets/onf-login-logo.svg`, navy compact lockup, links to home). Remove the My WP Login Logo plugin once `onf-core` is active.
- **Legacy Ninja Forms** (6, 8, 13, 14, 15, 18) and their Zaps: deleted on staging 2026-10-04. Delete on live at cutover after exporting submissions.

## Current stack (staging)
Astra theme + Elementor/Elementor Pro (+ Ultimate Addons), Classic Editor plugin active, ACF + CPT UI, GiveWP + add-ons, Ninja Forms + Zapier, Mailchimp for WP, Yoast SEO, Jetpack, SiteGround (SG Security/Optimizer), Duplicator Pro, WP Mail SMTP, Tournament Bracket Manager, My Custom Functions. Full detail: `docs/phase0-audit.md`.

## Staging state (2026-10-04)
- **https** on (Speed Optimizer HTTPS Enforce). ~1,000 hard-coded `http://staging2…` URLs remain in old content; SG Optimizer's "fix insecure content" covers them and old content gets rebuilt.
- GiveWP **test mode ON**. Any Stripe testing needs Stripe test keys/connection.
- WP Mail SMTP **"Do Not Send" ON** — no email leaves staging.
- All Zapier actions on staging are off (56, 57, 118, 120 inactive; legacy-form Zaps deleted with their forms). Live is untouched. Re-enable only when pointed at a test Zap/sheet (set `active=1` in `nf3_actions`).

## Data model (planned)
- **Event** = Mission peer-to-peer campaign (event totals, leaderboards).
- **Player** = `onf_player` post type in `onf-core` with ACF fields:
  - Public: name, photo, position, jersey, shot, hometown, height, weight, last team, favorite NHL team, sponsor/company
  - Private: contact info, birthday, additional info
  - `events` relationship; `historical_total` frozen from GiveWP at cutover
  - Migrate values from the existing 150 `player` posts.
- **Fundraiser** = Mission fundraiser page per player per event, linked to the Player.
- **All-time total** = historical total + sum of the player's Mission fundraiser totals.
- Existing reality (audit): favorite NHL team + company only exist in Ninja Forms submissions. Player→form link is just a `[give_form]` shortcode in content.

## Registration
- **Stays on Ninja Forms + Zapier → Google Sheet.** Fields today: name, email, phone, position, additional info, company (if sponsored), favorite NHL team, address, jersey size. No fee.
- Moving to ONE evergreen form (not per event) is still the goal; the Zaps keep feeding the same sheet.
- `onf-core` hooks Ninja Forms' after-submission event to: match player by email (create if new), attach event, and create the player's Mission fundraiser. Notifications stay as Ninja Forms email actions.
- Event dropdown shows only events open for registration.
- Favorite NHL team drives decoration via team COLORS, not team logos.
- 20 Something has its own registration (form 19: birthday, sessions) — keep separate.
- Optional later (same workflow, no Zapier bill): a Google Apps Script web app that appends rows to the same sheet, called from `onf-core`. Only if Zapier cost/limits become a problem.

## Mission findings (source code, v1.5.0, 2026-10-03)
- **Admin can create a fundraiser on a player's behalf.** REST `POST /mission-donation-platform/v1/fundraisers` (admin-only) takes `campaign_id`, `donor_id`, optional `goal`, `story`, `headline`, `status`, `team_id`. The donor record comes from admin-only `POST /donors` (email, first/last name, phone). There's no "add fundraiser" button in the admin UI — so `onf-core` (or a small admin screen) does it. Approval workflow (manual or instant) is also available for self sign-ups.
- **GiveWP importer exists** (Tools → import): campaigns, donors, subscriptions, transactions; skips trashed and (optionally) test donations. On GiveWP 4 it reads the `give_campaigns` table — ONF has **343** of those (one per player form), so a full import creates 343 ordinary Mission campaigns, not P2P fundraisers. Player history would still need the frozen `historical_total` in `onf-core`.
- Mission does NOT import Ninja Forms or player data — those stay ours.

## Open items
- [x] Staging locked down: GiveWP test mode, no outgoing mail, all Zaps off, https on, legacy forms deleted.
- [x] GitHub: `udelhockey/onf-site` created; starter commit pushed to `main` (2026-10-04).
- [ ] Connect WPVibe (staging) to Claude Code on the Mac (`/mcp`).
- [ ] Install `onf-core` 0.1.0 on staging, check the login logo, then remove My WP Login Logo.
- [ ] **Brand blue:** adopt logo blue **#29ABE2** (C70 M15 Y0 K0) in place of Ice Blue #6EC1E4? (recommended) — decide at start of Phase 2.
- [ ] Wordmark font name — ask original designer or run a rendered wordmark through WhatTheFont/Matcherator. Not blocking.
- [x] Player fields: keep all existing; add favorite NHL team + sponsor.
- [x] Name-merge list confirmed (Nick/Nicholas Butler = same person).
- [ ] Decide on remaining "decide" plugins: Jetpack, iframe, Post Type Switcher, Simple Analytics, SiteGround AI Agent.
- [ ] Phase 3: test Mission's GiveWP import on staging; decide full import (donor history + 343 ended campaigns) vs. donors-only vs. archive GiveWP data and freeze totals only.
- [ ] Export all GiveWP data from **live** at cutover (donations, donors, forms, per-player totals) + Ninja Forms submissions + Duplicator archive.
- [x] GiveWP add-ons in use: Stripe, PDF Receipts, Email Reports, Manual Donations (still used 2024–26), Elementor widgets.
- [x] Phase 0 audit — `docs/phase0-audit.md` (2026-10-03). Staging donations total $144,341.42 / 1,761 gifts through 2026-06-23.
- [x] Zapier mapped: form 4 → Google Sheets + Mailchimp (Trello zap disabled); form 19 → Google Sheets.
- [x] Mission: admin-created fundraisers possible via REST; GiveWP importer exists (see findings).

## Phases
| # | When | What |
|---|------|------|
| 0 | Done 10/4 | Audit, staging lockdown, repo + starter code |
| 1 | → Nov 11 | Event on current site; optionally automate registration notifications |
| 2 | October | Design system: `theme.json`, header/footer, core templates (staging) |
| 3 | Late Oct–Nov | Mission on staging in test mode; sample P2P event; import test |
| 4 | November | Rebuild content on new templates; freeze historical totals |
| 5 | Late Nov/Dec | Live Stripe, cutover, retire GiveWP (keep data archived) |
