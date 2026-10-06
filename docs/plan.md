# ONF website redesign — plan & decisions

_Last updated: 2026-10-04 (evening). Source of truth for the redesign. Update it when decisions land._

## Goal
Rebuild opennetfoundation.org as a clean, standards-driven WordPress site, and settle the donations platform before GiveWP's renewal (end of Nov 2026). Work happens on a staging site first.

## Key dates
- **Nov 11, 2026** — next event. Runs on the CURRENT site + GiveWP (no platform swap before it).
- **End of Nov 2026** — GiveWP renewal. Either we've cut over to our own donations (no renewal), or we renew **GiveWP Essentials ($199)** as the fallback. An expired license is not a safe option (reports of a 2% Stripe platform fee without a valid license).
- **Cutover** — late Nov / Dec, after post-event donations settle.

## Decisions made
- **Full block theme** (custom `onf-theme`), `theme.json` as the single source of design truth. No inline/hard-coded styles in content.
- **Donations platform — down to two options (2026-10-04):**
  1. **Build our own** in `onf-core` (Stripe Checkout, tagged gifts, GiveWP history imported). Design: `docs/custom-donations-design.md`. **Leading option.**
  2. **GiveWP Essentials** ($199/yr) — today's per-player-form model rebuilt on modern GiveWP blocks, plus `onf-core` blocks for the gaps. Fallback if the build isn't ready and tested before cutover.
  - **Ruled out:** Mission (preselected donor tip, payments through Mission's server), GiveWP Elite ($599 — too much for P2P alone). Charitable Pro dropped from consideration. Detail: `docs/donations-evaluation.md`.
- **Donation must-haves:** **donor board + totals on every player page**, event total + top-fundraiser leaderboard, all-time player totals back to 2015, non-player funds, own Stripe, manual check/cash gifts, receipts, export, no tip pressure.
- **Not needed:** tributes (in honor / in memory), recurring donations, PayPal, player self-service sign-up, Zapier on donations.
- **Preferred model:** one permanent page per player, tagged by event (the old category approach) — not GiveWP 4's campaign → form per player.
- **Custom plugin `onf-core`** for ONF-specific pieces (players, events, registration hooks, login branding, and the donations module if we build).
- **Bob builds player pages** (human in the loop).
- **Fundraising is individual**; teams possible later (e.g., a tournament).
- **Money saved on licenses** goes to texting (Twilio), event payments and reminders (e.g. 20 Something skate) — later modules.
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

## Staging state (2026-10-04) — cleanup done
- **https** on (Speed Optimizer HTTPS Enforce). ~1,000 hard-coded `http://staging2…` URLs remain in old content; SG Optimizer's "fix insecure content" covers them and old content gets rebuilt.
- GiveWP **test mode ON**. Any Stripe testing needs Stripe test keys/connection.
- WP Mail SMTP **"Do Not Send" ON** — no email leaves staging.
- All Zapier actions on staging are off (56, 57, 118, 120 inactive; legacy-form Zaps deleted with their forms). Live is untouched. Re-enable only when pointed at a test Zap/sheet (set `active=1` in `nf3_actions`).
- Legacy Ninja Forms deleted.
- WPVibe connected to Claude Code on the Mac (verified 2026-10-04, admin user).

## Data model (build-our-own; see `docs/custom-donations-design.md`)
- **Player** = `onf_player` post type in `onf-core` — one permanent page per person. Public: name, photo, position, jersey, shot, hometown, height, weight, last team, favorite NHL team, sponsor/company. Private: email, phone, birthday, notes.
- **Event** = `onf_event` post type: dates, venue, goal, default player goal, status (registration / fundraising / closed).
- **Entry** = `onf_entries` table: player × event, with goal, sponsor for that event, status.
- **Fund** = `onf_fund` post type for non-player causes (General, Food Bank, Dolan Fund, Holiday Giving, rinks).
- **Gift** = `onf_gifts` table, tagged with player / event / fund; every total is a sum over those tags.
- **All-time totals come from real rows:** GiveWP history (1,761+ completed gifts) is imported into `onf_gifts`, not frozen.
- Existing reality (staging, 2026-10-04): 150 published `player` posts, one per person (titles unique), each with a featured image and a `[give_form]` shortcode (137 with one form, 13 with two). Event membership = `give_forms_category` terms on the player post: **296 player-event links** across 18 event terms (2018Player → 2026 Rouxster Shootout). Favorite NHL team + company exist only in Ninja Forms submissions; the migration copies email, phone, favorite team and company from the latest matching Event Registration (73 of 150 players match by name; the rest stay blank — fine per Bob).

## Registration
- **Stays on Ninja Forms + Zapier → Google Sheet.** Fields today: name, email, phone, position, additional info, company (if sponsored), favorite NHL team, address, jersey size. No fee.
- Moving to ONE evergreen form (not per event) is still the goal; the Zaps keep feeding the same sheet.
- `onf-core` hooks Ninja Forms' after-submission event to: match player by email (create if new), add an entry for the chosen event, notify Bob. Bob reviews and publishes the player page. Notifications stay as Ninja Forms email actions.
- Event dropdown shows only events open for registration.
- Favorite NHL team drives decoration via team COLORS, not team logos.
- 20 Something has its own registration (form 19: birthday, sessions) — keep separate.
- Optional later (same workflow, no Zapier bill): a Google Apps Script web app that appends rows to the same sheet, called from `onf-core`. Only if Zapier cost/limits become a problem.

## Open items
- [x] Staging locked down: GiveWP test mode, no outgoing mail, all Zaps off, https on, legacy forms deleted.
- [x] GitHub: `udelhockey/onf-site` created; starter commit pushed to `main` (2026-10-04).
- [x] Connect WPVibe (staging) to Claude Code on the Mac — verified 2026-10-04.
- [x] Donation needs confirmed; Mission and GiveWP Elite ruled out; tributes not needed.
- [ ] **Decide: build our own vs GiveWP Essentials.** Build step 1 (data model + player migration) comes next and is useful under either option; go/no-go on the full build before Phase 3.
- [x] Donations build step 1 written: `onf-core` 0.2.0 — `player` post type moved from CPT UI into `onf-core` (same name, IDs, URLs), events, funds, entries/gifts/donors tables, Players/Events/Gifts admin, manual gifts, CSV export, Tools → ONF Migration (2026-10-04). Smoke-tested locally; not yet on staging.
- [x] Donor management (`onf-core` 0.3.0, 2026-10-04): Gifts → Donors list with lifetime total, gift count, first/last gift, search, sort, CSV export; donor page to edit details (name, email, company, phone, address, private notes) with full gift history; merge duplicate donors (gifts move, blanks filled, other email kept in notes); delete only donors with no gifts; email optional (check/cash givers); manual gift form picks an existing donor. Gifts keep the donor name/email as given at the time, so receipts don't change when a donor is edited.
- [x] `onf-core` 0.3.0 installed on staging and migration run (2026-10-05): 21 events (closed drafts), 296 entries covering 135 players, contact fields filled for 74 players, GiveWP form IDs on all 150, old ACF group disabled. Player pages still load with their GiveWP forms.
- [ ] Remove My WP Login Logo (check the `onf-core` login logo) and Custom Post Type UI on staging.
- [x] Donations build step 2 written (`onf-core` 0.4.0, 2026-10-05): Stripe Checkout (hosted page; cards, Apple/Google Pay) via `[onf_donate]` (block in step 3), cover-the-fee option, webhook `/wp-json/onf/v1/stripe-webhook` (signature-checked; records gifts once; full refunds → refunded), thank-you page also records the gift if the webhook is late. Receipt numbers continue GiveWP's `25-` sequence from 26704. PDF receipt (no library; logo, donor, amount, EIN, no-goods-or-services statement, fee-covered total). Emails from editable templates matching GiveWP's: donor receipt with PDF, player notification (respects Anonymous), admin notification (bob@, rroux@). Email log (Gifts → Email log) shows every email, also on staging where sending is off. Manual gifts: optional receipt/player email; pledges send when marked Completed. Gifts list: Receipt PDF, Resend receipt. Settings: Gifts → Settings (Stripe keys stored, never shown; live mode only possible on opennetfoundation.org). Migration also fills player email from GiveWP per-form notification recipients. Locally tested end to end with simulated Stripe webhooks; real Stripe test on staging next.
- [x] 0.4.1 (2026-10-05, from Bob's first staging test): Edit gift (move between player/event/fund; amount/date/method for manual gifts only), Refund… link in Gifts (refunds through Stripe), email log says "blocked" when WP Mail SMTP Do Not Send is on, an explicitly chosen event counts even if Closed. First staging test gift went through (receipt, PDF, 3 emails logged); it missed the event because 2026 HMC was still Closed.
- [ ] Step 2 on staging: install 0.4.1, enter Stripe TEST key + webhook secret, set one event to Fundraising, test page with `[onf_donate]`, pay with Stripe test card 4242 4242 4242 4242, check gift/receipt/email log, refund in Stripe and check status. Re-run the migration (fills more player emails).
- [ ] Not yet built: online "mail a check" pledge option, weekly/monthly email reports, partial refunds (only full refunds change status), donor "look up my receipts" link.
- [ ] Review the migrated event names (old categories renamed, e.g. 2018Player → 2018 Face-off for Teen Mental Health, 2019Adult → 2019 Face-off for Juvenile Arthritis, 2022Adult → 2022 Face-off for Teen Mental Health) and add dates/venues. Events are created as closed drafts, dated by their first GiveWP form (often months before the event, e.g. 2021 Chowder Cup shows 2020-04-26) — set real start dates.
- [ ] **Brand blue:** adopt logo blue **#29ABE2** (C70 M15 Y0 K0) in place of Ice Blue #6EC1E4? (recommended) — decide at start of Phase 2.
- [ ] Wordmark font name — ask original designer or run a rendered wordmark through WhatTheFont/Matcherator. Not blocking.
- [x] Player fields: keep all existing; add favorite NHL team + sponsor.
- [x] Name-merge list confirmed (Nick/Nicholas Butler = same person).
- [ ] Decide on remaining "decide" plugins: Post Type Switcher, Simple Analytics, SiteGround AI Agent. (Jetpack and iframe deleted on staging 2026-10-05; `onf-core` 0.4.0 replaces the `[iframe]` shortcode for the 73 EliteProspects stats embeds on player pages — EliteProspects only. Delete both on live at cutover, after `onf-core` is active there.)
- [ ] Ask Stripe about nonprofit discounted pricing.
- [ ] Favorite NHL team → pick-list of the 32 teams (with step 3 player pages). One-time cleanup maps the free-text values (Flyers / Philly / Flywrs → Philadelphia Flyers); unmatched ones left for Bob.
- [ ] Registration form: replace the Favorite NHL Team text box with a dropdown — on live, after Nov 11, re-mapping the Zapier → Google Sheet step in the same sitting (a new Ninja Forms field has a new key).
- [ ] Export all GiveWP data from **live** at cutover (donations, donors, forms, per-player totals) + Ninja Forms submissions + Duplicator archive.
- [x] GiveWP add-ons in use: Stripe, PDF Receipts, Email Reports, Manual Donations (still used 2024–26), Elementor widgets.
- [x] Phase 0 audit — `docs/phase0-audit.md` (2026-10-03). Staging donations total $144,341.42 / 1,761 gifts through 2026-06-23.
- [x] Zapier mapped: form 4 → Google Sheets + Mailchimp (Trello zap disabled); form 19 → Google Sheets.

## Phases
| # | When | What |
|---|------|------|
| 0 | Done 10/4 | Audit, staging lockdown, repo + starter code, donations shortlist |
| 1 | → Nov 11 | Event on current site + GiveWP |
| 2 | October | Design system: `theme.json`, header/footer, core templates (staging). In parallel: donations build steps 1–3 (data model + migration, Stripe test mode, blocks) |
| 3 | Late Oct–Nov | GiveWP import + reconcile totals to the cent; registration hook; go/no-go vs renewing GiveWP Essentials |
| 4 | November | Rebuild content on new templates |
| 5 | Late Nov/Dec | Live Stripe keys, final GiveWP import, cutover, retire GiveWP (keep data archived) |
