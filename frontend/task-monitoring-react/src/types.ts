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
  division_name: string
  section_name: string
}

export type TmsTaskType = {
  id: number
  code: string
  name: string
  scope_code: string
  workflow_rule: string
  assignment_role_label: string
  reference_label: string
  allow_multi_assignees: number
  show_date_surveyed: number
  show_date_received: number
  show_date_started: number
  show_target_completion: number
  show_progress: number
  show_reference_code: number
  sort_order: number
  is_active: number
}

export type TmsTask = {
  id: number
  task_type_id: number
  task_type_code: string
  task_type_name: string
  task_type_scope_code: string
  assignment_role_label: string
  reference_label: string
  workflow_rule: string
  allow_multi_assignees: number
  show_date_surveyed: number
  show_date_received: number
  show_date_started: number
  show_target_completion: number
  show_progress: number
  show_reference_code: number
  project_id: number | null
  document_id: number | null
  created_by_user_id: number
  updated_by_user_id?: number | null
  owner_user_id?: number | null
  division_id?: number | null
  section_id?: number | null
  scope_code: string
  project_code: string
  project_title: string
  description: string
  deo: string
  lgu: string
  assignee_display: string
  date_surveyed?: string | null
  date_received?: string | null
  date_started?: string | null
  target_completion?: string | null
  remaining_workdays?: number | null
  progress_percent?: number | null
  status_label: string
  reference_code: string
  remarks: string
  completed_at?: string | null
  created_at: string
  updated_at: string
  created_by_name?: string
  owner_name?: string
  linked_project_code?: string
  linked_project_title?: string
  assignees_text?: string
  can_edit?: boolean
}

export type TmsUser = {
  id: number
  full_name: string
  username: string
  email: string
  section_name: string
  division_name: string
  official_title: string
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
  viewMode: 'my' | 'all'
  filters: TmsFilters
  taskTypes: TmsTaskType[]
  tasks: TmsTask[]
  users: TmsUser[]
  projects: TmsProject[]
  statusOptions: string[]
  tablesReady: boolean
}

export type TmsTaskDetail = TmsTask & {
  assignee_user_ids: number[]
}

declare global {
  interface Window {
    __APP__?: AppConfig
    __TMS_BOOTSTRAP__?: TmsBootstrap
  }
}
