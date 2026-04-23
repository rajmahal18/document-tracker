import { type CSSProperties } from 'react'
import { CrownIcon, PencilIcon } from './Icons'
import type { OrgUser } from '../types/org'
import { getAppConfig } from '../lib/app-bridge'
import { useScrollReveal } from '../hooks/useScrollReveal'
import { roleLabel, userInitials } from '../lib/org-helpers'

type Props = {
  user: OrgUser
  onView: (user: OrgUser) => void
  onEdit: (user: OrgUser) => void
  revealOrder?: number
}

export function UserRow({ user, onView, onEdit, revealOrder = 0 }: Props) {
  const canEdit = Boolean(user.can_edit || user.can_assign_assistant)
  const photoUrl = user.profile_photo_url || user.avatar_url || user.photo_url || ''
  const watermarkUrl = `${getAppConfig().assets.replace(/\/$/, '')}/mpwlogo1.png`
  const reveal = useScrollReveal({ delayMs: Math.min(revealOrder * 28, 180), threshold: 0.1, rootMargin: '0px 0px -4% 0px' })

  return (
    <article
      ref={reveal.ref}
      className={`org-user-row org-scroll-reveal group ${reveal.isVisible ? 'is-visible' : ''} ${user.is_leader ? 'is-leader' : 'is-member'}`}
      style={{ '--org-reveal-delay': `${reveal.delayMs}ms` } as CSSProperties}
      role="button"
      tabIndex={0}
      onClick={() => onView(user)}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onView(user)
        }
      }}
    >
      <div className="org-id-card-body">
        <div className={`org-id-photo ${user.is_leader ? 'is-leader' : 'is-member'}`} aria-hidden="true">
          {photoUrl ? (
            <img src={photoUrl} alt="" loading="lazy" />
          ) : (
            <span>{userInitials(user.full_name)}</span>
          )}
        </div>

        <div className="org-id-details">
          <img className="org-id-watermark" src={watermarkUrl} alt="" loading="lazy" aria-hidden="true" />
          {canEdit ? (
            <button
              type="button"
              className="org-id-edit-btn"
              onClick={(event) => {
                event.stopPropagation()
                onEdit(user)
              }}
            >
              <PencilIcon className="h-3.5 w-3.5" />
              <span>Edit</span>
            </button>
          ) : null}

          <div className="org-id-name-line">
            <h4 className="org-id-name">{user.full_name}</h4>
            {user.show_presence ? (
              <span className={`org-presence-dot ${user.is_online ? 'is-online' : 'is-offline'}`} title={user.is_online ? 'Online' : 'Offline'} />
            ) : null}
          </div>
          <div className="org-id-title-line">
            {user.is_leader ? (
              <span className="org-leader-crown" title="Chief / Head">
                <CrownIcon className="h-3.5 w-3.5" />
              </span>
            ) : null}
            <p className="org-id-title">{user.display_title}</p>
          </div>

          <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[10px] font-medium">
            <span className={`org-role-pill ${user.is_leader ? 'is-leader' : 'is-member'}`}>
              {roleLabel(user.authority_role)}
            </span>
          </div>
        </div>
      </div>
    </article>
  )
}
