/**
 * Live height of the sticky app chrome (banners + header bar), published by
 * `ConsoleLayout` so columns below it can pin themselves under the header
 * without hard-coding an offset that banners change at runtime.
 */
export const APP_HEADER_HEIGHT_VAR = '--app-header-height'

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
  '@[1024px]:sticky',
  '@[1024px]:top-[var(--app-header-height,0px)]',
  '@[1024px]:h-[calc(100dvh_-_var(--app-header-height,0px))]',
].join(' ')
