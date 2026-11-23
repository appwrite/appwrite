import { cn } from '@/lib/utils'

/** Column width shared by usage, docs section, import wizards, etc. */
export const SECONDARY_SIDEBAR_WIDTH_CLASS = 'w-[220px]'

/** Desktop aside for nested route navigation (usage, docs section, etc.). */
export const SECONDARY_SIDEBAR_ASIDE_CLASS = cn(
  SECONDARY_SIDEBAR_WIDTH_CLASS,
  'hidden shrink-0 border-e border-border bg-background lg:block',
)

/**
 * Flex shell: nav column + main content. First child follows inline-start
 * (left in LTR, right in RTL) when `dir` is set on an ancestor.
 */
export const SECONDARY_SIDEBAR_LAYOUT_CLASS =
  'flex min-h-0 flex-1 overflow-hidden'

/** @deprecated Use {@link SECONDARY_SIDEBAR_LAYOUT_CLASS}. */
export const SECONDARY_SIDEBAR_LAYOUT_GRID_CLASS = SECONDARY_SIDEBAR_LAYOUT_CLASS

/** Main content column beside a {@link SECONDARY_SIDEBAR_ASIDE_CLASS} panel. */
export const SECONDARY_SIDEBAR_CONTENT_CLASS =
  'min-h-0 min-w-0 flex-1 overflow-y-auto'

/** Group label above sidebar links. */
export const SECONDARY_SIDEBAR_GROUP_HEADING_CLASS =
  'mb-1.5 px-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60'

const NAV_LINK_FOCUS =
  'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background'

/** Shared base styles for icon + label nav rows. */
export function secondarySidebarNavLinkClassName(
  active: boolean,
  extra?: string,
) {
  return cn(
    'rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors',
    NAV_LINK_FOCUS,
    active
      ? 'bg-accent text-foreground'
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
    extra,
  )
}

/** Icon + label row. Mirrors in RTL via inherited `dir` on html or a local wrapper. */
export const SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS =
  'flex w-full items-center gap-x-2.5 text-start'

/** Icon + label + trailing badge/icon row. */
export const SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS =
  'flex w-full items-center gap-x-2.5 text-start'

export const SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS =
  'min-w-0 flex-1 truncate text-start'

/** Collapsed sidebar: icon-only cell centered. */
export const SECONDARY_SIDEBAR_NAV_LINK_COLLAPSED_CLASS =
  'flex w-full justify-center px-0'
