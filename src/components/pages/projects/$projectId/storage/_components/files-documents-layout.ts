/**
 * Layout + table chrome for Storage files list, aligned with documents DB
 * `Spreadsheet` (inline table + right preview split).
 */

import { cn } from '@/lib/utils'

export const STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY =
  'console.storageFilesTablePaneWidthPx'

export const STORAGE_FILES_TABLE_PANE_MIN_PX = 260
export const STORAGE_FILES_TABLE_PANE_MAX_PX = 4000
export const STORAGE_FILES_PREVIEW_PANE_MIN_PX = 480

/**
 * Minimum viewport width for the files table + inline preview **side-by-side**
 * layout. Below this width the layout stacks (table, then preview) so columns
 * and the inspector stay usable; aligns roughly with preview min width plus a
 * workable table pane.
 */
export const STORAGE_FILES_TABLE_PREVIEW_SPLIT_MIN_VIEWPORT_PX = 1024

/**
 * Stacked layout (narrow viewport): max height for the file inspector so the
 * files table + pagination can use `flex-1` and take most of the vertical space.
 */
export const STORAGE_FILES_STACKED_PREVIEW_MAX_H_CLASS =
  'max-h-[min(40dvh,600px)]'

/** Checkbox column width in `table-fixed` mode (matches documents rows grid). */
export const STORAGE_FILES_TABLE_EDGE_COL_PX = 40

/**
 * Reads persisted table (left) pane width. When unset, returns the max width so
 * the first layout clamp yields a narrow preview pane (preview `min-width`).
 */
export function readStoredStorageFilesTablePaneWidthPx(): number {
  if (typeof window === 'undefined') return STORAGE_FILES_TABLE_PANE_MAX_PX
  const raw = localStorage.getItem(STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY)
  const n = raw ? parseInt(raw, 10) : NaN
  return Number.isFinite(n) &&
    n >= STORAGE_FILES_TABLE_PANE_MIN_PX &&
    n <= STORAGE_FILES_TABLE_PANE_MAX_PX
    ? n
    : STORAGE_FILES_TABLE_PANE_MAX_PX
}

export const STORAGE_SPREADSHEET_STICKY_THEAD_CLASS =
  'sticky top-0 z-20 bg-background'

/**
 * Fixed height for the table header row and the preview pane toolbar so their
 * bottom borders line up across the split (preview `h-8` actions fit inside).
 */
export const STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS = 'h-10'

/** Inset shadows use `var(--border)` so grid lines match `border-border` in all themes. */
export const STORAGE_SPREADSHEET_HEADER_CELL_BORDER =
  'border-r border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'

/**
 * Header cells when the files / preview split wrapper already draws `border-t`
 * (single continuous top rule across both panes).
 */
export const STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP =
  'border-r border-border shadow-[inset_0_-1px_0_0_var(--border)]'

/** Last header cell: no right border at the table edge. */
export const STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP_LAST =
  'shadow-[inset_0_-1px_0_0_var(--border)]'

/**
 * Sticky checkbox column header when split-top chrome is used.
 * Uses inset shadow for the vertical rule next to the first data column — no
 * `border-r` here (that would double the line with `inset_-1px_0_0_0`).
 */
export const STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SPLIT_TOP =
  'shadow-[inset_0_-1px_0_0_var(--border),inset_-1px_0_0_0_var(--border)]'

/** Checkbox body cell: bottom edge only; vertical rule is `inset_-1px_0_0_0` on the cell (not `border-r`). */
export const STORAGE_SPREADSHEET_BODY_CHECKBOX_CELL_BORDER =
  'border-b border-border'

export const STORAGE_SPREADSHEET_BODY_CELL_BORDER =
  'border-b border-r border-border'

/** Last body cell: no right border at the table edge. */
export const STORAGE_SPREADSHEET_BODY_CELL_BORDER_LAST =
  'border-b border-border'

/**
 * Column resize rail (matches Tables DB `Spreadsheet` `DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS`).
 */
export const STORAGE_FILES_LIST_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS = cn(
  'group absolute top-0 bottom-0 z-[41] w-2 -translate-x-1/2 cursor-col-resize touch-none border-0 bg-transparent p-0 outline-none',
  'after:pointer-events-none after:absolute after:inset-y-0 after:left-1/2 after:w-[0.5px] after:-translate-x-1/2 after:bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:z-10 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100',
)
