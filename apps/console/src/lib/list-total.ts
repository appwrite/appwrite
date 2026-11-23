/**
 * Appwrite list endpoints compute `total` with a bounded count (`APP_LIMIT_COUNT` server-side), so
 * a `total` that reaches the bound means "at least this many rows", never "exactly this many". The
 * clamp is cheap insurance against an unbounded `COUNT(*)`, so the number has to be read as a floor
 * wherever it is shown — a project with 50,000 users otherwise reads as having exactly 5,000.
 */
export const LIST_TOTAL_CAP = 5000

/** Whether a list `total` came back clamped, making it a floor rather than a count. */
export function isListTotalCapped(total: number): boolean {
  return total >= LIST_TOTAL_CAP
}

/** Display form of a list `total` on its own: `5,000+` once the count is clamped. */
export function formatListTotal(total: number): string {
  return isListTotalCapped(total)
    ? `${total.toLocaleString()}+`
    : total.toLocaleString()
}

/**
 * Display form of the total in a `start–end of total` pagination summary.
 *
 * The count is a floor when it sits at the clamp, when the rows already listed outrun it (a count
 * taken before the latest writes), or when the caller reports another page past the counted last
 * one. Once paging runs past the clamp and the caller stops reporting more rows, the last row index
 * is the exact total.
 */
export function formatPaginationTotal({
  total,
  rangeEnd,
  currentPage,
  totalPages,
  hasNextPage,
}: {
  /** `total` as reported by the list endpoint. */
  total: number
  /** 1-based index of the last row shown on this page. */
  rangeEnd: number
  currentPage: number
  /** Last page the reported `total` accounts for. */
  totalPages: number
  /** Whether the caller found rows past this page. */
  hasNextPage: boolean
}): string {
  if (rangeEnd > total && !hasNextPage) {
    return rangeEnd.toLocaleString()
  }

  const isFloor =
    isListTotalCapped(total) ||
    rangeEnd > total ||
    (hasNextPage && currentPage >= totalPages)

  return isFloor
    ? `${Math.max(total, rangeEnd).toLocaleString()}+`
    : total.toLocaleString()
}
