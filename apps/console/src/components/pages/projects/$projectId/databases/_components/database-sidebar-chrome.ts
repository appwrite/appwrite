/** Compact strip for sidebar tables/collections list pagination. */
export const DATABASE_SIDEBAR_LIST_STRIP_CLASS =
  'shrink-0 border-t border-border px-2 py-1.5'

export const DATABASE_SIDEBAR_LIST_STRIP_ROW_CLASS =
  'flex min-h-6 min-w-0 items-center justify-between gap-1 text-[11px] text-muted-foreground'

/**
 * Sticky spreadsheet / table footer height (see tablesdb `Spreadsheet.tsx`).
 * Keeps sidebar compute spec aligned with main content pagination.
 */
export const DATABASE_CONTENT_FOOTER_HEIGHT_CLASS = 'h-[54px]'

/**
 * Footer strip aligned with main content pagination footer.
 * Used for the compute spec row at the bottom of database sidebars.
 */
export const DATABASE_SIDEBAR_FOOTER_STRIP_CLASS =
  'h-[54px] shrink-0 border-t border-border bg-background'

export const DATABASE_SIDEBAR_FOOTER_STRIP_ROW_CLASS =
  'flex h-full min-w-0 items-center justify-between gap-2 px-4 text-[12px] text-muted-foreground'

/** Cancel nav footer `px-2.5` so strip borders span the full sidebar width. */
export const DATABASE_SIDEBAR_STRIP_FULL_BLEED_CLASS = '-mx-2.5'

const SPREADSHEET_LIKE_TABLE_TABS = new Set([
  'rows',
  'documents',
  'columns',
  'indexes',
])

/** Table detail tabs that render a full-height spreadsheet grid. */
export function isSpreadsheetLikeTableTab(
  activeTab: string | undefined,
): boolean {
  return activeTab != null && SPREADSHEET_LIKE_TABLE_TABS.has(activeTab)
}
