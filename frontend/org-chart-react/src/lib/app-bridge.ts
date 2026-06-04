import type { AppRuntimeConfig, OrgChartBootstrap, OrgUserActivityStats, UpdateOrgUserPayload } from '../types/org'
import { mockBootstrap } from './mock-data'

export function getAppConfig(): AppRuntimeConfig {
  return window.__APP__ ?? {
    base: '',
    api: '/api',
    public: '/public',
    assets: '/assets',
    csrf: '',
    currentPage: 'org_chart.php',
    isDevelopment: true,
  }
}

export function getBootstrapData(): OrgChartBootstrap {
  return window.__ORG_CHART_BOOTSTRAP__ ?? mockBootstrap
}

export async function updateOrgUser(payload: UpdateOrgUserPayload) {
  const app = getAppConfig()
  const fd = new FormData()
  fd.set('csrf_token', app.csrf || '')
  fd.set('target_user_id', String(payload.target_user_id))
  fd.set('full_name', payload.full_name)
  fd.set('email', payload.email)
  fd.set('official_title', payload.official_title)
  fd.set('authority_role', payload.authority_role)
  fd.set('permanent', payload.permanent ? '1' : '0')
  if (payload.profile_photo) {
    fd.set('profile_photo', payload.profile_photo)
  }
  if (payload.remove_profile_photo) {
    fd.set('remove_profile_photo', '1')
  }
  payload.chief_assistant_user_ids.forEach((id) => fd.append('chief_assistant_user_ids[]', String(id)))

  const response = await fetch(`${app.api}/org_chart_update_user.php`, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  })

  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid response from server.' }))
  if (!response.ok || !data.ok) {
    throw new Error(data.error || 'Failed to update org user.')
  }
  return data
}

export async function fetchOrgUserStats(userId: number): Promise<OrgUserActivityStats> {
  const app = getAppConfig()
  const response = await fetch(`${app.api}/org_chart_user_stats.php?user_id=${encodeURIComponent(String(userId))}`, {
    credentials: 'same-origin',
  })

  const data = await response.json().catch(() => ({ ok: false, error: 'Invalid response from server.' }))
  if (!response.ok || !data.ok || !data.stats) {
    throw new Error(data.error || 'Failed to load org user stats.')
  }

  return data.stats as OrgUserActivityStats
}
