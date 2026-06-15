/** Sidebar submenu (Data / Queries / History) and database title bar. */
export const POSTGRES_TOP_HEADER_BAR_CLASS =
  'h-12 shrink-0 items-center border-b border-border bg-muted/20'

/** Shared segmented toggle item styling (Data / Queries / History, Clients / Backends). */
export const POSTGRES_SEGMENTED_TOGGLE_ITEM_CLASS =
  'h-8 flex-1 px-1.5 text-[11px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground sm:text-[12px]'

/** Muted track when a segmented toggle sits on a default (non-muted) surface. */
export const POSTGRES_SEGMENTED_TOGGLE_TRACK_CLASS =
  'rounded-md bg-muted/20 p-0.5'

/** Matches Monaco `editor.background` (--editor-bg in dark, --background in light). */
export const POSTGRES_SQL_EDITOR_SURFACE_CLASS =
  'bg-[var(--editor-bg,var(--background))]'

/** Format document in the Postgres SQL editor (Shift+Alt+F). */
export const POSTGRES_SQL_FORMAT_SHORTCUT_RAW = 'shift+alt+f'
