# Design references

- `mobile-home-reference.png` — the approved visual direction for the staff portal's Home on phones (product owner, 2026-10-17; Phase 37C, DEC-069).
- `mobile-home-preview-*.png` — **static layout previews**, not screenshots of the running app:
  - each is a hand-built copy of the new Home markup with sample data matching the reference, styled with the compiled CSS;
  - captured with Playwright (Chromium) at 390 × 844 (light and dark) and 1280 × 900;
  - the real page couldn't be rendered in the authoring environment, because Composer can't install there;
  - check the real page on staging (UAT-37C).
- `portal-style-reference.webp` — the approved style for **every staff-portal screen** (product owner, 2026-10-24; Phase 37D, DEC-071). It supersedes the Home reference's colours.
- `portal-preview-*.webp` — **screenshots of the running app** (Phase 37D): the real pages served from a scratch copy on SQLite with sample data, captured with Playwright (Chromium) at 390 × 844 (light and dark; Home, Staff directory, Tasks) and 1280 × 900 (Home). In full-page captures the fixed tab bar and sidebar appear mid-page; that's the capture, not the layout.
