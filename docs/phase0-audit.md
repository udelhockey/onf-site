# ONF redesign — Phase 0 audit (staging)

_Run 2026-10-03 against https://staging2.opennetfoundation.org (WP 7.1.2, PHP 8.2, Astra 4.13). Read-only; nothing was changed._

> **Staging is a snapshot.** Newest donation on staging is 2026-06-23. Anything after that (incl. the Nov 11 event) exists only on live. The frozen historical totals must be re-run on **live** at cutover (queries at the bottom).

## ⚠️ Fix on staging before Phase 3
1. **GiveWP is in LIVE mode with Stripe enabled** (`test_mode = disabled`, gateways: stripe + offline). A test donation on staging could charge a real card, and Stripe webhooks may point at the wrong site.
2. **WP Mail SMTP is configured** — staging can email real donors/players (receipts, Email Reports).
3. `siteurl` is `http://` (not https).

Recommended: switch GiveWP to test mode, set WP Mail SMTP to "do not send"/log only, set siteurl/home to https.

## Theme & builder
- Astra + **Elementor/Elementor Pro + Ultimate Addons** + Classic Editor plugin. Inactive themes: hello-elementor, twentytwentyfive.
- Elementor-built: **42 of 95** published pages, 8 posts, 3 landing pages, 8 library templates, 1 header/footer (`elementor-hf`).
- Rest are classic HTML or blocks; newer content (Donate, 20 Something, player pages) is already blocks.
- Astra stores per-post layout meta (`site-sidebar-layout`, `ast-*`) on ~150 posts — harmless, drops away with the new theme.
- Menus: Main Menu – Header (**56 items**), Main Menu – Footer (5).
- Synced patterns (`wp_block`): 2. Sidebar Manager: 7 custom sidebars.

## Plugins (34 installed, 33 active)
| Keep / replace | Plugin |
|---|---|
| Keep | ACF 6.8, Yoast SEO (update avail.), WP Mail SMTP, Akismet, SG Security + Speed Optimizer, Duplicator Pro, Enable Media Replace, Mailchimp for WP |
| Replace in redesign | Elementor + Pro + Ultimate Addons, Classic Editor, Astra, Sidebar Manager, GiveWP + 5 add-ons, Ninja Forms + Zapier (update avail.), CPT UI (→ `onf-core`), My Custom Functions (→ `onf-core`) |
| Decide | Tournament Bracket Manager (Rouxster bracket), Jetpack, iframe, Post Type Switcher, Yoast Duplicate Post, Simple Analytics, My WP Login Logo, ManageWP Worker, SiteGround AI Agent |
| Remove | WP Bulk Delete (inactive) |

Leftover data from removed plugins: `logooo` (14), `smartlogo`, `flamingo_*`, `product` (1), `tournament`/`stb-tournament`, `tb_sidebar`, and WooCommerce pages (Shop, Cart, Checkout, My Account) with no Woo installed.

## Custom code (My Custom Functions)
- `[top_give_forms]` shortcode — top N GiveWP forms in a category by `_give_form_earnings` (excludes form 32422). Leaderboard → Mission leaderboards replace it.
- `elementor/query/pd-roster` — sorts Elementor roster query by `jersey`.
Both are small; nothing else is hiding in there.

## Players & ACF
- CPT `player` (CPT UI): **150 published**, public, REST on, no archive, supports title/editor/thumbnail, shares the `give_forms_category` taxonomy.
- ACF group "Player" (8 fields, side panel, not in REST):

| Field | Type | Filled (of 150) |
|---|---|---|
| position | radio forward/defenseman/goaltender | 150 |
| shot | radio left/right | 150 |
| hometown | text | 131 |
| height, weight, last_team, birthday | text/number | 74 |
| jersey | text | 42 |

- **Gap vs plan:** the plan's public fields (favorite NHL team, sponsor/company) are **not on the player** — they live only in Ninja Forms submissions. Height/weight/shot/last team/birthday aren't in the plan. Decide which to keep.
- Player → donation form link is **only the `[give_form id=…]` shortcode in page content.** Sponsor logos are images in content too.
- Player posts started ~2021. Donors gave to ~200 named player forms going back to 2015, so many historical fundraisers have **no player post**.

## Events (give_forms_category, 22 terms)
Event categories run 2018Player → 2026 Herb Mitchell Cup, mixed with non-event terms (Sponsors, Save our Rinks, InfoSolution Holiday ×3). Player counts per recent event: 2026 HMC 25, 2026 Rouxster 21, 2025 HMC 31, 2025 Duhadaway 23. Naming is inconsistent (`2018Player`, `2022PreDraft JR A`, `2024 Herb Mitchell Cup`) — map them to clean Event records.

## GiveWP data
- Forms: 422 published, 34 draft, 26 trash. GiveWP 4 auto-created **343 campaigns**.
- Donors: 1,143. Subscriptions: 0 (no recurring — easy migration).
- Donations (live mode): **1,761 complete = $144,341.42** (2015-10 → 2026-06). Plus 21 refunded ($1,546), 3 stuck "processing" ($102), 2,215 in trash, 49 abandoned, 11 failed, 11 cancelled. Every complete donation maps to a form.
- Gateways over time: PayPal (2015–2023), legacy Stripe/Stripe Checkout (2018–2024), **Stripe Payment Element** (2025–26), offline (2017–2023), **Manual Donations** add-on (10 gifts, $3,901, 2024–26 — still used).
- Email Reports + PDF Receipts are active; 30 PDF receipt templates.

## Per-player all-time totals (draft)
Grouping by form title works, but names need a merge pass before freezing. Same person, different spellings:
Rich/Richard Roux · Mike/Michael Bufano · Tavis Miller/Miiler · Jim Martosella/Martosellla · Rich/Richard Kolovyansky · Rich/Richard Verna · Greg/Gregory Gustitis · Carmen/Carmine Marchesano · Matt/Matthew Burlew · Dave/David Imbrogno · Nick/Nicholas Falkowski · Nick/Nicholas Butler (2018 vs 2022 — confirm same person) · Patrick Flaherty/"-pd" · Zack Hammond/"-pd" · Jack Keller/"-21" · JP/JP Thomas.

Non-player forms to keep out of player totals: Skating Club of Wilmington ($9,634), Patriot Ice Center ($9,434), Sponsors, General Donation, General, ONF Donation, Donation Form, Food Bank of Delaware, Dolan Fund (+2023), Adopt a Family, Tender Hearts, Face-off for Teen Mental Health, Make Steve Shave, HMC/Duhadaway "Donations" forms.

Top player fundraisers (staging): Anthony Petrucci $7,147 · Richard Roux $3,880 (+$660 as Rich Roux) · Mark Stellini $3,003 · Kelly Stroik $2,769 · Jared Card $2,647.

## Registration (Ninja Forms)
- **Event Registration** (form 4, 314 subs): first/last name, email, phone, position, additional info, company, favorite NHL team, address/city/state/zip, jersey size.
- **20 Something Registration** (form 19, 59 subs): name, phone, email, position, birthday, jersey size, session (checkboxes).
- Other forms are legacy (Contact, Sponsor, Order Tickets 122 subs, 2022 Party, Raffle, etc.). Zapier add-on active — **check what Zaps fire** before removing.
- 575 total NF submissions to archive/export.

## Content cleanup candidates
5 duplicate "Donor Dashboard" pages, WooCommerce pages, "x", "xxx2025", "Bracket"/"Bracket2", draft copies, empty Charities/Showcase-players pages, 2004–2009 photo pages (keep as archive?). Most 2019–2022 event pages are Elementor + GiveWP shortcodes and will need either rebuild or a static archive.

## Re-run on live at cutover
```sql
-- Grand total (complete, live mode)
SELECT COUNT(*), ROUND(SUM(t.meta_value),2) FROM {prefix}posts p
JOIN {prefix}give_donationmeta t ON t.donation_id=p.ID AND t.meta_key='_give_payment_total'
JOIN {prefix}give_donationmeta m ON m.donation_id=p.ID AND m.meta_key='_give_payment_mode' AND m.meta_value='live'
WHERE p.post_type='give_payment' AND p.post_status='publish';

-- Per form-title totals (then apply the name-merge list above)
SELECT LOWER(TRIM(f.post_title)) name, COUNT(DISTINCT f.ID) forms, COUNT(p.ID) gifts,
       ROUND(SUM(dt.meta_value),2) raised, YEAR(MIN(p.post_date)) y1, YEAR(MAX(p.post_date)) y2
FROM {prefix}posts f
JOIN {prefix}give_donationmeta fm ON fm.meta_key='_give_payment_form_id' AND fm.meta_value=f.ID
JOIN {prefix}posts p ON p.ID=fm.donation_id AND p.post_type='give_payment' AND p.post_status='publish'
JOIN {prefix}give_donationmeta dt ON dt.donation_id=p.ID AND dt.meta_key='_give_payment_total'
WHERE f.post_type='give_forms' GROUP BY name ORDER BY raised DESC;
```
Also do GiveWP's own exports from live (Donations → Tools → Export: donations, donors, forms) and a Ninja Forms submissions export, and keep a Duplicator archive.
