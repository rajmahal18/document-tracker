# Changelog

Summary: This file is the source of truth for release notes and in-app version display.
Summary: Keep small ongoing work under [Unreleased]. Promote it to a numbered version only when the user explicitly says release, deploy, milestone, or version bump.

## [Unreleased]
Summary: Ongoing improvements that are not yet packaged into a released patch.

### Added
- Automated due-today email reminder support for document assignees, with separate morning and afternoon send windows in Manila time and rerun-safe delivery logging.
- A CLI reminder-window runner for cron, so due-today reminder checks can safely repeat during the day without resending already logged reminders.
- A chief dashboard that surfaces overdue, due-today, and no-movement documents together with the currently accountable personnel for director, division-chief, and section-chief scopes.
- A separate Issuances page in the sidebar for memo-style public references, with admin file uploads, smart year tabs generated from each issuance date, and rows that open source documents in a new tab.

### Changed
- Task Monitoring now enforces lead-assignee editing rules on existing monitoring records, so progress-driven workflows no longer let any editor change protected task details after assignment.
- The chief dashboard now shows focal-person avatars in its grouped attention view, improving visual scanning for accountable personnel.
- The Issuances list now sorts by oldest issued date first, keeping memo-series rows in natural order.

### Fixed
- Due-today reminder logging now preserves nullable route references and surfaces rerun-protection write failures instead of silently reporting success.
- Due-today reminder emails now build document links from the app base path during CLI runs, so production messages no longer point to `/scripts/public/...`.
- Chief dashboard avatars now use the same user photo source fallback as the org chart, so accountable-person pictures load from existing profile image columns instead of dropping to initials unnecessarily.
- Task Monitoring now keeps progress updates with the lead assignee on progress-based workflows, while still allowing assigned operators to update non-protected fields like remarks and reference-driven status inputs.
- Task Monitoring task deletion and edit access now follow the task's actual assignee context instead of relying only on creator ownership.
- The org chart now defers per-user document activity stats until a person row is opened, so the initial page load no longer precomputes modal-only workload metrics for every user.
- Legacy non-branch documents no longer stay stuck in a non-actionable state for a past signature/approval recipient after that same user later receives the real forwarded document through a normal route.
- The documents page now provides a safe early view-context value before its inline JS bootstrap runs, preventing forward/receive interactions from failing when PHP warning output would otherwise corrupt the page script.
- The Issuances page now renders year tabs and the listing for non-admin users who open the page without an explicit year filter.

### Removed

### Affected Areas
- Chief Dashboard
- Email Notifications
- Issuances
- Document Deadlines
- Scheduled Reminder Jobs
- Task Monitoring
- Task Monitoring Permissions
- Task Monitoring Edit Flow
- Organizational Chart

### Breaking Changes

## [V1.2] - 2026-06-03
Summary: Signature/approval workflow, outgoing division slips, queue clarity, and org chart performance refinements for DTS.

### Added
- A signature/approval request workflow from the Forward picker, with a dedicated receiver response flow for Signed, Approved, or Rejected outcomes.
- A latest-activity cue in the documents list and drawer overview, so users can immediately see why a document is in their queue and what happened most recently.

### Changed
- DTS now locks sender-side forward and lifecycle actions while a signature/approval request is still waiting to be received or answered.
- Add Document now supports separate incoming vs outgoing division tracking slip numbering, while keeping the original incoming sequence unchanged.
- The default non-admin documents sort is now `My next action`, which keeps the existing work buckets but orders them by the latest activity affecting the viewer before deadline tie-breakers.

### Fixed
- Signature/approval requests now keep a clean audit trail by storing each request-and-response cycle as its own task record instead of mutating old request history.
- Sender-side drawer lifecycle buttons now stay hidden while a signature/approval request is still waiting for the receiver's response, including section-lane view.
- Sender/requester drawer action buttons now stay hidden while a signature/approval request is still pending receive or pending response.
- Division tracking duplicate checks now treat `PPD` and `PPDOUT` as separate namespaces, so matching date/sequence pairs no longer collide across incoming and outgoing slips.
- Division tracking now restores a DB-level scope-aware guard for normal numbers, while still allowing intentionally forced duplicates to exist as explicit duplicate overrides.
- The documents list now keeps recently forwarded, shared, requested, or received items easier to spot by surfacing their latest relevant activity text and by briefly focusing the row when the drawer restores after an action.
- Add Document now rolls back the whole create flow when optional PDF generation fails, so a document is no longer left behind without its requested division slip or transmittal output.
- Split child-document completion now has an explicit `document_events.event_type` migration so older databases no longer fail with enum truncation when saving the child setup.
- The org chart now lazy-mounts off-screen division panels and section bodies, reducing the initial rendering cost when opening the page without changing the visible workflow.
- Signature/approval response now keeps notes first and actions in the footer, with an extra confirmation modal before Signed, Approved, or Reject is sent.
- Recipient-side signature/approval requests no longer leak into the Completed card or Completed quick filter while they are still waiting for receive or response.
- The `Which button should I click?` guide now covers newer DTS actions such as Share visibility, Request signature/approval, Respond, project split, and slip continuation actions.
- The latest-activity column no longer repeats the requester line in generic activity states, and the requester value now has stronger emphasis.

### Removed

### Affected Areas
- Documents List
- Documents Drawer
- Forward / Share Visibility Flow
- Signature / Approval Request Flow
- Receive Flow
- Add Document
- Division Tracking Slip Generation
- Child Document Setup
- Timeline / Audit Trail
- Organizational Chart
- Action Guide / Help Page

### Breaking Changes

## [V1.1] - 2026-05-19
Summary: Parent-child documents, drawer cleanup, attachment controls, and division slip refinements for DTS.

### Added
- A changelog page inside the app, with a patch-directory layout for browsing current and future patches.
- A project-splitting workflow that creates linked child documents from selected project codes.
- A dedicated child-document setup flow so split-created documents can be completed as new records with inherited context, instead of being treated like ordinary corrections.
- A document family view in the drawer so parent and child documents can be opened from one place.
- A continuation-page generator for division tracking slips when extra movement rows are needed.
- Admin-only soft delete for attachments, with audit visibility kept intact.

### Changed
- The drawer now separates core document details from context by moving project, family, and timing details into a dedicated Context tab.
- Related document navigation now uses a family-tree style view instead of ambiguous parent and child labels.
- The login page version label now reflects the latest released patch from this changelog.
- The changelog page is now intended to read from this file as the canonical release source instead of relying on hardcoded page copy.
- Parent-child document logic now expects formal schema support instead of silently mutating the database during normal page requests.
- Child-document completion now uses an explicit setup-completed event instead of brittle payload-text matching.
- Child documents now stay out of other parties' main document lists unless those users are directly involved in the child workflow; parent-context discovery remains available in the drawer.
- The mobile documents page now uses a more compact, list-first layout with reduced top-of-screen clutter and a denser document summary card.

### Fixed
- Transmittal Memo visibility now follows the actual DTS scope rules instead of guessed labels.
- Own Division Tracking Number is only applied when a Division Tracking Slip is actually selected.
- Second-page division slip attachments no longer clutter the merged View Document output.
- Split-created child documents now clearly say they were created from an existing document in their timeline.
- Original sender visibility can continue into child documents through the parent relationship for follow-up viewing.
- Division Tracking Slip behavior in child-document setup now follows the Add Document rules, including when tracking fields appear and when division tracking records are actually created.
- Child setup is no longer marked complete before optional division-slip generation succeeds, preventing half-finished child documents from falling out of setup mode.
- Current holders can now upload attachments while a share-visibility route is still in transit, because reference-only visibility does not transfer possession.
- Receivers who can access one child document can now continue navigating the full related-document family in the drawer without losing access after opening the parent or a sibling.
- Linked document drawer fallback now returns a more faithful payload so parent and child navigation is less likely to open in an incorrectly degraded state.
- Parent-child visibility and documents-page SQL now fail safely when the split schema is not yet installed, instead of assuming the new column already exists.
- Mobile document browsing no longer forces horizontal scrolling on the main documents page, and the mobile filter/assistant controls now collapse into a cleaner, more compact arrangement.

### Removed
- The old drawer-style clutter that mixed project codes, relationship labels, and operational details in one overview block.
- Request-time schema alteration from the document split feature path.

### Affected Areas
- Add Document
- Document Drawer
- Document Family / Parent-Child Navigation
- Project Splitting
- Child Document Setup
- Documents List Visibility
- Timeline
- Files / Attachments
- Division Tracking Slip Generation
- Mobile Documents Page
- Login Page Version Display
- Changelog Page
- Login-safe / Backward-compatible rollout behavior

### Breaking Changes
