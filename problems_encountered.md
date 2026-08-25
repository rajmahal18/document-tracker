# Problems Encountered

Purpose: keep a short, practical record of recurring bug patterns, where they were actually rooted, and the fastest way to debug them next time.

## 1. Sender action buttons stayed visible during pending signature/approval request

Date encountered: 2026-06-03

### Symptom

- Sender/requester could still see drawer action buttons like `Forward`, `Forward attach`, `Add remarks`, `Release`, `Archive`, and `End Now`
- This happened even while the signature/approval request was still only `PENDING_RECEIVE` or `IN_PROGRESS`
- Timeline already showed `REQUEST SENT` or `REQUEST RECEIVED`, but the drawer still behaved as if no lock existed

### Misleading first assumptions

- The frontend button hide/show logic looked wrong at first
- The branch selection logic also looked suspicious because some locks depended on the selected lane
- `document_drawer_snapshot.php` looked like the likely payload source, but the broken drawer instance was not using it

### Actual root cause

The drawer for the documents list opens from the row's inline `data-doc` payload generated in [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php), not always from [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php).

In assistant mode:

- page payload was built using the acting principal as `myUserId`
- signature/approval request records were stored with the real logged-in assistant as `sender_user_id`

So sender-side request lookups returned nothing for the principal identity, which meant:

- `action_request_summary` was empty
- `flat_action_request_sender_waiting` stayed false
- the drawer never entered the locked state

### Files involved

- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)
- [core/workflow.php](C:/xampp/htdocs/document-tracker/core/workflow.php)
- [assets/js/documents-page.js](C:/xampp/htdocs/document-tracker/assets/js/documents-page.js)
- [api/action_request_create.php](C:/xampp/htdocs/document-tracker/api/action_request_create.php)
- [includes/bootstrap.php](C:/xampp/htdocs/document-tracker/includes/bootstrap.php)

### Final fix

Action-request sender lookups were updated to support assistant mode using both:

- effective principal user ID
- actual signed-in assistant user ID

This was applied to:

- action request meta lookups
- action request summary lookups
- inline row payload generation
- drawer snapshot payload generation

Frontend lock logic was also tightened so the drawer hides sender actions when request summary shows an open sender-side request.

### Best debugging path next time

If the drawer buttons are wrong, check these in this order:

1. Determine where the drawer payload came from
- Documents list rows usually use inline `data-doc` from [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- Related-document opens may use [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)

2. Check whether the payload actually contains request-lock data
- `action_request_summary`
- `action_request_open_task_count`
- `flat_action_request_sender_waiting`
- `flat_action_request_recipient_pending_receive`
- `flat_action_request_recipient_in_progress`
- `my_has_actionable_role`

3. If assistant mode is active, verify identity mismatch
- compare `actual_user_id`
- compare `effective_user_id`
- check whether request rows were saved using actual user while page filtering/rendering uses effective user

4. Only after payload is confirmed should frontend button logic be blamed
- [assets/js/documents-page.js](C:/xampp/htdocs/document-tracker/assets/js/documents-page.js)
- especially sender lock functions and drawer action visibility paths

### Quick console checks

Use these when the drawer is open:

```js
(() => {
  const buttons = {
    forward: document.getElementById('btnToggleForward')?.style.display,
    attach: document.getElementById('btnToggleAttachmentForward')?.style.display,
    remarks: document.getElementById('btnEditPendingRemarks')?.style.display,
    release: document.getElementById('btnRelease')?.style.display,
    archive: document.getElementById('btnArchive')?.style.display,
    endNow: document.getElementById('btnEndHere')?.style.display
  };

  const hint = document.getElementById('drawerActionRequestHint')?.textContent || '';
  const statusHtml = document.getElementById('drawerActionRequestStatus')?.innerText || '';

  console.log({ buttons, hint, statusHtml });
})();
```

If `hint` and `statusHtml` are empty while timeline clearly shows request activity, suspect missing request metadata in the payload before touching button logic.

### Related rule to remember

Assistant mode bugs often come from identity split:

- actual logged-in user does the action
- acting principal is the effective business identity

Any query that checks ownership, sender, actor, participation, or actionable state should be reviewed for whether it needs:

- actual user
- effective user
- or both

## 2. General DTS drawer debugging rule

### Symptom pattern

- timeline and status cards look correct
- buttons or hints look wrong
- branch pills and row state disagree

### First places to inspect

1. [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- row `data-doc` payload

2. [assets/js/documents-page.js](C:/xampp/htdocs/document-tracker/assets/js/documents-page.js)
- `openDrawer(payload)`
- payload-derived booleans
- branch-mode vs flat-mode paths

3. [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)
- only if the drawer path came from an API snapshot

4. [core/workflow.php](C:/xampp/htdocs/document-tracker/core/workflow.php)
- summary/meta helpers
- sender vs recipient state detection
- branch vs non-branch helper differences

### Practical rule

If UI state is wrong, verify payload first. If payload is wrong, fix backend/helper logic. If payload is right, fix frontend visibility logic.

## 3. Chief dashboard row can appear, but drawer APIs still return `Access denied`

### Symptom pattern

- a document is visible in the chief dashboard list
- clicking `Open document` works for some rows but not others
- Network shows `document_drawer_snapshot.php` or follow-up drawer APIs returning `403` / `Access denied`

### Misleading first assumption

- it looks like a frontend click bug because some buttons work and some do nothing

### Actual root cause

The chief dashboard had its own scope query for "documents needing attention", but the drawer APIs still enforced the normal personal `can_view_document_family()` visibility rules.

So a document could be:

- valid for chief oversight scope
- invalid for ordinary personal/route visibility

That mismatch caused partial-open behavior:

- some rows opened
- some rows fetched but were denied by backend

### Best debugging path next time

1. Compare the page query and the drawer API permission rule
- [core/chief_dashboard.php](C:/xampp/htdocs/document-tracker/core/chief_dashboard.php)
- [includes/bootstrap.php](C:/xampp/htdocs/document-tracker/includes/bootstrap.php)

2. If the page has a special oversight scope, make sure the drawer-dependent APIs receive that same context
- snapshot
- history
- attachments
- related documents
- pending remarks

3. Do not loosen global visibility blindly
- prefer a scoped fallback like `chief_view=1` that is only honored for chief-dashboard flows

## 4. Old completed signature/approval requests can incorrectly make legacy documents look permanently non-actionable

Date encountered: 2026-06-11

### Symptom

- User A requested signature/approval from User B
- User B received and approved
- User A later forwarded the actual document to User B
- User B received the routed document, but the drawer no longer showed normal action buttons even though the document was already with User B

### Misleading first assumption

- It looked like the new forward/receive flow failed to restore actionable ownership
- Branch/actionable-lane logic looked suspicious first, especially around `can_forward`

### Actual root cause

For legacy non-branch documents, the page payload and drawer snapshot both derived:

- `flatActionRequestIsRecipient` from historical `recipient_any_count`
- `flatActionRequestRecipientCompleted` when there were no longer any open request tasks

That meant a user who had ever been the recipient of a signature/approval request on the document could still be treated as a completed request recipient later, even after a separate normal forward made them the true current actionable holder.

The payload override then forced:

- `my_has_actionable_role = false`
- `my_can_change_lifecycle = false`

So the drawer behaved as if the user only had old request history, not current ownership.

### Files involved

- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)
- [core/workflow.php](C:/xampp/htdocs/document-tracker/core/workflow.php)

### Final fix

Stopped treating completed signature/approval recipient history as an action-locking state for legacy documents.

Only open signature/approval states now suppress normal actionable behavior:

- `PENDING_RECEIVE`
- `IN_PROGRESS`
- sender waiting on an open request

Completed request history is still preserved for summaries/audit purposes, but it no longer overrides a later real actionable forward.

### Best debugging path next time

1. If a user is the current holder but has no normal actions, check whether the document is branch-mode or legacy-mode first
2. For legacy-mode documents, inspect payload booleans before blaming route ownership:
- `my_has_actionable_role`
- `my_can_change_lifecycle`
- `flat_action_request_recipient_pending_receive`
- `flat_action_request_recipient_in_progress`
- `flat_action_request_recipient_completed`
3. If `flat_action_request_recipient_completed = 1`, confirm whether that came from old request history instead of an actually open request
4. Do not let historical completed request metadata override current actionable ownership

## 5. Documents page JS can silently break when a server-injected context variable is used before it is defined

Date encountered: 2026-06-11

### Symptom

- Forward modal opens but recipient users never load
- Receive button stops working or appears dead
- Multiple documents-page interactions fail at once even though the backend endpoints themselves are still valid

### Actual root cause

The page injected `window.__CTX__.currentDocumentsView` from PHP before `$currentDocumentsView` had been defined later in [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php).

On environments where PHP warnings are rendered into the response, that undefined-variable warning can corrupt the inline script block and prevent the rest of `assets/js/documents-page.js` from behaving normally.

### Files involved

- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [assets/js/documents-page.js](C:/xampp/htdocs/document-tracker/assets/js/documents-page.js)

### Final fix

Provide a safe early fallback value for the JS-injected documents view context before including the layout/script block.

### Best debugging path next time

1. If several unrelated documents-page actions die at once, suspect an early JS bootstrap failure
2. Check inline server-rendered script values before blaming fetch handlers
3. Look for PHP warnings inserted into script tags, especially from undefined variables used in `window.__CTX__`

## 6. PHP numeric array keys can break strict string template helpers mid-render

Date encountered: 2026-06-16

### Symptom

- Issuances page rendered the hero and counters for a non-admin user
- The total/year counts were correct, but the search tools, year tabs, and table did not appear
- Admin-created data existed and was counted by the page

### Actual root cause

The page grouped issuance years using numeric-looking array keys such as `2026`.

PHP casts numeric string array keys to integers, so `array_key_first($years)` returned `2026` as an integer. The page then passed that value into `issuances_h(string $value)` while `strict_types=1` was enabled, causing a `TypeError` in the template after the hero had already rendered.

### Files involved

- [public/issuances.php](C:/xampp/htdocs/document-tracker/public/issuances.php)

### Final fix

Cast the selected year back to string immediately after resolving it from request/default year state.

### Best debugging path next time

If a PHP page renders the first section and then silently stops:

1. Check for typed helper calls in the template, especially `function h(string $value)`
2. Check values coming from array keys, counts, IDs, or dates
3. Remember that numeric string keys become integers in PHP arrays

## 7. Chief dashboard in-transit rows must scope the person, not just the route

Date encountered: 2026-06-16

### Symptom

- A section chief dashboard could show a person who was not under that section
- A chief dashboard could include the chief's own personal backlog
- Creation-only stale documents could appear under vague section names such as `Programming Section` instead of the actual document creator
- Forward Attach and Signature/Approval work could be shown as generic in-transit work or missed because the dashboard only looked at normal route/branch state
- Assistant-mode Forward Attach and Signature/Approval work could group under the assistant's name instead of the acting principal, causing assistants of chiefs to appear in another chief's personnel list
- A Signature/Approval request sent by a chief's own staff to an outside boss or assistant could still group the outside recipient on the chief dashboard, even though the requester was the directly supervised person
- After a Signature/Approval request was completed, the latest received `REFERENCE` route could make the dashboard holder fallback combine the outside receiver user with the document's real current holder section
- After a Forward Attach task was marked done, the open task row disappeared and the dashboard could fall back to the latest received `ACTION` route, where `received_by_user_id` was the actual assistant even though the route was addressed to the chief/principal
- This happened on in-transit documents where the outside person was the receiver or sender of an open route
- The row did not clearly explain whether the scoped person was the sender or receiver

### Actual root cause

The chief dashboard treated open in-transit routes as a single accountable receiver row. That made the route direction unclear and could hide the in-scope sender context when the receiver was outside the chief's section or division.

The first scoped filter also allowed the effective viewer's own user ID, which made the chief dashboard behave like a duplicate personal backlog instead of a pure oversight dashboard.

For creation-only documents, the holder fallback used `documents.current_holder_section_id` even when there was no received route and no branch assignee. That produced section-name personnel buckets instead of the actual creator responsible for the untouched document.

Forward Attach and Signature/Approval have first-class task tables:

- `attachment_forward_tasks`
- `document_action_requests`

Those task rows carry the real sender, recipient, task status, and accountable timestamp. Treating only `routes.route_kind = 'ACTION'` and active non-reference branches as the dashboard source can miss `REFERENCE`-route signature/approval recipients or flatten task work into vague route movement.

In assistant mode, the task sender columns can store the actual signed-in assistant because the assistant performed the click. The matching `document_events.payload_json` carries `acting_principal_user_id`, which is the business identity that should be used for chief-dashboard accountability.

### Files involved

- [core/chief_dashboard.php](C:/xampp/htdocs/document-tracker/core/chief_dashboard.php)
- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [assets/css/documents.css](C:/xampp/htdocs/document-tracker/assets/css/documents.css)

### Final fix

Open routes now build separate sender and receiver participant contexts, then apply chief scope to those participant records. The dashboard row labels in-transit documents with the scoped person's role and counterpart, such as `Receiver from ...` or `Sender to ...`.

The personnel scope filter now excludes the effective chief/acting principal user ID so chiefs do not see their own assigned documents in the oversight dashboard.

When the dashboard falls back to the document holder and no received-route holder exists, it now uses `documents.created_by_user_id` and the creator's org metadata before falling back to section-only labels.

Open Forward Attach and Signature/Approval task rows now take precedence over generic route/branch fallback and are labeled by task type in the dashboard row.

For assistant-mode Forward Attach and Signature/Approval task rows, the chief dashboard resolves the sender to `acting_principal_user_id` from the matching audit event before grouping or scope filtering. This keeps assistant names out of personnel lists unless the assistant is truly the scoped recipient/person.

For Signature/Approval task rows, dashboard scoping now filters accountable people through a sender-first rule per request. If the scoped sender is already one of the chief's personnel, the outside receiver remains only as route context and is not grouped as dashboard personnel.

For holder fallback, the chief dashboard now resolves the latest received holder from `ACTION` routes only. Completed Signature/Approval `REFERENCE` receipts are audit/context events and must not become the accountable holder source.

Forward Attach uses the same sender-first scoping rule as Signature/Approval. When holder fallback reads a completed `ACTION` route received by an assigned assistant on behalf of the addressed principal, the dashboard resolves the accountable holder to that principal instead of the actual assistant actor.

### Best debugging path next time

For chief dashboard scope bugs, inspect the participant being grouped, not only the document or route:

1. Check whether the row comes from open routes, branch assignees, or holder fallback
2. For open routes, compare both sender and receiver sections/divisions against the chief scope
3. Render the scoped person's route role so the dashboard explains why the document appears
4. Exclude the effective chief/acting principal user ID from personnel grouping; chief dashboard is for subordinate oversight, not personal backlog review
5. For creation-only/no-route documents, use `documents.created_by_user_id` before falling back to `current_holder_section_id`; section-only labels are usually too vague for personnel grouping
6. Check task tables before route/branch fallback for special workflows; Forward Attach and Signature/Approval have their own sender/recipient/status source of truth
7. In assistant mode, normalize task senders through matching `document_events.payload_json.acting_principal_user_id` before grouping personnel for chief-dashboard accountability
8. For Signature/Approval and Forward Attach tasks, if the sender/requester is already in the chief's personnel scope, keep that sender as the grouped accountable person and treat the requested boss/assistant as counterpart context
9. For holder fallback, do not use completed `REFERENCE` routes as current-holder evidence; use the latest received `ACTION` route or the creator fallback
10. When a latest received `ACTION` route has different `to_user_id` and `received_by_user_id`, check whether the receiver is an assigned assistant for the addressed user before grouping the receiver as accountable

## 8. Visible header controls can be unresponsive when a page omits the shared footer

Date encountered: 2026-06-18

### Symptom

- On the Changelogs and Admin pages, the hamburger menu button was visible but did not open the side navigation.
- Other pages using the same shell could still open the drawer normally.

### Actual root cause

The affected pages included the shared layout header but did not include `includes/footer.php` at the end of the page.

The hamburger button is rendered by `includes/layout.php`, but its click listener is registered in `assets/js/pwa-ui.js`, which is loaded through the shared footer. Without the footer, the button stayed visible but had no drawer behavior attached.

### Files involved

- [includes/layout.php](C:/xampp/htdocs/document-tracker/includes/layout.php)
- [public/changelogs.php](C:/xampp/htdocs/document-tracker/public/changelogs.php)
- [public/admin.php](C:/xampp/htdocs/document-tracker/public/admin.php)
- [includes/footer.php](C:/xampp/htdocs/document-tracker/includes/footer.php)
- [assets/js/pwa-ui.js](C:/xampp/htdocs/document-tracker/assets/js/pwa-ui.js)

### Final fix

Added the missing shared footer include to `public/changelogs.php` and `public/admin.php`, matching working pages such as Issuances and Task Monitoring.

### Best debugging path next time

If a visible header button is not clickable:

1. Compare the broken page against a working page that uses the same shell
2. Confirm `includes/footer.php` is included and `assets/js/pwa-ui.js` is loaded
3. Check whether `#navToggle` has its click listener initialized before investigating z-index

## 9. Drawer display labels must not overwrite machine status values

Date encountered: 2026-07-07

### Symptom

- A document ended through End Now showed `LIFECYCLE ENDED` in the drawer
- `Reopen Lifecycle` did not appear
- Admin lifecycle buttons like `Release` and `Archive` still appeared even though the document was already released by End Now

### Actual root cause

The row payload correctly contained:

- `current_status = RELEASED`
- `status_label = LIFECYCLE ENDED`
- `last_end_here_kind = document_ended_here`

But the frontend drawer normalizer preferred `status_label` when rebuilding `current_status`.

That turned the machine status into `LIFECYCLE ENDED`, so the action logic skipped the `RELEASED` branch that calls `syncEndHereButtons()` and fell through to normal privileged active-action controls.

### Files involved

- [assets/js/documents-page.js](C:/xampp/htdocs/document-tracker/assets/js/documents-page.js)
- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)

### Final fix

Kept `current_status` as the raw lifecycle value and treated `status_label` as display-only during drawer payload normalization.

### Best debugging path next time

If drawer buttons disagree with the visible status chip:

1. Compare `payload.current_status` against `payload.status_label`
2. Confirm JS normalization does not replace raw status with display text
3. Check action visibility against raw machine values like `ACTIVE`, `RELEASED`, and `ARCHIVED`, not labels such as `LIFECYCLE ENDED`

## 10. Optional foreign keys must be saved as NULL, not 0

Date encountered: 2026-07-07

### Symptom

- Creating a Task Monitoring task showed `Failed to save task.`
- Browser Network/Console showed `api/tms_task_save.php` returning HTTP 500.

### Actual root cause

The new TMS save flow inserted `0` into nullable foreign-key columns on generated task steps, task-specific timeline steps, and participants.

For example, non-first workflow steps did not have a responsible user yet, but the API bound `0` into `tms_task_steps.responsible_user_id`. Task-specific timeline subtasks also do not have a source template row, but the API initially bound `0` into `tms_task_steps.workflow_step_id`.

MySQL treated those `0` values as real referenced ids and rejected the insert because no matching parent row existed.

### Files involved

- [api/tms_task_save.php](C:/xampp/htdocs/document-tracker/api/tms_task_save.php)
- `tms_task_steps`
- `tms_task_participants`
- `users`
- `tms_workflow_steps`

### Final fix

Normalize optional foreign-key values to `NULL` before binding them into TMS inserts.

### Best debugging path next time

If a create/update endpoint returns a generic 500 while inserting rows with optional relationships:

1. Check the PHP/Apache error log for the exact SQL constraint failure
2. Inspect nullable foreign-key inputs for `0`
3. Use `NULL` for absent optional relationships and reserve positive integers for actual referenced rows

## 11. Timeline/list remarks can disappear when audit payload keys differ

Date encountered: 2026-08-25

### Symptom

- Some remarks or notes were saved, but did not appear clearly in the document timeline.
- Some saved notes did not appear in the Documents list `Latest remark` column.
- Pending-route remark events could be logged, but the timeline treated them as generic updates instead of pending remark actions.

### Actual root cause

The display readers only treated `payload_json.remarks` as the remark source.

Some DTS workflows store user-entered note text under more specific keys:

- `request_notes` for signature/approval request notes
- `decision_notes` for signature/approval response notes

Also, pending-route remarks were logged with `kind` values such as `pending_remarks_added`, but `api/get_history.php` did not promote those kinds into first-class timeline actions.

### Files involved

- [api/get_history.php](C:/xampp/htdocs/document-tracker/api/get_history.php)
- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [api/action_request_create.php](C:/xampp/htdocs/document-tracker/api/action_request_create.php)
- [api/action_request_decide.php](C:/xampp/htdocs/document-tracker/api/action_request_decide.php)
- `document_events.payload_json`
- `routes.remarks`

### Final fix

Timeline and document-list latest remark extraction now checks `remarks`, `request_notes`, and `decision_notes`.

Pending-route remark kinds are now recognized as explicit timeline actions:

- `pending_remarks_added`
- `pending_remarks_updated`
- `pending_remarks_cleared`

New signature/approval request and response events also duplicate their notes into `remarks` while keeping the existing specific note keys for backward compatibility.

### Best debugging path next time

1. Confirm whether the text is stored in `routes.remarks` or `document_events.payload_json`
2. Inspect the exact payload key, not just whether the UI says "remarks" or "notes"
3. Check whether `api/get_history.php` maps the event `kind` to a first-class timeline action
4. Check whether `public/documents.php` latest remark extraction reads that same payload key
5. For old records, prefer reader-side support for existing payload keys instead of migration-only fixes

## 12. Completed Forward Attach tasks can be misclassified by legacy route predicates

Date encountered: 2026-08-25

### Symptom

- A document that passed through Forward Attach still appeared as active/incoming-style work for the attachment recipient after the recipient marked the attachment task done.
- The row payload could know the Forward Attach task was complete, but quick filters, counts, sorting, or fallback status text could still be influenced by the underlying route.

### Actual root cause

Forward Attach stores its real task state in `attachment_forward_tasks`.

Legacy document-list predicates in [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php) also inspect `routes` to decide whether a user has open inbound or actionable work. A Forward Attach delivery uses a route, but that route is only the delivery mechanism for the attachment task. Once the task is done, it should not count as a normal ownership transfer.

The shared workflow helper already ignored Forward Attach routes for legacy actionable checks, but the documents list SQL had its own predicate copy that did not make the same exclusion.

### Files involved

- [public/documents.php](C:/xampp/htdocs/document-tracker/public/documents.php)
- [api/document_drawer_snapshot.php](C:/xampp/htdocs/document-tracker/api/document_drawer_snapshot.php)
- [core/workflow.php](C:/xampp/htdocs/document-tracker/core/workflow.php)
- `attachment_forward_tasks`
- `routes`

### Final fix

The documents list predicates now treat flat Forward Attach task state as first-class:

- `PENDING_RECEIVE` recipient tasks count as Incoming
- `IN_PROGRESS` recipient tasks and sender-waiting tasks count as Pending
- completed Forward Attach recipient tasks no longer inherit normal actionable state from the attachment delivery route

Completed Forward Attach rows also use completed styling and a completed attachment-task activity line.

The drawer snapshot keeps the same guard so old completed Forward Attach history does not hide normal actions if the same user later receives a real forward.

### Best debugging path next time

1. Check `attachment_forward_tasks.task_status` before trusting `routes` for Forward Attach documents
2. Compare list SQL predicates against `workflow_user_can_act_legacy_document()` when a row, filter count, and drawer payload disagree
3. For legacy documents, exclude Forward Attach delivery routes from normal holder/actionable fallback
4. Do not let completed task history override a later normal route to the same user

## 13. Division tracking slip PDF fields can break from double text normalization or route-derived labels

Date encountered: 2026-08-25

### Symptom

- Names with `ñ` did not print correctly on generated division tracking slips.
- The `Assigned to` field could be filled with the current route recipient, such as a boss who received a document from a focal person.
- That made the slip look like the focal person was assigning work to the boss.

### Actual root cause

Division tracking slip text is rendered through FPDF, which expects single-byte PDF font text. Some values were normalized to PDF-compatible text before reaching the renderer, then normalized again inside the renderer. That second pass could treat already-converted text as invalid UTF-8 and strip non-ASCII characters.

Separately, `Assigned to` was sourced from active branches/routes through `build_division_slip_assigned_to_label()`. That route-derived value is useful for workflow state, but it is not appropriate for the printed slip field because the slip's purpose is to provide a blank form plus an auto-filled movement table.

### Files involved

- [core/division_tracking.php](C:/xampp/htdocs/document-tracker/core/division_tracking.php)
- [core/DivisionTrackingSlip.php](C:/xampp/htdocs/document-tracker/core/DivisionTrackingSlip.php)
- [api/division_tracking_slip_generate.php](C:/xampp/htdocs/document-tracker/api/division_tracking_slip_generate.php)
- [public/add_document.php](C:/xampp/htdocs/document-tracker/public/add_document.php)
- `division_tracking_slip_user_order`

### Final fix

PDF text normalization is now idempotent for already-converted single-byte text, so names like `Peña` and `Niño` survive repeated normalization.

The active division slip renderer now normalizes text at draw-time consistently, and the `Assigned to` printed value is forced blank even if an older caller passes a value.

Slip name ordering still defaults to Assistant Division Chief and Section Chiefs, but admins can deliberately add another active staff member to a division's slip name list through the Admin slip-order screen.

### Best debugging path next time

1. Check whether the PDF renderer expects UTF-8, Windows-1252, or another single-byte font encoding
2. Make text normalization idempotent before normalizing in both helpers and renderers
3. Verify whether a printed form field should be workflow-derived or intentionally blank
4. For slip name-list changes, preserve the default query and use explicit saved order rows for division-specific exceptions
