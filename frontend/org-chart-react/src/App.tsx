import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import { DivisionBlock } from './components/DivisionBlock'
import { EditOrgUserModal } from './components/EditOrgUserModal'
import { ArrowLeftIcon, ArrowRightIcon } from './components/Icons'
import { PersonInfoModal } from './components/PersonInfoModal'
import { TopToolbar } from './components/TopToolbar'
import { countVisiblePeople, countVisibleSections, divisionMatches } from './lib/org-helpers'
import { getBootstrapData, updateOrgUser } from './lib/app-bridge'
import type { OrgUser, UpdateOrgUserPayload } from './types/org'
import mpwLogo from './assets/mpwlogo1.png'

const bootstrap = getBootstrapData()

export default function App() {
  const [query, setQuery] = useState('')
  const [expandedSections, setExpandedSections] = useState<Record<number, boolean>>({})
  const [forceExpandMembers, setForceExpandMembers] = useState(false)
  const [selectedUser, setSelectedUser] = useState<OrgUser | null>(null)
  const [viewedUser, setViewedUser] = useState<OrgUser | null>(null)
  const [toast, setToast] = useState('')
  const divisionRailRef = useRef<HTMLDivElement | null>(null)

  const topDivision = useMemo(() => bootstrap.rootDivision || bootstrap.spotlightDivision, [])
  const divisions = useMemo(() => bootstrap.childDivisions.filter((division) => divisionMatches(division, query)), [query])
  const visibleDivisions = divisions
  const visibleDivisionCount = visibleDivisions.length + (bootstrap.rootDivision && divisionMatches(bootstrap.rootDivision, query) ? 1 : 0)
  const visibleSectionCount = (bootstrap.rootDivision ? countVisibleSections(bootstrap.rootDivision, query) : 0) + visibleDivisions.reduce((total, division) => total + countVisibleSections(division, query), 0)
  const visiblePeopleCount = (bootstrap.rootDivision ? countVisiblePeople(bootstrap.rootDivision, query) : 0) + visibleDivisions.reduce((total, division) => total + countVisiblePeople(division, query), 0)
  const showDivisionArrows = visibleDivisions.length > 1
  const hasQuery = query.trim().length > 0

  useEffect(() => {
    if (!divisionRailRef.current || visibleDivisions.length <= 1) return

    window.requestAnimationFrame(() => {
      const rail = divisionRailRef.current
      const viewerDivisionIndex = visibleDivisions.findIndex((division) => division.id === bootstrap.viewerDivisionId)
      const targetIndex = viewerDivisionIndex >= 0 ? viewerDivisionIndex : Math.floor(visibleDivisions.length / 2)
      const target = rail?.children[targetIndex] as HTMLElement | undefined
      target?.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' })
    })
  }, [visibleDivisions, query])

  const scrollDivisionRail = (direction: 'left' | 'right') => {
    const rail = divisionRailRef.current
    if (!rail) return

    const firstPanel = rail.querySelector<HTMLElement>('.org-division-panel')
    const distance = firstPanel ? firstPanel.offsetWidth + 18 : rail.clientWidth * 0.84
    rail.scrollBy({ left: direction === 'left' ? -distance : distance, behavior: 'smooth' })
  }

  const openEdit = (user: OrgUser) => {
    setSelectedUser(user)
  }

  const resetView = () => {
    setQuery('')
    setForceExpandMembers(false)
    setExpandedSections({})
  }

  const onSave = async (payload: UpdateOrgUserPayload) => {
    await updateOrgUser(payload)
    setToast('Org user updated successfully. Reloading latest state...')
    window.setTimeout(() => {
      window.location.reload()
    }, 500)
  }

  return (
    <div
      className={`org-page min-h-screen px-3 py-4 md:px-5 md:py-6 ${hasQuery ? 'is-searching' : ''}`}
      style={{ '--org-logo-url': `url(${mpwLogo})` } as CSSProperties}
    >
      <div className="org-ambient-grid" aria-hidden="true" />
      <div className="mx-auto max-w-[1500px] space-y-5">
        <TopToolbar
          query={query}
          onQueryChange={setQuery}
          onReset={resetView}
          stats={bootstrap.stats}
          copy={bootstrap.copy}
          visibleDivisionCount={visibleDivisionCount}
          visibleSectionCount={visibleSectionCount}
          visiblePeopleCount={visiblePeopleCount}
        />

        {toast ? (
          <div className="org-toast px-4 py-3 text-sm text-brand-700">{toast}</div>
        ) : null}

        {topDivision && divisionMatches(topDivision, query) ? (
          <div className="org-reveal">
            <DivisionBlock
              division={topDivision}
              query={query}
              revealOrder={0}
              forceExpandMembers={forceExpandMembers}
              expandedSections={expandedSections}
              onToggleSection={(sectionId) => setExpandedSections((current) => ({ ...current, [sectionId]: !current[sectionId] }))}
              onViewUser={setViewedUser}
              onEdit={openEdit}
              spotlight
            />
          </div>
        ) : null}

        {visibleDivisions.length ? (
          <section className="org-division-panel-stage" aria-label="Divisions under the Office of the Director">
            <div className="org-tree-connector" aria-hidden="true" />
            {showDivisionArrows ? (
              <div className="org-panel-arrow-row" aria-label="Browse divisions">
                <button type="button" className="org-panel-arrow" onClick={() => scrollDivisionRail('left')} aria-label="Previous division">
                  <ArrowLeftIcon className="h-4 w-4" />
                </button>
                <button type="button" className="org-panel-arrow" onClick={() => scrollDivisionRail('right')} aria-label="Next division">
                  <ArrowRightIcon className="h-4 w-4" />
                </button>
              </div>
            ) : null}
            <div className="org-division-panel-rail" ref={divisionRailRef}>
              {visibleDivisions.map((division, index) => (
                <div className="org-division-panel org-reveal" key={division.id} style={{ animationDelay: `${Math.min(index * 70, 280)}ms` }}>
                  <DivisionBlock
                    division={division}
                    query={query}
                    revealOrder={index + 1}
                    forceExpandMembers={forceExpandMembers}
                    expandedSections={expandedSections}
                    onToggleSection={(sectionId) => setExpandedSections((current) => ({ ...current, [sectionId]: !current[sectionId] }))}
                    onViewUser={setViewedUser}
                    onEdit={openEdit}
                  />
                </div>
              ))}
            </div>
          </section>
        ) : null}

        {!visibleDivisions.length ? (
          <section className="org-shell p-10 text-center">
            <h3 className="text-lg font-semibold text-ink-950">No matching divisions</h3>
            <p className="mt-2 text-sm text-ink-500">Try another keyword or reset the current view.</p>
          </section>
        ) : null}
      </div>

      <EditOrgUserModal
        open={!!selectedUser}
        user={selectedUser}
        assignableRoles={bootstrap.assignableRoles}
        onClose={() => setSelectedUser(null)}
        onSave={onSave}
      />
      <PersonInfoModal
        user={viewedUser}
        onClose={() => setViewedUser(null)}
        onEdit={(user) => {
          setViewedUser(null)
          openEdit(user)
        }}
      />
    </div>
  )
}
