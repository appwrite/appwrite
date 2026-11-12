/**
 * Layout + table chrome for Storage files list, aligned with documents DB
 * `Spreadsheet` (inline table + right preview split).
 */

import { clampSplitFirstPaneWidthPx } from '@/lib/resizable-layout'
import {
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
} from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
  SPREADSHEET_STICKY_START_EDGE_SHADOW,
  SPREADSHEET_STICKY_START_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'

export {
  SPREADSHEET_STICKY_END_EDGE_SHADOW as STORAGE_SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_START_EDGE_SHADOW as STORAGE_SPREADSHEET_STICKY_START_EDGE_SHADOW,
}
import { cn } from '@/lib/utils'
import {
  STORAGE_FILES_TABLE_PANE_MAX_PX,
  STORAGE_FILES_TABLE_PANE_MIN_PX,
} from '@/lib/user-prefs-keys'

export { STORAGE_FILES_TABLE_PANE_MIN_PX, STORAGE_FILES_TABLE_PANE_MAX_PX }

export const STORAGE_FILES_PREVIEW_PANE_MIN_PX = 480

/**
 * Minimum viewport width for the files table + inline preview **side-by-side**
 * layout. Below this width the layout stacks (table, then preview) so columns
 * and the inspector stay usable; aligns roughly with preview min width plus a
 * workable table pane.
 */
export const STORAGE_FILES_TABLE_PREVIEW_SPLIT_MIN_VIEWPORT_PX = 1024

/** Checkbox column width in `table-fixed` mode (matches documents rows grid). */
export const STORAGE_FILES_TABLE_EDGE_COL_PX = 40

/** Shared with Tables / Documents / Vectors DB row grids (inline row preview split). */
export const DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY =
  'console.documentsTablePaneWidthPx'

export const DOCUMENTS_TABLE_PANE_MIN_PX = 260
export const DOCUMENTS_TABLE_PANE_MAX_PX = 4000
export const DOCUMENTS_PREVIEW_PANE_MIN_PX = 280

/**
 * Table pane width when no pref is saved: as wide as allowed while keeping the
 * preview at {@link STORAGE_FILES_PREVIEW_PANE_MIN_PX}.
 */
export function defaultStorageFilesTablePaneWidthPx(
  containerWidth: number,
): number {
  return clampSplitFirstPaneWidthPx(
    containerWidth - STORAGE_FILES_PREVIEW_PANE_MIN_PX,
    containerWidth,
    STORAGE_FILES_TABLE_PANE_MIN_PX,
    STORAGE_FILES_TABLE_PANE_MAX_PX,
    STORAGE_FILES_PREVIEW_PANE_MIN_PX,
  )
}

/**
 * Reads persisted table (left) pane width. When unset, returns the minimum so
 * the table opens narrow and the preview pane gets the remaining space.
 */
export function readStoredDocumentsTablePaneWidthPx(): number {
  if (typeof window === 'undefined') return DOCUMENTS_TABLE_PANE_MIN_PX
  const raw = localStorage.getItem(DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY)
  const n = raw ? parseInt(raw, 10) : NaN
  return Number.isFinite(n) &&
    n >= DOCUMENTS_TABLE_PANE_MIN_PX &&
    n <= DOCUMENTS_TABLE_PANE_MAX_PX
    ? n
    : DOCUMENTS_TABLE_PANE_MIN_PX
}

/** Shared pane surface for the files table and inline file inspector split. */
export const STORAGE_FILES_SPLIT_PANE_BG_CLASS = 'bg-background'

/**
 * Fixed height for the files table header row and preview toolbar (must stay in sync).
 * Table `th` uses {@link STORAGE_FILES_TABLE_HEADER_TH_CLASS}; preview uses
 * {@link STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS}.
 */
export const STORAGE_FILES_TABLE_HEADER_ROW_HEIGHT_CLASS = 'h-[34px]'

/** Apply to files grid header `th` cells (replaces `py-2`). */
export const STORAGE_FILES_TABLE_HEADER_TH_CLASS = cn(
  STORAGE_FILES_TABLE_HEADER_ROW_HEIGHT_CLASS,
  'max-h-[34px] box-border py-0 align-middle',
)

/**
 * File inspector toolbar - same height/inset rules as table header (no pane `border-t`).
 */
export const STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS = cn(
  'box-border flex shrink-0 items-center gap-2 px-3 py-0',
  STORAGE_FILES_TABLE_HEADER_ROW_HEIGHT_CLASS,
  'max-h-[34px]',
  'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]',
)

/** Bottom sheet height when the file inspector opens on narrow viewports. */
export const STORAGE_FILES_INSPECTOR_DRAWER_HEIGHT_CLASS =
  'h-[min(92dvh,920px)] max-h-[92dvh]'

/** Side-by-side split: table + pagination left column, preview spans full height on the right. */
export function storageFilesSplitGridStyle(tablePaneWidthPx: number): {
  gridTemplateColumns: string
  gridTemplateRows: string
} {
  return {
    gridTemplateColumns: `${tablePaneWidthPx}px minmax(${STORAGE_FILES_PREVIEW_PANE_MIN_PX}px, 1fr)`,
    gridTemplateRows: '1fr auto',
  }
}

/** @deprecated Use {@link STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS} */
export const STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS =
  STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS

/**
 * Row grid styles - keep in sync with `tablesdb/Spreadsheet.tsx`
 * (`stickyTheadClass`, `headerCellBorderClass`, `bodyCellBorderClass`).
 */
export const STORAGE_SPREADSHEET_STICKY_THEAD_CLASS =
  'sticky top-0 z-20 bg-background'

export const STORAGE_SPREADSHEET_HEADER_CELL_BORDER =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'

export const STORAGE_SPREADSHEET_BODY_CELL_BORDER =
  'border-b border-e border-border'

/** Sticky checkbox/actions cells - fixed surface; row hover/selection must not tint these. */
export const STORAGE_SPREADSHEET_BODY_STICKY_EDGE_BG_CLASS = 'bg-background'

export const STORAGE_SPREADSHEET_TABLE_LAYER_CLASS =
  SPREADSHEET_SCROLL_LAYER_CLASS

/** Sticky checkbox `th` - single shadow utility (do not stack multiple `shadow-[...]`). */
export const STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SHADOW =
  SPREADSHEET_STICKY_START_HEADER_SHADOW

/** Sticky actions `th` - single shadow utility (do not stack multiple `shadow-[...]`). */
export const STORAGE_SPREADSHEET_HEADER_STICKY_ACTIONS_SHADOW =
  SPREADSHEET_STICKY_END_HEADER_SHADOW

/**
 * Column resize rail (matches Tables DB `Spreadsheet` `DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS`).
 */
export const STORAGE_FILES_LIST_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS = cn(
  'group absolute top-0 bottom-0 z-[41] w-2 cursor-col-resize touch-none border-0 bg-transparent p-0 outline-none',
  'after:pointer-events-none after:absolute after:inset-y-0 after:w-[0.5px] after:bg-border',
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  'before:pointer-events-none before:absolute before:inset-y-0 before:z-10 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
  'hover:before:opacity-100',
)
