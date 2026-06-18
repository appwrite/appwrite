/** Shared layout tokens for overview chart + side breakdown panels. */
export const OVERVIEW_CHART_HEIGHT = 240

/** Max rows shown in the overview top-endpoints breakdown (matches usage API limit). */
export const OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT = 7

/** Fixed-height list so skeleton, partial, and full results share the same layout. */
export const overviewTopBreakdownListClass =
  'relative flex min-h-[276px] w-full min-w-0 flex-col gap-1'

/** One breakdown row — keep skeleton and data rows the same height. */
export const overviewTopBreakdownRowClass =
  'flex h-9 min-h-9 w-full min-w-0 shrink-0 items-center gap-2 overflow-hidden rounded-md px-2'

/** Chart + breakdown row min height (column padding + header + breakdown list). */
export const overviewChartContentRowClass =
  'flex min-w-0 flex-col @[700px]:min-h-[368px] @[700px]:flex-row @[700px]:items-stretch'

/** Left chart column — grows to fill space not used by the breakdown panel. */
export const overviewChartColumnClass =
  'flex w-full min-w-0 flex-col border-b border-border p-5 @[700px]:min-h-0 @[700px]:min-w-0 @[700px]:flex-1 @[700px]:border-b-0 @[700px]:border-r'

/** Right breakdown column (top endpoints / consumers). */
export const overviewBreakdownColumnClass =
  'flex min-h-0 w-full min-w-0 flex-col p-5 @[700px]:w-[400px] @[700px]:shrink-0'

export const overviewChartPanelHeaderClass =
  'mb-4 flex min-h-9 shrink-0 items-center justify-between gap-3'

export const overviewChartPanelBodyClass =
  'flex w-full flex-col text-muted-foreground @[700px]:min-h-0 @[700px]:min-w-0 @[700px]:flex-1'

/** Fixed height on mobile so Recharts can measure; grows on wide layouts. */
export const overviewChartPanelChartAreaClass =
  'flex h-[240px] w-full shrink-0 flex-col @[700px]:h-full @[700px]:min-h-[240px] @[700px]:flex-1'

export const overviewChartPanelEmptyClass =
  'flex min-h-[240px] w-full flex-1 items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 text-[13px] text-muted-foreground'

export const overviewChartPanelErrorClass =
  'flex min-h-[240px] w-full flex-1 flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center'

export const OVERVIEW_METRIC_NOT_AVAILABLE = 'N/A'

export const OVERVIEW_BANDWIDTH_ERROR = {
  title: "Couldn't load bandwidth",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

export const OVERVIEW_REQUESTS_ERROR = {
  title: "Couldn't load requests",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const
