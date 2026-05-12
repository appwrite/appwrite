/**
 * Layout + table chrome for Storage files list, aligned with documents DB
 * `Spreadsheet` (inline table + right preview split).
 */

export const STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY =
  'console.storageFilesTablePaneWidthPx'

export const STORAGE_FILES_TABLE_PANE_MIN_PX = 260
export const STORAGE_FILES_TABLE_PANE_MAX_PX = 4000
export const STORAGE_FILES_PREVIEW_PANE_MIN_PX = 650

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
