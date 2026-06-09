/** Stripe-style request builder: table rows with cell dividers, borderless inline inputs. */
export const REQUEST_BUILDER_PANEL =
  'overflow-hidden rounded-lg border border-border bg-background'

export const REQUEST_BUILDER_ROW =
  'group grid grid-cols-1 border-b border-border/50 last:border-b-0 hover:bg-muted/[0.07] sm:grid-cols-[minmax(140px,22%)_80px_minmax(0,1fr)] sm:min-h-[44px]'

export const REQUEST_BUILDER_ROW_COMPLEX = 'sm:items-stretch'

export const REQUEST_BUILDER_NAME_CELL =
  'flex min-h-[44px] min-w-0 items-center px-4 py-2 sm:py-0'

export const REQUEST_BUILDER_TYPE_CELL =
  'flex min-h-[44px] items-center border-border/50 px-3 py-2 sm:border-l sm:py-0'

export const REQUEST_BUILDER_VALUE_CELL =
  'flex min-h-[44px] min-w-0 items-center border-border/50 sm:border-l'

/** Borderless cell input (no frame, no ring). */
export const REQUEST_BUILDER_INPUT =
  'h-full min-h-[44px] w-full rounded-none border-0 bg-transparent px-4 py-2 text-[13px] font-mono shadow-none outline-none ring-0 focus-visible:border-0 focus-visible:bg-transparent focus-visible:ring-0 hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent placeholder:font-mono placeholder:text-muted-foreground/45'

export const REQUEST_BUILDER_SELECT =
  'h-full min-h-[44px] w-full rounded-none border-0 bg-transparent px-4 py-2 text-[13px] font-mono shadow-none outline-none ring-0 focus:ring-0 focus-visible:border-0 focus-visible:bg-transparent focus-visible:ring-0 hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent data-[placeholder]:text-muted-foreground/45 [&>svg]:ml-auto [&>svg]:size-3.5 [&>svg]:opacity-35'

export const REQUEST_BUILDER_VALUE_INNER =
  'flex min-h-[44px] w-full items-center'

export const REQUEST_BUILDER_VALUE_INNER_COMPLEX =
  'flex w-full flex-col px-4 py-3'

export const REQUEST_BUILDER_REQUIRED =
  'shrink-0 select-none pr-4 text-[12px] font-medium text-red-600 dark:text-red-400'
