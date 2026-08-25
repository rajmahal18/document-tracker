import { startTransition, useDeferredValue, useMemo, useState, type FormEvent } from 'react'
import { assignTaskStep, deleteTask, fetchTaskDetail, getAppConfig, getBootstrapData, previewTaskDueDate, respondToInvitation, saveTask, saveTaskProgress, saveTaskType, saveWorkflowTemplate } from './lib/app-bridge'
import './index.css'
import type { TmsBootstrap, TmsFilters, TmsTask, TmsTaskDetail, TmsTaskType, TmsWorkflowStep, TmsWorkflowTemplate, TmsWorkflowTransition } from './types'

type TimelineStepState = {
  workflow_step_id?: string
  title: string
  division_id: string
  section_id: string
  responsible_user_id: string
  duration_working_days: string
}

type TemplateStepState = TimelineStepState & {
  instructions: string
  role_label: string
  placement: 'main' | 'conditional' | 'optional'
  can_run_parallel: boolean
  requires_output: boolean
  requires_validation: boolean
}

type TemplateTransitionState = {
  from_step_order: string
  to_step_order: string
  label: string
  type: string
}

type TemplateFormState = {
  id: number
  task_type_id: string
  new_task_type_name: string
  name: string
  description: string
  flow_mode: string
  steps: TemplateStepState[]
  transitions: TemplateTransitionState[]
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
  has_indefinite_timeline: boolean
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

type ModalMode = 'view' | 'edit' | 'append-subtask'

const bootstrap: TmsBootstrap = getBootstrapData()
const app = getAppConfig()

const blankTaskTypeForm = (): NewTaskTypeState => ({
  name: '',
  description: '',
  default_priority: 'normal',
})

const blankTimelineStep = (): TimelineStepState => ({
  workflow_step_id: '',
  title: '',
  division_id: '',
  section_id: '',
  responsible_user_id: '',
  duration_working_days: '',
})

const blankTemplateStep = (): TemplateStepState => ({
  ...blankTimelineStep(),
  instructions: '',
  role_label: 'Lead',
  placement: 'main',
  can_run_parallel: false,
  requires_output: false,
  requires_validation: false,
})

const blankTemplateTransition = (): TemplateTransitionState => ({
  from_step_order: '',
  to_step_order: '',
  label: '',
  type: 'next',
})

const blankTemplateForm = (): TemplateFormState => ({
  id: 0,
  task_type_id: bootstrap.taskTypes[0] ? String(bootstrap.taskTypes[0].id) : '',
  new_task_type_name: '',
  name: '',
  description: '',
  flow_mode: 'sequential',
  steps: [blankTemplateStep()],
  transitions: [],
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
    has_indefinite_timeline: true,
    participant_user_ids: [String(bootstrap.viewer.id)],
    participant_role_labels: { [String(bootstrap.viewer.id)]: 'Lead' },
    lead_user_id: String(bootstrap.viewer.id),
    timeline_steps: [],
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

function workingDaysFromMinutes(minutes?: number | null) {
  if (!minutes || minutes <= 0) return ''
  return String(Math.max(1, Math.ceil(minutes / 480)))
}

function templateDurationLabel(template: TmsWorkflowTemplate) {
  const average = Number(template.average_working_days || 0)
  if (average > 0) return `Average timeline based on historical data: ${average} working days`
  const estimate = Number(template.estimated_working_minutes || 0)
  if (estimate <= 0) return 'No duration estimate'
  const days = Math.max(1, Math.ceil(estimate / 480))
  return `Estimated timeline: ${days} working day${days === 1 ? '' : 's'}`
}

function toDateTimeInput(value?: string | null) {
  if (!value) return ''
  return value.slice(0, 16).replace(' ', 'T')
}

function nowDateTimeInput() {
  const date = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function totalWorkingDaysFromTimeline(steps: TimelineStepState[]) {
  return steps.reduce((sum, step) => sum + Math.max(0, Number.parseInt(step.duration_working_days || '0', 10) || 0), 0)
}

type WorkflowMapNode = {
  key: string
  order: number
  title: string
  office: string
  duration: string
  placement?: 'main' | 'conditional' | 'optional'
  isParallel: boolean
  requiresOutput?: boolean
  requiresValidation?: boolean
}

type WorkflowMapEdge = {
  key: string
  fromOrder: number | null
  toOrder: number
  label: string
  type: string
}

function transitionTypeLabel(type: string) {
  const normalized = type.toLowerCase()
  if (normalized === 'approved') return 'Approved'
  if (normalized === 'not_approved') return 'Not approved'
  if (normalized === 'rejected') return 'Rejected'
  if (normalized === 'returned') return 'Returned'
  if (normalized === 'blocked') return 'Blocked'
  return 'Next'
}

function flowModeLabel(mode: string) {
  const normalized = mode.toLowerCase()
  if (normalized === 'parallel') return 'Parallel'
  if (normalized === 'mixed') return 'Mixed'
  return 'Sequential'
}

function branchLabel(edge: WorkflowMapEdge) {
  const typeLabel = transitionTypeLabel(edge.type)
  const label = edge.label.trim() || typeLabel
  return label.toLowerCase() === typeLabel.toLowerCase() ? typeLabel : `${typeLabel}: ${label}`
}

function stepPlacementLabel(placement?: string) {
  if (placement === 'conditional') return 'Conditional'
  if (placement === 'optional') return 'Optional'
  return 'Main path'
}

function inferredStepPlacement(step: TmsWorkflowStep, transitions: TmsWorkflowTransition[]): TemplateStepState['placement'] {
  const order = Number(step.step_order || 0)
  if (order <= 1) return 'main'

  const incoming = transitions.filter((transition) => Number(transition.to_step_order || 0) === order)
  if (!incoming.length) return 'main'

  const hasMainIncoming = incoming.some((transition) => {
    const fromOrder = Number(transition.from_step_order || 0)
    const type = String(transition.transition_type || 'next').toLowerCase()
    return fromOrder === order - 1 && type === 'next'
  })

  return hasMainIncoming ? 'main' : 'conditional'
}

function mainTemplateSteps(template: TmsWorkflowTemplate) {
  const steps = template.steps || []
  const transitions = template.transitions || []
  const mainSteps = steps.filter((step) => inferredStepPlacement(step, transitions) === 'main')
  return mainSteps.length ? mainSteps : steps
}

function templateNodesFromSteps(steps: TmsWorkflowStep[]): WorkflowMapNode[] {
  return steps.map((step) => ({
    key: `saved-${step.id}`,
    order: Number(step.step_order || 0),
    title: step.title || 'Untitled step',
    office: step.responsible_section_name || step.responsible_division_name || 'No office specified',
    duration: workingDaysFromMinutes(step.estimated_working_minutes),
    isParallel: Number(step.can_run_parallel || 0) === 1,
    requiresOutput: Number(step.requires_output || 0) === 1,
    requiresValidation: Number(step.requires_validation || 0) === 1,
  }))
}

function templateEdgesFromTransitions(transitions: TmsWorkflowTransition[], nodeCount: number): WorkflowMapEdge[] {
  const explicit = transitions.map((transition) => ({
    key: `saved-transition-${transition.id}`,
    fromOrder: transition.from_step_order ? Number(transition.from_step_order) : null,
    toOrder: Number(transition.to_step_order || 0),
    label: transition.transition_label || transitionTypeLabel(transition.transition_type || 'next'),
    type: transition.transition_type || 'next',
  })).filter((edge) => edge.toOrder > 0)

  if (explicit.length) return explicit
  return Array.from({ length: Math.max(0, nodeCount - 1) }, (_, index) => ({
    key: `generated-transition-${index + 1}`,
    fromOrder: index + 1,
    toOrder: index + 2,
    label: 'Next',
    type: 'next',
  }))
}

function draftNodesFromSteps(steps: TemplateStepState[]): WorkflowMapNode[] {
  return steps.map((step, index) => ({
    key: `draft-${index}`,
    order: index + 1,
    title: step.title || `Step ${index + 1}`,
    office: [
      bootstrap.sections.find((section) => String(section.id) === step.section_id)?.name,
      bootstrap.divisions.find((division) => String(division.id) === step.division_id)?.name,
    ].filter(Boolean).join(', ') || 'No office specified',
    duration: step.duration_working_days,
    placement: step.placement,
    isParallel: step.can_run_parallel,
    requiresOutput: step.requires_output,
    requiresValidation: step.requires_validation,
  }))
}

function autoMainPathEdges(nodes: WorkflowMapNode[], explicit: WorkflowMapEdge[], keyPrefix: string): WorkflowMapEdge[] {
  const explicitFromOrders = new Set(explicit.map((edge) => edge.fromOrder).filter((order): order is number => order !== null))
  const mainOrders = nodes
    .filter((node) => (node.placement || 'main') === 'main')
    .map((node) => node.order)
    .filter((order) => order > 0)
    .sort((a, b) => a - b)

  return mainOrders.slice(0, -1).flatMap((fromOrder, index) => {
    if (explicitFromOrders.has(fromOrder)) return []
    return [{
      key: `${keyPrefix}-${fromOrder}-${mainOrders[index + 1]}`,
      fromOrder,
      toOrder: mainOrders[index + 1],
      label: 'Next',
      type: 'next',
    }]
  })
}

function draftEdgesFromTransitions(transitions: TemplateTransitionState[], steps: TemplateStepState[]): WorkflowMapEdge[] {
  const explicit = transitions.map((transition, index) => ({
    key: `draft-transition-${index}`,
    fromOrder: transition.from_step_order ? Number(transition.from_step_order) : null,
    toOrder: Number(transition.to_step_order || 0),
    label: transition.label || transitionTypeLabel(transition.type),
    type: transition.type || 'next',
  })).filter((edge) => edge.toOrder > 0)

  return [...autoMainPathEdges(draftNodesFromSteps(steps), explicit, 'draft-generated-transition'), ...explicit]
}

function WorkflowMap({ mode, nodes, edges }: { mode: string; nodes: WorkflowMapNode[]; edges: WorkflowMapEdge[] }) {
  const orderedNodes = [...nodes].filter((node) => node.order > 0).sort((a, b) => a.order - b.order)
  const modeKey = mode.toLowerCase() === 'parallel' ? 'parallel' : mode.toLowerCase() === 'mixed' ? 'mixed' : 'sequential'
  const nodeCount = orderedNodes.length
  const nodeOrders = new Set(orderedNodes.map((node) => node.order))
  const normalizedEdges = edges.filter((edge) => edge.toOrder > 0 && (edge.fromOrder === null || nodeOrders.has(edge.fromOrder)) && nodeOrders.has(edge.toOrder))
  const decisionEdges = normalizedEdges.filter((edge) => {
    const type = edge.type.toLowerCase()
    return edge.fromOrder !== null && (type !== 'next' || edge.toOrder !== edge.fromOrder + 1)
  })
  const forwardEdges = normalizedEdges.filter((edge) => edge.fromOrder !== null && edge.toOrder > edge.fromOrder && ['next', 'approved'].includes(edge.type.toLowerCase()))
  const returnEdges = decisionEdges.filter((edge) => edge.fromOrder !== null && edge.toOrder <= edge.fromOrder)
  const hasConditionals = decisionEdges.length > 0
  const rowHeight = 148
  const mapHeight = Math.max(220, 104 + (nodeCount - 1) * rowHeight)
  const nodeCenterX = 330
  const returnLaneX = 34
  const yForOrder = (order: number) => 54 + (order - 1) * rowHeight
  const returnPath = (edge: WorkflowMapEdge, index: number) => {
    const from = Number(edge.fromOrder || 0)
    const to = Number(edge.toOrder || 0)
    const startY = yForOrder(from) + 44
    const endY = yForOrder(to) + 4
    const laneX = returnLaneX - (index % 2) * 14
    return `M ${nodeCenterX - 238} ${startY} C ${laneX + 60} ${startY}, ${laneX} ${startY - 18}, ${laneX} ${startY - 54} L ${laneX} ${endY + 54} C ${laneX} ${endY + 16}, ${laneX + 62} ${endY}, ${nodeCenterX - 238} ${endY}`
  }

  if (!orderedNodes.length) {
    return (
      <div className="tms-flow-map is-empty">
        <span>No workflow steps yet.</span>
      </div>
    )
  }

  const decisionEdgesForNode = (order: number) => decisionEdges.filter((edge) => edge.fromOrder === order)
  const classifyBranch = (edge: WorkflowMapEdge) => {
    const type = edge.type.toLowerCase()
    if (edge.fromOrder !== null && edge.toOrder <= edge.fromOrder) return 'return'
    if (type === 'approved' || type === 'next') return 'continue'
    return 'alternate'
  }

  return (
    <div className={`tms-flow-map is-${modeKey}`}>
      <div className="tms-flow-map-head">
        <span>{flowModeLabel(mode)}</span>
        <strong>{hasConditionals ? 'Conditional workflow' : 'Direct workflow'}</strong>
      </div>
      <div className="tms-flow-canvas">
        <div className="tms-flow-start">Start</div>
        <div className={`tms-flow-diagram ${modeKey === 'parallel' ? 'is-parallel' : ''}`} style={{ ['--flow-node-count' as string]: nodeCount }}>
          {modeKey !== 'parallel' ? (
            <svg className="tms-flow-routes" viewBox={`0 0 660 ${mapHeight}`} preserveAspectRatio="none" aria-hidden="true">
              <defs>
                <marker id="tmsFlowArrow" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto" markerUnits="strokeWidth">
                  <path d="M 0 0 L 8 4 L 0 8 z" />
                </marker>
                <marker id="tmsFlowReturnArrow" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto" markerUnits="strokeWidth">
                  <path d="M 0 0 L 8 4 L 0 8 z" />
                </marker>
              </defs>
              {forwardEdges.map((edge) => {
                const startY = yForOrder(Number(edge.fromOrder)) + 52
                const endY = yForOrder(edge.toOrder) - 52
                return <path key={`main-spine-${edge.key}`} className="tms-flow-route is-forward" d={`M ${nodeCenterX} ${startY} L ${nodeCenterX} ${endY}`} markerEnd="url(#tmsFlowArrow)" />
              })}
              {returnEdges.map((edge, index) => (
                <path key={edge.key} className="tms-flow-route is-return" d={returnPath(edge, index)} markerEnd="url(#tmsFlowReturnArrow)" />
              ))}
            </svg>
          ) : null}
          <div className={`tms-flow-nodes ${modeKey === 'parallel' ? 'is-parallel' : ''}`}>
            {orderedNodes.map((node) => {
              const nodeBranches = decisionEdgesForNode(node.order)
              const hasDecision = nodeBranches.length > 0
              return (
                <div key={node.key} className={`tms-flow-node-wrap ${node.isParallel ? 'is-parallel-node' : ''}`}>
                  <div className={`tms-flow-node ${hasDecision ? 'has-decision' : ''} is-${node.placement || 'main'}`}>
                    <span className="tms-flow-step-no">{node.order}</span>
                    <div className="tms-flow-node-main">
                      <strong>{node.title}</strong>
                      <small>{node.office}</small>
                      <em>{node.duration ? `${node.duration} working day${node.duration === '1' ? '' : 's'}` : 'No duration set'}</em>
                    </div>
                    <div className="tms-flow-node-tags">
                      {node.placement && node.placement !== 'main' ? <span className={`tone-${node.placement}`}>{stepPlacementLabel(node.placement)}</span> : null}
                      {node.isParallel ? <span>Parallel</span> : null}
                      {node.requiresOutput ? <span>Output</span> : null}
                      {node.requiresValidation ? <span>Validation</span> : null}
                    </div>
                  </div>
                  {hasDecision ? (
                    <div className="tms-flow-decision-panel">
                      <div className="tms-flow-decision-head">
                        <span className="tms-flow-diamond"><span>?</span></span>
                        <strong>Decision after Step {node.order}</strong>
                      </div>
                      <div className="tms-flow-branches">
                        {nodeBranches.map((edge) => {
                          const className = classifyBranch(edge)
                          const targetLabel = className === 'return' ? `Return to Step ${edge.toOrder}` : `Go to Step ${edge.toOrder}`
                          return (
                            <span key={edge.key} className={`tms-flow-branch tone-${edge.type.toLowerCase()} is-${className}`}>
                              <b>{branchLabel(edge)}</b>
                              <small>{targetLabel}</small>
                            </span>
                          )
                        })}
                      </div>
                    </div>
                  ) : null}
                </div>
              )
            })}
          </div>
        </div>
        <div className="tms-flow-end">End</div>
      </div>
    </div>
  )
}

export default function App() {
  const [tasks, setTasks] = useState<TmsTask[]>(bootstrap.tasks)
  const [taskTypes, setTaskTypes] = useState<TmsTaskType[]>(bootstrap.taskTypes)
  const [workflowTemplates, setWorkflowTemplates] = useState<TmsWorkflowTemplate[]>(bootstrap.workflowTemplates)
  const [filters, setFilters] = useState<TmsFilters>(bootstrap.filters)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalBusy, setModalBusy] = useState(false)
  const [modalReadOnly, setModalReadOnly] = useState(false)
  const [modalMode, setModalMode] = useState<ModalMode>('edit')
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
  const [progressDraft, setProgressDraft] = useState(0)
  const [progressBusy, setProgressBusy] = useState(false)
  const [workspaceTab, setWorkspaceTab] = useState<'tasks' | 'templates'>('tasks')
  const [templateModalOpen, setTemplateModalOpen] = useState(false)
  const [templateBusy, setTemplateBusy] = useState(false)
  const [templateForm, setTemplateForm] = useState<TemplateFormState>(() => blankTemplateForm())
  const [templateMessage, setTemplateMessage] = useState('')
  const [templateError, setTemplateError] = useState(false)
  const [templatePickerOpen, setTemplatePickerOpen] = useState(false)

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

  async function fillTargetDueFromTimeline(startAt: string, steps: TimelineStepState[]) {
    const workingDays = totalWorkingDaysFromTimeline(steps)
    if (!startAt || workingDays <= 0) return

    try {
      const result = await previewTaskDueDate(startAt, workingDays)
      const targetDueAt = toDateTimeInput(String(result.target_due_at || ''))
      if (!targetDueAt) return
      setForm((current) => (
        current.target_start_at === startAt
          ? { ...current, has_indefinite_timeline: false, target_due_at: targetDueAt }
          : current
      ))
    } catch {
      setFormMessage('Unable to calculate target completion from the selected template.')
      setFormError(true)
    }
  }

  function updateTargetStart(value: string) {
    setForm((current) => ({ ...current, target_start_at: value }))
    if (!form.has_indefinite_timeline && form.timeline_steps.length) {
      void fillTargetDueFromTimeline(value, form.timeline_steps)
    }
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
    const nextSteps = form.timeline_steps.map((step, stepIndex) => {
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
    })
    setForm((current) => ({ ...current, timeline_steps: nextSteps }))
    if (patch.duration_working_days !== undefined && !form.has_indefinite_timeline && form.target_start_at) {
      void fillTargetDueFromTimeline(form.target_start_at, nextSteps)
    }
  }

  function addTimelineStep() {
    setForm((current) => ({ ...current, timeline_steps: [...current.timeline_steps, blankTimelineStep()] }))
  }

  function removeTimelineStep(index: number) {
    setForm((current) => ({
      ...current,
      timeline_steps: current.timeline_steps.filter((_, stepIndex) => stepIndex !== index),
    }))
  }

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
    setModalMode('edit')
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
      setModalMode('view')
      setProgressDraft(Number(taskDetail.progress_percent || 0))
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
        has_indefinite_timeline: !taskDetail.target_due_at,
        participant_user_ids: (taskDetail.participant_user_ids || []).map(String),
        participant_role_labels: taskDetail.participant_role_labels || {},
        lead_user_id: String(taskDetail.participants.find((participant) => participant.is_lead === 1)?.user_id || bootstrap.viewer.id),
        timeline_steps: taskDetail.steps?.length
          ? taskDetail.steps.map((step) => ({
              title: step.title || '',
              division_id: step.responsible_division_id ? String(step.responsible_division_id) : '',
              section_id: step.responsible_section_id ? String(step.responsible_section_id) : '',
              responsible_user_id: step.responsible_user_id ? String(step.responsible_user_id) : '',
              duration_working_days: step.estimated_working_minutes ? String(Math.max(1, Math.ceil(step.estimated_working_minutes / 480))) : '',
            }))
          : [],
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
    formData.set('target_due_at', form.has_indefinite_timeline ? '' : form.target_due_at)
    formData.set('has_indefinite_timeline', form.has_indefinite_timeline ? '1' : '0')
    formData.set('lead_user_id', form.lead_user_id)
    formData.set('project_id', form.project_id)
    formData.set('remarks', form.remarks)
    if (modalMode === 'append-subtask') {
      formData.set('append_timeline_steps', '1')
    }
    form.participant_user_ids.forEach((userId) => {
      formData.append('participant_user_ids[]', userId)
      formData.append(`participant_role_labels[${userId}]`, form.participant_role_labels[userId] || 'Contributor')
    })
    if (form.id <= 0 || modalMode === 'append-subtask') {
      form.timeline_steps.forEach((step, index) => {
        formData.append(`timeline_steps[${index}][title]`, step.title)
        formData.append(`timeline_steps[${index}][division_id]`, step.division_id)
        formData.append(`timeline_steps[${index}][section_id]`, step.section_id)
        formData.append(`timeline_steps[${index}][responsible_user_id]`, step.responsible_user_id)
        formData.append(`timeline_steps[${index}][duration_working_days]`, step.duration_working_days)
        formData.append(`timeline_steps[${index}][workflow_step_id]`, step.workflow_step_id || '')
      })
    }

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

  function openTemplateBuilder() {
    setTemplateForm(blankTemplateForm())
    setTemplateMessage('')
    setTemplateError(false)
    setTemplateModalOpen(true)
  }

  function editWorkflowTemplate(template: TmsWorkflowTemplate) {
    const mappedSteps = (template.steps || []).map((step) => ({
      title: step.title || '',
      division_id: step.default_responsible_division_id ? String(step.default_responsible_division_id) : '',
      section_id: step.default_responsible_section_id ? String(step.default_responsible_section_id) : '',
      responsible_user_id: '',
      duration_working_days: workingDaysFromMinutes(step.estimated_working_minutes),
      instructions: step.instructions || '',
      role_label: step.default_role_label || 'Lead',
      placement: inferredStepPlacement(step, template.transitions || []),
      can_run_parallel: Number(step.can_run_parallel || 0) === 1,
      requires_output: Number(step.requires_output || 0) === 1,
      requires_validation: Number(step.requires_validation || 0) === 1,
    }))
    setTemplateForm({
      id: template.id,
      task_type_id: template.task_type_id ? String(template.task_type_id) : '',
      new_task_type_name: '',
      name: template.name || '',
      description: template.description || '',
      flow_mode: template.flow_mode || 'sequential',
      steps: mappedSteps.length ? mappedSteps : [blankTemplateStep()],
      transitions: (template.transitions || []).map((transition) => ({
        from_step_order: transition.from_step_order ? String(transition.from_step_order) : '',
        to_step_order: transition.to_step_order ? String(transition.to_step_order) : '',
        label: transition.transition_label || '',
        type: transition.transition_type || 'next',
      })),
    })
    setTemplateMessage('')
    setTemplateError(false)
    setTemplateModalOpen(true)
  }

  function updateTemplateStep(index: number, patch: Partial<TemplateStepState>) {
    setTemplateForm((current) => ({
      ...current,
      steps: current.steps.map((step, stepIndex) => {
        if (stepIndex !== index) return step
        const next = { ...step, ...patch }
        if (patch.division_id !== undefined) {
          next.section_id = ''
          next.responsible_user_id = ''
        }
        return next
      }),
    }))
  }

  function addTemplateStep() {
    setTemplateForm((current) => ({ ...current, steps: [...current.steps, blankTemplateStep()] }))
  }

  function removeTemplateStep(index: number) {
    setTemplateForm((current) => ({
      ...current,
      steps: current.steps.length <= 1 ? current.steps : current.steps.filter((_, stepIndex) => stepIndex !== index),
      transitions: current.transitions.filter((transition) => Number(transition.from_step_order) !== index + 1 && Number(transition.to_step_order) !== index + 1),
    }))
  }

  function updateTemplateTransition(index: number, patch: Partial<TemplateTransitionState>) {
    setTemplateForm((current) => ({
      ...current,
      transitions: current.transitions.map((transition, transitionIndex) => (transitionIndex === index ? { ...transition, ...patch } : transition)),
    }))
  }

  function addTemplateTransition() {
    setTemplateForm((current) => ({ ...current, transitions: [...current.transitions, blankTemplateTransition()] }))
  }

  function removeTemplateTransition(index: number) {
    setTemplateForm((current) => ({
      ...current,
      transitions: current.transitions.filter((_, transitionIndex) => transitionIndex !== index),
    }))
  }

  async function submitWorkflowTemplate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setTemplateBusy(true)
    setTemplateMessage('')
    setTemplateError(false)

    const formData = new FormData()
    formData.set('csrf_token', app.csrf || '')
    formData.set('id', String(templateForm.id || 0))
    formData.set('task_type_id', templateForm.task_type_id)
    formData.set('new_task_type_name', templateForm.new_task_type_name)
    formData.set('name', templateForm.name)
    formData.set('description', templateForm.description)
    formData.set('flow_mode', templateForm.flow_mode)
    templateForm.steps.forEach((step, index) => {
      formData.append(`steps[${index}][title]`, step.title)
      formData.append(`steps[${index}][division_id]`, step.division_id)
      formData.append(`steps[${index}][section_id]`, step.section_id)
      formData.append(`steps[${index}][duration_working_days]`, step.duration_working_days)
      formData.append(`steps[${index}][instructions]`, step.instructions)
      formData.append(`steps[${index}][role_label]`, step.role_label)
      formData.append(`steps[${index}][placement]`, step.placement)
      formData.append(`steps[${index}][can_run_parallel]`, step.can_run_parallel ? '1' : '0')
      formData.append(`steps[${index}][requires_output]`, step.requires_output ? '1' : '0')
      formData.append(`steps[${index}][requires_validation]`, step.requires_validation ? '1' : '0')
    })
    templateForm.transitions.forEach((transition, index) => {
      formData.append(`transitions[${index}][from_step_order]`, transition.from_step_order)
      formData.append(`transitions[${index}][to_step_order]`, transition.to_step_order)
      formData.append(`transitions[${index}][label]`, transition.label)
      formData.append(`transitions[${index}][type]`, transition.type)
    })

    try {
      const result = await saveWorkflowTemplate(formData)
      const savedTemplate = result.workflow_template as TmsWorkflowTemplate | null
      const savedTypes = Array.isArray(result.task_types) ? result.task_types as TmsTaskType[] : []
      if (!savedTemplate) {
        throw new Error('Template was saved but the server response was incomplete.')
      }

      if (savedTypes.length) {
        setTaskTypes(savedTypes)
      }
      setWorkflowTemplates((current) => [...current.filter((entry) => entry.id !== savedTemplate.id), savedTemplate])
      setTemplateMessage(result.message || 'Workflow template saved.')
      setTemplateError(false)
      window.setTimeout(() => setTemplateModalOpen(false), 450)
    } catch (error) {
      setTemplateMessage(error instanceof Error ? error.message : 'Failed to save workflow template.')
      setTemplateError(true)
    } finally {
      setTemplateBusy(false)
    }
  }

  function useTemplate(template: TmsWorkflowTemplate) {
    const nextSteps = mainTemplateSteps(template).map((step) => ({
      workflow_step_id: String(step.id || ''),
      title: step.title || '',
      division_id: step.default_responsible_division_id ? String(step.default_responsible_division_id) : '',
      section_id: step.default_responsible_section_id ? String(step.default_responsible_section_id) : '',
      responsible_user_id: '',
      duration_working_days: workingDaysFromMinutes(step.estimated_working_minutes),
    }))
    const targetStartAt = form.target_start_at || nowDateTimeInput()

    setForm((current) => ({
      ...current,
      task_type_id: template.task_type_id ? String(template.task_type_id) : current.task_type_id,
      workflow_template_id: String(template.id),
      flow_mode: template.flow_mode || 'sequential',
      target_start_at: targetStartAt,
      has_indefinite_timeline: totalWorkingDaysFromTimeline(nextSteps) <= 0,
      timeline_steps: nextSteps,
    }))
    setModalReadOnly(false)
    setModalMode('edit')
    setDetail(null)
    setFormMessage('')
    setFormError(false)
    setModalOpen(true)
    setTemplatePickerOpen(false)
    void fillTargetDueFromTimeline(targetStartAt, nextSteps)
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

  async function updateTaskProgress() {
    if (!detail?.can_edit || progressBusy) return
    const nextProgress = Math.max(0, Math.min(100, Math.round(progressDraft)))
    setProgressBusy(true)
    setFormMessage('')
    setFormError(false)
    try {
      const result = await saveTaskProgress(detail.id, nextProgress)
      const savedProgress = Number(result.progress_percent ?? nextProgress)
      setProgressDraft(savedProgress)
      setDetail((current) => (current ? { ...current, progress_percent: savedProgress } : current))
      setTasks((current) => current.map((task) => (task.id === detail.id ? { ...task, progress_percent: savedProgress } : task)))
      setFormMessage(result.message || 'Progress updated.')
      setFormError(false)
    } catch (error) {
      setFormMessage(error instanceof Error ? error.message : 'Failed to save progress.')
      setFormError(true)
    } finally {
      setProgressBusy(false)
    }
  }

  function editCurrentTask() {
    if (!detail?.can_edit) return
    setModalMode('edit')
    setFormMessage('')
    setFormError(false)
  }

  function appendSubtaskToCurrentTask() {
    if (!detail?.can_edit) return
    setModalMode('append-subtask')
    setForm((current) => ({
      ...current,
      timeline_steps: [blankTimelineStep()],
    }))
    setFormMessage('')
    setFormError(false)
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
  const canEditTimeline = (!workflowLocked || modalMode === 'append-subtask') && !modalReadOnly
  const isDetailView = Boolean(detail && modalMode === 'view')
  const modalTitle = form.id
    ? modalMode === 'append-subtask'
      ? 'Add Subtask'
      : modalMode === 'edit'
        ? 'Edit Task'
        : 'Task Details'
    : 'Create Task'

  function renderTemplateRow(template: TmsWorkflowTemplate, actionLabel = 'Use Template') {
    const steps = template.steps || []
    const transitions = template.transitions || []
    const mapNodes = templateNodesFromSteps(steps)
    const mapEdges = templateEdgesFromTransitions(transitions, mapNodes.length)
    return (
      <article key={template.id} className="tms-template-row">
        <div className="tms-template-main">
          <div className="tms-row-title-line">
            <span className="tms-row-code">{template.task_type_name || 'General'}</span>
            <h2>{template.name}</h2>
            <span className="tms-badge tone-open">{template.flow_mode}</span>
          </div>
          <p>{template.description || 'No template description entered.'}</p>
          <div className="tms-row-info-line">
            <span>{templateDurationLabel(template)}</span>
            <span>{steps.length} step{steps.length === 1 ? '' : 's'}</span>
            <span>Created by {template.created_by_name || 'System'}</span>
          </div>
          {steps.length ? (
            <div className="tms-template-steps" aria-label={`${template.name} steps`}>
              {steps.map((step) => (
                <span key={step.id}>
                  <strong>{step.step_order}. {step.title}</strong>
                  <small>{step.responsible_section_name || step.responsible_division_name || 'No office specified'}{step.estimated_working_minutes ? ` / ${workingDaysFromMinutes(step.estimated_working_minutes)} working day${workingDaysFromMinutes(step.estimated_working_minutes) === '1' ? '' : 's'}` : ''}</small>
                </span>
              ))}
            </div>
          ) : null}
          {transitions.length ? (
            <div className="tms-template-transitions">
              {transitions.map((transition) => (
                <span key={transition.id}>
                  {transition.transition_label}: {transition.from_step_order ? `Step ${transition.from_step_order}` : 'Start'} to Step {transition.to_step_order}
                </span>
              ))}
            </div>
          ) : null}
          <WorkflowMap mode={template.flow_mode || 'sequential'} nodes={mapNodes} edges={mapEdges} />
        </div>
        <div className="tms-template-actions">
          <button className="tms-btn tms-btn-secondary" type="button" onClick={() => editWorkflowTemplate(template)}>
            Edit Template
          </button>
          <button className="tms-btn tms-btn-primary" type="button" onClick={() => useTemplate(template)}>
            {actionLabel}
          </button>
        </div>
      </article>
    )
  }

  function participantStatusText(participant: { user_id: number; participant_role_label: string; participation_status: string; is_lead: number }) {
    const status = statusLabel(participant.participation_status)
    if (participant.user_id === bootstrap.viewer.id) {
      return participant.is_lead === 1 ? 'Assigned to self - Lead' : 'Assigned to self'
    }
    return `${participant.participant_role_label} - ${status}`
  }

  return (
    <div className="tms-app">
      <div className="tms-wrap">
        <header className="tms-page-head">
          <div>
            <span className="tms-kicker">Task Monitoring</span>
            <h1>Task Registry</h1>
            <p>Track shared office work, participants, workflow steps, and outputs.</p>
          </div>
          <div className="tms-head-actions">
            <button className="tms-btn tms-btn-secondary" type="button" onClick={openTemplateBuilder}>
              New Template
            </button>
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

        <section className="tms-workspace-tabs" aria-label="Task Monitoring views">
          <button type="button" className={workspaceTab === 'tasks' ? 'is-active' : ''} onClick={() => setWorkspaceTab('tasks')}>
            Task Registry
          </button>
          <button type="button" className={workspaceTab === 'templates' ? 'is-active' : ''} onClick={() => setWorkspaceTab('templates')}>
            Workflow Templates
          </button>
        </section>

        {workspaceTab === 'tasks' ? (
        <>
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
            filteredTasks.map((task) => {
              const participants = task.participants_text ? task.participants_text.split(',').map((entry) => entry.trim()).filter(Boolean) : []
              const leadParticipant = participants.find((entry) => entry.toLowerCase().startsWith('lead:')) || participants[0] || 'No participants listed'
              const office = task.owner_section_name || task.owner_division_name || 'No owner office'
              const currentStep = task.current_step_title || 'No active step'
              const rowTone = toneForTask(task)
              const progress = Math.max(0, Math.min(100, Number(task.progress_percent || 0)))

              return (
                <article
                  key={task.id}
                  className={`tms-row tone-${rowTone}`}
                  role="button"
                  tabIndex={0}
                  onClick={() => void openTask(task)}
                  onKeyDown={(event) => {
                    if (event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
                      event.preventDefault()
                      void openTask(task)
                    }
                  }}
                >
                  <div className="tms-row-main">
                    <div className="tms-row-title-line">
                      <span className="tms-row-code">{task.task_type_name}</span>
                      <h2>{task.title}</h2>
                      <span className={`tms-badge tone-${rowTone}`}>{statusLabel(task.lifecycle_status)}</span>
                    </div>
                    <div className="tms-row-info-line">
                      <span>Current: {currentStep}</span>
                      <span>{responsibleText(task)}</span>
                      <span>{task.timing_label || 'No timing data'}</span>
                      <span>{participants.length} participant{participants.length === 1 ? '' : 's'}</span>
                      <span>{office}</span>
                    </div>
                  </div>

                  <aside className="tms-row-side">
                    <div className="tms-row-compact-stat">
                      <span>Timing</span>
                      <strong>{task.target_due_at ? formatDateTime(task.target_due_at) : 'No completion date'}</strong>
                    </div>
                    <div className="tms-row-compact-stat">
                      <span>Lead</span>
                      <strong>{leadParticipant.replace(/^Lead:\s*/i, '')}</strong>
                    </div>
                    {task.permissions?.is_invited ? (
                      <div className="tms-row-actions">
                        <button className="tms-btn tms-btn-primary" type="button" onClick={(event) => { event.stopPropagation(); void answerInvitation(task.id, 'join') }}>
                          Join
                        </button>
                        <button className="tms-btn tms-btn-secondary" type="button" onClick={(event) => { event.stopPropagation(); void answerInvitation(task.id, 'decline') }}>
                          Decline
                        </button>
                      </div>
                    ) : task.can_delete ? (
                      <div className="tms-row-actions">
                        <button className="tms-btn tms-btn-ghost" type="button" onClick={(event) => { event.stopPropagation(); void removeTask(task) }}>
                          Delete
                        </button>
                      </div>
                    ) : null}
                  </aside>

                  <div className="tms-row-progress-wrap">
                    <span className="tms-row-progress-label">Progress</span>
                    <div className="tms-row-progress" aria-label={`Progress ${progress}%`}>
                      <span style={{ width: `${progress}%` }} />
                    </div>
                    <strong className="tms-row-progress-value">{progress}%</strong>
                  </div>
                </article>
              )
            })
          ) : (
            <div className="tms-empty spacious">No tasks match the current filters.</div>
          )}
          </main>
        </>
        ) : (
          <main className="tms-template-registry">
            {workflowTemplates.length ? workflowTemplates.map((template) => renderTemplateRow(template)) : (
              <div className="tms-empty spacious">No workflow templates available.</div>
            )}
          </main>
        )}
      </div>

      {templateModalOpen ? (
        <div className="tms-modal-shell" role="dialog" aria-modal="true">
          <div className="tms-modal-backdrop" onClick={() => setTemplateModalOpen(false)} />
          <div className="tms-modal tms-template-modal">
            <div className="tms-modal-head">
              <div>
                <span className="tms-section-kicker">Workflow Template</span>
                <h2>{templateForm.id > 0 ? 'Edit Template' : 'Create Template'}</h2>
                <p>{templateForm.id > 0 ? 'Update the saved workflow structure and conditions.' : 'Save a reusable task structure for future work.'}</p>
              </div>
              <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setTemplateModalOpen(false)}>
                Close
              </button>
            </div>

            <form className="tms-modal-body" onSubmit={(event) => void submitWorkflowTemplate(event)}>
              <section className="tms-form-section">
                <div className="tms-form-section-head">
                  <div>
                    <span className="tms-section-kicker">A</span>
                    <h3>Template Details</h3>
                  </div>
                </div>
                <div className="tms-form-grid">
                  <label className="tms-field">
                    <span>Task type</span>
                    <select value={templateForm.task_type_id} onChange={(event) => setTemplateForm((current) => ({ ...current, task_type_id: event.target.value, new_task_type_name: '' }))} disabled={templateBusy}>
                      {taskTypes.map((taskType) => (
                        <option key={taskType.id} value={taskType.id}>
                          {taskType.name}
                        </option>
                      ))}
                      <option value="">New task type</option>
                    </select>
                  </label>
                  <label className="tms-field">
                    <span>Structure</span>
                    <select value={templateForm.flow_mode} onChange={(event) => setTemplateForm((current) => ({ ...current, flow_mode: event.target.value }))} disabled={templateBusy}>
                      <option value="sequential">Sequential</option>
                      <option value="parallel">Parallel</option>
                      <option value="mixed">Mixed</option>
                    </select>
                  </label>
                  {!templateForm.task_type_id ? (
                    <label className="tms-field span-2">
                      <span>New task type name</span>
                      <input value={templateForm.new_task_type_name} onChange={(event) => setTemplateForm((current) => ({ ...current, new_task_type_name: event.target.value }))} disabled={templateBusy} placeholder="Example: POW and Plan" required />
                    </label>
                  ) : null}
                  <label className="tms-field span-2">
                    <span>Template name</span>
                    <input value={templateForm.name} onChange={(event) => setTemplateForm((current) => ({ ...current, name: event.target.value }))} disabled={templateBusy} placeholder="Example: Development of the Task Monitoring System" required />
                  </label>
                  <label className="tms-field span-2">
                    <span>Description</span>
                    <textarea rows={3} value={templateForm.description} onChange={(event) => setTemplateForm((current) => ({ ...current, description: event.target.value }))} disabled={templateBusy} placeholder="Describe when this template should be used." />
                  </label>
                </div>
              </section>

              <section className="tms-form-section">
                <div className="tms-form-section-head">
                  <div>
                    <span className="tms-section-kicker">Preview</span>
                    <h3>Workflow Map</h3>
                  </div>
                </div>
                <WorkflowMap
                  mode={templateForm.flow_mode}
                  nodes={draftNodesFromSteps(templateForm.steps)}
                  edges={draftEdgesFromTransitions(templateForm.transitions, templateForm.steps)}
                />
              </section>

              <section className="tms-form-section">
                <div className="tms-section-head">
                  <div>
                    <span className="tms-section-kicker">B</span>
                    <h3>Steps</h3>
                  </div>
                  <button className="tms-btn tms-btn-secondary" type="button" onClick={addTemplateStep} disabled={templateBusy}>
                    Add Step
                  </button>
                </div>
                <div className="tms-template-step-list">
                  {templateForm.steps.map((step, index) => {
                    const rowSections = sectionsForDivision(step.division_id)
                    return (
                      <div key={index} className="tms-template-step-row">
                        <div className="tms-timeline-index">{index + 1}</div>
                        <label className="tms-field tms-timeline-title">
                          <span>Step</span>
                          <input value={step.title} onChange={(event) => updateTemplateStep(index, { title: event.target.value })} disabled={templateBusy} placeholder="Example: POW prep" required />
                        </label>
                        <label className="tms-field">
                          <span>Division</span>
                          <select value={step.division_id} onChange={(event) => updateTemplateStep(index, { division_id: event.target.value })} disabled={templateBusy}>
                            <option value="">No specific division</option>
                            {divisionOptions.map((division) => (
                              <option key={division.id} value={division.id}>
                                {division.name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Section</span>
                          <select value={step.section_id} onChange={(event) => updateTemplateStep(index, { section_id: event.target.value })} disabled={templateBusy || !step.division_id}>
                            <option value="">No specific section</option>
                            {rowSections.map((section) => (
                              <option key={section.id} value={section.id}>
                                {section.name}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Working days</span>
                          <input type="number" min="1" value={step.duration_working_days} onChange={(event) => updateTemplateStep(index, { duration_working_days: event.target.value })} disabled={templateBusy} placeholder="Days" />
                        </label>
                        <button className="tms-remove-user" type="button" onClick={() => removeTemplateStep(index)} disabled={templateBusy || templateForm.steps.length <= 1} aria-label={`Remove template step ${index + 1}`}>
                          x
                        </button>
                        <label className="tms-field span-all">
                          <span>Instructions / required output</span>
                          <input value={step.instructions} onChange={(event) => updateTemplateStep(index, { instructions: event.target.value })} disabled={templateBusy} placeholder="Optional instructions, output, or MOV requirement" />
                        </label>
                        <label className="tms-field span-all">
                          <span>Placement</span>
                          <select value={step.placement} onChange={(event) => updateTemplateStep(index, { placement: event.target.value as TemplateStepState['placement'] })} disabled={templateBusy}>
                            <option value="main">Main path - auto-connect in sequence</option>
                            <option value="conditional">Conditional branch - only appears when a transition points here</option>
                            <option value="optional">Optional step - keep outside the normal path unless connected</option>
                          </select>
                        </label>
                        <div className="tms-template-checks span-all">
                          <label><input type="checkbox" checked={step.can_run_parallel} onChange={(event) => updateTemplateStep(index, { can_run_parallel: event.target.checked })} disabled={templateBusy} /> Can run in parallel</label>
                          <label><input type="checkbox" checked={step.requires_output} onChange={(event) => updateTemplateStep(index, { requires_output: event.target.checked })} disabled={templateBusy} /> Requires output</label>
                          <label><input type="checkbox" checked={step.requires_validation} onChange={(event) => updateTemplateStep(index, { requires_validation: event.target.checked })} disabled={templateBusy} /> Requires validation</label>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </section>

              <section className="tms-form-section">
                <div className="tms-section-head">
                  <div>
                    <span className="tms-section-kicker">C</span>
                    <h3>Transitions</h3>
                  </div>
                  <button className="tms-btn tms-btn-secondary" type="button" onClick={addTemplateTransition} disabled={templateBusy}>
                    Add Transition
                  </button>
                </div>
                {templateForm.transitions.length ? (
                  <div className="tms-transition-list">
                    {templateForm.transitions.map((transition, index) => (
                      <div key={index} className="tms-transition-row">
                        <label className="tms-field">
                          <span>From</span>
                          <select value={transition.from_step_order} onChange={(event) => updateTemplateTransition(index, { from_step_order: event.target.value })} disabled={templateBusy}>
                            <option value="">Start</option>
                            {templateForm.steps.map((_, stepIndex) => (
                              <option key={stepIndex + 1} value={stepIndex + 1}>
                                Step {stepIndex + 1}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>To</span>
                          <select value={transition.to_step_order} onChange={(event) => updateTemplateTransition(index, { to_step_order: event.target.value })} disabled={templateBusy}>
                            <option value="">Select step</option>
                            {templateForm.steps.map((_, stepIndex) => (
                              <option key={stepIndex + 1} value={stepIndex + 1}>
                                Step {stepIndex + 1}
                              </option>
                            ))}
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Type</span>
                          <select value={transition.type} onChange={(event) => updateTemplateTransition(index, { type: event.target.value })} disabled={templateBusy}>
                            <option value="next">Next</option>
                            <option value="approved">Approved</option>
                            <option value="not_approved">Not approved / Not balanced</option>
                            <option value="rejected">Rejected</option>
                            <option value="returned">Returned</option>
                            <option value="blocked">Blocked</option>
                          </select>
                        </label>
                        <label className="tms-field">
                          <span>Label</span>
                          <input value={transition.label} onChange={(event) => updateTemplateTransition(index, { label: event.target.value })} disabled={templateBusy} placeholder="Example: If rejected - back to step 1" />
                        </label>
                        <button className="tms-remove-user" type="button" onClick={() => removeTemplateTransition(index)} disabled={templateBusy} aria-label={`Remove transition ${index + 1}`}>
                          x
                        </button>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="tms-empty">Main-path steps will be auto-connected. Add transitions for approvals, returns, issue-only steps, or loop-backs.</div>
                )}
              </section>

              {templateMessage ? <div className={`tms-form-message ${templateError ? 'is-error' : 'is-ok'}`}>{templateMessage}</div> : null}

              <div className="tms-modal-actions">
                <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setTemplateModalOpen(false)} disabled={templateBusy}>
                  Cancel
                </button>
                <button className="tms-btn tms-btn-primary" type="submit" disabled={templateBusy}>
                  {templateBusy ? 'Saving...' : templateForm.id > 0 ? 'Update Template' : 'Save Template'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {templatePickerOpen ? (
        <div className="tms-modal-shell tms-modal-shell-top" role="dialog" aria-modal="true">
          <div className="tms-modal-backdrop" onClick={() => setTemplatePickerOpen(false)} />
          <div className="tms-modal tms-template-picker-modal">
            <div className="tms-modal-head">
              <div>
                <span className="tms-section-kicker">Existing Templates</span>
                <h2>Use Existing Template</h2>
                <p>Select a saved workflow template to fill the task timeline.</p>
              </div>
              <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setTemplatePickerOpen(false)}>
                Close
              </button>
            </div>
            <div className="tms-modal-body">
              <div className="tms-template-picker-list">
                {workflowTemplates.length ? workflowTemplates.map((template) => renderTemplateRow(template, 'Use This Template')) : (
                  <div className="tms-empty spacious">No workflow templates available.</div>
                )}
              </div>
            </div>
          </div>
        </div>
      ) : null}

      {modalOpen ? (
        <div className="tms-modal-shell" role="dialog" aria-modal="true">
          <div className="tms-modal-backdrop" onClick={() => setModalOpen(false)} />
          <div className="tms-modal">
            <div className="tms-modal-head">
              <div>
                <span className="tms-section-kicker">{form.id ? `Task #${form.id}` : 'New Task'}</span>
                <h2>{modalTitle}</h2>
                {detail?.timing?.label ? <p>{detail.timing.label}</p> : null}
              </div>
              <button className="tms-btn tms-btn-secondary" type="button" onClick={() => setModalOpen(false)}>
                Close
              </button>
            </div>

            {isDetailView && detail ? (
              <div className="tms-modal-body tms-detail-view">
                <section className="tms-detail-hero">
                  <div>
                    <span className="tms-row-code">{detail.task_type_name}</span>
                    <h3>{detail.title}</h3>
                    <p>{detail.description || 'No description entered.'}</p>
                  </div>
                  <span className={`tms-badge tone-${toneForTask(detail)}`}>{statusLabel(detail.lifecycle_status)}</span>
                </section>

                <section className="tms-detail-actions" aria-label="Task actions">
                  {detail.can_edit ? (
                    <>
                      <button className="tms-btn tms-btn-primary" type="button" onClick={editCurrentTask}>
                        Edit
                      </button>
                      <button className="tms-btn tms-btn-secondary" type="button" onClick={appendSubtaskToCurrentTask}>
                        Add Subtask
                      </button>
                    </>
                  ) : null}
                  {detail.can_delete ? (
                    <button className="tms-btn tms-btn-ghost" type="button" onClick={() => void removeTask(detail)}>
                      Delete
                    </button>
                  ) : null}
                </section>

                <section className="tms-detail-grid" aria-label="Task summary">
                  <div className="tms-detail-progress-card">
                    <span>Progress</span>
                    <strong>{Number(detail.progress_percent || 0)}%</strong>
                    <small>Manual update</small>
                  </div>
                  <div>
                    <span>Current</span>
                    <strong>{detail.current_step_title || 'No active step'}</strong>
                    <small>{responsibleText(detail)}</small>
                  </div>
                  <div>
                    <span>Schedule</span>
                    <strong>{detail.timing?.label || 'No timing data'}</strong>
                    <small>{detail.target_due_at ? `Due ${formatDateTime(detail.target_due_at)}` : 'No completion date'}</small>
                  </div>
                  <div>
                    <span>Office</span>
                    <strong>{detail.owner_section_name || detail.owner_division_name || 'No owner office'}</strong>
                    <small>{detail.workflow_template_name || 'No workflow'} / {detail.flow_mode}</small>
                  </div>
                  <div>
                    <span>Priority</span>
                    <strong>{statusLabel(detail.priority)}</strong>
                    <small>{detail.participants.length} participant{detail.participants.length === 1 ? '' : 's'}</small>
                  </div>
                </section>

                <section className="tms-progress-panel" aria-label="Manual progress">
                  <div>
                    <span className="tms-section-kicker">Progress</span>
                    <h3>{progressDraft}% complete</h3>
                  </div>
                  <input type="range" min="0" max="100" step="5" value={progressDraft} onChange={(event) => setProgressDraft(Number(event.target.value))} disabled={!detail.can_edit || progressBusy} />
                  <div className="tms-progress-track">
                    <span style={{ width: `${Math.max(0, Math.min(100, progressDraft))}%` }} />
                  </div>
                  {detail.can_edit ? (
                    <button className="tms-btn tms-btn-secondary" type="button" onClick={() => void updateTaskProgress()} disabled={progressBusy || progressDraft === Number(detail.progress_percent || 0)}>
                      {progressBusy ? 'Saving...' : 'Save Progress'}
                    </button>
                  ) : null}
                </section>

                {detail.remarks ? (
                  <section className="tms-detail-note">
                    <span>Remarks</span>
                    <p>{detail.remarks}</p>
                  </section>
                ) : null}

                <section className="tms-detail-section">
                  <div className="tms-section-head">
                    <div>
                      <span className="tms-section-kicker">Workflow Steps</span>
                      <h3>Step Status</h3>
                    </div>
                  </div>
                  {detail.steps.length ? (
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
                  ) : (
                    <div className="tms-empty">No subtasks added.</div>
                  )}
                </section>

                <section className="tms-detail-section">
                  <div className="tms-section-head">
                    <div>
                      <span className="tms-section-kicker">Participants</span>
                      <h3>Assignments and Invitations</h3>
                    </div>
                  </div>
                  {detail.participants.length ? (
                    <div className="tms-participant-list">
                      {detail.participants.map((participant) => (
                        <span key={participant.id} className="tms-participant-pill">
                          <strong>{participant.full_name}</strong>
                          <small>{participantStatusText(participant)}</small>
                        </span>
                      ))}
                    </div>
                  ) : (
                    <div className="tms-empty">No participants listed.</div>
                  )}
                </section>

                {formMessage ? <div className={`tms-form-message ${formError ? 'is-error' : 'is-ok'}`}>{formMessage}</div> : null}
              </div>
            ) : (
            <form className="tms-modal-body" onSubmit={(event) => void submitTask(event)}>
              {modalMode !== 'append-subtask' ? (
              <>
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
                      <button className="tms-btn tms-btn-secondary tms-compact-action" type="button" onClick={() => setTemplatePickerOpen(true)} disabled={modalBusy || workflowTemplates.length === 0}>
                        Use Existing Template
                      </button>
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
                    <input type="datetime-local" value={form.target_start_at} onChange={(event) => updateTargetStart(event.target.value)} disabled={modalBusy || modalReadOnly || workflowLocked} required={!workflowLocked} />
                  </label>

                  <label className="tms-check-field">
                    <input
                      type="checkbox"
                      checked={form.has_indefinite_timeline}
                      onChange={(event) => {
                        const checked = event.target.checked
                        setForm((current) => ({
                          ...current,
                          has_indefinite_timeline: checked,
                          target_due_at: checked ? '' : current.target_due_at,
                        }))
                        if (!checked && form.target_start_at) {
                          void fillTargetDueFromTimeline(form.target_start_at, form.timeline_steps)
                        }
                      }}
                      disabled={modalBusy || modalReadOnly}
                    />
                    <span>Task has indefinite timeline</span>
                  </label>

                  {!form.has_indefinite_timeline ? (
                    <label className="tms-field tms-calculated-field">
                      <span>Target completion</span>
                      <input type="datetime-local" value={form.target_due_at} onChange={(event) => updateField('target_due_at', event.target.value)} disabled={modalBusy || modalReadOnly} required={!form.has_indefinite_timeline} />
                      <small className="tms-help-text">Required when the task has a set completion target.</small>
                    </label>
                  ) : (
                    <div className="tms-field tms-static-field">
                      <span>Completion date</span>
                      <strong>Not set</strong>
                      <small className="tms-help-text">This task uses an indefinite timeline.</small>
                    </div>
                  )}
                </div>
              </section>
              </>
              ) : null}

              <section className="tms-form-section">
                <div className="tms-section-head">
                  <div>
                    <span className="tms-section-kicker">C</span>
                    <h3>Subtasks / Timeline</h3>
                  </div>
                  {canEditTimeline ? (
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
                  <span>Remove</span>
                </div>
                <div className="tms-timeline-list">
                  {form.timeline_steps.length ? form.timeline_steps.map((step, index) => {
                    const rowSections = sectionsForDivision(step.division_id)
                    const rowUsers = usersForStep(step)
                    return (
                      <div key={index} className="tms-timeline-row">
                        <div className="tms-timeline-index">{index + 1}</div>
                        <label className="tms-field tms-timeline-title">
                          <span>Subtask</span>
                          <input value={step.title} onChange={(event) => updateTimelineStep(index, { title: event.target.value })} disabled={modalBusy || !canEditTimeline} placeholder="Example: Prepare POW and plan draft" />
                        </label>
                        <label className="tms-field">
                          <span>Division</span>
                          <select value={step.division_id} onChange={(event) => updateTimelineStep(index, { division_id: event.target.value })} disabled={modalBusy || !canEditTimeline}>
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
                          <select value={step.section_id} onChange={(event) => updateTimelineStep(index, { section_id: event.target.value })} disabled={modalBusy || !canEditTimeline || !step.division_id}>
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
                          <select value={step.responsible_user_id} onChange={(event) => updateTimelineStep(index, { responsible_user_id: event.target.value })} disabled={modalBusy || !canEditTimeline || !step.division_id}>
                            <option value="">Chief assigns later</option>
                            {rowUsers.map((user) => (
                              <option key={user.id} value={user.id}>
                                {user.full_name}
                              </option>
                            ))}
                          </select>
                        </label>
                        {canEditTimeline ? (
                          <button className="tms-remove-user" type="button" onClick={() => removeTimelineStep(index)} disabled={modalBusy} aria-label={`Remove subtask ${index + 1}`}>
                            x
                          </button>
                        ) : (
                          <span className="tms-timeline-remove-placeholder" aria-hidden="true" />
                        )}
                      </div>
                    )
                  }) : (
                    <div className="tms-empty">No subtasks added.</div>
                  )}
                </div>
                <div className="tms-timeline-summary">
                  <strong>{form.has_indefinite_timeline ? 'Indefinite timeline' : 'With target completion'}</strong>
                  <span>{form.has_indefinite_timeline ? 'Only the target start date is saved. Completion date stays blank until the task is completed.' : 'The task will use the selected target completion date.'}</span>
                </div>
              </section>

              {modalMode !== 'append-subtask' ? (
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
              ) : null}

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
                      <h3>Assignments and Invitations</h3>
                    </div>
                  </div>
                  <div className="tms-participant-list">
                    {detail.participants.map((participant) => (
                      <span key={participant.id} className="tms-participant-pill">
                        <strong>{participant.full_name}</strong>
                        <small>{participantStatusText(participant)}</small>
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
                    {modalBusy ? 'Saving...' : modalMode === 'append-subtask' ? 'Save Subtask' : 'Save Task'}
                  </button>
                ) : null}
              </div>
            </form>
            )}
          </div>
        </div>
      ) : null}
    </div>
  )
}
