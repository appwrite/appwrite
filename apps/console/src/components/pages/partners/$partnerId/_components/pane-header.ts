/**
 * First header row for every partner column (Clients, client nav, resource panes).
 * `min-h-16` matches an `h-8` CTA plus `py-4` so title-only rows stay as tall as
 * Projects / Domains / Apps when those trailing buttons are absent.
 */
export const PARTNER_PANE_HEADER_CLASSNAME =
  'flex min-h-16 shrink-0 items-center justify-between gap-3 overflow-hidden border-b border-border px-4 py-4'

export const PARTNER_PROJECTS_PANE_HEADER_WRAP_CLASSNAME =
  'shrink-0 border-b border-border'

/** Title row of two-row panes (Projects/Domains/Apps). Same token as Clients / nav. */
export const PARTNER_PROJECTS_PANE_TITLE_ROW_CLASSNAME =
  PARTNER_PANE_HEADER_CLASSNAME

/** Entire title-row actions group on the trailing edge (`ms-auto`). */
export const PARTNER_PANE_HEADER_ACTIONS_CLASSNAME =
  'ms-auto flex min-h-8 flex-row flex-nowrap items-center gap-2'

export const PARTNER_PANE_HEADER_SECONDARY_ACTIONS_CLASSNAME =
  'flex flex-row flex-nowrap items-center gap-2'

/** Primary create CTA: last in the group (no `ms-auto`; spacer is on the group). */
export const PARTNER_PANE_HEADER_PRIMARY_ACTION_CLASSNAME = 'shrink-0'

export const PARTNER_PROJECTS_PANE_TOOLBAR_CLASSNAME =
  'flex w-full min-w-0 items-center gap-2 overflow-visible px-4 py-4'

export const PARTNER_PANE_TITLE_CLASSNAME =
  'flex min-h-8 min-w-0 items-center truncate text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

export const PARTNER_PANE_HEADER_SEARCH_WRAP_CLASSNAME =
  'relative min-w-0 w-[16rem] max-w-full shrink'

export const PARTNER_PANE_HEADER_SEARCH_INPUT_CLASSNAME =
  'h-8 border-border bg-accent/50 ps-8 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0'

export const PARTNER_PANE_HEADER_BUTTON_CLASSNAME =
  'h-8 cursor-pointer gap-1.5 px-2.5 text-[13px]'

/** FiltersPopover trigger: match partner search (`h-8`, `rounded-md`). */
export const PARTNER_PANE_HEADER_FILTERS_BUTTON_CLASSNAME =
  'h-8 shrink-0 cursor-pointer gap-1.5 rounded-md px-2.5'

/** RefreshButton on the filters row, pushed to inline-end (`ms-auto` for RTL). */
export const PARTNER_PANE_HEADER_REFRESH_BUTTON_CLASSNAME =
  'ms-auto h-8 w-8 shrink-0 cursor-pointer'
