# Org Chart React

A separate React + Tailwind frontend repo for the Document Tracker org chart.

## What this repo is for

This repo is **frontend-only**.

It is designed to plug into the existing PHP Document Tracker app without replacing:

- PHP auth/session
- existing org permissions
- current database structure
- current update endpoint at `/api/org_chart_update_user.php`

The React app reads data from `window.__ORG_CHART_BOOTSTRAP__` and uses `window.__APP__` for API and asset paths.

---

## Current integration contract

The PHP app must provide these globals before the React bundle loads:

### `window.__APP__`
Already present in your app through `includes/layout.php`.

Expected keys:
- `base`
- `api`
- `public`
- `assets`
- `csrf`
- `currentPage`
- `isDevelopment`

### `window.__ORG_CHART_BOOTSTRAP__`
Provide this from `public/org_chart.php` using your already-prepared org chart arrays.

See:
- `php-integration/org_chart.bootstrap.example.php`
- `php-integration/org_chart.page-shell.example.php`

---

## Local development

```bash
npm install
npm run dev
```

By default, if `window.__ORG_CHART_BOOTSTRAP__` does not exist, the app falls back to mock data so the UI can still render during isolated frontend work.

---

## Build

```bash
npm install
npm run build
```

This creates a `dist/` folder.

Because `vite.config.ts` uses:

```ts
base: './'
```

its built asset paths stay relative, which makes it safer to copy into the PHP app without hardcoding a server root path.

---

## Suggested deployment into Document Tracker

Recommended target inside the PHP app:

```txt
public/org-chart-react/
```

Example:

```txt
public/org-chart-react/index.html
public/org-chart-react/assets/index.css
public/org-chart-react/assets/index.js
```

If you only want the built bundle and not the HTML file, you can just copy the `assets/` output and reference the CSS/JS directly from `public/org_chart.php`.

---

## Required PHP-side work

This repo already handles the frontend, but your PHP page must still:

1. keep existing session/auth checks
2. keep current data shaping logic
3. expose `window.__ORG_CHART_BOOTSTRAP__`
4. load the built CSS and JS
5. leave `api/org_chart_update_user.php` intact

---

## Real image strategy

### Keep in the PHP app
- personnel photos
- uploaded user images
- backend-owned real content images

### Keep in this React repo
- fallback/default avatar
- empty-state graphics
- purely decorative UI assets

Right now this repo uses initials avatars, so it does not force an image setup.

---

## Existing API compatibility

This repo currently submits the following fields to:

```txt
/api/org_chart_update_user.php
```

- `csrf_token`
- `target_user_id`
- `full_name`
- `email`
- `official_title`
- `authority_role`
- `permanent`
- `chief_assistant_user_ids[]`

That matches the current backend flow more closely than inventing a new contract.

---

## Main source files

- `src/App.tsx` — top-level page composition
- `src/components/TopToolbar.tsx` — hero/search/actions/stats
- `src/components/DivisionBlock.tsx` — division-level layout
- `src/components/SectionBlock.tsx` — section layout and member expansion
- `src/components/UserRow.tsx` — user item row
- `src/components/EditOrgUserModal.tsx` — edit form modal
- `src/lib/app-bridge.ts` — reads runtime globals and posts updates to PHP
- `src/lib/mock-data.ts` — fallback standalone demo data
- `src/types/org.ts` — source-of-truth typings for bootstrap data

---

## Notes about current state

This repo is already shaped around the current Document Tracker org chart logic found in:

- `public/org_chart.php`
- `assets/js/org-chart-page.js`
- `assets/js/drill-down.js`
- `api/org_chart_update_user.php`
- `core/org_permissions.php`

So this is **not** a random generic org chart frontend. It is intentionally aligned with your existing structure.

---

## Important limitation

This repo is the new frontend repo only.

It does **not** automatically patch your PHP app by itself. You still need to:

- copy the built output into the Document Tracker project
- add the bootstrap script in `public/org_chart.php`
- replace the old org chart markup with the React mount root
- load the new CSS and JS bundle

If you want, the next step is to generate the exact PHP-side patch plan and target snippets for your current Document Tracker repo.
