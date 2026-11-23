import { cn } from '@/lib/utils'

export type UsageChartLoadingState = {
  /** Full skeleton — only when there is no chart data to show yet. */
  showSkeleton: boolean
  /** Soft refresh — keep prior chart visible with a subtle fade. */
  isRefreshing: boolean
}

type UsageChartQueryState = {
  isError: boolean
  isLoading: boolean
  isFetching?: boolean
  isPlaceholderData?: boolean
}

export function resolveUsageChartLoadingState(
  query: UsageChartQueryState,
  hasChartData: boolean,
): UsageChartLoadingState {
  const {
    isError,
    isLoading,
    isFetching = false,
    isPlaceholderData = false,
  } = query

  if (isError) {
    return { showSkeleton: false, isRefreshing: false }
  }

  if (!hasChartData && (isLoading || isPlaceholderData)) {
    return { showSkeleton: true, isRefreshing: false }
  }

  const isRefreshing =
    hasChartData && (isFetching || isPlaceholderData || isLoading)

  return { showSkeleton: false, isRefreshing }
}

/** Chart card loading props derived from a React Query result and visible points. */
export function getUsageChartLoadingProps(
  query: UsageChartQueryState & { isFetching: boolean },
  chartPoints: readonly unknown[],
): { isLoading: boolean; isRefreshing: boolean } {
  const state = resolveUsageChartLoadingState(
    query,
    chartPoints.length > 0,
  )
  return {
    isLoading: state.showSkeleton,
    isRefreshing: state.isRefreshing,
  }
}

/** Show chart skeleton on first load and while date/interval filters change. */
export function shouldShowUsageChartSkeleton(
  isError: boolean,
  isLoading: boolean,
  isPlaceholderData?: boolean,
  hasChartData?: boolean,
): boolean {
  const effectiveHasChartData =
    hasChartData ??
    Boolean(isPlaceholderData && !isLoading)

  return resolveUsageChartLoadingState(
    { isError, isLoading, isPlaceholderData },
    effectiveHasChartData,
  ).showSkeleton
}

/** Tab totals only skeleton on the initial fetch when there is no data yet. */
export function shouldShowUsageTabMetricSkeleton(
  isError: boolean,
  data: unknown,
  isFetching: boolean,
): boolean {
  return !isError && data == null && isFetching
}

/** Soft fade-in for chart content when data first replaces a loading state. */
export const USAGE_CHART_FADE_IN_CLASS_NAME =
  'animate-in fade-in animation-duration-500 motion-reduce:animate-none'

/** Subtle fade while a chart refetches with previous data still visible. */
export function usageChartRefreshingClassName(isRefreshing: boolean): string {
  return cn(
    'transition-opacity duration-300 ease-out motion-reduce:transition-none',
    isRefreshing && 'opacity-55',
  )
}
