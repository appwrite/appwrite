import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'

/**
 * Container on the explorer root (measures `main` content width only).
 * Pair utilities with the `/reference-explorer` container name — not the layout-row
 * `@container`, which includes sidebar width and wrongly enables desktop columns on iPad.
 */
export const REFERENCE_EXPLORER_CONTAINER = '@container/reference-explorer'

/** Hide at/above this main-column width (sheet navigation). */
export const REFERENCE_EXPLORER_MOBILE_ONLY_CLASS =
  '@[900px]/reference-explorer:hidden'

/** Show at/above this main-column width (methods + detail columns). */
export const REFERENCE_EXPLORER_DESKTOP_ONLY_CLASS =
  'hidden @[900px]/reference-explorer:block'

/** Min layout width before pinning the API references section subnav beside global docs nav. */
export const REFERENCE_SECTION_SUBNAV_DESKTOP_CLASS = '@[1280px]:flex'

/** Matches {@link DOCS_SECTION_HEADER_CLASS} height for alignment with the docs section subnav. */
export const REFERENCE_COLUMN_HEADER_CLASS = `${DOCS_SECTION_HEADER_CLASS} px-4`

export const REFERENCE_SCROLL_AREA_CLASS = 'min-h-0 min-w-0 flex-1 overflow-hidden'

export const REFERENCE_RESIZE_HANDLE_CLASS =
  'relative z-[45] w-[0.5px] bg-border before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100 after:w-2 after:left-1/2 after:-translate-x-1/2'

export function getHttpMethodVariant(
  method: string,
): 'info' | 'success' | 'warning' | 'error' | 'secondary' {
  switch (method.toLowerCase()) {
    case 'get':
      return 'info'
    case 'post':
      return 'success'
    case 'put':
    case 'patch':
      return 'warning'
    case 'delete':
      return 'error'
    default:
      return 'secondary'
  }
}
