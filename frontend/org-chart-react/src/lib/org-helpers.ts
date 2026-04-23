import type { AssistantCandidate, OrgDivision, OrgSection, OrgUser } from '../types/org'

export function normalizeText(value: string | null | undefined) {
  return String(value ?? '').trim().toLowerCase()
}

export function searchBlob(...values: Array<string | null | undefined>) {
  return values.map((value) => normalizeText(value)).filter(Boolean).join(' ')
}

export function userInitials(name: string) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  return (parts[0]?.[0] ?? 'U') + (parts[1]?.[0] ?? '')
}

export function roleLabel(role: OrgUser['authority_role']) {
  switch (role) {
    case 'director': return 'Director'
    case 'division_head': return 'Division Head'
    case 'division_assistant': return 'Division Assistant'
    case 'section_head': return 'Section Head'
    case 'admin': return 'Admin'
    default: return 'Staff'
  }
}

export function divisionKicker(divisionName: string) {
  const n = normalizeText(divisionName)
  if (n.includes('director office')) return 'Top Office'
  if (n.includes('planning') && n.includes('programming')) return 'Planning + Programming'
  if (n.includes('survey') && n.includes('design')) return 'Survey + Design'
  if (n.includes('special project')) return 'Special Projects'
  return 'Division'
}

export function divisionThemeClass(divisionName: string) {
  const n = normalizeText(divisionName)
  if (n.includes('director office') || n.includes('office of the director')) return 'theme-director'
  if (n.includes('planning') && n.includes('programming')) return 'theme-ppd'
  if (n.includes('survey') && n.includes('design')) return 'theme-sdd'
  if (n.includes('special project')) return 'theme-spd'
  return 'theme-default'
}

export function parseAssistantCandidates(user: OrgUser): AssistantCandidate[] {
  try {
    const parsed = JSON.parse(user.assistant_candidates_json || '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export function parseSelectedAssistantIds(user: OrgUser): number[] {
  const csv = String(user.chief_assistant_user_ids || '').trim()
  if (!csv) return []
  return csv.split(',').map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
}

export function flattenSections(division: OrgDivision) {
  return [division.chief_office, ...division.child_sections].filter(Boolean) as OrgSection[]
}

export function divisionMatches(division: OrgDivision, query: string) {
  const q = normalizeText(query)
  if (!q) return true
  if (searchBlob(division.name).includes(q)) return true
  return flattenSections(division).some((section) => sectionMatches(section, q))
}

export function sectionMatches(section: OrgSection, query: string) {
  const q = normalizeText(query)
  if (!q) return true
  if (searchBlob(section.name).includes(q)) return true
  return [...section.leaders, ...section.members].some((user) => userMatches(user, q))
}

export function userMatches(user: OrgUser, query: string) {
  const q = normalizeText(query)
  if (!q) return true
  return searchBlob(
    user.full_name,
    user.display_title,
    user.section_name,
    user.authority_role,
    user.chief_assistant_names,
  ).includes(q)
}

export function countVisibleSections(division: OrgDivision, query: string) {
  return flattenSections(division).filter((section) => sectionMatches(section, query)).length
}

export function countVisiblePeople(division: OrgDivision, query: string) {
  return flattenSections(division).reduce((total, section) => {
    if (!sectionMatches(section, query)) return total
    return total + [...section.leaders, ...section.members].filter((user) => userMatches(user, query)).length
  }, 0)
}

export function countLeaders(division: OrgDivision) {
  return flattenSections(division).reduce((total, section) => total + section.leaders.length, 0)
}
