import type { ReactNode } from 'react'

type IconProps = {
  children: ReactNode
  className?: string
}

function Svg({ children, className = 'h-4 w-4' }: IconProps) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" className={className}>
      {children}
    </svg>
  )
}

export const SearchIcon = ({ className }: { className?: string }) => <Svg className={className}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></Svg>
export const ExpandIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M8 4H4v4" /><path d="M16 4h4v4" /><path d="M4 16v4h4" /><path d="M20 16v4h-4" /></Svg>
export const CollapseIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M8 8 4 4" /><path d="M16 8 20 4" /><path d="M8 16 4 20" /><path d="m16 16 4 4" /></Svg>
export const RefreshIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M21 12a9 9 0 0 1-15.5 6.4" /><path d="M3 12A9 9 0 0 1 18.5 5.6" /><path d="M3 17v-4h4" /><path d="M21 7v4h-4" /></Svg>
export const PencilIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="m12 20 8-8-4-4-8 8-1 5z" /><path d="m14 6 4 4" /></Svg>
export const ChevronDownIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="m6 9 6 6 6-6" /></Svg>
export const ArrowLeftIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M19 12H5" /><path d="m12 19-7-7 7-7" /></Svg>
export const ArrowRightIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></Svg>
export const DotIcon = ({ className }: { className?: string }) => <Svg className={className}><circle cx="12" cy="12" r="2.4" /></Svg>
export const BuildingIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M4 20h16" /><path d="M6 20V8l6-4 6 4v12" /><path d="M9 11h.01" /><path d="M15 11h.01" /><path d="M9 15h.01" /><path d="M15 15h.01" /></Svg>
export const LayersIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="m12 3 9 4.5-9 4.5L3 7.5 12 3Z" /><path d="m3 12 9 4.5 9-4.5" /><path d="m3 16.5 9 4.5 9-4.5" /></Svg>
export const UsersIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="10" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></Svg>
export const SparklesIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="m12 3 1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3Z" /><path d="M5 16 6 18.5 8.5 20 6 21.5 5 24l-1-2.5L1.5 20 4 18.5 5 16Z" /><path d="M19 14 20 16.5 22.5 18 20 19.5 19 22l-1-2.5L15.5 18 18 16.5 19 14Z" /></Svg>
export const CrownIcon = ({ className }: { className?: string }) => <Svg className={className}><path d="M3 8 7.5 12 12 5l4.5 7L21 8l-2 11H5L3 8Z" /></Svg>
