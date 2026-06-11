# Document Tracker - Codex Instructions

## Bug-Fix Protocol

For every task involving a bug, regression, broken workflow, incorrect UI state,
wrong permission behavior, missing data, unexpected result, or debugging request:

1. Before proposing a fix or editing any code, read `problems_encountered.md`.
2. Search for similar symptoms, affected modules, database tables, endpoints,
   user roles, status values, and previously identified root causes.
3. If a similar issue exists, use the documented root cause and debugging path
   as the starting point. Do not repeat already disproven assumptions.
4. Identify the actual data source used by the broken screen or workflow before
   making frontend-only or backend-only fixes.
5. After resolving the bug, update `problems_encountered.md` only when the issue
   reveals a reusable debugging lesson, recurring bug pattern, or misleading
   assumption worth remembering.

## Required Debugging Response Format

When fixing a bug, briefly report:

- Similar prior issue found: yes/no
- Files or data sources actually involved
- Root cause
- Fix applied
- Whether `problems_encountered.md` was updated

## Important Project Rule

Do not change existing behavior outside the confirmed bug scope unless necessary.
Always verify both normal user flow and assistant/delegated-user flow when a bug
involves identity, permissions, routing, signatures, approvals, or document actions.

## Docs Update Checklist

When finishing work, proactively check whether any project markdown file should be updated:

1. `problems_encountered.md`
- Update this for bugs only when the fix reveals a reusable debugging lesson, recurring bug pattern, permission mismatch, payload/source mismatch, or misleading assumption worth remembering.

2. `CHANGELOG.md`
- Update this for meaningful user-facing changes, workflow changes, new features, visible UI changes, or behavior changes that should appear in the app changelog.
- Follow `CHANGELOG_WORKFLOW.md` and `CHANGELOG_GUIDE.md`.

3. `prod_details.md`
- Update this when production-relevant setup changes, such as cron jobs, backup behavior, deployment assumptions, environment-specific paths, or operational runbooks.
- Keep it operational and concise, not a changelog.

4. Other `.md` docs
- If a task changes an established developer workflow, debugging workflow, rollout procedure, or repo convention, update the nearest relevant markdown doc in the repo instead of leaving the knowledge only in code or chat.
