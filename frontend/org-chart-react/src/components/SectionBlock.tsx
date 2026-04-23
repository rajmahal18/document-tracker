import { useMemo, type CSSProperties } from 'react'
import type { OrgSection, OrgUser } from '../types/org'
import { useScrollReveal } from '../hooks/useScrollReveal'
import { sectionMatches, userMatches } from '../lib/org-helpers'
import { ChevronDownIcon, CrownIcon, UsersIcon } from './Icons'
import { UserRow } from './UserRow'

type Props = {
  section: OrgSection
  query: string
  expanded: boolean
  onToggleExpanded: () => void
  onView: (user: OrgUser) => void
  onEdit: (user: OrgUser) => void
  revealOrder?: number
}

export function SectionBlock({ section, query, expanded, onToggleExpanded, onView, onEdit, revealOrder = 0 }: Props) {
  const isVisible = useMemo(() => sectionMatches(section, query), [section, query])
  const visibleLeaders = useMemo(() => section.leaders.filter((member) => userMatches(member, query)), [section, query])
  const visibleMembers = useMemo(() => section.members.filter((member) => userMatches(member, query)), [section, query])
  const reveal = useScrollReveal({ delayMs: Math.min(50 + (revealOrder * 40), 220), threshold: 0.12, rootMargin: '0px 0px -6% 0px' })

  if (!isVisible) return null

  return (
    <section
      ref={reveal.ref}
      className={`org-section-shell org-scroll-reveal ${reveal.isVisible ? 'is-visible' : ''}`}
      style={{ '--org-reveal-delay': `${reveal.delayMs}ms` } as CSSProperties}
    >
      <div className="org-section-brandmark" aria-hidden="true" />
      <div className="org-section-waterline" aria-hidden="true" />
      <div className="flex flex-wrap items-start justify-between gap-3 pl-3">
        <div className="min-w-0 space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ${section.is_chief_office ? 'bg-brand-50 text-brand-700' : 'bg-ink-100 text-ink-600'}`}>
              {section.is_chief_office ? 'Chief Office' : 'Section'}
            </span>
            <span className="metric-chip text-[11px] font-medium text-ink-500">
              {visibleLeaders.length + visibleMembers.length} visible
            </span>
          </div>
          <div className="flex min-w-0 items-center gap-2.5">
            <span className="org-section-title-icon" aria-hidden="true" />
            <h3 className="min-w-0 text-base font-semibold text-ink-900">{section.name}</h3>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <span className="metric-chip inline-flex items-center gap-1.5 text-xs font-medium text-ink-600">
            <CrownIcon className="h-3.5 w-3.5 text-brand-700" />
            {section.leaders.length} leaders
          </span>
          <span className="metric-chip inline-flex items-center gap-1.5 text-xs font-medium text-ink-600">
            <UsersIcon className="h-3.5 w-3.5 text-ink-500" />
            {section.members.length} members
          </span>
          {section.members.length > 0 ? (
            <button type="button" className="btn-secondary px-3 py-2 text-xs md:hidden" onClick={onToggleExpanded}>
              <ChevronDownIcon className={`h-4 w-4 transition ${expanded ? 'rotate-180' : ''}`} />
              {expanded ? 'Hide members' : 'Show members'}
            </button>
          ) : null}
        </div>
      </div>

      <div className="mt-4 pl-3">
        {visibleLeaders.length > 0 ? (
          <p className="mb-2.5 text-center text-[10px] font-bold uppercase tracking-[0.16em] text-brand-700">Leadership</p>
        ) : null}
        <div className="org-leader-grid">
          {visibleLeaders.map((user, index) => <UserRow key={user.id} user={user} onView={onView} onEdit={onEdit} revealOrder={index} />)}
        </div>
      </div>

      {section.members.length > 0 ? (
        <div className={`section-grid-line mt-4 pt-4 pl-3 ${expanded ? '' : 'hidden md:block'}`}>
          <p className="mb-2.5 text-[10px] font-bold uppercase tracking-[0.16em] text-ink-500">Members</p>
          <div className="org-member-grid">
            {visibleMembers.map((user, index) => <UserRow key={user.id} user={user} onView={onView} onEdit={onEdit} revealOrder={visibleLeaders.length + index + 1} />)}
          </div>
        </div>
      ) : null}
    </section>
  )
}
