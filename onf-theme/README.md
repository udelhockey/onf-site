# onf-theme

Block theme for opennetfoundation.org (Phase 2). Pairs with the `onf-core` plugin, which provides the
players, events, funds and donation blocks the templates use.

## Where things live
- `theme.json` — the design system: brand-guide palette (navy #0C2A3B, Ice Blue #6EC1E4, Rink Blue #0170B9, neutrals), Exo + Roboto (bundled in `assets/fonts`, no Google Fonts call), type scale, spacing, 4px buttons.
- `styles/` — block styles editors can pick: sections **Navy / Cloud / Ice tint**, **Card**, buttons **Rink blue / Outline / Outline on dark**, paragraphs **Eyebrow / Lead**.
- `style.css` — only what theme.json can't express (header on phones, hovers, card image crops).
- `parts/` — header and footer (both load the PHP patterns in `patterns/`, so logo URLs and the year are always right).
- `templates/`
  | Template | Used for |
  |---|---|
  | `front-page` | Home: hero, foundation totals, events happening now + top fundraisers, mission, past events |
  | `single-player` | Every player page, assembled from blocks — Bob only fills in the profile fields |
  | `single-onf_event` | Event: details, progress with per-fund breakdown, leaderboard, donate form, supporters, full roster |
  | `single-onf_fund` | Fund: progress, donate form, supporters |
  | `archive-onf_event` | `/events/`: happening now, then past events as cards with what each raised |
  | `taxonomy-onf_series` | `/series/…/`: totals by year and fund, the series' events |
  | `donate-page` | Pick it for the Donate page (Page → Template): events and funds taking gifts |
  | `page`, `page-wide`, `single`, `index`/`home`, `archive`, `search`, `404` | Everything else |
- `assets/logos/` — SVGs from the brand guide (compact and full lockups, white/navy; skater mark; badge). Favicon = skater mark unless a Site Icon is set.

## Install
Build the zip from the committed code and upload it (Appearance → Themes → Add New → Upload):

```bash
git archive --format=zip --prefix=onf-theme/ -o onf-theme-0.1.0.zip HEAD:onf-theme
```

Use **Live Preview** first; the theme needs ONF Core 0.8.0 or later for its blocks.
