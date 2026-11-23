/**
 * Container on the explorer root (measures main content width only).
 * Pair utilities with the `/api-explorer` container name - not a parent
 * layout container that includes sidebar width.
 */
export const API_EXPLORER_CONTAINER = '@container/api-explorer'

/** Hide at/above this main-column width (sheet navigation). */
export const API_EXPLORER_MOBILE_ONLY_CLASS =
  '@[900px]/api-explorer:hidden'

/** Show at/above this main-column width (three-column layout). */
export const API_EXPLORER_DESKTOP_ONLY_CLASS =
  'hidden @[900px]/api-explorer:block'
