export type AppConfig = {
  base: string
  api: string
  public: string
  assets: string
  csrf: string
  currentPage: string
  isDevelopment: boolean
}

export type TmsViewer = {
  id: number
  full_name: string
  username: string
  official_title: string
  division_id: number | null
  section_id: number | null
  division_name: string
  section_name: string
}

export type TmsTaskType = {
  id: number
  code: string
  name: string
  description: string
  owner_division_id: number | null
  owner_section_id: number | null
  default_priority: string
  default_workflow_template_id: number | null
  is_ipcr_relevant: number
  is_active: number
  sort_order: number
}

export type TmsWorkflowTemplate = {
  id: number
  task_type_id: number | null
  task_type_name: string
  name: string
  description: string
  flow_mode: 'sequential' | 'parallel' | 'mixed' | string
  owner_division_id: number | null
  owner_section_id: number | null
  is_default: number
  is_active: number
  step_count: number
  estimated_working_minutes: number
}

export type TmsRolePreset = {
  id: number
  role_label: string
  description: string
  sort_order: number
}

export type TmsTask = {
  id: number
  task_type_id: number
  workflow_template_id: number | null
  current_step_id: number | null
  project_id: number | null
  document_id: number | null
  created_by_user_id: number
  updated_by_user_id?: number | null
  owner_division_id?: number | null
  owner_section_id?: number | null
  title: string
  description: string
  priority: string
  flow_mode: string
  lifecycle_status: string
  target_start_at?: string | null
  target_due_at?: string | null
  started_at?: string | null
  completed_at?: string | null
  estimated_working_minutes?: number | null
  actual_working_minutes?: number | null
  remarks: string
  created_at: string
  updated_at: string
  task_type_code: string
  task_type_name: string
  workflow_template_name: string
  workflow_flow_mode: string
  current_step_title?: string
  current_step_status?: string
  current_role_label?: string
  current_responsible_name?: string
  current_responsible_division_name?: string
  current_responsible_section_name?: string
  created_by_name?: string
  owner_division_name?: string
  owner_section_name?: string
  participants_text?: string
  timing_tone?: 'open' | 'soon' | 'overdue' | 'done' | string
  timing_label?: string
  timing_days?: number | null
  can_edit?: boolean
  can_delete?: boolean
  permissions?: TmsTaskPermissions
}

export type TmsTaskStep = {
  id: number
  task_id: number
  workflow_step_id: number | null
  step_order: number
  title: string
  instructions: string
  responsible_division_id: number | null
  responsible_section_id: number | null
  responsible_user_id: number | null
  role_label: string
  status: string
  planned_start_at?: string | null
  planned_due_at?: string | null
  started_at?: string | null
  completed_at?: string | null
  estimated_working_minutes?: number | null
  actual_working_minutes?: number | null
  can_run_parallel: number
  requires_output: number
  requires_validation: number
  is_ipcr_creditable: number
  is_completion_step: number
  responsible_user_name: string
  responsible_division_name: string
  responsible_section_name: string
}

export type TmsParticipant = {
  id: number
  task_id: number
  task_step_id: number | null
  user_id: number
  division_id: number | null
  section_id: number | null
  participant_role_label: string
  participation_status: string
  is_lead: number
  full_name: string
  section_name: string
  division_name: string
}

export type TmsUser = {
  id: number
  full_name: string
  username: string
  email: string
  profile_photo_url: string
  avatar_initials: string
  section_id: number | null
  division_id: number | null
  section_name: string
  division_name: string
  official_title: string
}

export type TmsDivision = {
  id: number
  name: string
}

export type TmsSection = {
  id: number
  name: string
  division_id: number | null
  division_name: string
}

export type TmsProject = {
  id: number
  project_code: string
  title: string
}

export type TmsFilters = {
  type: string
  status: string
  q: string
}

export type TmsBootstrap = {
  viewer: TmsViewer
  canManageAll: boolean
  viewMode: 'my' | 'section' | 'division' | 'all'
  filters: TmsFilters
  taskTypes: TmsTaskType[]
  workflowTemplates: TmsWorkflowTemplate[]
  rolePresets: TmsRolePreset[]
  divisions: TmsDivision[]
  sections: TmsSection[]
  tasks: TmsTask[]
  users: TmsUser[]
  projects: TmsProject[]
  statusOptions: string[]
  tablesReady: boolean
}

export type TmsTaskPermissions = {
  can_view_task: boolean
  can_edit_task: boolean
  can_delete_task: boolean
  can_supervise_task: boolean
  is_creator: boolean
  is_participant: boolean
  is_lead: boolean
  is_invited: boolean
}

export type TmsTaskDetail = TmsTask & {
  context: Record<string, unknown>
  ipcr_metadata: Record<string, unknown>
  timing: {
    tone: string
    label: string
    days: number | null
  }
  steps: TmsTaskStep[]
  participants: TmsParticipant[]
  participant_user_ids: number[]
  participant_role_labels: Record<string, string>
  permissions: TmsTaskPermissions
}

declare global {
  interface Window {
    __APP__?: AppConfig
    __TMS_BOOTSTRAP__?: TmsBootstrap
  }
}
