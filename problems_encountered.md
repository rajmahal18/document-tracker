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
