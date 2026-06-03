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