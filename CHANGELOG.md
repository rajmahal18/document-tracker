# Changelog

Summary: This file is the source of truth for release notes and in-app version display.
Summary: Keep small ongoing work under [Unreleased]. Promote it to a numbered version only when the user explicitly says release, deploy, milestone, or version bump.

## [Unreleased]
Summary: Ongoing improvements that are not yet packaged into a released patch.

### Added
- A dedicated child-document setup flow so split-created documents can be completed as new records with inherited context, instead of being treated like ordinary corrections.

### Changed
- Parent-child document logic now expects formal schema support instead of silently mutating the database during normal page requests.
- Child-document completion now uses an explicit setup-completed event instead of brittle payload-text matching.
- Child documents now stay out of other parties' main document lists unless those users are directly involved in the child workflow; parent-context discovery remains available in the drawer.

### Fixed
- Division Tracking Slip behavior in child-document setup now follows the Add Document rules, including when tracking fields appear and when division tracking records are actually created.
- Child setup is no longer marked complete before optional division-slip generation succeeds, preventing half-finished child documents from falling out of setup mode.
- Current holders can now upload attachments while a share-visibility route is still in transit, because reference-only visibility does not transfer possession.
- Receivers who can access one child document can now continue navigating the full related-document family in the drawer without losing access after opening the parent or a sibling.
- Linked document drawer fallback now returns a more faithful payload so parent and child navigation is less likely to open in an incorrectly degraded state.
- Parent-child visibility and documents-page SQL now fail safely when the split schema is not yet installed, instead of assuming the new column already exists.

### Removed
- Request-time schema alteration from the document split feature path.

### Affected Areas
- Project Splitting
- Child Document Setup
- Document Drawer
- Documents List Visibility
- Division Tracking Slip Generation
- Attachments
- Login-safe / Backward-compatible rollout behavior

### Breaking Changes

## [V1.1] - 2026-05-19
Summary: Parent-child documents, drawer cleanup, attachment controls, and division slip refinements for DTS.

### Added
- A changelog page inside the app, with a patch-directory layout for browsing current and future patches.
- A project-splitting workflow that creates linked child documents from selected project codes.
- A document family view in the drawer so parent and child documents can be opened from one place.
- A continuation-page generator for division tracking slips when extra movement rows are needed.
- Admin-only soft delete for attachments, with audit visibility kept intact.

### Changed
- The drawer now separates core document details from context by moving project, family, and timing details into a dedicated Context tab.
- Related document navigation now uses a family-tree style view instead of ambiguous parent and child labels.
- The login page version label now reflects the latest released patch from this changelog.
- The changelog page is now intended to read from this file as the canonical release source instead of relying on hardcoded page copy.

### Fixed
- Transmittal Memo visibility now follows the actual DTS scope rules instead of guessed labels.
- Own Division Tracking Number is only applied when a Division Tracking Slip is actually selected.
- Second-page division slip attachments no longer clutter the merged View Document output.
- Split-created child documents now clearly say they were created from an existing document in their timeline.
- Original sender visibility can continue into child documents through the parent relationship for follow-up viewing.

### Removed
- The old drawer-style clutter that mixed project codes, relationship labels, and operational details in one overview block.

### Affected Areas
- Add Document
- Document Drawer
- Document Family / Parent-Child Navigation
- Timeline
- Files / Attachments
- Division Tracking Slip Generation
- Login Page Version Display
- Changelog Page

### Breaking Changes
