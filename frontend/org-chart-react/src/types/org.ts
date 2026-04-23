export type AuthorityRole = 'director' | 'division_head' | 'division_assistant' | 'section_head' | 'staff' | 'admin'

export interface AssistantCandidate {
  id: number
  full_name: string
  display_title: string
  section_id: number
  section_name: string
  division_id: number
  division_name: string
}

export interface OrgUser {
  id: number
  full_name: string
  email: string
  username?: string
  profile_photo_url?: string
  avatar_url?: string
  photo_url?: string
  documents_received_count?: number
  documents_forwarded_count?: number
  documents_incoming_count?: number
  documents_pending_count?: number
  documents_completed_count?: number
  authority_role: AuthorityRole
  display_title: string
  official_title: string
  section_name: string
  section_id: number
  division_id: number
  division_name: string
  permanent: number
  chief_assistant_user_id?: number
  chief_assistant_name?: string
  chief_assistant_user_ids?: string
  chief_assistant_names?: string
  assistant_for_count?: number
  assistant_for_names?: string
  is_online?: boolean
  show_presence?: boolean
  is_leader?: boolean
  can_edit?: boolean
  can_upload_photo?: boolean
  can_assign_assistant?: boolean
  assistant_candidates_json?: string
}

export interface OrgSection {
  id: number
  name: string
  users: OrgUser[]
  leaders: OrgUser[]
  members: OrgUser[]
  member_count: number
  is_chief_office?: boolean
}

export interface OrgDivision {
  id: number
  name: string
  section_count: number
  user_count: number
  chief_office: OrgSection | null
  child_sections: OrgSection[]
  sort_weight?: number
}

export interface AssignableRolesMap {
  [key: string]: string
}

export interface OrgChartBootstrap {
  rootDivision: OrgDivision | null
  childDivisions: OrgDivision[]
  spotlightDivision: OrgDivision | null
  divisions: OrgDivision[]
  assignableRoles: AssignableRolesMap
  canManageOrg: boolean
  viewerDivisionId: number
  stats: {
    activeDivisions: number
    activeUsers: number
    totalSections: number
  }
  copy?: {
    eyebrow?: string
    title?: string
    subtitle?: string
  }
}

export interface AppRuntimeConfig {
  base: string
  api: string
  public: string
  assets: string
  csrf: string
  currentPage?: string
  isDevelopment?: boolean
}

export interface UpdateOrgUserPayload {
  target_user_id: number
  full_name: string
  email: string
  official_title: string
  authority_role: string
  permanent: boolean
  chief_assistant_user_ids: number[]
  profile_photo?: File | null
  remove_profile_photo?: boolean
}
