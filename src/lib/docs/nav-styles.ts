import { cn } from '@/lib/utils'
import { API_NAV_ACTIVE_BG_CLASS } from '@/lib/api-explorer/nav-styles'
import { DOCS_TOC_LINK_TEXT_CLASS } from '@/lib/docs/prose-typography'

/** @deprecated Use {@link API_NAV_ACTIVE_BG_CLASS} from `@/lib/api-explorer/nav-styles`. */
export { API_NAV_ACTIVE_BG_CLASS as DOCS_NAV_ACTIVE_BG_CLASS } from '@/lib/api-explorer/nav-styles'

/** Shared height for section subnav header and article sticky title bar. */
export const DOCS_SECTION_HEADER_CLASS =
  'flex h-14 shrink-0 items-center border-b border-border bg-background'

/** Scrollable docs nav panels - overlay scrollbar on Windows/Linux. */
export const DOCS_NAV_SCROLL_CLASS =
  'min-h-0 overlay-scrollbar overscroll-y-contain'

/** Gap between top-level docs nav groups (Products, APIs, Tooling, …). */
export const DOCS_NAV_TREE_GAP_CLASS = 'space-y-6'

/** Gap between links inside a docs nav group. */
export const DOCS_NAV_ITEM_LIST_CLASS = 'space-y-0.5'

/** Category labels in the global sidebar and section submenu. */
export const DOCS_NAV_CATEGORY_LABEL_CLASS =
  'mb-1.5 px-2.5 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60'

/** Collapsible category trigger (Utilities, API product groups). */
export const DOCS_NAV_CATEGORY_TRIGGER_CLASS =
  'mb-1.5 flex w-full cursor-pointer items-center gap-1 rounded-md px-2.5 py-1 text-start text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60 transition-colors hover:bg-accent/50 hover:text-muted-foreground'

/** Shared chrome for docs sidebar and submenu links. */
export function docsNavLinkClassName(active: boolean) {
  return cn(
    'rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors duration-150',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
    active
      ? cn(API_NAV_ACTIVE_BG_CLASS, 'text-foreground')
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

/** Section subnav links (desktop panel + mobile sheet). */
export function docsSidebarNavLinkClassName(active: boolean) {
  return cn('block w-full truncate text-start', docsNavLinkClassName(active))
}

/** Right-rail table of contents links. */
export function docsTocLinkClassName(active: boolean) {
  return cn(
    'block min-w-0 truncate rounded-md px-2 py-1.5 font-medium transition-colors',
    DOCS_TOC_LINK_TEXT_CLASS,
    active
      ? cn(API_NAV_ACTIVE_BG_CLASS, 'text-foreground/90')
      : 'hover:bg-accent/50 hover:text-foreground/85',
  )
}
