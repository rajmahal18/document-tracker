import { useMemo, type CSSProperties } from 'react'
import type { OrgDivision, OrgUser } from '../types/org'
import { countLeaders, countVisiblePeople, countVisibleSections, divisionKicker, divisionMatches, divisionThemeClass, flattenSections } from '../lib/org-helpers'
import { BuildingIcon } from './Icons'
import { SectionBlock } from './SectionBlock'
import { useScrollReveal } from '../hooks/useScrollReveal'

type Props = {
  division: OrgDivision
  query: string
  forceExpandMembers: boolean
  expandedSections: Record<number, boolean>
  onToggleSection: (sectionId: number) => void
  onViewUser: (user: OrgUser) => void
  onEdit: (user: OrgUser) => void
  hidden?: boolean
  spotlight?: boolean
  revealOrder?: number
}

export function DivisionBlock({ division, query, forceExpandMembers, expandedSections, onToggleSection, onViewUser, onEdit, hidden = false, spotlight = false, revealOrder = 0 }: Props) {
  const visible = useMemo(() => divisionMatches(division, query), [division, query])
  const sections = useMemo(() => flattenSections(division), [division])
  const visibleSections = useMemo(() => countVisibleSections(division, query), [division, query])
  const visiblePeople = useMemo(() => countVisiblePeople(division, query), [division, query])
  const leaderCount = useMemo(() => countLeaders(division), [division])
  const themeClass = useMemo(() => divisionThemeClass(division.name), [division.name])
  const reveal = useScrollReveal({ delayMs: Math.min(revealOrder * 65, 220), threshold: 0.08, rootMargin: '0px 0px -8% 0px' })

  if (!visible || hidden) return null

  return (
    <article
      ref={reveal.ref}
      className={`org-division-shell org-scroll-reveal ${reveal.isVisible ? 'is-visible' : ''} ${themeClass} ${spotlight ? 'is-top-office ring-1 ring-brand-200' : ''}`}
      style={{ '--org-reveal-delay': `${reveal.delayMs}ms` } as CSSProperties}
    >
      <div className="org-division-node-header">
        <div className="org-division-glow" aria-hidden="true" />
        <div className="org-division-node-main">
          <span className="org-division-icon" aria-hidden="true">
            <BuildingIcon className="h-4 w-4" />
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-[10px] font-bold uppercase tracking-[0.16em] text-brand-700">{divisionKicker(division.name)}</span>
              {spotlight ? <span className="org-node-state">Top office</span> : null}
            </div>
            <h2>{division.name}</h2>
          </div>
        </div>

        <div className="org-division-node-meta">
          <span>{visiblePeople || division.user_count} people</span>
          <span>{visibleSections || division.section_count} sections</span>
          <span>{leaderCount} leaders</span>
        </div>
      </div>

      <div className="divide-y divide-ink-100">
        {sections.map((section, index) => (
          <SectionBlock
            key={section.id}
            section={section}
            query={query}
            revealOrder={index}
            expanded={forceExpandMembers || !!expandedSections[section.id]}
            onToggleExpanded={() => onToggleSection(section.id)}
            onView={onViewUser}
            onEdit={onEdit}
          />
        ))}
      </div>
    </article>
  )
}
