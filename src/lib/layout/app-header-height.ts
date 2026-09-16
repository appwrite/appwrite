/**
 * Live height of the sticky app chrome (banners + header bar), published by
 * `ConsoleLayout` so columns below it can pin themselves under the header
 * without hard-coding an offset that banners change at runtime.
 */
export const APP_HEADER_HEIGHT_VAR = '--app-header-height'

/** Stick in-flow content (table headers, TOCs) just below the live app header. */
export const BELOW_APP_HEADER_STICKY_TOP_CLASS =
  'sticky top-[var(--app-header-height,0px)]'

export const BELOW_APP_HEADER_STICKY_MAX_HEIGHT_CLASS =
  'max-h-[calc(100dvh_-_var(--app-header-height,0px))]'

/**
 * Pins a layout-row column under the app header for the length of the page.
 * Marketing and docs scroll the document (see `marketing-document-scroll` in
 * styles.css), so a plain `h-full` column grows to the article height and
 * scrolls away with it.
 *
 * Desktop only: `position: sticky` creates a stacking context, which would trap
 * an off-canvas mobile drawer below the layout's backdrop.
 */
export const BELOW_APP_HEADER_STICKY_CLASS = [
  'h-full',
  'min-h-0',
  '@[1024px]:sticky',
  '@[1024px]:top-[var(--app-header-height,0px)]',
  '@[1024px]:self-start',
  '@[1024px]:h-[calc(100dvh_-_var(--app-header-height,0px))]',
  '@[1024px]:max-h-[calc(100dvh_-_var(--app-header-height,0px))]',
].join(' ')
