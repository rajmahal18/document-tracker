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
