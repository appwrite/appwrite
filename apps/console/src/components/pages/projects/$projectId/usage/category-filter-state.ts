/** Categories that expose live usage charts with interval breakdown. */
export function categorySupportsChartInterval(categoryId: string): boolean {
  return (
    categoryId === 'requests' ||
    categoryId === 'bandwidth' ||
    categoryId === 'compute' ||
    categoryId === 'functions' ||
    categoryId === 'sites' ||
    categoryId === 'databases' ||
    categoryId === 'realtime' ||
    categoryId === 'auth' ||
    categoryId === 'avatars' ||
    categoryId === 'messaging' ||
    categoryId === 'webhooks' ||
    categoryId === 'storage'
  )
}
