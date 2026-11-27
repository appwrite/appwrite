export function pageAfterEmptyResult(
  page: number,
  total: number,
  pageSize: number,
): number | null {
  if (page <= 1 || total <= 0) return null
  const last = Math.max(1, Math.ceil(total / pageSize))
  return Math.min(page - 1, last)
}
