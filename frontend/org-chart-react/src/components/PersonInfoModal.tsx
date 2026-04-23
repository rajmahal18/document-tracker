import { roleLabel, userInitials } from '../lib/org-helpers'
import type { OrgUser } from '../types/org'

type Props = {
  user: OrgUser | null
  onClose: () => void
  onEdit: (user: OrgUser) => void
}

export function PersonInfoModal({ user, onClose, onEdit }: Props) {
  if (!user) return null

  const photoUrl = user.profile_photo_url || user.avatar_url || user.photo_url || ''
  const canEdit = Boolean(user.can_edit || user.can_assign_assistant)
  const incomingCount = user.documents_incoming_count ?? user.documents_received_count ?? 0
  const pendingCount = user.documents_pending_count ?? 0
  const completedCount = user.documents_completed_count ?? user.documents_forwarded_count ?? 0

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/45 px-4 py-6 backdrop-blur-sm" onClick={onClose}>
      <section className="person-modal w-full max-w-2xl overflow-hidden" onClick={(event) => event.stopPropagation()}>
        <header className="person-modal-header">
          <div className={`org-person-avatar ${user.is_leader ? 'is-leader' : 'is-member'}`} aria-hidden="true">
            {photoUrl ? <img src={photoUrl} alt="" loading="lazy" /> : <span>{userInitials(user.full_name)}</span>}
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-brand-700">{roleLabel(user.authority_role)}</p>
            <h3 className="mt-1 truncate text-lg font-semibold text-ink-950">{user.full_name}</h3>
            <p className="mt-1 truncate text-sm text-ink-600">{user.display_title}</p>
          </div>
        </header>

        <div className="space-y-4 px-4 py-4 md:px-5">
          <div className="person-info-grid">
            <InfoItem label="Division" value={user.division_name} />
            <InfoItem label="Section" value={user.section_name} />
            <InfoItem label="Email" value={user.email || 'Not set'} />
            <InfoItem label="Employment" value={user.permanent ? 'Permanent' : 'Non-permanent'} />
          </div>

          <section>
            <p className="person-modal-label">Document activity</p>
            <div className="person-doc-grid">
              <DocMetric label="Incoming" helper="Waiting for receive" value={incomingCount} tone="incoming" />
              <DocMetric label="Pending" helper="Already with user for action" value={pendingCount} tone="pending" />
              <DocMetric label="Completed" helper="User part is already done" value={completedCount} tone="complete" />
            </div>
          </section>

          {user.chief_assistant_names ? (
            <section className="person-note">
              <p className="person-modal-label">Assigned assistants</p>
              <p>{user.chief_assistant_names}</p>
            </section>
          ) : null}

          {user.assistant_for_names ? (
            <section className="person-note">
              <p className="person-modal-label">Assistant for</p>
              <p>{user.assistant_for_names}</p>
            </section>
          ) : null}
        </div>

        <footer className="person-modal-footer">
          <button type="button" className="btn-secondary" onClick={onClose}>Close</button>
          {canEdit ? (
            <button type="button" className="btn-primary" onClick={() => onEdit(user)}>Edit</button>
          ) : null}
        </footer>
      </section>
    </div>
  )
}

function InfoItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="person-info-item">
      <p>{label}</p>
      <span>{value}</span>
    </div>
  )
}

function DocMetric({ label, value, helper, tone }: { label: string; value?: number; helper?: string; tone?: 'incoming' | 'pending' | 'complete' }) {
  return (
    <div className={`person-doc-metric ${tone ? `tone-${tone}` : ''}`}>
      <p>{label}</p>
      {helper ? <small>{helper}</small> : null}
      <span>{typeof value === 'number' ? value : 0}</span>
    </div>
  )
}
