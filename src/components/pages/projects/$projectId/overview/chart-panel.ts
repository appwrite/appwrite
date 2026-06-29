/** Shared layout tokens for overview chart + side breakdown panels. */
export const OVERVIEW_CHART_HEIGHT = 240

export {
  CHART_X_AXIS_DEFAULT_DY,
  CHART_X_AXIS_DEFAULT_TICK,
  USAGE_CHART_MARGIN,
  USAGE_CHART_X_AXIS_PADDING,
  USAGE_CHART_Y_AXIS_WIDTH,
} from '@/lib/usage/chart-layout'

/** Header block above chart/breakdown body — fixed so tabs do not shift vertically. */
export const OVERVIEW_CHART_PANEL_HEADER_MIN_HEIGHT = 32

/** Desktop row height when the breakdown column is visible (padding + header + list). */
export const OVERVIEW_CHART_PANEL_ROW_HEIGHT_WITH_BREAKDOWN = 348

/** Desktop row height for chart-only layout (padding + header + chart). */
export const OVERVIEW_CHART_PANEL_ROW_HEIGHT_CHART_ONLY = 312

import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT, OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

/** Max rows shown in the overview top-endpoints breakdown (matches usage API limit). */
export const OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT

/** Executions / GB-hours breakdown rows (matches compute resource fetch limit). */
export const OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT =
  COMPUTE_BREAKDOWN_RESOURCE_LIMIT

/** Fixed-height list so skeleton, partial, and full results share the same layout. */
export const overviewTopBreakdownListClass =
  'relative flex min-h-[276px] w-full min-w-0 flex-col gap-1'

/** One breakdown row — keep skeleton and data rows the same height. */
export const overviewTopBreakdownRowClass =
  'flex h-9 min-h-9 w-full min-w-0 shrink-0 items-center gap-2 overflow-hidden rounded-md px-2'

/** Stacks tab panels in one grid cell so the card keeps a stable height. */
export const overviewChartTabPanelsContainerClass =
  'grid w-full [&>*]:col-start-1 [&>*]:row-start-1 [&>*]:w-full'

/** Tab panel visibility — keep in layout for stable sizing, hide visually when inactive. */
export function overviewChartTabPanelVisibilityClass(
  isActive: boolean,
): string {
  return isActive ? '' : 'invisible pointer-events-none'
}

/** Chart + breakdown row — fixed height on wide layouts. */
export function overviewChartContentRowClassName(
  withBreakdown = true,
): string {
  return withBreakdown
    ? 'flex min-h-[660px] min-w-0 flex-col @[700px]:h-[348px] @[700px]:min-h-[348px] @[700px]:max-h-[348px] @[700px]:flex-row @[700px]:items-stretch'
    : 'flex min-h-[312px] min-w-0 flex-col @[700px]:h-[312px] @[700px]:min-h-[312px] @[700px]:max-h-[312px] @[700px]:flex-row @[700px]:items-stretch'
}

/** @deprecated Use overviewChartContentRowClassName(withBreakdown) */
export const overviewChartContentRowClass =
  overviewChartContentRowClassName(true)

/** Left chart column — grows to fill space not used by the breakdown panel. */
export const overviewChartColumnClass =
  'flex h-full w-full min-w-0 flex-col border-b border-border px-5 pb-5 pt-3 @[700px]:min-h-0 @[700px]:min-w-0 @[700px]:flex-1 @[700px]:border-b-0 @[700px]:border-r'

/** Right breakdown column (top endpoints / consumers). */
export const overviewBreakdownColumnClass =
  'flex h-full min-h-0 w-full min-w-0 flex-col px-5 pb-5 pt-3 @[700px]:w-[400px] @[700px]:shrink-0'

export const overviewChartPanelHeaderClass =
  'mb-2 flex min-h-8 shrink-0 flex-wrap items-start justify-between gap-x-3 gap-y-2'

/** Legend rows, links, and other header actions — full width below the title on narrow containers. */
export const overviewChartPanelHeaderActionsClass =
  'flex w-full min-w-0 flex-wrap items-center justify-end gap-x-4 gap-y-1.5 @[420px]:w-auto @[420px]:justify-start'

export const overviewChartPanelBodyClass =
  'flex min-h-0 w-full min-w-0 flex-1 flex-col text-muted-foreground'

/** Chart canvas — fixed on narrow viewports; fills remaining column height on wide layouts. */
export const overviewChartPanelChartAreaClass =
  'relative flex h-[240px] w-full min-w-0 shrink-0 flex-col @[700px]:min-h-0 @[700px]:h-auto @[700px]:flex-1'

/** Fills the chart area so ResponsiveContainer can measure 100% width and height. */
export const overviewChartPanelChartFillClass =
  'absolute inset-0 min-h-0 min-w-0'

export const overviewChartPanelEmptyClass =
  'flex h-full min-h-[240px] w-full items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 text-[13px] text-muted-foreground'

export const overviewChartPanelErrorClass =
  'flex h-full min-h-[240px] w-full flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center'

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

export const OVERVIEW_EXECUTIONS_ERROR = {
  title: "Couldn't load executions",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

export const OVERVIEW_GB_HOURS_ERROR = {
  title: "Couldn't load compute",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

export const OVERVIEW_STORAGE_ERROR = {
  title: "Couldn't load storage",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const
