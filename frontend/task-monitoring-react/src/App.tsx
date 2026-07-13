import { startTransition, useDeferredValue, useMemo, useState, type FormEvent } from 'react'
import { assignTaskStep, deleteTask, fetchTaskDetail, getAppConfig, getBootstrapData, respondToInvitation, saveTask, saveTaskType } from './lib/app-bridge'
import './index.css'
import type { TmsBootstrap, TmsFilters, TmsTask, TmsTaskDetail, TmsTaskType, TmsWorkflowTemplate } from './types'

type TimelineStepState = {
  title: string
  division_id: string
  section_id: string
  responsible_user_id: string
  duration_working_days: string
}

type FormState = {
  id: number
  task_type_id: string
  workflow_template_id: string
  title: string
  description: string
  priority: string
  flow_mode: string
  target_start_at: string
  target_due_at: string
  participant_user_ids: string[]
  participant_role_labels: Record<string, string>
  lead_user_id: string
  timeline_steps: TimelineStepState[]
  project_id: string
  remarks: string
}

type NewTaskTypeState = {
  name: string
  description: string
  default_priority: string
}

const bootstrap: TmsBootstrap = getBootstrapData()
const app = getAppConfig()

const blankTaskTypeForm = (): NewTaskTypeState => ({
  name: '',
  description: '',
  default_priority: 'normal',
})

const blankTimelineStep = (): TimelineStepState => ({
  title: '',
  division_id: '',
  section_id: '',
  responsible_user_id: '',
  duration_working_days: '1',
})

const defaultForm = (taskTypes = bootstrap.taskTypes, workflowTemplates = bootstrap.workflowTemplates): FormState => {
  const firstType = taskTypes[0]
  const defaultWorkflow = firstType?.default_workflow_template_id
    ? workflowTemplates.find((workflow) => workflow.id === firstType.default_workflow_template_id)
    : workflowTemplates.find((workflow) => !workflow.task_type_id || workflow.task_type_id === firstType?.id)

  return {
    id: 0,
    task_type_id: firstType ? String(firstType.id) : '',
    workflow_template_id: defaultWorkflow ? String(defaultWorkflow.id) : '',
    title: '',
    description: '',
    priority: firstType?.default_priority || 'normal',
    flow_mode: defaultWorkflow?.flow_mode || 'sequential',
    target_start_at: '',
    target_due_at: '',
    participant_user_ids: [String(bootstrap.viewer.id)],
    participant_role_labels: { [String(bootstrap.viewer.id)]: 'Lead' },
    lead_user_id: String(bootstrap.viewer.id),
    timeline_steps: [blankTimelineStep()],
    project_id: '',
    remarks: '',
  }
}

function formatDateTime(value?: string | null) {
  if (!value) return 'No date'
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const date = new Date(normalized)
  if (Number.isNaN(date.getTime())) return 'No date'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
}

function toneForTask(task: TmsTask) {
  if (task.timing_tone) return task.timing_tone
  if (task.lifecycle_status === 'COMPLETED') return 'done'
  if (task.lifecycle_status === 'BLOCKED') return 'overdue'
  return 'open'
}

function statusLabel(status: string) {
  return status
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

function workflowOptionsForType(typeId: string, workflowTemplates = bootstrap.workflowTemplates) {
  const selected = Number(typeId)
  return workflowTemplates.filter((workflow) => !workflow.task_type_id || workflow.task_type_id === selected)
}

function matchesTask(task: TmsTask, filters: TmsFilters) {
  if (filters.type && task.task_type_code !== filters.type) return false
  if (filters.status && task.lifecycle_status !== filters.status) return false
  if (!filters.q) return true
  const q = filters.q.toLowerCase()
  return [
    task.title,
    task.description,
    task.task_type_name,
    task.workflow_template_name,
    task.current_step_title,
    task.current_responsible_name,
    task.participants_text,
  ]
    .join(' ')
    .toLowerCase()
    .includes(q)
}

function responsibleText(task: TmsTask) {
  const person = task.current_responsible_name
  const office = [task.current_responsible_section_name, task.current_responsible_division_name].filter(Boolean).join(', ')
  if (person && office) return `${person} - ${office}`
  if (person) return person
  if (office) return office
  return 'Not assigned'
}

export default function App() {
  const [tasks] = useState<TmsTask[]>(bootstrap.tasks)
  const [taskTypes, setTaskTypes] = useState<TmsTaskType[]>(bootstrap.taskTypes)
  const [workflowTemplates, setWorkflowTemplates] = useState<TmsWorkflowTemplate[]>(bootstrap.workflowTemplates)
  const [filters, setFilters] = useState<TmsFilters>(bootstrap.filters)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalBusy, setModalBusy] = useState(false)
  const [modalReadOnly, setModalReadOnly] = useState(false)
  const [form, setForm] = useState<FormState>(() => defaultForm())
  const [detail, setDetail] = useState<TmsTaskDetail | null>(null)
  const [formMessage, setFormMessage] = useState('')
  const [formError, setFormError] = useState(false)
  const [showTaskTypeForm, setShowTaskTypeForm] = useState(false)
  const [taskTypeBusy, setTaskTypeBusy] = useState(false)
  const [taskTypeForm, setTaskTypeForm] = useState<NewTaskTypeState>(() => blankTaskTypeForm())
  const [taskTypeMessage, setTaskTypeMessage] = useState('')
  const [taskTypeError, setTaskTypeError] = useState(false)
  const [stepAssignees, setStepAssignees] = useState<Record<string, string>>({})
  const [assigningStepId, setAssigningStepId] = useState<number | null>(null)

  const deferredQuery = useDeferredValue(filters.q)
  const effectiveFilters = useMemo<TmsFilters>(() => ({ ...filters, q: deferredQuery }), [filters, deferredQuery])
  const filteredTasks = useMemo(() => tasks.filter((task) => matchesTask(task, effectiveFilters)), [tasks, effectiveFilters])

  const summary = useMemo(() => {
    const open = filteredTasks.filter((task) => !['COMPLETED', 'CANCELLED'].includes(task.lifecycle_status)).length
    const invitations = filteredTasks.filter((task) => task.permissions?.is_invited).length
    const overdue = filteredTasks.filter((task) => toneForTask(task) === 'overdue').length
    const completed = filteredTasks.filter((task) => task.lifecycle_status === 'COMPLETED').length
    return { total: filteredTasks.length, open, invitations, overdue, completed }
  }, [filteredTasks])

  const typeCounts = useMemo(() => {
    const counts = new Map<string, number>()
    for (const task of tasks) counts.set(task.task_type_code, (counts.get(task.task_type_code) || 0) + 1)
    return counts
  }, [tasks])

  const statusOptions = useMemo(() => {
    const statuses = new Set(bootstrap.statusOptions)
    for (const task of tasks) statuses.add(task.lifecycle_status)
    return [...statuses].filter(Boolean).sort()
  }, [tasks])

  const divisionOptions = useMemo(() => {
    return [...bootstrap.divisions].sort((a, b) => a.name.localeCompare(b.name))
  }, [])

  function updateField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function sectionsForDivision(divisionId: string) {
    return bootstrap.sections
      .filter((section) => !divisionId || String(section.division_id || '') === divisionId)
      .sort((a, b) => a.name.localeCompare(b.name))
  }

  function usersForStep(step: TimelineStepState) {
    return bootstrap.users
      .filter((user) => !step.division_id || String(user.division_id || '') === step.division_id)
      .filter((user) => !step.section_id || String(user.section_id || '') === step.section_id)
      .sort((a, b) => a.full_name.localeCompare(b.full_name))
  }

  function usersForSavedStep(step: { responsible_division_id: number | null; responsible_section_id: number | null }) {
    return bootstrap.users
      .filter((user) => !step.responsible_division_id || user.division_id === step.responsible_division_id)
      .filter((user) => !step.responsible_section_id || user.section_id === step.responsible_section_id)
      .sort((a, b) => a.full_name.localeCompare(b.full_name))
  }

  function canAssignSavedStep(step: { responsible_user_id: number | null; responsible_division_id: number | null; responsible_section_id: number | null }) {
    if (modalReadOnly && !detail?.permissions?.can_supervise_task) return false
    if (step.responsible_user_id) return false
    if (bootstrap.canManageAll) return true
    const viewerSection = bootstrap.viewer.section_id || null
    const viewerDivision = bootstrap.viewer.division_id || null
    if (step.responsible_section_id && viewerSection) return step.responsible_section_id === viewerSection
    if (step.responsible_division_id && viewerDivision) return step.responsible_division_id === viewerDivision
    return false
  }

  function updateTimelineStep(index: number, patch: Partial<TimelineStepState>) {
    setForm((current) => ({
      ...current,
      timeline_steps: current.timeline_steps.map((step, stepIndex) => {
        if (stepIndex !== index) return step
        const next = { ...step, ...patch }
        if (patch.division_id !== undefined) {
          next.section_id = ''
          next.responsible_user_id = ''
        }
        if (patch.section_id !== undefined) {
          next.responsible_user_id = ''
        }
        return next
      }),
    }))
  }

  function addTimelineStep() {
    setForm((current) => ({ ...current, timeline_steps: [...current.timeline_steps, blankTimelineStep()] }))
  }

  function removeTimelineStep(index: number) {
    setForm((current) => ({
      ...current,
      timeline_steps: current.timeline_steps.length > 1 ? current.timeline_steps.filter((_, stepIndex) => stepIndex !== index) : current.timeline_steps,
    }))
  }

  const totalWorkingDays = useMemo(
    () => form.timeline_steps.reduce((sum, step) => sum + Math.max(0, Number.parseInt(step.duration_working_days || '0', 10) || 0), 0),
    [form.timeline_steps],
  )

  function selectTaskType(typeId: string) {
    const taskType = taskTypes.find((type) => String(type.id) === typeId)
    const workflows = workflowOptionsForType(typeId, workflowTemplates)
    const workflow = taskType?.default_workflow_template_id
      ? workflows.find((entry) => entry.id === taskType.default_workflow_template_id) || workflows[0]
      : workflows[0]

    setForm((current) => ({
      ...current,
      task_type_id: typeId,
      workflow_template_id: workflow ? String(workflow.id) : '',
      flow_mode: workflow?.flow_mode || current.flow_mode || 'sequential',
      priority: taskType?.default_priority || current.priority || 'normal',
    }))
  }

  function openCreateModal() {
    setForm(defaultForm(taskTypes, workflowTemplates))
    setDetail(null)
    setShowTaskTypeForm(false)
    setTaskTypeForm(blankTaskTypeForm())
    setTaskTypeMessage('')
    setTaskTypeError(false)
    setStepAssignees({})
    setModalReadOnly(false)
    setFormMessage('')
    setFormError(false)
    setModalOpen(true)
  }

  async function openTask(task: TmsTask) {
    setModalBusy(true)
    setModalOpen(true)
    setFormMessage('')
    setFormError(false)
    try {
      const taskDetail = await fetchTaskDetail(task.id)
      setDetail(taskDetail)
      setModalReadOnly(!taskDetail.can_edit)
      setStepAssignees({})
      setForm({
        id: taskDetail.id,
        task_type_id: String(taskDetail.task_type_id || ''),
        workflow_template_id: String(taskDetail.workflow_template_id || ''),
        title: taskDetail.title || '',
        description: taskDetail.description || '',
        priority: taskDetail.priority || 'normal',
        flow_mode: taskDetail.flow_mode || taskDetail.workflow_flow_mode || 'sequential',
        target_start_at: (taskDetail.target_start_at || '').slice(0, 16).replace(' ', 'T'),
        target_due_at: (taskDetail.target_due_at || '').slice(0, 16).replace(' ', 'T'),
        participant_user_ids: (taskDetail.participant_user_ids || []).map(String),
        participant_role_labels: taskDetail.participant_role_labels || {},
        lead_user_id: String(taskDetail.participants.find((participant) => participant.is_lead === 1)?.user_id || bootstrap.viewer.id),
        timeline_steps: taskDetail.steps?.length
          ? taskDetail.steps.map((step) => ({
              title: step.title || '',
              division_id: step.responsible_division_id ? String(step.responsible_division_id) : '',
              section_id: step.responsible_section_id ? String(step.responsible_section_id) : '',
              responsible_user_id: step.responsible_user_id ? String(step.responsible_user_id) : '',
              duration_working_days: step.estimated_working_minutes ? String(Math.max(1, Math.ceil(step.estimated_working_minutes / 480))) : '1',
            }))
          : [blankTimelineStep()],
        project_id: taskDetail.project_id ? String(taskDetail.project_id) : '',
        remarks: taskDetail.remarks || '',
      })
    } catch (error) {
      setFormMessage(error instanceof Error ? error.message : 'Failed to load task.')
      setFormError(true)
    } finally {
      setModalBusy(false)
    }
  }

  async function submitTask(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (modalReadOnly) {
      setModalOpen(false)
      return
    }

    setModalBusy(true)
    setFormMessage('')
    setFormError(false)

    const formData = new FormData()
    formData.set('csrf_token', app.csrf || '')
    formData.set('id', String(form.id || 0))
    formData.set('task_type_id', form.task_type_id)
    formData.set('workflow_template_id', form.workflow_template_id)
    formData.set('title', form.title)
    formData.set('description', form.description)
    formData.set('priority', form.priority)
    formData.set('flow_mode', form.flow_mode)
    formData.set('target_start_at', form.target_start_at)
    formData.set('target_due_at', form.target_due_at)
    formData.set('lead_user_id', form.lead_user_id)
    formData.set('project_id', form.project_id)
    formData.set('remarks', form.remarks)
    form.participant_user_ids.forEach((userId) => {
      formData.append('participant_user_ids[]', userId)
      formData.append(`participant_role_labels[${userId}]`, form.participant_role_labels[userId] || 'Contributor')
    })
    form.timeline_steps.forEach((step, index) => {
      formData.append(`timeline_steps[${index}][title]`, step.title)
      formData.append(`timeline_steps[${index}][division_id]`, step.division_id)
      formData.append(`timeline_steps[${index}][section_id]`, step.section_id)
      formData.append(`timeline_steps[${index}][responsible_user_id]`, step.responsible_user_id)
      formData.append(`timeline_steps[${index}][duration_working_days]`, step.duration_working_days)
    })

    try {
      const result = await saveTask(formData)
      setFormMessage(result.message || 'Task saved.')
      setFormError(false)
      window.setTimeout(() => window.location.reload(), 350)
    } catch (error) {
      setFormMessage(error instanceof Error ? error.message : 'Failed to save task.')
      setFormError(true)
    } finally {
      setModalBusy(false)
    }
  }

  async function submitTaskType() {
    if (modalReadOnly || taskTypeBusy) return
    setTaskTypeBusy(true)
    setTaskTypeMessage('')
    setTaskTypeError(false)

    const formData = new FormData()
    formData.set('csrf_token', app.csrf || '')
    formData.set('name', taskTypeForm.name)
    formData.set('description', taskTypeForm.description)
    formData.set('default_priority', taskTypeForm.default_priority)

    try {
      const result = await saveTaskType(formData)
      const createdType = result.task_type as TmsTaskType | null
      const createdWorkflow = result.workflow_template as TmsWorkflowTemplate | null
      if (!createdType || !createdWorkflow) {
        throw new Error('Task type was created but the server response was incomplete.')
      }

      setTaskTypes((current) => [...current.filter((entry) => entry.id !== createdType.id), createdType].sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)))
      setWorkflowTemplates((current) => [...current.filter((entry) => entry.id !== createdWorkflow.id), createdWorkflow])
      setForm((current) => ({
        ...current,
        task_type_id: String(createdType.id),
        workflow_template_id: String(createdWorkflow.id),
        priority: createdType.default_priority || current.priority,
        flow_mode: createdWorkflow.flow_mode || 'sequential',
      }))
      setTaskTypeForm(blankTaskTypeForm())
      setShowTaskTypeForm(false)
      setTaskTypeMessage(result.message || 'Task type created.')
      setTaskTypeError(false)
    } catch (error) {
      setTaskTypeMessage(error instanceof Error ? error.message : 'Failed to save task type.')
      setTaskTypeError(true)
    } finally {
      setTaskTypeBusy(false)
    }
  }

  async function removeTask(task: TmsTask) {
    if (!task.can_delete) return
    if (!window.confirm(`Delete "${task.title}" from Task Monitoring?`)) return
    try {
      await deleteTask(task.id)
      window.location.reload()
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Failed to delete task.')
    }
  }

  async function answerInvitation(taskId: number, responseValue: 'join' | 'decline') {
    try {
      await respondToInvitation(taskId, responseValue)
      window.location.reload()
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Failed to update invitation.')
    }
  }

  async function assignStep(stepId: number) {
    const userId = Number(stepAssignees[String(stepId)] || 0)
    if (stepId <= 0 || userId <= 0) return

    setAssigningStepId(stepId)
    try {
      await assignTaskStep(stepId, userId)
      window.location.reload()
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Failed to assign step.')
    } finally {
      setAssigningStepId(null)
    }
  }

  function changeViewMode(nextMode: TmsBootstrap['viewMode']) {
    if (nextMode === bootstrap.viewMode) return
    const params = new URLSearchParams(window.location.search)
    params.set('view_mode', nextMode)
    window.location.href = `${app.public}/task_monitoring.php?${params.toString()}`
  }

  function applyTaskTypeFilter(code: string) {
    startTransition(() => setFilters((current) => ({ ...current, type: code })))
  }

  const workflowLocked = form.id > 0

  return (
    <div className="tms-app">
      <div className="tms-wrap">
        <header className="tms-page-head">
          <div>
            <span className="tms-kicker">Task Monitoring</span>
            <h1>Task Registry</h1>
            <p>Track shared office work, participants, workflow steps, due dates, and outputs.</p>
          </div>
          <div className="tms-head-actions">
            <a className="tms-btn tms-btn-secondary" href={`${app.public}/documents.php`}>
              Open DTS
            </a>
            <button className="tms-btn tms-btn-primary" type="button" onClick={openCreateModal}>
              New Task
            </button>
          </div>
        </header>

        <section className="tms-summary-strip" aria-label="Task summary">
          <div className="tms-summary-cell">
            <span>Visible</span>
            <strong>{summary.total}</strong>
          </div>
          <div className="tms-summary-cell">
            <span>Open</span>
            <strong>{summary.open}</strong>
          </div>
          <div className="tms-summary-cell tone-soon">
            <span>Invites</span>
            <strong>{summary.invitations}</strong>
          </div>
          <div className="tms-summary-cell tone-overdue">
            <span>Overdue</span>
            <strong>{summary.overdue}</strong>
          </div>
          <div className="tms-summary-cell tone-done">
            <span>Completed</span>
            <strong>{summary.completed}</strong>
          </div>
        </section>

        <section className="tms-control-band">
          <div className="tms-segmented">
            <button type="button" className={bootstrap.viewMode === 'my' ? 'is-active' : ''} onClick={() => changeViewMode('my')}>
              My Tasks
            </button>
            <button type="button" className={bootstrap.viewMode === 'section' ? 'is-active' : ''} onClick={() => changeViewMode('section')}>
              Section
            </button>
            <button type="button" className={bootstrap.viewMode === 'division' ? 'is-active' : ''} onClick={() => changeViewMode('division')}>
              Division
            </button>
            {bootstrap.canManageAll ? (
              <button type="button" className={bootstrap.viewMode === 'all' ? 'is-active' : ''} onClick={() => changeViewMode('all')}>
                All
              </button>
            ) : null}
          </div>
          <div className="tms-viewer-line">
            <strong>{bootstrap.viewer.full_name}</strong>
            <span>{[bootstrap.viewer.section_name, bootstrap.viewer.division_name].filter(Boolean).join(' - ') || 'No office assigned'}</span>
          </div>
        </section>

        <section className="tms-tabs" aria-label="Task type filters">
          <button type="button" className={!filters.type ? 'is-active' : ''} onClick={() => applyTaskTypeFilter('')}>
            <span>All Types</span>
            <strong>{tasks.length}</strong>
          </button>
          {taskTypes.map((taskType) => (
            <button key={taskType.id} type="button" className={filters.type === taskType.code ? 'is-active' : ''} onClick={() => applyTaskTypeFilter(taskType.code)}>
              <span>{taskType.name}</span>
              <strong>{typeCounts.get(taskType.code) || 0}</strong>
            </button>
          ))}
        </section>

        <section className="tms-filter-strip">
          <label className="tms-field">
            <span>Search</span>
            <input value={filters.q} onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value }))} placeholder="Search task, step, participant, workflow" />
          </label>
          <label className="tms-field">
            <span>Status</span>
            <select value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}>
              <option value="">All statuses</option>
              {statusOptions.map((option) => (
                <option key={option} value={option}>
                  {statusLabel(option)}
                </option>
              ))}
            </select>
          </label>
        </section>

        <main className="tms-registry">
          {filteredTasks.length ? (
            filteredTasks.map((task) => (
              <article key={task.id} className={`tms-row tone-${toneForTask(task)}`}>
                <div className="tms-row-main">
                  <div className="tms-row-top">
                    <div>
                      <span className="tms-row-code">{task.task_type_name}</span>
                      <h2>{task.title}</h2>
                    </div>
                    <span className={`tms-badge tone-${toneForTask(task)}`}>{statusLabel(task.lifecycle_status)}</span>
                  </div>

                  <p className="tms-row-desc">{task.description || 'No description entered.'}</p>

                  <div className="tms-step-line">
                    <span>Current</span>
                    <strong>{task.current_step_title || 'No active step'}</strong>
                    <small>{responsibleText(task)}</small>
                  </div>

                  <div className="tms-row-meta">
                    <span>{task.workflow_template_name || 'No workflow'}</span>
                    <span>{task.flow_mode}</span>
                    <span>{task.owner_section_name || task.owner_division_name || 'No owner office'}</span>
                  </div>
                </div>

                <aside className="tms-row-side">
                  <div className="tms-row-stat">
                    <span>Timing</span>
                    <strong>{task.timing_label || 'No timing data'}</strong>
                    <small>Due {formatDateTime(task.target_due_at)}</small>
                  </div>
                  <div className="tms-row-stat">
                    <span>Participants</span>
                    <strong>{task.participants_text ? task.participants_text.split(',').length : 0}</strong>
                    <small>{task.participants_text || 'No participants listed'}</small>
                  </div>
                  {task.permissions?.is_invited ? (
                    <div className="tms-row-actions">
                      <button className="tms-btn tms-btn-primary" type="button" onClick={() => void answerInvitation(task.id, 'join')}>
                        Join
                      </button>
                      <button className="tms-btn tms-btn-secondary" type="button" onClick={() => void answerInvitation(task.id, 'decline')}>
                        Decline
                      </button>
                    </div>
                  ) : (
                    <div className="tms-row-actions">
                      <button className="tms-btn tms-btn-secondary" type="button" onClick={() => void openTask(task)}>
                        {task.can_edit ? 'Open' : 'View'}
                      </button>
                      {task.can_delete ? (
                        <button className="tms-btn tms-btn-ghost" type="button" onClick={() => void removeTask(task)}>
                          Delete
                        </button>
                      ) : null}
                    </div>
                  )}
                </aside>
              </article>
            ))
          ) : (
            <div className="tms-empty spacious">No tasks match the current filters.</div>
          )}
        </main>
      </div>

      {modalOpen ? (
        <div className="tms-modal-shell" role="dialog" aria-modal="true">
          <div className="tms-modal-backdrop" onClick={() => setModalOpen(false)} />
          <div className="tms-modal">
            <div className="tms-modal-head">
              <div>
                <span className="tms-section-kicker">{form.id ? `Task #${form.id}` : 'New Task'}</span>
                <h2>{form.id ? (modalReadOnly ? 'Task Details' : 'Update Task') : 'Create Task'}</h2>
                {detail?.timing?.label ? <p>{detail.timing.label}</p> : null}
              </div>
              <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setModalOpen(false)}>
                Close
              </button>
            </div>

            <form className="tms-modal-body" onSubmit={(event) => void submitTask(event)}>
              <section className="tms-form-section">
                <div className="tms-form-section-head">
                  <div>
                    <span className="tms-section-kicker">A</span>
                    <h3>Task Details</h3>
                  </div>
                </div>
                <div className="tms-form-grid">
                  <label className="tms-field">
                    <span>Task type</span>
                    <select value={form.task_type_id} onChange={(event) => selectTaskType(event.target.value)} disabled={modalBusy || modalReadOnly || workflowLocked} required>
                      {taskTypes.map((taskType) => (
                        <option key={taskType.id} value={taskType.id}>
                          {taskType.name}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label className="tms-field">
                    <span>Priority</span>
                    <select value={form.priority} onChange={(event) => updateField('priority', event.target.value)} disabled={modalBusy || modalReadOnly}>
                      <option value="low">Low</option>
                      <option value="normal">Normal</option>
                      <option value="high">High</option>
                      <option value="urgent">Urgent</option>
                    </select>
                  </label>

                  {!workflowLocked && !modalReadOnly ? (
                    <div className="tms-task-type-action span-2">
                      <button className="tms-link-button" type="button" onClick={() => setShowTaskTypeForm((current) => !current)} disabled={modalBusy || taskTypeBusy}>
                        {showTaskTypeForm ? 'Cancel new task type' : 'Add new task type'}
                      </button>
                    </div>
                  ) : null}

                  {showTaskTypeForm && !workflowLocked && !modalReadOnly ? (
                    <section className="tms-inline-panel span-2" aria-label="New task type">
                      <div className="tms-inline-panel-head">
                        <div>
                          <span className="tms-section-kicker">Task Type</span>
                          <h3>New Task Type</h3>
                        </div>
                      </div>
                      <div className="tms-inline-grid">
                        <label className="tms-field">
                          <span>Name</span>
                          <input value={taskTypeForm.name} onChange={(event) => setTaskTypeForm((current) => ({ ...current, name: event.target.value }))} disabled={taskTypeBusy || modalBusy} placeholder="Example: POW and Plan" />
                        </label>
                        <label className="tms-field">
                          <span>Default priority</span>
                          <select value={taskTypeForm.default_priority} onChange={(event) => setTaskTypeForm((current) => ({ ...current, default_priority: event.target.value }))} disabled={taskTypeBusy || modalBusy}>
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                          </select>
                        </label>
                        <label className="tms-field span-2">
                          <span>Description</span>
                          <textarea rows={2} value={taskTypeForm.description} onChange={(event) => setTaskTypeForm((current) => ({ ...current, description: event.target.value }))} disabled={taskTypeBusy || modalBusy} placeholder="Describe when this task type should be used." />
                        </label>
                      </div>
                      {taskTypeMessage ? <div className={`tms-form-message ${taskTypeError ? 'is-error' : 'is-ok'}`}>{taskTypeMessage}</div> : null}
                      <div className="tms-inline-actions">
                        <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setShowTaskTypeForm(false)} disabled={taskTypeBusy}>
                          Cancel
                        </button>
                        <button className="tms-btn tms-btn-primary" type="button" onClick={() => void submitTaskType()} disabled={taskTypeBusy || modalBusy || !taskTypeForm.name.trim()}>
                          {taskTypeBusy ? 'Saving...' : 'Save Type'}
                        </button>
                      </div>
                    </section>
                  ) : null}

                  <label className="tms-field tms-title-field span-2">
                    <span>Title</span>
                    <input value={form.title} onChange={(event) => updateField('title', event.target.value)} disabled={modalBusy || modalReadOnly} required placeholder="Example: Prepare POW and Plan" />
                  </label>

                  <label className="tms-field span-2">
                    <span>Description</span>
                    <textarea rows={4} value={form.description} onChange={(event) => updateField('description', event.target.value)} disabled={modalBusy || modalReadOnly} placeholder="Describe the task, expected output, or instructions..." />
                  </label>
                </div>
              </section>

              <section className="tms-form-section">
                <div className="tms-form-section-head">
                  <div>
                    <span className="tms-section-kicker">B</span>
                    <h3>Schedule</h3>
                  </div>
                </div>
                <div className="tms-form-grid">
                  <label className="tms-field">
                    <span>Target start</span>
                    <input type="datetime-local" value={form.target_start_at} onChange={(event) => updateField('target_start_at', event.target.value)} disabled={modalBusy || modalReadOnly || workflowLocked} required={!workflowLocked} />
                  </label>

                  <label className="tms-field tms-calculated-field">
                    <span>Estimated completion</span>
                    <input type="datetime-local" value={form.target_due_at} onChange={(event) => updateField('target_due_at', event.target.value)} disabled={modalBusy || modalReadOnly || !workflowLocked} readOnly={!workflowLocked} />
                    <small className="tms-help-text">{workflowLocked ? 'Computed from the saved task timeline.' : 'Calculated from the subtask timeline after saving.'}</small>
                  </label>
                </div>
              </section>

              <section className="tms-form-section">
                <div className="tms-section-head">
                  <div>
                    <span className="tms-section-kicker">C</span>
                    <h3>Subtasks / Timeline</h3>
                  </div>
                  {!workflowLocked && !modalReadOnly ? (
                    <button className="tms-btn tms-btn-secondary" type="button" onClick={addTimelineStep} disabled={modalBusy}>
                      Add Subtask
                    </button>
                  ) : null}
                </div>
                <div className="tms-timeline-head" aria-hidden="true">
                  <span>No.</span>
                  <span>Subtask</span>
                  <span>Division</span>
                  <span>Section</span>
                  <span>Employee</span>
                  <span>Working Days</span>
                  <span>Remove</span>
                </div>
                <div className="tms-timeline-list">
                  {form.timeline_steps.map((step, index) => {
                    const rowSections = sectionsForDivision(step.division_id)
                    const rowUsers = usersForStep(step)
                    return (
                      <div key={index} className="tms-timeline-row">
                        <div className="tms-timeline-index">{index + 1}</div>
                        <label className="tms-field tms-timeline-title">
                          <span>Subtask</span>
                          <input value={step.title} onChange={(event) => updateTimelineStep(index, { title: event.target.value })} disabled={modalBusy || modalReadOnly || workflowLocked} required={!workflowLocked} placeholder="Example: Prepare POW and plan draft" />
                        </label>
                        <label className="tms-field">
                          <span>Division</span>
                          <select value={step.division_id} onChange={(event) => updateTimelineStep(index, { division_id: event.target.value })} disabled={modalBusy || modalReadOnly || workflowLocked} required={!workflowLocked}>
                            <option value="">Select division</option>
                            {divisionOptions.map((division) => (
                              <option key={division.id} value={division.id}>
                                {division.name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Section</span>
                          <select value={step.section_id} onChange={(event) => updateTimelineStep(index, { section_id: event.target.value })} disabled={modalBusy || modalReadOnly || workflowLocked || !step.division_id}>
                            <option value="">No specific section</option>
                            {rowSections.map((section) => (
                              <option key={section.id} value={section.id}>
                                {section.name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Employee</span>
                          <select value={step.responsible_user_id} onChange={(event) => updateTimelineStep(index, { responsible_user_id: event.target.value })} disabled={modalBusy || modalReadOnly || workflowLocked || !step.division_id}>
                            <option value="">Chief assigns later</option>
                            {rowUsers.map((user) => (
                              <option key={user.id} value={user.id}>
                                {user.full_name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field tms-duration-field">
                          <span>Working days</span>
                          <input type="number" min="1" step="1" value={step.duration_working_days} onChange={(event) => updateTimelineStep(index, { duration_working_days: event.target.value })} disabled={modalBusy || modalReadOnly || workflowLocked} required={!workflowLocked} />
                        </label>
                        {!workflowLocked && !modalReadOnly ? (
                          <button className="tms-remove-user" type="button" onClick={() => removeTimelineStep(index)} disabled={modalBusy || form.timeline_steps.length <= 1} aria-label={`Remove subtask ${index + 1}`}>
                            x
                          </button>
                        ) : (
                          <span className="tms-timeline-remove-placeholder" aria-hidden="true" />
                        )}
                      </div>
                    )
                  })}
                </div>
                <div className="tms-timeline-summary">
                  <strong>{totalWorkingDays} working day{totalWorkingDays === 1 ? '' : 's'}</strong>
                  <span>Final completion date is computed by the server using the working calendar.</span>
                </div>
              </section>

              <section className="tms-form-section">
                <div className="tms-form-section-head">
                  <div>
                    <span className="tms-section-kicker">D</span>
                    <h3>Remarks</h3>
                  </div>
                </div>
                <label className="tms-field">
                  <span>Remarks</span>
                  <textarea rows={4} value={form.remarks} onChange={(event) => updateField('remarks', event.target.value)} disabled={modalBusy || modalReadOnly} placeholder="Optional notes, reminders, or special instructions..." />
                </label>
              </section>

              {detail?.steps?.length ? (
                <section className="tms-detail-section">
                  <div className="tms-section-head">
                    <div>
                      <span className="tms-section-kicker">Workflow Steps</span>
                      <h3>Step Status</h3>
                    </div>
                  </div>
                  <div className="tms-step-list">
                    {detail.steps.map((step) => (
                      <div key={step.id} className="tms-step-item">
                        <span>{step.step_order}</span>
                        <div>
                          <strong>{step.title}</strong>
                          <small>
                            {statusLabel(step.status)} - {step.responsible_user_name || step.responsible_section_name || step.responsible_division_name || 'No responsible party'}
                          </small>
                        </div>
                        {canAssignSavedStep(step) ? (
                          <div className="tms-step-assign">
                            <select value={stepAssignees[String(step.id)] || ''} onChange={(event) => setStepAssignees((current) => ({ ...current, [String(step.id)]: event.target.value }))} disabled={assigningStepId === step.id}>
                              <option value="">Select employee</option>
                              {usersForSavedStep(step).map((user) => (
                                <option key={user.id} value={user.id}>
                                  {user.full_name}
                                </option>
                              ))}
                            </select>
                            <button className="tms-btn tms-btn-secondary" type="button" onClick={() => void assignStep(step.id)} disabled={assigningStepId === step.id || !stepAssignees[String(step.id)]}>
                              Assign
                            </button>
                          </div>
                        ) : null}
                      </div>
                    ))}
                  </div>
                </section>
              ) : null}

              {detail?.participants?.length ? (
                <section className="tms-detail-section">
                  <div className="tms-section-head">
                    <div>
                      <span className="tms-section-kicker">Participants</span>
                      <h3>Invitation Status</h3>
                    </div>
                  </div>
                  <div className="tms-participant-list">
                    {detail.participants.map((participant) => (
                      <span key={participant.id} className="tms-participant-pill">
                        <strong>{participant.full_name}</strong>
                        <small>{participant.participant_role_label} - {statusLabel(participant.participation_status)}</small>
                      </span>
                    ))}
                  </div>
                </section>
              ) : null}

              {formMessage ? <div className={`tms-form-message ${formError ? 'is-error' : 'is-ok'}`}>{formMessage}</div> : null}

              <div className="tms-modal-actions">
                <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setModalOpen(false)} disabled={modalBusy}>
                  Cancel
                </button>
                {!modalReadOnly ? (
                  <button className="tms-btn tms-btn-primary" type="submit" disabled={modalBusy}>
                    {modalBusy ? 'Saving...' : 'Save Task'}
                  </button>
                ) : null}
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  )
}
