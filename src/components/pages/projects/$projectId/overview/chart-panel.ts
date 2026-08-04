import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'

/** Shared layout tokens for overview chart + side breakdown panels. */
export const OVERVIEW_CHART_HEIGHT = 240

export {
  CHART_X_AXIS_DEFAULT_DY,
  CHART_X_AXIS_DEFAULT_TICK,
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
  USAGE_CHART_X_AXIS_PADDING,
  USAGE_CHART_Y_AXIS_WIDTH,
} from '@/lib/usage/chart-layout'

/** Header block above chart/breakdown body - fixed height so chart columns stay aligned. */
export const OVERVIEW_CHART_PANEL_HEADER_HEIGHT = 60

/** Body height below the header (matches {@link OVERVIEW_CHART_HEIGHT} on desktop rows). */
export const OVERVIEW_CHART_PANEL_BODY_HEIGHT = 240

/** Desktop row height when the breakdown column is visible (padding + header + list). */
export const OVERVIEW_CHART_PANEL_ROW_HEIGHT_WITH_BREAKDOWN = 360

/** Desktop row height for chart-only layout (padding + header + chart). */
export const OVERVIEW_CHART_PANEL_ROW_HEIGHT_CHART_ONLY = 324

import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT, OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

/** Max rows shown in the overview top-endpoints breakdown (matches usage API limit). */
export const OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT

/** Executions / GB-hours breakdown rows (matches compute resource fetch limit). */
export const OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT =
  COMPUTE_BREAKDOWN_RESOURCE_LIMIT

/** Breakdown row height (matches {@link overviewTopBreakdownRowClass} `h-9`). */
const OVERVIEW_BREAKDOWN_ROW_HEIGHT_PX = 36

/** Gap between breakdown rows (matches `gap-1`). */
const OVERVIEW_BREAKDOWN_ROW_GAP_PX = 4

function overviewBreakdownListMinHeightPx(rowCount: number): number {
  return (
    rowCount * OVERVIEW_BREAKDOWN_ROW_HEIGHT_PX +
    Math.max(0, rowCount - 1) * OVERVIEW_BREAKDOWN_ROW_GAP_PX
  )
}

/** Min height for endpoint / usage-section breakdown lists (6 rows). */
export const OVERVIEW_TOP_BREAKDOWN_LIST_MIN_HEIGHT_PX =
  overviewBreakdownListMinHeightPx(OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT)

/** Min height for compute overview breakdown lists (8 rows). */
export const OVERVIEW_COMPUTE_BREAKDOWN_LIST_MIN_HEIGHT_PX =
  overviewBreakdownListMinHeightPx(OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT)

/** Fixed-height list so skeleton, partial, empty, and full results share the same layout. */
export const overviewTopBreakdownListClass =
  'relative flex h-full min-h-[236px] w-full min-w-0 flex-col gap-1 overflow-hidden'

export const overviewComputeBreakdownListClass =
  'relative flex h-full min-h-[316px] w-full min-w-0 flex-col gap-1 overflow-hidden'

export function overviewBreakdownListClassForRowCount(
  rowCount: number = OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
): string {
  return rowCount === OVERVIEW_COMPUTE_BREAKDOWN_ITEM_COUNT
    ? overviewComputeBreakdownListClass
    : overviewTopBreakdownListClass
}

/** One breakdown row - keep skeleton and data rows the same height. */
export const overviewTopBreakdownRowClass =
  'flex h-9 min-h-9 w-full min-w-0 shrink-0 items-center gap-2 overflow-hidden rounded-md px-2'

/** Stacks tab panels in one grid cell so the card keeps a stable height. */
export const overviewChartTabPanelsContainerClass =
  'grid w-full [&>*]:col-start-1 [&>*]:row-start-1 [&>*]:w-full'

/** Tab panel visibility - keep in layout for stable sizing, hide visually when inactive. */
export function overviewChartTabPanelVisibilityClass(
  isActive: boolean,
): string {
  return isActive ? '' : 'invisible pointer-events-none'
}

/** Chart + breakdown row - fixed height on wide layouts. */
export function overviewChartContentRowClassName(
  withBreakdown = true,
): string {
  return withBreakdown
    ? 'flex min-h-[684px] min-w-0 flex-col @[700px]:h-[360px] @[700px]:min-h-[360px] @[700px]:max-h-[360px] @[700px]:flex-row @[700px]:items-stretch'
    : 'flex min-h-[324px] min-w-0 flex-col @[700px]:h-[324px] @[700px]:min-h-[324px] @[700px]:max-h-[324px] @[700px]:flex-row @[700px]:items-stretch'
}

/** @deprecated Use overviewChartContentRowClassName(withBreakdown) */
export const overviewChartContentRowClass =
  overviewChartContentRowClassName(true)

/** Main chart column - grows to fill space not used by the breakdown panel. */
export const overviewChartColumnClass =
  'flex h-full w-full min-w-0 flex-col overflow-hidden border-b border-border px-5 pb-8 pt-3 @[700px]:min-h-0 @[700px]:min-w-0 @[700px]:flex-1 @[700px]:border-b-0 @[700px]:border-e'

/** Breakdown column (top endpoints / consumers). */
export const overviewBreakdownColumnClass =
  'flex h-full min-h-0 w-full min-w-0 flex-col overflow-hidden px-5 pb-8 pt-3 @[700px]:w-[400px] @[700px]:shrink-0'

export const overviewChartPanelHeaderClass =
  'mb-4 flex h-[60px] shrink-0 flex-nowrap items-center justify-between gap-x-3'

/** Legend rows, links, and other header actions - single row beside the title. */
export const overviewChartPanelHeaderActionsClass =
  'flex shrink-0 flex-nowrap items-center justify-end gap-x-4 gap-y-1.5'

export const overviewChartPanelBodyClass =
  'flex h-[240px] w-full min-w-0 shrink-0 flex-col text-muted-foreground'

/** Chart canvas - fills the fixed panel body. */
export const overviewChartPanelChartAreaClass =
  'relative flex h-full min-h-0 w-full min-w-0 flex-col'

/** Fills the chart area so ResponsiveContainer can measure 100% width and height. */
export const overviewChartPanelChartFillClass =
  `absolute inset-0 min-h-0 min-w-0 ${FORCE_LTR_CLASS}`

export const overviewChartPanelEmptyClass =
  'flex h-full min-h-0 w-full items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 text-[13px] text-muted-foreground'

export const overviewChartPanelErrorClass =
  'flex h-full min-h-0 w-full flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 text-center'

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
