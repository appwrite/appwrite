/**
 * Sticky spreadsheet column edge chrome.
 *
 * Vertical edges use inset box-shadows (not borders) so they stay visible over
 * scrolling cells with `border-collapse`. Offsets are physical, so RTL must
 * flip them: start-sticky sits on inline-start and needs its border on
 * inline-end (physical right in LTR, physical left in RTL).
 */

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
