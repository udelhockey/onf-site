# Donations platform — needs & evaluation

_Drafted 2026-10-04. Needs confirmed by Bob; no platform decision yet. GiveWP is back on the table._

## What ONF actually does (from the staging audit)
- ~4 events a year (Herb Mitchell Cup, Duhadaway Cup, Rouxster Shootout, Info Solutions holiday fund), 20–35 players each, most returning year to year.
- One donation form per player per event; Bob builds each player page. ~$10–18k raised a year; $144k since 2015.
- Leaderboard of top fundraisers per event (`[top_give_forms]` shortcode).
- Non-player campaigns too: General Donation, Food Bank of Delaware, Dolan Fund, Adopt a Family, rink campaigns, sponsor gifts.
- Checks/cash entered by hand (Manual Donations: 10 gifts, $3.9k in 2024–26).
- PDF receipts and email reports are active.
- No recurring donations (0 subscriptions ever). PayPal last used 2023.

## Needs
**Must have**
1. Per-player fundraising page per event, created by Bob (no self-service required).
2. Event total + top-fundraiser leaderboard.
3. All-time player totals, including GiveWP history back to 2015.
4. Non-player campaigns/funds (general, food bank, holiday fund, rinks).
5. Card payments through ONF's own Stripe; Apple/Google Pay a plus.
6. Manual entry of check/cash gifts that count toward player and event totals.
7. Donor receipts (tax-deductible language, PDF or email).
8. Donor list and donation export for ONF's records.
9. Donors are never pushed to tip. A flat yearly cost is fine.
10. Works with the new block theme (blocks/shortcodes, no Elementor dependency) and looks on-brand.

**Nice to have**
- Donor can choose to cover the card fee (fee recovery).
- Teams, for a future tournament format.
- Player self-service sign-up with Bob's approval.
- Open enough to change behavior ourselves without forking the payment layer.
- Vendor stability; predictable renewals.

**Wanted (Bob, 2026-10-04):** peer-to-peer fundraising and tribute gifts (in honor / in memory).

**Not needed (confirmed):** recurring donations, PayPal. Zapier on donations not needed (registration Zaps stay on Ninja Forms).

## Options

| | GiveWP Essentials (today's model) | GiveWP Elite (P2P) | Charitable Pro | Mission | Build our own |
|---|---|---|---|---|---|
| Yearly cost | $199 | $599 | $199 yr 1, then $399 | $0 + 15% preselected donor tip, or 3% per gift | $0 (Stripe fees only) + our time |
| 1 Player pages by Bob | ✓ form per player (works now) | ✓ P2P pages | ✓ admin can add ambassadors | API only, no admin button | ✓ |
| 2 Event leaderboard | via our shortcode (exists) | ✓ built in | ✓ built in | ✓ built in | build |
| 3 All-time totals | ✓ history stays put | ✓ history stays put | freeze + import | freeze + import | freeze |
| 4 Non-player funds | ✓ | ✓ | ✓ | ✓ | build |
| 5 Own Stripe | ✓ | ✓ | ✓ | via Mission's server | ✓ Stripe Checkout |
| 6 Manual gifts | ✓ add-on in plan | ✓ | verify | verify | build |
| 7 Receipts | ✓ PDF receipts | ✓ | ✓ PDF receipts | ✓ | Stripe email receipts |
| 9 No tip pressure | ✓ | ✓ | ✓ | ✗ | ✓ |
| 10 Block theme | GiveWP 4 campaigns + blocks — verify on staging | same | verify | ✓ 25 blocks | ✓ |
| Fee recovery | ✗ (Pro+) | ✓ | ✓ | ✓ | build |
| Teams | ✗ | ✓ | ✓ | ✓ | build later |
| Migration work | none | small (P2P setup) | medium | medium | medium |
| Main risk | Liquid Web direction, price creep | same, at $599 | Awesome Motive price creep | depends on Mission's API; tip model | maintenance is ours |

## Notes and things to verify
- **Not renewing GiveWP isn't free:** reviews report a 2% platform fee on Stripe in GiveWP without a valid license, and ONF's 2025–26 gifts ran through GiveWP's core Stripe gateway. Verify before relying on an expired license.
- **Staying on GiveWP decouples the redesign from the donation decision.** The theme can be built on staging now and keep GiveWP forms/campaigns; `onf-core` reads GiveWP data for totals. The platform switch (if any) becomes a separate, later project instead of a December deadline.
- ONF paid **$149/yr** for GiveWP; the current Essentials price is **$199** (+34%). Liquid Web's account side lost ONF's purchase/license history in the migration (Bob, 2026-10-04). Donation data itself lives in ONF's own WordPress database and is unaffected — but back up live before anything else.
- Mission's tip is preselected at 15% in its code; hiding it switches the form to a 3% flat fee. All payments route through `api.missionwp.com`.
- Charitable Pro is needed for Ambassadors (P2P) and Zapier; free Charitable charges 3%.

## Suggested path (pending Bob)
1. Confirm the needs list above.
2. Get the GiveWP renewal quote and test GiveWP 4 campaign/form blocks in a block theme on staging.
3. If GiveWP holds up: renew Essentials, build the new theme around it, keep `onf-core` platform-agnostic.
4. If not: Charitable Pro (ready-made) or build our own (fully open).

Sources: liquidweb.com/software/give (GiveWP pricing) · wpmanageninja.com/givewp-review · wpcharitable.com/pricing · wpcharitable.com/documentation/fair-pricing · wpcharitable.com/extensions/charitable-ambassadors · github.com/mission-wp/mission

## Update 2026-10-04 — with P2P + tributes wanted, no recurring/PayPal

| | Yearly cost | P2P | Tributes | History | Notes |
|---|---|---|---|---|---|
| GiveWP Elite | $599 (was paying $149 for a lower plan) | ✓ | ✓ | stays in place | No migration; Liquid Web account history already lost |
| Charitable Pro | $199 yr 1, then $399 | ✓ Ambassadors (admin can add fundraisers, approval queue, teams, leaderboards) | ✓ Tributes add-on with notification e-cards (verify included in Pro) | GiveWP importer: forms → campaigns, donations, donors | Importer needs Fee Relief add-on (Plus+) for single donations; player categories not migrated — rebuild events |
| Mission | $0 + 15% preselected tip, or 3% | ✓ | ✓ | GiveWP importer | Tip pressure; payments via Mission's server |
| Build our own | Stripe fees only | Bob-built player pages = today's P2P; self-service/dashboards are extra build | simple field + notification email | freeze totals | Most work, fully open |

Leading candidates: **Charitable Pro** (both features, own Stripe, no tips, ~$400/yr) vs **GiveWP Elite** (both features, no migration, $599/yr).
Next: trial Charitable Pro on staging (14-day refund window) — sample event, 2 players, a tribute gift, and a GiveWP import test.

## Update 2026-10-04 — GiveWP Elite ruled out; totals check on GiveWP 4.18 (staging)
- **GiveWP Elite ($599) ruled out** — too much for P2P alone.
- **Stored form totals are accurate on 4.18:** for all 422 published forms (2015–2026), `_give_form_earnings` matches the sum of completed donations (2025–26 differ only by cents of rounding). `[give_totals]` and our `[top_give_forms]` read those stored totals.
- **GiveWP shortcodes in published content:** `[give_form]` 153 posts, `[give_donor_wall]` 151, `[give_totals]` 60 (+26 inside Elementor pages), `[top_give_forms]` 8 (ours), `[give_donor_dashboard]` 5, `[give_form_grid]` 4. These are GiveWP's legacy (option-based form) shortcodes — still supported in 4.18, but they're what a future GiveWP release is most likely to drop.
- Rendering not yet eyeballed on staging (player page + event page).
- Remaining contenders: **Charitable Pro** (P2P + tributes, ~$399/yr) vs **GiveWP Essentials** ($199; no P2P add-on, no tributes — today's per-player-form model + our leaderboard).

## If we keep GiveWP: modern-only rebuild (GiveWP 4.17 source + staging, 2026-10-04)
Bob's rule: no deprecated features in the rebuild.

**Already modern:** since 2025 every event is a GiveWP **campaign** and every player has a **visual-builder (v3) form** in it — 107 of 422 forms are v3, all from 2025–26 (e.g. 2026 Herb Mitchell Cup campaign = 39 forms). The 315 older v2 forms are 2015–2024 history only.

**Legacy pieces to drop:** `[give_form]`, `[give_totals]`, `[give_donor_wall]`, `[give_form_grid]`, `[give_donor_dashboard]`, `[top_give_forms]`, Elementor GiveWP widgets.

| Need | Modern GiveWP replacement | Native? |
|---|---|---|
| Player's donation form on player page | Donation Form block (`givewp/donation-form`) with the player's v3 form | ✓ |
| Player's total / goal | v3 form goal (shown in the form header) | ✓ |
| Event total + goal | Campaign Goal block | ✓ |
| Event donors / recent gifts | Campaign Donors (sort: top donors) + Campaign Donations blocks | ✓ |
| Event pages / cards | Campaign page, Campaign block, Campaign Grid (sort by amount, filter by campaign IDs) | ✓ |
| Top-fundraiser leaderboard (players within an event) | none — Campaign Grid ranks campaigns, not forms | ✗ `onf-core` block on GiveWP's current models |
| Player donor list | none per form (Campaign Donors is per campaign) | ✗ `onf-core` block, or drop |
| All-time player totals (incl. v2 history) | none | ✗ `onf-core` (as planned) |
| Tributes | Tributes add-on = Elite only | ✗ custom form field, or skip |
| P2P self-service sign-up | P2P add-on = Elite only | ✗ not needed: Bob builds pages |

Old v2 forms: leave as closed history (no pages show them); `wp givewp form:migrate` exists if any must be converted. Cost: Essentials $199/yr.

## Update 2026-10-04 — Bob's priorities
- Tributes **not needed**. **Must:** donor board + totals on every player page.
- Pain point with GiveWP 4: campaign → form per player. Preferred model: one player form/page tagged by event (the old category approach).
- If we build our own, the saved license money goes to texting (Twilio), event payments and reminders (e.g. 20 Something skate).
- Build-our-own design: `docs/custom-donations-design.md`.
