/**
 * Sticky spreadsheet column edge chrome.
 *
 * Vertical edges use inset box-shadows (not borders) so they stay visible over
 * scrolling cells with `border-collapse`. Offsets are physical, so RTL must
 * flip them: start-sticky sits on inline-start and needs its border on
 * inline-end (physical right in LTR, physical left in RTL).
 */

/**
 * Body sticky edge cells (checkbox column, row actions) must sit above
 * horizontally scrolling row content; without this, cells paint over the
 * fixed column when scrolled.
 */
export const SPREADSHEET_STICKY_BODY_Z = 'z-10'

/** Body cell: sticky start column edge facing scroll content (inline-end). */
export const SPREADSHEET_STICKY_START_EDGE_SHADOW =
  'shadow-[inset_-1px_0_0_0_var(--border)] rtl:shadow-[inset_1px_0_0_0_var(--border)]'

/** Body cell: sticky end column edge facing scroll content (inline-start). */
export const SPREADSHEET_STICKY_END_EDGE_SHADOW =
  'shadow-[inset_1px_0_0_0_var(--border)] rtl:shadow-[inset_-1px_0_0_0_var(--border)]'

/** Header cell: sticky start column (top + bottom + inline-end). */
export const SPREADSHEET_STICKY_START_HEADER_SHADOW =
  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border),inset_-1px_0_0_0_var(--border)] rtl:shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border),inset_1px_0_0_0_var(--border)]'

/** Header cell: sticky end column (top + bottom + inline-start). */
export const SPREADSHEET_STICKY_END_HEADER_SHADOW =
  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border),inset_1px_0_0_0_var(--border)] rtl:shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border),inset_-1px_0_0_0_var(--border)]'

/**
 * Inner layer for spreadsheet tables inside `overflow-auto`.
 *
 * `inline-block min-w-full` plus `w-full` on the table grows about 1px past the
 * pane (collapsed borders), which shows a phantom horizontal scrollbar. Use a
 * block layer with `max-w-full overflow-x-clip`, set `minWidth` to the sum of
 * column mins so real overflow still scrolls, and put leftover width in a
 * filler column before sticky actions.
 */
export const SPREADSHEET_SCROLL_LAYER_CLASS =
  'relative isolate w-full max-w-full overflow-x-clip'

/** Empty header cell that absorbs leftover width before sticky actions. */
export const SPREADSHEET_FILLER_HEADER_CLASS =
  'bg-background p-0 shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'

/** Empty body cell matching {@link SPREADSHEET_FILLER_HEADER_CLASS}. */
export const SPREADSHEET_FILLER_CELL_CLASS = 'border-b border-border p-0'
