/** Show chart skeleton on first load and while date/interval filters change. */
export function shouldShowUsageChartSkeleton(
  isError: boolean,
  isLoading: boolean,
  isPlaceholderData: boolean,
): boolean {
  return !isError && (isLoading || isPlaceholderData)
}
