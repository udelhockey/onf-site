# ONF website redesign — plan & decisions

_Last updated: 2026-10-07. Source of truth for the redesign. Update it when decisions land._

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

## Phase 2 + donations step 3 decisions (Bob, 2026-10-07)
- **Theme:** `onf-theme` block theme; `theme.json` holds palette (brand guide), Exo + Roboto (bundled in the theme, no Google Fonts call), type scale, spacing, 4px buttons. Header = compact reversed lockup on navy + navigation + **Donate button**; footer = navy with address, EIN, links, foundation total.
- **Menu:** no more long "Past events" dropdown. Events · 20 Something Hockey · About · Sponsors · Contact (`/contact-us/`) + **Donate button**. **Events** → `/events/` (event archive): events happening now first, then **past events as a card grid with total raised per event**. Donate button → the existing `/donation/` page, switched to the theme's **Donate page** template (events and funds taking gifts). Not by page address: staging has three old pages called "donate".
- **"Raised since 2015"** — foundation total counts from the first gift year (2015).
- **Donor board defaults:** current (latest) event, amounts **on**, messages **on**.
- **Classic Editor deactivated on staging** (Bob) — pages and templates use the block editor.
- **Player pages switch** from GiveWP by one setting (Gifts → Settings → Player-page donations: GiveWP / ONF). ONF mode hides `[give_form]` on player pages at display time (content untouched, reversible). GiveWP stays active until cutover so its shortcodes elsewhere keep working.
- **EliteProspects ID** player field; a migration tool fills it from the existing `[iframe]` embeds.
- [x] **Built (2026-10-07): `onf-theme` 0.1.0 + `onf-core` 0.8.0** — tested locally (WP 7.1.2, Docker, seeded players/events/funds/gifts): every template, desktop + 375px phone (no sideways scroll), phone menu, all templates/patterns pass block validation, blocks preview in the editor, GiveWP/ONF switch, EP ID fill (re-run safe), all admin pages load.
  - Blocks (server-rendered, plain-JS editor, no build step): **Donate** (button or form), **Progress / totals** (player-in-event, event, fund, series, or chosen funds added together; current event / chosen event / year / all-time; goal bar; per-fund breakdown; counts), **Donor board** (defaults: current event, amounts + messages on, newest first, 12 then "Show all"), **Leaderboard** (list or photo cards; 0 = whole roster), **Foundation total** (line or big numbers; "since" = first gift year), **Player profile** (photo, number·position·event, facts, events with totals, EP stats, all-time), **Event details** (series·status, dates·venue, Register/Donate), **Event & fund cards** (happening now / past with totals / funds), **Series totals** (years × funds table).
  - New fields: player `ep_id`; event `registration_url` (Register button while registration is open).
  - Navy changed to #0C2A3B in receipts, PDF report and login logo. Returning from Stripe lands on the thank-you (`#onf-donate`).
- [ ] **On staging (Bob):** upload onf-core 0.8.0, then onf-theme 0.1.0 → Live Preview → activate; Pages → "Donate" (/donation/, ID 6) → Template "Donate page"; Tools → ONF Migration → Fill EliteProspects IDs; Events: set status/dates/venues/featured images and **publish** past events (cards only list published events; all but 2026 HMC are drafts); Funds: publish + "Accepting gifts" + excerpt/image; Gifts → Settings → Player-page donations = ONF when ready to test; one Stripe test gift from a player page (check it lands back on the thank-you).
- **Donors give on player pages, never from a list on a generic form (Bob, 2026-10-07).** The point is to get donors onto the player's page. `onf-core` 0.8.1 / theme 0.1.1: an event with players has **no event-wide donate form**; its buttons (event hero, event cards on Home / Events / Donate page) say **"Find a player to support"** and go to the event's **Top fundraisers** list (`#players`). Events without players (e.g. holiday giving) and funds keep their own form.
- **Top fundraisers = a ranked list, not a grid (Bob, 2026-10-07):** top 10 shown, then **"Show all N players"** opens the rest; a name search above it when there are more than 12 players (also searches the hidden ones). **Rewards:** event edit screen → "Top fundraiser rewards" (places 1–5: name + optional picture). Shown quietly: a small picture (or gift icon) beside the place number — only for players who have raised something — and one small line under the list ("Thank-you gifts for our top fundraisers: 1st ONF hoodie · 2nd …").
- [x] 0.8.0 / 0.1.0 uploaded and the theme activated on staging (Bob, 2026-10-07). Checked: pages load on onf-theme with no PHP errors; /donation/ uses the Donate page template; 72 EliteProspects IDs filled; player donations = ONF; 2 events published, 0 funds published.
- [x] Found on staging: **Elementor Pro Theme Builder** still took over player pages ("player -general" single template, condition all players; it also brings the "ONF Header" Elementor header). A block theme registers no Elementor locations, so Elementor swaps in its own page layout. Theme 0.1.1 keeps its own templates for players, events, funds, the events list and series pages. Old Elementor-built pages still render with Elementor until rebuilt (Phase 4). At cutover: set those Theme Builder templates to draft (or remove Elementor Pro).
- [ ] Later: favorite NHL team pick-list + cleanup.

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
- **Fund** = `onf_fund` post type for non-player causes (General, Food Bank, Dolan Fund, rinks) — permanent; can belong to an event series so its gifts count toward that year's event.
- **Series** = `onf_series` taxonomy on events: the same event each year; totals add up across years.
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
- [x] 0.4.2 (2026-10-05): fixed `[onf_donate player="…"]` ignoring the player's current event (gifts saved with no event); Edit gift opens inside Gifts (was "not allowed"); bulk **Assign to event…** on the Gifts list.
- [x] Step 2 verified on staging with Stripe test mode (2026-10-05, 0.4.2): test donations recorded with receipt numbers and PDFs, all three emails in the log (blocked by Do Not Send), refund from Gifts list worked, new gifts land on the event roster automatically, bulk Assign to event works.
- [ ] Before cutover: delete staging test gifts/test page (they're test-mode only).
- [x] Receipt numbers (Bob, 2026-10-05): by year, restarting each January — 26-0001, 26-0002 … 27-0001 (year from the gift date). Not continuing GiveWP's numbers; imported gifts keep their GiveWP numbers.
- [x] Donations build step 4 written (`onf-core` 0.5.0, 2026-10-05): Gifts → Import from GiveWP. Maps each GiveWP form with gifts (378 on staging, 1,782 donations, $144,341.42) to player/event/fund: form on player page (134), same name (151), else nickname (Rich=Richard…), alias (JP=JP Thomas), 1–2-letter typo, else new draft "historical" player; known non-player forms → funds (General Donation, Dolan Fund, Food Bank, Adopt a Family, Tender Hearts, Sponsors, Patriot Ice Center, Skating Club of Wilmington, Make Steve Shave); "… Donations" forms → event only. Imports completed + refunded live donations (skips test/abandoned/failed/trash) with donors, comments, anonymous flag, GiveWP receipt numbers; adds roster entries; batches of 250; reconciles totals per player/event/fund to the cent; re-runnable; Undo. Locally tested with simulated GiveWP data.
- [x] Make Steve Shave = sub-fund of Save Our Rinks (Bob, 2026-10-05): imported as fund "Make Steve Shave" under event 2020 Save Our Rinks, like Patriot Ice Center and Skating Club of Wilmington.
- [x] Players → Merge players (`onf-core` 0.5.1): moves gifts, event entries (combined when both were in the same event), GiveWP form links and blank profile fields to the player that stays; the other page goes to Trash and its old address redirects. Nick Falkowski = Nicholas Falkowski (Bob) — merge on staging; Bob picks which page stays.
- [x] Archive players (`onf-core` 0.5.2, Bob's idea): Players list shows active players; Archived view; Archive/Restore link + bulk actions; "Last event before YEAR / No events" filter for bulk archiving youth/junior players; history, totals and public pages kept; archived players listed last under "Archived players" in gift pickers; adding a player to an open event restores them; GiveWP-import historical players start archived.
- [x] Step 4 on staging (2026-10-05): GiveWP import run and verified independently — 1,782 donations (1,761 completed + 21 refunded), $144,341.42, every row matches its GiveWP donation (amount + status), 0 untagged, 0 emails; 1,120 donors; 44 historical players (draft, archived) + 9 funds created; 87 roster entries added. Spot checks: Richard Roux $4,540 (= $3,880 + $660 as Rich Roux); Anthony Petrucci GiveWP part $7,147 = audit (plus $300 of staging test gifts).
- [x] Staging housekeeping (Bob, verified 2026-10-07): Nick Falkowski merged into Nicholas Falkowski (#5712; 37 gifts, $1,343); 119 players archived (none with 2025–26 events), 70 active published; My WP Login Logo + CPT UI removed. Login page (behind SG Security's custom login URL) shows the onf-core navy ONF logo, linked to the home page — verified 2026-10-07.
- [x] Found: copying players with Yoast Duplicate Post copied old addresses (_wp_old_slug) — e.g. "nick-falkowski" on 132 players, so dead links redirect to the wrong player. `onf-core` 0.7.4: Tools → ONF Migration → "Clean up old player addresses" (keeps only old addresses matching the player's own name, dry run first); Duplicate Post no longer copies old addresses, private contact fields or `_onf_*` data.
- [x] 0.7.4 on staging, old-address cleanup run (2026-10-07): 3 genuine old player addresses left, none shared; /player/nick-falkowski/ → Nicholas Falkowski (301).
- [x] Event series + annual funds (`onf-core` 0.6.0, Bob 2026-10-06): yearly events belong to a series (Herb Mitchell Cup, DuHadaway Cup, Info Solutions Team Holiday Giving Fund, Chowder Cup, Pre-Draft Showcase, Face-off for Teen Mental Health, …); new events join their series automatically from the title. Funds are permanent (one Dolan Fund, not one per year) and can belong to a series: a gift to the fund counts toward that series' open event. Gifts → Totals: all-time, by year, each series by year, each fund by year. One-click setup groups existing events and links funds.
- [x] Campaign grid (`onf-core` 0.6.1, Bob 2026-10-06): per series, years down × funds across (+ Player pages, General), campaign total per year and all-time per fund; on Gifts → Totals and on each series' page. Each gift counted once.
- [ ] On staging: Gifts → Totals → "Group events into series and link funds"; check series names under Events → Series.
- [x] PDF fundraising report (`onf-core` 0.7.0, Bob 2026-10-06): Gifts → Totals → PDF report. Choose title/subtitle, year range, sections (summary with gifts/donors/average by year; series by year; campaign grids for chosen series; funds by year; top N fundraisers). Landscape, ONF logo header, "Prepared by" Madkel logo footer, page numbers; tables continue across pages. Built with onf-core's own PDF writer (now multi-page). 0.7.2: Scope option — whole foundation, or only the ticked series (summary, series, funds and top fundraisers count just those events); page 1 lists scope, years and sections; empty sections say so. Madkel logo from ~/Documents/madkel/madkel-logo-2026.png → `onf-core/assets/madkel-logo.jpg`.
- [ ] Step 3 requirement (Bob, 2026-10-06): Progress/Totals block can show a campaign total (event = all its funds combined, e.g. 2026 holiday = Dolan Fund + Food Bank) with an optional per-fund breakdown, and can add any chosen funds together, limited to an event, a year or all-time.
- [ ] At cutover: run Import from GiveWP again on LIVE (after onf-core is installed there) — it imports every live donation; re-runs add only new ones.
- [ ] Not yet built: online "mail a check" pledge option, weekly/monthly email reports, partial refunds (only full refunds change status), donor "look up my receipts" link.
- [ ] Review the migrated event names (old categories renamed, e.g. 2018Player → 2018 Face-off for Teen Mental Health, 2019Adult → 2019 Face-off for Juvenile Arthritis, 2022Adult → 2022 Face-off for Teen Mental Health) and add dates/venues. Events are created as closed drafts, dated by their first GiveWP form (often months before the event, e.g. 2021 Chowder Cup shows 2020-04-26) — set real start dates.
- [x] **Brand blue (Bob, 2026-10-07): follow the brand guide — accent is Ice Blue #6EC1E4** (not logo blue #29ABE2). Navy follows the brand guide too: **#0C2A3B** "Deep Net Navy" (Bob, 2026-10-07; was #0F2A3F in receipts/report/login logo — changed in onf-core 0.8.0).
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
