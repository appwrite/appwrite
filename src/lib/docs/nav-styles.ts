import { cn } from '@/lib/utils'
import { apiNavItemClassName, API_NAV_ACTIVE_BG_CLASS } from '@/lib/api-explorer/nav-styles'
import { DOCS_TOC_LINK_TEXT_CLASS } from '@/lib/docs/prose-typography'

/** @deprecated Use {@link API_NAV_ACTIVE_BG_CLASS} from `@/lib/api-explorer/nav-styles`. */
export { API_NAV_ACTIVE_BG_CLASS as DOCS_NAV_ACTIVE_BG_CLASS } from '@/lib/api-explorer/nav-styles'

/** Shared height for section subnav header and article sticky title bar. */
export const DOCS_SECTION_HEADER_CLASS =
  'flex h-14 shrink-0 items-center border-b border-border bg-background'

/** Scrollable docs nav panels - overlay scrollbar on Windows/Linux. */
export const DOCS_NAV_SCROLL_CLASS = 'overlay-scrollbar overscroll-y-contain'

/** Section subnav links (desktop panel + mobile sheet). */
export function docsSidebarNavLinkClassName(active: boolean) {
  return cn('block w-full text-start', apiNavItemClassName(active))
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
