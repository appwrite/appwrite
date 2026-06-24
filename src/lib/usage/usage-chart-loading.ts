/** Show chart skeleton on first load and while date/interval filters change. */
export function shouldShowUsageChartSkeleton(
  isError: boolean,
  isLoading: boolean,
  isPlaceholderData: boolean,
): boolean {
  return !isError && (isLoading || isPlaceholderData)
}

/** Tab totals only skeleton on the initial fetch when there is no data yet. */
export function shouldShowUsageTabMetricSkeleton(
  isError: boolean,
  data: unknown,
  isFetching: boolean,
): boolean {
  return !isError && data == null && isFetching
}
