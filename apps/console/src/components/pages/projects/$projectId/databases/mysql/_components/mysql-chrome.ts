/** Sidebar submenu (Data / Queries / History) and database title bar. */
export const MYSQL_TOP_HEADER_BAR_CLASS =
  'h-12 shrink-0 items-center border-b border-border bg-muted/20'

/** Shared segmented toggle item styling (Data / Queries / History, Clients / Backends). */
export const MYSQL_SEGMENTED_TOGGLE_ITEM_CLASS =
  'h-8 flex-1 px-1.5 text-[11px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground sm:text-[12px]'

/** Matches Monaco `editor.background` (--editor-bg in dark, --background in light). */
export const MYSQL_SQL_EDITOR_SURFACE_CLASS =
  'bg-[var(--editor-bg,var(--background))]'

export { MYSQL_SQL_FORMAT_SHORTCUT_RAW } from '@/lib/mysql-sql-editor-shortcuts'

/** Filled play icon used for Run query and SQL editor entry points. */
export const MYSQL_RUN_QUERY_PLAY_ICON_CLASS =
  'h-3.5 w-3.5 shrink-0 fill-current'
