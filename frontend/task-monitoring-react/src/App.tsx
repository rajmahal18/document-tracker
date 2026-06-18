import { startTransition, useDeferredValue, useMemo, useState, type FormEvent } from 'react'
import { deleteTask, fetchTaskDetail, getAppConfig, getBootstrapData, saveTask } from './lib/app-bridge'
import './index.css'
import type { TmsBootstrap, TmsFilters, TmsProject, TmsTask, TmsTaskDetail, TmsTaskPermissions, TmsTaskType, TmsUser } from './types'

type FormState = {
  id: number
  task_type_id: string
  project_id: string
  project_code: string
  project_title: string
  description: string
  deo: string
  lgu: string
  assignee_user_ids: string[]
  date_surveyed: string
  date_received: string
  date_started: string
  target_completion: string
  progress_percent: string
  reference_code: string
  remarks: string
}

const bootstrap: TmsBootstrap = getBootstrapData()
const app = getAppConfig()

const defaultForm = (taskTypeId = ''): FormState => ({
  id: 0,
  task_type_id: taskTypeId,
  project_id: '',
  project_code: '',
  project_title: '',
  description: '',
  deo: '',
  lgu: '',
  assignee_user_ids: [],
  date_surveyed: '',
  date_received: '',
  date_started: '',
  target_completion: '',
  progress_percent: '',
  reference_code: '',
  remarks: '',
})

function formatDate(value?: string | null) {
  if (!value) return 'No date'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'No date'
  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date)
}

function formatDateTime(value?: string | null) {
  if (!value) return 'No update'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'No update'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
}

function formatProgress(value?: number | null) {
  if (value === null || value === undefined) return 'No progress'
  return `${Number(value).toFixed(value % 1 === 0 ? 0 : 1)}%`
}

function statusTone(task: TmsTask) {
  const status = task.status_label.toLowerCase()
  if (status.includes('complete') || status.includes('submitted')) return 'done'
  if ((task.remaining_workdays ?? 0) < 0) return 'overdue'
  if (task.remaining_workdays !== null && task.remaining_workdays !== undefined && task.remaining_workdays <= 3) return 'soon'
  if (status.includes('review') || status.includes('sign')) return 'review'
  return 'open'
}

function workdayLabel(value?: number | null) {
  if (value === null || value === undefined) return 'No workday target'
  if (value < 0) return `${Math.abs(value)} workdays overdue`
  return `${value} workdays left`
}

function matchesTask(task: TmsTask, filters: TmsFilters) {
  if (filters.type && task.task_type_code !== filters.type) return false
  if (filters.status && task.status_label !== filters.status) return false
  if (!filters.q) return true
  const q = filters.q.toLowerCase()
  const blob = [
    task.project_code,
    task.project_title,
    task.description,
    task.deo,
    task.lgu,
    task.assignees_text,
    task.assignee_display,
    task.task_type_name,
  ]
    .join(' ')
    .toLowerCase()
  return blob.includes(q)
}

function typeConfig(taskTypes: TmsTaskType[], idOrCode: string | number | undefined) {
  return taskTypes.find((type) => String(type.id) === String(idOrCode) || type.code === String(idOrCode))
}

function createTaskPermissions(currentType: TmsTaskType | undefined, assigneeUserIds: string[]): TmsTaskPermissions {
  const usesProtectedRules = currentType?.workflow_rule === 'progress_remaining'
  const leadAssigneeId = Number(assigneeUserIds[0] || 0)
  const isPrimaryAssignee = bootstrap.canManageAll || (leadAssigneeId > 0 && leadAssigneeId === bootstrap.viewer.id)

  return {
    can_edit_task: true,
    can_delete_task: false,
    can_edit_protected_fields: true,
    can_edit_progress: !usesProtectedRules || isPrimaryAssignee,
    uses_protected_rules: usesProtectedRules,
    is_creator: true,
    is_owner: false,
    is_assignee: assigneeUserIds.includes(String(bootstrap.viewer.id)),
    is_primary_assignee: isPrimaryAssignee,
  }
}

function sortFocusTasks(tasks: TmsTask[]) {
  return [...tasks]
    .sort((a, b) => {
      const toneOrder = { overdue: 0, soon: 1, review: 2, open: 3, done: 4 } as const
      const aTone = toneOrder[statusTone(a)]
      const bTone = toneOrder[statusTone(b)]
      if (aTone !== bTone) return aTone - bTone
      const aRemaining = a.remaining_workdays ?? 999999
      const bRemaining = b.remaining_workdays ?? 999999
      if (aRemaining !== bRemaining) return aRemaining - bRemaining
      return new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime()
    })
    .slice(0, 5)
}

export default function App() {
  const [tasks] = useState<TmsTask[]>(bootstrap.tasks)
  const [filters, setFilters] = useState<TmsFilters>(bootstrap.filters)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalBusy, setModalBusy] = useState(false)
  const [modalReadOnly, setModalReadOnly] = useState(false)
  const [modalTitle, setModalTitle] = useState('Create task')
  const [formMessage, setFormMessage] = useState('')
  const [formError, setFormError] = useState(false)
  const [form, setForm] = useState<FormState>(defaultForm(bootstrap.filters.type ? String(typeConfig(bootstrap.taskTypes, bootstrap.filters.type)?.id ?? '') : ''))
  const [activeTaskId, setActiveTaskId] = useState<number | null>(null)
  const [activeTaskPermissions, setActiveTaskPermissions] = useState<TmsTaskPermissions | null>(null)

  const deferredQuery = useDeferredValue(filters.q)
  const effectiveFilters = useMemo<TmsFilters>(() => ({ ...filters, q: deferredQuery }), [filters, deferredQuery])

  const filteredTasks = useMemo(() => tasks.filter((task) => matchesTask(task, effectiveFilters)), [tasks, effectiveFilters])
  const focusTasks = useMemo(() => sortFocusTasks(filteredTasks), [filteredTasks])
  const recentTasks = useMemo(
    () => [...filteredTasks].sort((a, b) => new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime()).slice(0, 6),
    [filteredTasks],
  )

  const statusCounts = useMemo(() => {
    const counts = new Map<string, number>()
    for (const task of filteredTasks) {
      counts.set(task.status_label, (counts.get(task.status_label) || 0) + 1)
    }
    return [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5)
  }, [filteredTasks])

  const typeCounts = useMemo(() => {
    const counts = new Map<string, number>()
    for (const task of tasks) {
      counts.set(task.task_type_code, (counts.get(task.task_type_code) || 0) + 1)
    }
    return counts
  }, [tasks])

  const summary = useMemo(() => {
    const total = filteredTasks.length
    const closed = filteredTasks.filter((task) => {
      const status = task.status_label
      return status === 'Completed/Approved' || status === 'Submitted'
    }).length
    const overdue = filteredTasks.filter((task) => (task.remaining_workdays ?? 0) < 0).length
    const dueSoon = filteredTasks.filter((task) => {
      const remaining = task.remaining_workdays
      return remaining !== null && remaining !== undefined && remaining >= 0 && remaining <= 3 && statusTone(task) !== 'done'
    }).length
    return {
      total,
      open: total - closed,
      closed,
      overdue,
      dueSoon,
    }
  }, [filteredTasks])

  const currentType = typeConfig(bootstrap.taskTypes, form.task_type_id)
  const formPermissions = activeTaskPermissions ?? createTaskPermissions(currentType, form.assignee_user_ids)
  const dashboardDate = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric' }).format(new Date())
  const activeTypeLabel = filters.type ? bootstrap.taskTypes.find((type) => type.code === filters.type)?.name || 'Filtered type' : 'All task types'
  const protectedFieldsLocked = !modalReadOnly && activeTaskId !== null && !formPermissions.can_edit_protected_fields
  const progressLocked = !modalReadOnly && !formPermissions.can_edit_progress
  const progressHelp = currentType?.show_progress
    ? formPermissions.uses_protected_rules
      ? (formPermissions.is_primary_assignee || bootstrap.canManageAll
          ? 'Progress follows the lead assignee for this workflow.'
          : 'Only the lead assignee can update progress for this workflow.')
      : 'Progress is editable by assigned operators.'
    : ''
  const protectedHelp = protectedFieldsLocked
    ? 'Core task details are locked here. Only the lead assignee or a protected editor can change them after creation.'
    : ''

  function updateField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function openCreateModal() {
    const preferredType = bootstrap.filters.type ? String(typeConfig(bootstrap.taskTypes, bootstrap.filters.type)?.id ?? '') : ''
    setForm(defaultForm(preferredType))
    setModalTitle('Create task')
    setModalReadOnly(false)
    setFormMessage('')
    setFormError(false)
    setActiveTaskId(null)
    setActiveTaskPermissions(null)
    setModalOpen(true)
  }

  async function openTask(task: TmsTask) {
    setModalBusy(true)
    setModalOpen(true)
    setModalTitle('Loading task...')
    setFormMessage('')
    setFormError(false)
    try {
      const detail = await fetchTaskDetail(task.id)
      hydrateForm(detail)
      setActiveTaskPermissions(detail.permissions)
      setModalTitle(detail.can_edit ? 'Update task' : 'Task details')
      setModalReadOnly(!detail.can_edit)
      setActiveTaskId(detail.id)
    } catch (error) {
      setFormMessage(error instanceof Error ? error.message : 'Failed to load task.')
      setFormError(true)
    } finally {
      setModalBusy(false)
    }
  }

  function hydrateForm(task: TmsTaskDetail) {
    setForm({
      id: task.id,
      task_type_id: String(task.task_type_id || ''),
      project_id: task.project_id ? String(task.project_id) : '',
      project_code: task.project_code || '',
      project_title: task.project_title || '',
      description: task.description || '',
      deo: task.deo || '',
      lgu: task.lgu || '',
      assignee_user_ids: (task.assignee_user_ids || []).map((id) => String(id)),
      date_surveyed: (task.date_surveyed || '').slice(0, 10),
      date_received: (task.date_received || '').slice(0, 10),
      date_started: (task.date_started || '').slice(0, 10),
      target_completion: (task.target_completion || '').slice(0, 10),
      progress_percent: task.progress_percent === null || task.progress_percent === undefined ? '' : String(task.progress_percent),
      reference_code: task.reference_code || '',
      remarks: task.remarks || '',
    })
  }

  function onSelectProject(projectId: string) {
    const project = bootstrap.projects.find((entry) => String(entry.id) === projectId)
    setForm((current) => ({
      ...current,
      project_id: projectId,
      project_code: current.project_code || project?.project_code || '',
      project_title: current.project_title || project?.title || '',
    }))
  }

  function onToggleAssignee(userId: string) {
    setForm((current) => {
      const exists = current.assignee_user_ids.includes(userId)
      return {
        ...current,
        assignee_user_ids: exists
          ? current.assignee_user_ids.filter((id) => id !== userId)
          : [...current.assignee_user_ids, userId],
      }
    })
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
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
    formData.set('project_id', form.project_id)
    formData.set('project_code', form.project_code)
    formData.set('project_title', form.project_title)
    formData.set('description', form.description)
    formData.set('deo', form.deo)
    formData.set('lgu', form.lgu)
    formData.set('date_surveyed', form.date_surveyed)
    formData.set('date_received', form.date_received)
    formData.set('date_started', form.date_started)
    formData.set('target_completion', form.target_completion)
    formData.set('progress_percent', form.progress_percent)
    formData.set('reference_code', form.reference_code)
    formData.set('remarks', form.remarks)
    form.assignee_user_ids.forEach((userId) => formData.append('assignee_user_ids[]', userId))

    try {
      const result = await saveTask(formData)
      setFormMessage(result.message || 'Task saved.')
      setFormError(false)
      window.setTimeout(() => window.location.reload(), 420)
    } catch (error) {
      setFormMessage(error instanceof Error ? error.message : 'Failed to save task.')
      setFormError(true)
    } finally {
      setModalBusy(false)
    }
  }

  async function onDelete(task: TmsTask) {
    if (!task.can_delete) return
    if (!window.confirm(`Delete ${task.project_code}? This only removes the TMS record.`)) return

    try {
      await deleteTask(task.id)
      window.location.reload()
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Failed to delete task.')
    }
  }

  function changeViewMode(nextMode: 'my' | 'all') {
    if (nextMode === bootstrap.viewMode) return
    const params = new URLSearchParams(window.location.search)
    params.set('view_mode', nextMode)
    window.location.href = `${app.public}/task_monitoring.php?${params.toString()}`
  }

  function applyTaskTypeFilter(code: string) {
    startTransition(() => {
      setFilters((current) => ({ ...current, type: code }))
    })
  }

  return (
    <div className="tms-app">
      <div className="tms-bg-grid" aria-hidden="true" />

      <div className="tms-wrap">
        <section className="tms-hero">
          <div className="tms-hero-copy">
            <div className="tms-kicker-row">
              <span className="tms-kicker">Task Monitoring System</span>
              <span className="tms-date-pill">{dashboardDate}</span>
            </div>

            <h1>Task execution, clearer at a glance.</h1>
            <p>
              A lighter operator workspace for plans, surveys, reviews, and reports. Routing stays in DTS. Monitoring,
              ownership, and follow-through stay here.
            </p>

            <div className="tms-hero-pulses">
              <div className="tms-pulse tone-overdue">
                <span>Need attention</span>
                <strong>{summary.overdue}</strong>
              </div>
              <div className="tms-pulse tone-soon">
                <span>Due soon</span>
                <strong>{summary.dueSoon}</strong>
              </div>
              <div className="tms-pulse tone-done">
                <span>Closed in view</span>
                <strong>{summary.closed}</strong>
              </div>
            </div>
          </div>

          <aside className="tms-hero-rail">
            <div className="tms-hero-actions">
              <a className="tms-btn tms-btn-secondary" href={`${app.public}/documents.php`}>
                Switch to Document Tracking
              </a>
              <button className="tms-btn tms-btn-primary" type="button" onClick={openCreateModal}>
                + New Task
              </button>
            </div>

            <div className="tms-rail-panel">
              <span className="tms-panel-label">Operator</span>
              <strong>{bootstrap.viewer.full_name}</strong>
              <p>{[bootstrap.viewer.official_title || 'Task operator', bootstrap.viewer.division_name].filter(Boolean).join(' · ')}</p>
            </div>

            <div className="tms-rail-panel accent">
              <span className="tms-panel-label">Workspace mode</span>
              <div className="tms-segmented">
                <button
                  type="button"
                  className={bootstrap.viewMode === 'my' ? 'is-active' : ''}
                  onClick={() => changeViewMode('my')}
                >
                  My queue
                </button>
                {bootstrap.canManageAll ? (
                  <button
                    type="button"
                    className={bootstrap.viewMode === 'all' ? 'is-active' : ''}
                    onClick={() => changeViewMode('all')}
                  >
                    All tasks
                  </button>
                ) : null}
              </div>
              <p>{bootstrap.canManageAll ? 'Switch personal and full monitoring views.' : 'Your current task visibility is scoped to your own work.'}</p>
            </div>
          </aside>
        </section>

        <section className="tms-summary-strip" aria-label="Summary metrics">
          <div className="tms-summary-cell">
            <div className="tms-summary-head">
              <i className="tms-summary-icon tone-open" aria-hidden="true" />
              <span>Visible tasks</span>
            </div>
            <strong>{summary.total}</strong>
            <small>Current filtered dataset</small>
          </div>
          <div className="tms-summary-cell tone-open">
            <div className="tms-summary-head">
              <i className="tms-summary-icon tone-open" aria-hidden="true" />
              <span>Open now</span>
            </div>
            <strong>{summary.open}</strong>
            <small>Needs action or follow-through</small>
          </div>
          <div className="tms-summary-cell tone-soon">
            <div className="tms-summary-head">
              <i className="tms-summary-icon tone-soon" aria-hidden="true" />
              <span>Due soon</span>
            </div>
            <strong>{summary.dueSoon}</strong>
            <small>0 to 3 workdays left</small>
          </div>
          <div className="tms-summary-cell tone-overdue">
            <div className="tms-summary-head">
              <i className="tms-summary-icon tone-overdue" aria-hidden="true" />
              <span>Overdue</span>
            </div>
            <strong>{summary.overdue}</strong>
            <small>Past target completion</small>
          </div>
          <div className="tms-summary-cell tone-done">
            <div className="tms-summary-head">
              <i className="tms-summary-icon tone-done" aria-hidden="true" />
              <span>Closed</span>
            </div>
            <strong>{summary.closed}</strong>
            <small>Completed or submitted</small>
          </div>
        </section>

        <section className="tms-command-deck">
          <div className="tms-command-main">
            <div className="tms-command-head">
              <div>
                <span className="tms-section-kicker">Workspace lens</span>
                <h2>{activeTypeLabel}</h2>
              </div>
              <div className="tms-command-pills">
                <span>{bootstrap.viewMode === 'all' ? 'Division-wide monitoring' : 'Personal queue view'}</span>
                <span>{summary.total} records live</span>
              </div>
            </div>
            <p>
              Use this page as the working registry: find priorities fast, narrow the list by type, and open records
              without leaving the task flow.
            </p>
          </div>

          <div className="tms-command-actions">
            <button className="tms-btn tms-btn-secondary" type="button" onClick={openCreateModal}>
              Create task
            </button>
            <a className="tms-btn tms-btn-secondary" href={`${app.public}/documents.php`}>
              Open DTS
            </a>
          </div>
        </section>

        <section className="tms-dashboard">
          <section className="tms-panel">
            <div className="tms-section-head">
              <div>
                <span className="tms-section-kicker">Priority board</span>
                <h2>What needs eyes first</h2>
              </div>
              <span className="tms-section-meta">{focusTasks.length} highlighted</span>
            </div>

            <div className="tms-focus-list">
              {focusTasks.length ? (
                focusTasks.map((task) => (
                  <button key={task.id} type="button" className={`tms-focus-item tone-${statusTone(task)}`} onClick={() => void openTask(task)}>
                    <div className="tms-focus-top">
                      <span>{task.task_type_name}</span>
                      <span className={`tms-badge tone-${statusTone(task)}`}>{task.status_label}</span>
                    </div>
                    <strong>{task.project_code}</strong>
                    <p>{task.project_title}</p>
                    <small>{workdayLabel(task.remaining_workdays)}</small>
                  </button>
                ))
              ) : (
                <div className="tms-empty">No urgent tasks in this view yet.</div>
              )}
            </div>
          </section>

          <div className="tms-rail-stack">
            <section className="tms-panel">
              <div className="tms-section-head">
                <div>
                  <span className="tms-section-kicker">Status mix</span>
                  <h2>Current breakdown</h2>
                </div>
              </div>

              <div className="tms-status-stack">
                {statusCounts.length ? (
                  statusCounts.map(([label, count]) => (
                    <div className="tms-status-row" key={label}>
                      <div className="tms-status-meta">
                        <strong>{label}</strong>
                        <span>{count}</span>
                      </div>
                      <div className="tms-status-bar">
                        <span style={{ width: `${Math.max(8, Math.round((count / Math.max(1, summary.total)) * 100))}%` }} />
                      </div>
                    </div>
                  ))
                ) : (
                  <div className="tms-empty small">No status data yet.</div>
                )}
              </div>
            </section>

            <section className="tms-panel">
              <div className="tms-section-head">
                <div>
                  <span className="tms-section-kicker">Recent movement</span>
                  <h2>Latest updates</h2>
                </div>
              </div>

              <div className="tms-recent-list">
                {recentTasks.length ? (
                  recentTasks.map((task) => (
                    <button key={task.id} type="button" className="tms-recent-item" onClick={() => void openTask(task)}>
                      <strong>{task.project_code}</strong>
                      <span>{task.task_type_name}</span>
                      <small>{formatDateTime(task.updated_at)}</small>
                    </button>
                  ))
                ) : (
                  <div className="tms-empty small">No updates yet.</div>
                )}
              </div>
            </section>
          </div>
        </section>

        <section className="tms-tabs" aria-label="Task type filters">
          <button type="button" className={!filters.type ? 'is-active' : ''} onClick={() => applyTaskTypeFilter('')}>
            <span>All types</span>
            <strong>{tasks.length}</strong>
          </button>
          {bootstrap.taskTypes.map((taskType) => (
            <button
              key={taskType.id}
              type="button"
              className={filters.type === taskType.code ? 'is-active' : ''}
              onClick={() => applyTaskTypeFilter(taskType.code)}
            >
              <span>{taskType.name}</span>
              <strong>{typeCounts.get(taskType.code) || 0}</strong>
            </button>
          ))}
        </section>

        <section className="tms-filter-strip">
          <label className="tms-field">
            <span>Search</span>
            <input
              type="text"
              value={filters.q}
              onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value }))}
              placeholder="Search by code, title, task, DEO, LGU"
            />
          </label>

          <label className="tms-field">
            <span>Status</span>
            <select value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}>
              <option value="">All statuses</option>
              {bootstrap.statusOptions.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </label>

          <div className="tms-filter-chip">
            <span>In this view</span>
            <strong>{filteredTasks.length} tasks</strong>
          </div>
        </section>

        <section className="tms-panel">
          <div className="tms-section-head">
            <div>
              <span className="tms-section-kicker">Working registry</span>
              <h2>Operational queue</h2>
            </div>
            <span className="tms-section-meta">{filteredTasks.length} items</span>
          </div>

          <div className="tms-queue">
            {filteredTasks.length ? (
              filteredTasks.map((task) => (
                <article key={task.id} className="tms-row">
                  <div className="tms-row-main">
                    <div className="tms-row-top">
                      <div>
                        <div className="tms-row-code">{task.project_code}</div>
                        <h3>{task.project_title}</h3>
                      </div>
                      <span className={`tms-badge tone-${statusTone(task)}`}>{task.status_label}</span>
                    </div>

                    <p className="tms-row-desc">{task.description}</p>

                    <div className="tms-row-meta">
                      <span>{task.task_type_name}</span>
                      <span>{task.assignees_text || task.assignee_display || 'Unassigned'}</span>
                      <span>{task.deo || task.lgu ? [task.deo, task.lgu].filter(Boolean).join(' / ') : 'No DEO or LGU yet'}</span>
                    </div>
                  </div>

                  <div className="tms-row-side">
                    <div className="tms-row-stat">
                      <span>Target</span>
                      <strong>{formatDate(task.target_completion)}</strong>
                      <small>{workdayLabel(task.remaining_workdays)}</small>
                    </div>
                    <div className="tms-row-stat">
                      <span>Progress</span>
                      <strong>{formatProgress(task.progress_percent)}</strong>
                      <small>Updated {formatDateTime(task.updated_at)}</small>
                    </div>
                    <div className="tms-row-actions">
                      <button className="tms-btn tms-btn-secondary" type="button" onClick={() => void openTask(task)}>
                        {task.can_edit ? 'Open' : 'View'}
                      </button>
                      {task.can_delete ? (
                        <button className="tms-btn tms-btn-ghost" type="button" onClick={() => void onDelete(task)}>
                          Delete
                        </button>
                      ) : null}
                    </div>
                  </div>
                </article>
              ))
            ) : (
              <div className="tms-empty spacious">No tasks match the current filters.</div>
            )}
          </div>
        </section>
      </div>

      {modalOpen ? (
        <div className="tms-modal-shell" role="dialog" aria-modal="true">
          <div className="tms-modal-backdrop" onClick={() => setModalOpen(false)} />
          <div className="tms-modal">
            <div className="tms-modal-head">
              <div>
                <span className="tms-section-kicker">{activeTaskId ? `Task #${activeTaskId}` : 'New monitoring record'}</span>
                <h2>{modalTitle}</h2>
                <p>Keep workload tracking inside TMS while DTS document routing stays separate.</p>
              </div>
              <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setModalOpen(false)}>
                Close
              </button>
            </div>

            <form className="tms-modal-body" onSubmit={(event) => void onSubmit(event)}>
              <div className="tms-form-grid">
                <label className="tms-field">
                  <span>Task type</span>
                  <select
                    value={form.task_type_id}
                    onChange={(event) => updateField('task_type_id', event.target.value)}
                    disabled={modalBusy || modalReadOnly || protectedFieldsLocked}
                    required
                  >
                    <option value="">Select task type</option>
                    {bootstrap.taskTypes.map((taskType) => (
                      <option key={taskType.id} value={taskType.id}>
                        {taskType.name}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="tms-field">
                  <span>Linked project</span>
                  <select value={form.project_id} onChange={(event) => onSelectProject(event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked}>
                    <option value="">None</option>
                    {bootstrap.projects.map((project: TmsProject) => (
                      <option key={project.id} value={project.id}>
                        {project.project_code} - {project.title}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="tms-field">
                  <span>Project code</span>
                  <input value={form.project_code} onChange={(event) => updateField('project_code', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} required />
                </label>

                <label className="tms-field">
                  <span>Project title</span>
                  <input value={form.project_title} onChange={(event) => updateField('project_title', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} required />
                </label>

                <label className="tms-field span-2">
                  <span>Description</span>
                  <input value={form.description} onChange={(event) => updateField('description', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} required />
                </label>

                <label className="tms-field">
                  <span>DEO</span>
                  <input value={form.deo} onChange={(event) => updateField('deo', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} />
                </label>

                <label className="tms-field">
                  <span>LGU</span>
                  <input value={form.lgu} onChange={(event) => updateField('lgu', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} />
                </label>

                <div className="tms-field span-2">
                  <span>{currentType?.assignment_role_label || 'Assigned users'}</span>
                  <div className="tms-assignee-pool">
                    {bootstrap.users.map((user: TmsUser) => {
                      const checked = form.assignee_user_ids.includes(String(user.id))
                      return (
                        <label key={user.id} className={`tms-user-chip ${checked ? 'is-selected' : ''}`}>
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => onToggleAssignee(String(user.id))}
                            disabled={modalBusy || modalReadOnly || protectedFieldsLocked}
                          />
                          <span>{user.full_name}</span>
                          <small>{user.division_name || user.section_name}</small>
                        </label>
                      )
                    })}
                  </div>
                </div>

                {currentType?.show_date_surveyed ? (
                  <label className="tms-field">
                    <span>Date surveyed</span>
                    <input type="date" value={form.date_surveyed} onChange={(event) => updateField('date_surveyed', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} />
                  </label>
                ) : null}

                {currentType?.show_date_received ? (
                  <label className="tms-field">
                    <span>Date received</span>
                    <input type="date" value={form.date_received} onChange={(event) => updateField('date_received', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} />
                  </label>
                ) : null}

                {currentType?.show_date_started ? (
                  <label className="tms-field">
                    <span>Date started</span>
                    <input type="date" value={form.date_started} onChange={(event) => updateField('date_started', event.target.value)} disabled={modalBusy || modalReadOnly || protectedFieldsLocked} />
                  </label>
                ) : null}

                {currentType?.show_target_completion ? (
                  <label className="tms-field">
                    <span>Target completion</span>
                    <input
                      type="date"
                      value={form.target_completion}
                      onChange={(event) => updateField('target_completion', event.target.value)}
                      disabled={modalBusy || modalReadOnly || protectedFieldsLocked}
                    />
                  </label>
                ) : null}

                {currentType?.show_progress ? (
                  <label className="tms-field">
                    <span>Progress percent</span>
                    <input
                      type="number"
                      min="0"
                      max="100"
                      step="0.01"
                      value={form.progress_percent}
                      onChange={(event) => updateField('progress_percent', event.target.value)}
                      disabled={modalBusy || modalReadOnly || progressLocked}
                    />
                    {progressHelp ? <small className={`tms-field-help ${progressLocked ? 'is-warning' : ''}`}>{progressHelp}</small> : null}
                  </label>
                ) : null}

                {currentType?.show_reference_code ? (
                  <label className="tms-field">
                    <span>{currentType.reference_label || 'Reference code'}</span>
                    <input value={form.reference_code} onChange={(event) => updateField('reference_code', event.target.value)} disabled={modalBusy || modalReadOnly} />
                  </label>
                ) : null}

                <label className="tms-field span-2">
                  <span>Remarks</span>
                  <textarea rows={5} value={form.remarks} onChange={(event) => updateField('remarks', event.target.value)} disabled={modalBusy || modalReadOnly} />
                </label>
              </div>

              {protectedHelp ? <div className="tms-form-callout">{protectedHelp}</div> : null}

              {formMessage ? <div className={`tms-form-message ${formError ? 'is-error' : 'is-ok'}`}>{formMessage}</div> : null}

              <div className="tms-modal-actions">
                <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setModalOpen(false)} disabled={modalBusy}>
                  Cancel
                </button>
                {!modalReadOnly ? (
                  <button className="tms-btn tms-btn-primary" type="submit" disabled={modalBusy}>
                    {modalBusy ? 'Saving...' : 'Save task'}
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
