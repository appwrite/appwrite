export const OVERVIEW_CHART_TAB_ORDER = [
  'bandwidth',
  'requests',
  'storage',
  'executions',
  'gbhours',
] as const

export type OverviewChartTabId = (typeof OVERVIEW_CHART_TAB_ORDER)[number]

export const OVERVIEW_CHART_TAB_LABELS: Record<OverviewChartTabId, string> = {
  bandwidth: 'Bandwidth',
  requests: 'Requests',
  storage: 'Storage',
  executions: 'Executions',
  gbhours: 'Compute',
}

/** Usage sidebar category for each overview chart tab. */
const OVERVIEW_CHART_TAB_USAGE_CATEGORY: Record<OverviewChartTabId, string> = {
  bandwidth: 'bandwidth',
  requests: 'requests',
  storage: 'storage',
  executions: 'compute',
  gbhours: 'compute',
}

export function getUsageCategoryIdForOverviewChartTab(
  tabId: OverviewChartTabId,
): string {
  return OVERVIEW_CHART_TAB_USAGE_CATEGORY[tabId]
}
