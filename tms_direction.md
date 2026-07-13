# TMS Direction

Purpose: keep the Task Monitoring System direction clear across sessions before implementation starts.

## Non-Negotiable Boundary

TMS and DTS are separate systems.

Do not touch DTS behavior, DTS workflows, DTS routing, DTS document actions, DTS drawer logic, or DTS permissions while building TMS.

For now, the only intended relationship is shared identity/reference data:

- `users`
- `sections`
- `divisions`
- existing role/chief identity metadata where needed for visibility

TMS may reference DTS documents or projects later as optional linked records, but it must not depend on DTS routing or change DTS behavior.

## Product Direction

TMS should become a configurable work and accomplishment monitoring system for offices.

It should not be a Survey and Design Division-specific task tracker. It should work for any ministry, division, section, or cross-office workflow.

The system should support:

- user-generated task types
- user-designed workflows
- task-specific timeline/subtask design during creation
- task creation by focal persons and staff
- multiple offices participating in one work item
- multiple participants from different sections/divisions
- participant invitations with join/decline responses
- step-level assignments, outputs, evidence, validation, and timing
- sequential, parallel, and mixed workflow step execution
- working-day duration and overdue tracking
- historical data for future estimates
- IPCR-ready accomplishment summaries without automatically assigning final IPCR ratings

## Conceptual Model

Use this mental model:

```text
Work Item / Task
  -> Workflow Instance
    -> Steps
      -> Participants
      -> Outputs / MOV
      -> Reviews / Validation
      -> Timing Metrics
```

A task is not limited to one assignee or one office.

Example:

```text
POW and Plan Package
  -> PPD: Planning / program validation
  -> SDD: Survey and design preparation
  -> PPD: Final consolidation / review
  -> Completed
```

## Core Features

### Task Type Builder

Users/admins should be able to define task types such as:

- Prepare procurement request
- Review legal document
- Conduct inspection
- Draft memo
- Validate records
- Prepare plan
- Field survey
- Committee review

Task type configuration should include:

- name
- code
- scope/owner office
- description
- default workflow template
- active/inactive state
- default priority
- default duration rule if no workflow step overrides it
- IPCR/accomplishment relevance

### Workflow Designer

Users/admins should be able to design reusable workflows.

Workflow steps should support:

- step name
- responsible division/section/role
- estimated working duration
- required output
- required proof/MOV
- default participant roles
- reviewer/validator requirement
- allowed next steps
- return/rework rules
- whether the step is IPCR-creditable
- whether the step pauses or counts against SLA
- whether the step completes the workflow

### Task Timeline Builder

Task creation should be timeline-first.

After selecting a task type, the creator should be able to define the actual subtasks for that specific task:

- subtask title
- responsible division, required
- responsible section, optional
- responsible employee, optional
- estimated duration in working days

The system should calculate the estimated completion date from:

- target start date/time
- subtask duration totals
- shared working calendar settings
- shared working calendar exceptions/non-working days

If a subtask has a division/section but no specific employee, it should remain office-assigned. The relevant section/division chief or authorized supervisor should be able to assign a staff member later.

Reusable workflow templates still matter, but they should function as presets behind the task timeline builder, not as confusing required fields for ordinary task creators.

### Collaboration

One task must allow multiple participants.

Participants may come from different:

- divisions
- sections
- roles
- offices

Participant roles should be flexible, such as:

- lead
- contributor
- reviewer
- approver
- observer
- requesting office
- receiving office
- validating supervisor

Participant role names should be user-input. The system may provide generic guide values, but it must not force every office into fixed titles.

When a task creator includes another user, that user should be invited into the task instead of being silently assigned. The included user should be able to join or decline. The creator and current lead/responsible participant remain clear while invitations are pending.

Chiefs should be treated primarily as supervisors/reviewers. TMS should not assume chiefs are the task lead by default, and it should avoid adding operational tasks to chief queues unless a workflow explicitly names them as a reviewer/validator/participant.

### Workflow Execution Mode

Task creators or template designers should be able to choose whether the work is:

- `sequential`: steps run in order
- `parallel`: steps can run at the same time
- `mixed`: some steps can run together, with configured transitions

The task page must make the current responsible office/user obvious at any given period, even when multiple participants share one work item.

### Step-Level Monitoring

Each workflow step should track:

- assigned office
- assigned employee/s
- lead assignee
- reviewer/validator
- status
- started date/time
- completed date/time
- estimated working duration
- actual working duration
- delay reason
- remarks
- proof/MOV
- whether the step can run in parallel with other steps
- whether validation is optional or required

### IPCR-Ready Accomplishments

TMS should provide evidence and summaries for IPCR, but it should not automatically produce final performance ratings.

The system should help generate:

- individual task accomplishments
- completed outputs
- timeliness records
- proof/MOV references
- supervisor validation records
- delay explanations
- summary by rating period
- summary by employee
- summary by task type/output

Final IPCR rating remains subject to supervisor/office evaluation.

IPCR is not integrated yet. For now, the schema should preserve enough structure for future IPCR integration: accountable user, output/MOV, completion date, working-day timing, validation, and task type/output mapping.

### Historical Intelligence

Use completed task history to improve future planning.

Historical metrics should eventually support:

- average completion time per task type
- median completion time per task type
- average duration per workflow step
- bottleneck steps
- delay rate
- suggested estimated duration for new workflows
- suggested target completion date
- workload by person/section/division

Start with simple deterministic metrics before adding advanced prediction.

## Schema Direction

Because TMS is not in production yet, the local TMS migration can be redesigned before rollout.

Keep existing identity tables intact. TMS should reference them instead of duplicating identity.

Reuse shared reference/system tables where appropriate:

- `users`
- `sections`
- `divisions`
- `projects` as an optional work context
- existing working calendar configuration/helpers for working-day calculations

Do not create TMS-only copies of users, sections, divisions, or working calendars.

Likely TMS tables:

- `tms_task_types`
- `tms_workflow_templates`
- `tms_workflow_steps`
- `tms_workflow_transitions`
- `tms_tasks`
- `tms_task_steps`
- `tms_task_participants`
- `tms_task_outputs`
- `tms_task_activity`
- `tms_task_metrics`
- `tms_ipcr_links` or `tms_accomplishment_links`
- `tms_participant_role_presets`

Avoid hardcoding Survey/Design-specific rows, names, codes, or behaviors into schema or code.

Any SDD-style workflow should be represented as a configurable template, not system behavior.

## Local Reset Direction

There is already migrated local TMS data, but TMS is not in production.

If needed, reset only TMS local tables/migrations. Do not reset the whole app database.

Candidate local-only reset scope:

- `tms_task_types`
- `tms_tasks`
- `tms_task_assignees`
- `tms_task_activity`
- `tms_import_batches`
- `tms_import_rows`
- any new `tms_workflow_*`, `tms_task_steps`, `tms_task_participants`, or related TMS-only tables
- any old SDD-specific seed data in `tms_task_types`

Do not drop or modify:

- `users`
- `sections`
- `divisions`
- DTS document tables
- DTS route/workflow tables
- DTS event/history tables

## UI Direction

TMS should feel like an internal office operations tool:

- compact
- tab-heavy
- clear hierarchy
- icon-centric where useful
- professional government-office tone
- no marketing copy
- no flashy dashboard wording

Important views:

- My Tasks
- Section Tasks
- Division Tasks
- Cross-Office Work
- Workflow Templates
- Task Types
- Overdue / Due Soon
- For Review / Validation
- Blocked / Returned
- Completed
- Accomplishment / IPCR Summary
- Historical Metrics

## Implementation Principles

- Do not touch DTS while building TMS.
- Keep TMS modular and self-contained.
- Build from configurable templates, not hardcoded workflow names.
- Prefer step-based workflow state over free-text status.
- Preserve auditability for every status, participant, estimate, output, and validation change.
- Use working days for duration/SLA calculations.
- Make old SDD-specific assumptions disposable before production.
- Add docs/changelog when TMS behavior becomes user-facing.

## Current Implementation Phase

Start with the generic foundation:

- generic schema and migrations
- task types and workflow templates as user-configurable records
- task creation from a task-specific timeline/subtask list
- participant invitations and visible responsible party
- working-calendar based timing labels
- generic task registry UI
- office-assigned subtasks that chiefs can assign to staff later

Defer for later:

- automatic IPCR generation
- automatic ratings
- historical/smart duration suggestions
- complex rework routing
- full workflow designer UI
