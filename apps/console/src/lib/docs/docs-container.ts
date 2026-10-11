/**
 * Container-query breakpoints for docs layout and prose.
 * Ancestor must include `@container/docs` (see DOCS_CONTAINER).
 *
 * Responsive behavior follows content width, not viewport, so docs work in the
 * console preview pane as well as full-page /docs routes.
 *
 * Narrow → wide (container width):
 * - @[480px]: comfortable padding, compact type bump
 * - @[560px]: two-column grids, inline header actions
 * - @[640px]: medium typography, three-column grids
 * - @[900px]: article + TOC column, wide hub sections
 * - @[1080px]: four- and five-column hub grids
 */

export const DOCS_CONTAINER = '@container/docs'

/** Horizontal padding that grows with content width */
export const docsContentPaddingX = 'px-4 @[480px]:px-6'

/** Section vertical padding */
export const docsSectionPaddingY = 'py-12 @[640px]:py-16'

/** Tighter section padding for the console docs preview home */
export const docsPreviewSectionPaddingY = 'py-6 @[640px]:py-8'

/** Primary page title in the console docs preview pane */
export const docsPreviewPrimaryTitleClass =
  'font-aeonik-pro text-balance text-[20px] font-normal leading-tight tracking-tight text-foreground @[480px]:text-[22px]'

/** Section titles on the preview docs home (subordinate to primary title) */
export const docsPreviewSectionTitleClass =
  'font-aeonik-pro text-balance text-[15px] font-normal leading-tight tracking-tight text-foreground @[480px]:text-[16px]'

/** Two-column grid */
export const docsGridTwoCol = 'grid-cols-1 @[560px]:grid-cols-2'

/** Three-column grid */
export const docsGridThreeCol =
  'grid-cols-1 @[560px]:grid-cols-2 @[900px]:grid-cols-3'

/** Four-column hub grid */
export const docsGridFourCol =
  'grid-cols-1 @[560px]:grid-cols-2 @[1080px]:grid-cols-4'

/** Quick starts hub: two, three, then four columns */
export const docsGridQuickStarts =
  'grid-cols-1 @[560px]:grid-cols-2 @[900px]:grid-cols-3 @[1080px]:grid-cols-4'

/** Five-column hub grid */
export const docsGridFiveCol =
  'grid-cols-1 @[560px]:grid-cols-2 @[900px]:grid-cols-3 @[1080px]:grid-cols-5'
