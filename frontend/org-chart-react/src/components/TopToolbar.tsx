import { BuildingIcon, LayersIcon, RefreshIcon, SearchIcon, UsersIcon } from './Icons'

type Props = {
  query: string
  onQueryChange: (value: string) => void
  onReset: () => void
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
  visibleDivisionCount: number
  visibleSectionCount: number
  visiblePeopleCount: number
}

export function TopToolbar({
  query,
  onQueryChange,
  onReset,
  stats,
  copy,
  visibleDivisionCount,
  visibleSectionCount,
  visiblePeopleCount,
}: Props) {
  const hasSearch = query.trim().length > 0

  return (
    <section className={`org-toolbar-shell ${hasSearch ? 'is-searching' : ''}`}>
      <div className="org-command-bar">
        <div className="org-command-title">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand-700">{copy?.eyebrow || '2026 Org Atlas'}</span>
              <span className="org-live-dot" aria-hidden="true" />
            </div>
            <h1>{copy?.title || 'Technical Services, refined.'}</h1>
            <p>{copy?.subtitle || 'Delivering precision in public works'}</p>
          </div>
        </div>

        <div className="org-count-strip" aria-label="Current chart totals">
          <MetricChip icon={<BuildingIcon className="h-3.5 w-3.5" />} label="Divisions" value={visibleDivisionCount} helper={`${stats.activeDivisions} total`} />
          <MetricChip icon={<LayersIcon className="h-3.5 w-3.5" />} label="Sections" value={visibleSectionCount} helper={`${stats.totalSections} total`} />
          <MetricChip icon={<UsersIcon className="h-3.5 w-3.5" />} label="People" value={visiblePeopleCount} helper={`${stats.activeUsers} active`} />
        </div>

        <div className="org-command-actions">
          <div className="org-toolbar-search">
            <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
            <input
              value={query}
              onChange={(event) => onQueryChange(event.target.value)}
              placeholder="Search division, section, person, or title"
              aria-label="Search the chart"
            />
            {hasSearch ? <span className="org-search-badge">{visiblePeopleCount} hits</span> : null}
          </div>

          <div className="org-action-row">
            <button type="button" className="btn-secondary org-icon-action" onClick={onReset} title="Reset view">
              <RefreshIcon className="h-4 w-4" />
              <span>Reset</span>
            </button>
          </div>
        </div>
      </div>
    </section>
  )
}

function MetricChip({ icon, label, value, helper }: { icon: React.ReactNode; label: string; value: number; helper: string }) {
  return (
    <div className="org-count-chip">
      <span className="text-brand-700">{icon}</span>
      <span className="font-semibold text-ink-950">{value}</span>
      <span className="text-ink-600">{label}</span>
      <span className="hidden text-ink-400 sm:inline">{helper}</span>
    </div>
  )
}
