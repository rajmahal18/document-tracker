# React Org Chart Integration Guide

This repo now supports a React + Tailwind org chart mounted inside the existing PHP app.

## What changed
- `public/org_chart.php` now keeps the PHP auth, permission, and data-shaping logic, then bootstraps the React app.
- `includes/layout.php` can skip the legacy org chart stylesheet for the React page.
- `frontend/org-chart-react` contains the React source.
- Vite now builds directly into `public/org-chart-react` and emits `manifest.json`.

## One-time local setup
```bash
cd frontend/org-chart-react
npm install
```

## Build
```bash
cd frontend/org-chart-react
npm run build
```

## Result
The build output lands in `public/org-chart-react`.
When `public/org-chart-react/manifest.json` exists, `public/org_chart.php` automatically loads the correct hashed CSS and JS files.

## Rebuild after frontend changes
```bash
cd frontend/org-chart-react
npm run build
```
