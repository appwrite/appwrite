import { cn } from '@/lib/utils'
import { DOCS_TOC_LINK_TEXT_CLASS } from '@/lib/docs/prose-typography'

/** Softer active background for docs navigation items. */
export const DOCS_NAV_ACTIVE_BG_CLASS = 'bg-accent/60'

/** Shared height for section subnav header and article sticky title bar. */
export const DOCS_SECTION_HEADER_CLASS =
  'flex h-14 shrink-0 items-center border-b border-border bg-background'

/** Section subnav links (desktop panel + mobile sheet). */
export function docsSidebarNavLinkClassName(active: boolean) {
  return cn(
    'block rounded-md px-2 py-1.5 text-[13px] font-medium leading-5 transition-colors',
    active
      ? cn(DOCS_NAV_ACTIVE_BG_CLASS, 'text-foreground')
      : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
  )
}

/** Right-rail table of contents links. */
export function docsTocLinkClassName(active: boolean) {
  return cn(
    'block rounded-md px-2 py-1.5 font-medium transition-colors',
    DOCS_TOC_LINK_TEXT_CLASS,
    active
      ? cn(DOCS_NAV_ACTIVE_BG_CLASS, 'text-foreground/90')
      : 'hover:bg-accent/50 hover:text-foreground/85',
  )
}
