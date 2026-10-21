/** Stripe-style request builder: table rows with cell dividers, borderless inline inputs. */
export const REQUEST_BUILDER_CONTAINER = '@container/request-builder'

export const REQUEST_BUILDER_PANEL =
  'overflow-hidden rounded-lg border border-border bg-background'

/**
 * Request builder column widths – keep in sync with REQUEST_BUILDER_ROW grid template.
 * Name, type, value, and required are fixed; helper flexes to fill remaining space.
 */
export const REQUEST_BUILDER_NAME_WIDTH_PX = 152
export const REQUEST_BUILDER_TYPE_WIDTH_PX = 80
export const REQUEST_BUILDER_VALUE_MIN_WIDTH_PX = 200
export const REQUEST_BUILDER_VALUE_MAX_WIDTH_PX = 280
export const REQUEST_BUILDER_HELPER_MIN_WIDTH_PX = 120
export const REQUEST_BUILDER_ID_SELECTOR_MAX_WIDTH_PX = 240
export const REQUEST_BUILDER_ID_SELECTOR_POPOVER_CLASS = 'max-w-[240px]'
export const REQUEST_BUILDER_REQUIRED_WIDTH_PX = 88

/**
 * Narrow: name | type | required header, then full-width value, then helper.
 * Wide (@[680px]): classic 5-column table (needs ~640px min).
 */
export const REQUEST_BUILDER_ROW =
  'group grid grid-cols-[minmax(0,1fr)_auto_auto] border-b border-border/50 last:border-b-0 hover:bg-muted/[0.07] @[680px]/request-builder:grid-cols-[152px_80px_minmax(200px,280px)_minmax(120px,1fr)_88px] @[680px]/request-builder:min-h-[44px]'

export const REQUEST_BUILDER_ROW_COMPLEX =
  '@[680px]/request-builder:items-stretch'

export const REQUEST_BUILDER_NAME_CELL =
  'col-start-1 row-start-1 flex min-h-10 min-w-0 items-center px-3 py-2.5 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:w-[152px] @[680px]/request-builder:max-w-[152px] @[680px]/request-builder:px-4 @[680px]/request-builder:py-0'

export const REQUEST_BUILDER_TYPE_CELL =
  'col-start-2 row-start-1 flex min-h-10 shrink-0 items-center justify-end px-1 py-2.5 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:w-[80px] @[680px]/request-builder:justify-center @[680px]/request-builder:border-s @[680px]/request-builder:border-border/50 @[680px]/request-builder:px-3 @[680px]/request-builder:py-0'

export const REQUEST_BUILDER_VALUE_CELL =
  'col-span-3 col-start-1 row-start-2 flex min-h-11 min-w-0 items-center border-t border-border/50 @[680px]/request-builder:col-span-1 @[680px]/request-builder:col-start-3 @[680px]/request-builder:row-start-1 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:border-s @[680px]/request-builder:border-t-0'

export const REQUEST_BUILDER_HELPER_CELL =
  'col-span-3 col-start-1 row-start-3 flex min-h-10 min-w-0 items-center justify-start border-t border-border/50 px-3 py-2 @[680px]/request-builder:col-span-1 @[680px]/request-builder:col-start-4 @[680px]/request-builder:row-start-1 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:border-t-0 @[680px]/request-builder:py-0'

export const REQUEST_BUILDER_HELPER_SLOT =
  'flex w-full min-w-0 items-center justify-start'

export const REQUEST_BUILDER_REQUIRED_CELL =
  'col-start-3 row-start-1 flex min-h-10 shrink-0 items-center justify-end pe-3 ps-1 py-2.5 empty:hidden @[680px]/request-builder:col-start-5 @[680px]/request-builder:empty:flex @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:w-[88px] @[680px]/request-builder:border-s @[680px]/request-builder:border-border/50 @[680px]/request-builder:px-0 @[680px]/request-builder:pe-4 @[680px]/request-builder:ps-3 @[680px]/request-builder:py-0'

/** Left-aligned helper actions sized to the helper column. */
export const REQUEST_BUILDER_HELPER_LINK =
  'link-neutral inline-flex h-8 w-full min-w-0 items-center justify-start whitespace-nowrap font-mono text-[12px]'

export const REQUEST_BUILDER_HELPER_ICON_BUTTON =
  'h-8 w-full justify-start px-0 text-muted-foreground hover:bg-transparent'

/** Borderless cell input (no frame, no ring). */
export const REQUEST_BUILDER_INPUT =
  'h-full min-h-11 w-full rounded-none border-0 bg-transparent px-3 py-2 text-[13px] font-mono shadow-none outline-none ring-0 focus-visible:border-0 focus-visible:bg-transparent focus-visible:ring-0 hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent placeholder:font-mono placeholder:text-muted-foreground/45 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:px-4'

export const REQUEST_BUILDER_SELECT =
  'h-full min-h-11 w-full rounded-none border-0 bg-transparent px-3 py-2 text-[13px] font-mono shadow-none outline-none ring-0 focus:ring-0 focus-visible:border-0 focus-visible:bg-transparent focus-visible:ring-0 hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent data-[placeholder]:text-muted-foreground/45 [&>svg]:ms-auto [&>svg]:size-3.5 [&>svg]:opacity-35 @[680px]/request-builder:min-h-[44px] @[680px]/request-builder:px-4'

export const REQUEST_BUILDER_VALUE_INNER =
  'flex min-h-11 w-full items-center @[680px]/request-builder:min-h-[44px]'

export const REQUEST_BUILDER_VALUE_INNER_COMPLEX =
  'flex w-full flex-col'

/** Dividers between stacked array item rows in value/helper cells. */
export const REQUEST_BUILDER_ARRAY_ITEMS =
  'flex w-full flex-col divide-y divide-border/50'

/** Value + helper columns rendered as one block with full-width row dividers. */
export const REQUEST_BUILDER_COMBINED_ARRAY_ROW =
  'grid w-full grid-cols-1 @[680px]/request-builder:grid-cols-[minmax(200px,280px)_minmax(120px,1fr)]'

export const REQUEST_BUILDER_COMBINED_ARRAY_HELPER_CELL =
  'flex h-9 shrink-0 items-center border-border/50 px-3 @[680px]/request-builder:border-s'

export const REQUEST_BUILDER_ARRAY_ITEM_ROW =
  'flex h-9 w-full shrink-0 items-center'

export const REQUEST_BUILDER_ARRAY_ADD_ROW =
  'flex h-9 shrink-0 items-center px-3 @[680px]/request-builder:px-4'

export const REQUEST_BUILDER_REQUIRED =
  'shrink-0 select-none text-[11px] font-medium text-red-600 dark:text-red-400 @[680px]/request-builder:text-[12px]'
