# onf-site

Code for the opennetfoundation.org redesign.

| Folder | What it is | Installs as |
|---|---|---|
| `onf-theme/` | Block theme. `theme.json` is the single source of design truth. | Appearance → Themes |
| `onf-core/` | ONF features: players, events, funds, gifts, registration hooks, login branding. | Plugins |

Plan, decisions and audit live in `docs/` (start with `docs/plan.md`).

## Installing on staging

1. On GitHub: **Code → Download ZIP**, unzip.
2. Zip the folder you need on its own (`onf-core` or `onf-theme`) so the zip contains that folder at the top level.
3. WordPress: **Plugins → Add New → Upload Plugin** (or **Themes → Add New → Upload Theme**), choose the zip, and when asked, **Replace current with uploaded**.

Staging: https://staging2.opennetfoundation.org

## Versions

Bump the `Version:` header (and `ONF_CORE_VERSION`) on every change so caches refresh.
