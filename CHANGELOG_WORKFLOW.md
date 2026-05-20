# Changelog Workflow

This repo uses `CHANGELOG.md` as the single source of truth for:
- the in-app changelog page
- the current released version shown on the login page

## Default behavior for AI

1. When making meaningful user-facing changes, add or update entries under `[Unreleased]` in `CHANGELOG.md`.
2. Keep the language user-facing and grouped under:
   - `Added`
   - `Changed`
   - `Fixed`
   - `Removed`
   - `Affected Areas`
   - `Breaking Changes`
3. Do not create a numbered version for tiny fixes.
4. Only promote `[Unreleased]` into a numbered version when the user explicitly says:
   - `release`
   - `deploy`
   - `milestone`
   - `version bump`

## Release command expectation

If the user says something like:

`make all the unreleased patches v1.2`

the expected AI behavior is:

1. Read `CHANGELOG.md`
2. Move all `[Unreleased]` entries into a new version section such as `## [V1.2] - YYYY-MM-DD`
3. Keep the grouped structure intact
4. Leave a fresh empty `[Unreleased]` section in place
5. Preserve or add `Affected Areas` for DTS-related work
6. Do not invent features that were not actually implemented
7. Let `public/changelogs.php` and the login page automatically reflect the new current release through `core/changelog.php`

## Files involved

- `CHANGELOG.md`
- `CHANGELOG_GUIDE.md`
- `CHANGELOG_WORKFLOW.md`
- `core/changelog.php`
- `public/changelogs.php`
- `public/login.php`
