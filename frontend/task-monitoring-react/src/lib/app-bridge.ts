import type { AppConfig, TmsBootstrap, TmsTaskDetail } from '../types'

export function getAppConfig(): AppConfig {
  return (
    window.__APP__ ?? {
      base: '',
      api: '/api',
      public: '/public',
      assets: '/assets',
      csrf: '',
      currentPage: 'task_monitoring.php',
      isDevelopment: true,
    }
  )
}

export function getBootstrapData(): TmsBootstrap {
  return (
    window.__TMS_BOOTSTRAP__ ?? {
      viewer: {
        id: 0,
        full_name: 'User',
        username: '',
        official_title: '',
        division_id: null,
        section_id: null,
        division_name: '',
        section_name: '',
      },
      canManageAll: false,
      viewMode: 'my',
      filters: { type: '', status: '', q: '' },
      taskTypes: [],
      workflowTemplates: [],
      rolePresets: [],
      divisions: [],
      sections: [],
      tasks: [],
      users: [],
      projects: [],
      statusOptions: [],
      tablesReady: false,
    }
  )
}

export async function respondToInvitation(taskId: number, responseValue: 'join' | 'decline') {
  const app = getAppConfig()
  const formData = new FormData()
  formData.set('csrf_token', app.csrf || '')
  formData.set('task_id', String(taskId))
  formData.set('response', responseValue)

  const response = await fetch(`${app.api}/tms_participant_response.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to update invitation.')
  }
  return data
}

export async function fetchTaskDetail(taskId: number): Promise<TmsTaskDetail> {
  const app = getAppConfig()
  const response = await fetch(`${app.api}/tms_task_detail.php?id=${encodeURIComponent(String(taskId))}`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to load task.')
  }
  return data.task as TmsTaskDetail
}

export async function saveTask(formData: FormData) {
  const app = getAppConfig()
  const response = await fetch(`${app.api}/tms_task_save.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to save task.')
  }
  return data
}

export async function saveTaskType(formData: FormData) {
  const app = getAppConfig()
  const response = await fetch(`${app.api}/tms_task_type_save.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to save task type.')
  }
  return data
}

export async function assignTaskStep(taskStepId: number, userId: number) {
  const app = getAppConfig()
  const formData = new FormData()
  formData.set('csrf_token', app.csrf || '')
  formData.set('task_step_id', String(taskStepId))
  formData.set('user_id', String(userId))

  const response = await fetch(`${app.api}/tms_task_step_assign.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to assign step.')
  }
  return data
}

export async function deleteTask(taskId: number) {
  const app = getAppConfig()
  const formData = new FormData()
  formData.set('csrf_token', app.csrf || '')
  formData.set('id', String(taskId))

  const response = await fetch(`${app.api}/tms_task_delete.php`, {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid server response.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to delete task.')
  }
  return data
}
