import {
  loadDebugOverrides,
  type DebugOverrides,
} from '@/lib/debug-overrides'

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

export const OVERVIEW_CHART_TAB_DISABLE_KEYS = {
  bandwidth: 'disableOverviewBandwidthChart',
  requests: 'disableOverviewRequestsChart',
  storage: 'disableOverviewStorageChart',
  executions: 'disableOverviewExecutionsChart',
  gbhours: 'disableOverviewComputeChart',
} as const satisfies Record<OverviewChartTabId, keyof DebugOverrides>

export type OverviewChartTabDisableKey =
  (typeof OVERVIEW_CHART_TAB_DISABLE_KEYS)[OverviewChartTabId]

export function isOverviewChartTabEnabled(
  tabId: OverviewChartTabId,
  overrides: DebugOverrides = loadDebugOverrides(),
): boolean {
  const key = OVERVIEW_CHART_TAB_DISABLE_KEYS[tabId]
  return !overrides[key]
}

export function getEnabledOverviewChartTabs(
  overrides: DebugOverrides = loadDebugOverrides(),
): OverviewChartTabId[] {
  return OVERVIEW_CHART_TAB_ORDER.filter((tabId) =>
    isOverviewChartTabEnabled(tabId, overrides),
  )
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
