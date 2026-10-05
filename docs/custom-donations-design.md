# Build-our-own donations — design proposal

_Drafted 2026-10-04. Status: proposal for Bob's decision. Lives in `onf-core`; replaces GiveWP after the Nov 11 event._

## Why
- Bob's must-haves: **donor board and totals on every player page**, event totals and leaderboard, all-time totals, manual gifts, receipts. Tributes not needed. P2P self-service not needed (Bob builds pages).
- Bob's pain with GiveWP 4: create a campaign, then a form inside it, for every player. The early-GiveWP model was better: **one form per player, tagged with a category (event) for totals.**
- Money saved ($199–399/yr) goes to a texting system (Twilio) and future features: event payments and reminders (e.g. 20 Something skate sessions).

## Core idea: every donation is tagged, totals are sums
No forms to create. A player has **one permanent page**. Adding a player to an event is one click. Every donation row carries tags — player, event, fund — and every total on the site is a sum over those tags. This is the old "category" approach, made explicit.

## Data model
| Thing | Stored as | Key fields |
|---|---|---|
| **Player** | post type `onf_player` (one page per person, forever) | name, photo, position, jersey, shot, hometown, height, weight, last team, favorite NHL team, sponsor; private: email, phone, birthday, notes |
| **Event** | post type `onf_event` | name, dates, venue, goal, default player goal, status (`registration` / `fundraising` / `closed`), optional fee (paid events) |
| **Entry** (player in an event) | table `onf_entries` | player_id, event_id, goal, sponsor for this event, status |
| **Fund** (non-player causes) | post type `onf_fund` | name, description, goal, active — General, Food Bank, Dolan Fund, Holiday Giving, rinks |
| **Gift / payment** | table `onf_gifts` | amount, fee_covered, type (`donation` / `event_payment`), player_id?, event_id?, fund_id?, donor name/email/company, display name, anonymous, message, source (`stripe` / `manual` / `givewp_import`), Stripe ids, status (`completed` / `refunded`), date |
| **Donor** | table `onf_donors` | email (unique), name, address, totals — built from gifts |

Totals (cached, refreshed on every new/refunded gift):
- Player in event = sum where player + event · Player all-time = sum where player · Event = sum where event · Fund = sum where fund.

## Payments: Stripe Checkout (hosted)
1. Donor clicks **Donate** on a player, event or fund page → picks amount, optional "cover the fees", name/message/anonymous.
2. `onf-core` creates a Stripe Checkout Session with the tags in metadata and sends the donor to Stripe's hosted page (cards, Apple Pay, Google Pay, Link).
3. Stripe calls our webhook (`checkout.session.completed`, signature-verified) → we record the gift, clear caches, send the receipt.
4. Refunds in Stripe (`charge.refunded`) mark the gift refunded; totals update.
- Card data never touches the site (lowest PCI burden). ONF's own Stripe account; no platform in the middle. Ask Stripe about nonprofit discounted pricing.

## Blocks (for the block theme)
- **Donate** (button or inline amount picker) — context-aware: player / event / fund.
- **Progress** — raised vs goal for the current player-in-event, event or fund.
- **Donor board** — names (or "Anonymous"), optional amounts, messages, newest or largest first.
- **Leaderboard** — top players in an event.
- **Totals** — event, all-time player, foundation-wide ("$X raised since 2004").
- Player page template uses these automatically: Bob just fills in the profile.

## Admin (Bob's workflow)
- **Players** list with an "Events" column and an **Add to event** bulk action — no form building.
- **Events**: open/close fundraising, see roster + totals.
- **Gifts**: list, filter, CSV export, **add manual gift** (check/cash) tagged to player/event/fund, resend receipt.
- **Receipts**: see Emails & receipts below.

## Emails & receipts (match what GiveWP does today on ONF)
Current GiveWP setup (from `give_settings` + per-form settings, 2026-10-04):
| Email | Who gets it today | Build |
|---|---|---|
| Donation receipt (with PDF receipt attached/linked) | donor | ✓ same text: thanks, amount, Federal Non-Profit ID 20-1472003, mailing address, logo |
| **PDF receipt** (template "Open Net Foundation Donation Receipt 1": logo, tagline "Putting a Check on Childhood Diseases", donor name/address, donation name, amount, method, date, receipt #, PO Box 5355 Wilmington DE 19808) | donor, plus download link | ✓ generated with Dompdf in `onf-core`; sequential receipt numbers (GiveWP uses prefix `25-`, next 26704 — continue the sequence) |
| **New donation → player** (per-form recipients on v3 forms) | the player (1–2 addresses per form) + admins | ✓ automatic: every gift tagged to a player emails that player's notification address(es) — no per-form setup |
| New donation → admins | bob@, rroux@ | ✓ configurable admin list |
| Offline (check) pledge: instructions to donor + "new pending donation" to admins | donor / admins | ✓ "Mail a check" option; counts toward totals only when Bob marks it received |
| Weekly report (Tue 4pm) and monthly report (1st, 6am) | bob@, rroux@ | ✓ scheduled digest: totals by event/player/fund, new gifts |
| Daily report | disabled today | optional |
| Donor dashboard / email access | enabled today (donor can look up past gifts) | phase 2: emailed magic link to download past receipts |
| Year-end giving summary | not used today | optional later |
All emails: editable templates with tags ({name}, {amount}, {player}, {event}, {receipt_number}…), sent through WP Mail SMTP, logged in admin, resend button.
IRS wording: donations = no goods or services provided; event payments = state fair-market value received.

## Registration
- Ninja Forms → Zapier → Google Sheet stays exactly as is.
- `onf-core` also listens to the form submission: match player by email (create if new), add an entry for the chosen event, notify Bob. Bob reviews and publishes the player page.

## GiveWP history → imported, not frozen
- Import all 1,761+ completed GiveWP donations into `onf_gifts` (`source = givewp_import`): v2 forms by category → event, v3 forms by campaign → event, form title → player (with the name-merge list in `phase0-audit.md`), non-player forms → funds.
- **Import filter (from staging data, 2026-10-04):**
  - Import only `publish` (completed) and `refunded` (as refunded) donations.
  - Skip `trash` — includes the **May 4, 2023 card-testing attack: 2,212 × $5** (already trashed, not in totals).
  - Skip `abandoned` (49), `failed` (11), `cancelled` (11), stuck `processing` (3).
  - **Keep the $1–2 gifts:** 156 completed $1–2 gifts ($158, 2019–2024) came through ONF/Bob email addresses but are **real donations** (Bob, 2026-10-04) — import them like any other gift.
- Result: all-time totals AND historical donor boards come from real rows — no frozen numbers. Keep a GiveWP export + Duplicator archive as backup.

## Later modules (the money saved)
- **Event payments**: same Checkout + `type = event_payment` (e.g. 20 Something session fees, tournament entry); separate from tax-deductible donations in reports and receipts.
- **Reminders**: scheduled emails/texts before sessions and events (WordPress Action Scheduler).
- **Texting (Twilio)**: opt-in at registration (required consent checkbox), send reminders and updates, STOP handling. US business texting requires A2P 10DLC brand + campaign registration with Twilio (one-time and monthly fees) plus per-message costs — get current pricing from Twilio before committing.

## Build order (all on staging; live stays on GiveWP through Nov 11)
1. Data model + admin (players, events, entries, funds, gifts) + player migration from the existing 150 `player` posts.
2. Stripe Checkout (test mode) + webhook + receipts + manual gifts.
3. Blocks: donate, progress, donor board, leaderboard, totals.
4. GiveWP import + reconcile totals against GiveWP to the cent.
5. Registration hook.
6. Cutover (late Nov / Dec): live Stripe keys, import final GiveWP data, retire GiveWP.
7. Later: event payments, reminders, Twilio.

## Risks
- We own it: security (webhook verification, capability checks), WordPress/PHP updates, bugs. Mitigated by Stripe hosting the payment page and by keeping scope small.
- Timeline: steps 1–4 must be done and tested before cutover; if not, renew GiveWP Essentials ($199) for one more year as the fallback.
