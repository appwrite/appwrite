/** Max endpoint rows (path) in overview breakdown panels. */
export const OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT = 6

/** Max rows in the usage breakdown right pane (show more). */
export const USAGE_BREAKDOWN_DRAWER_LIMIT = 100

/** Max compute resources (functions/sites) in overview executions and GB-hours breakdown. */
export const COMPUTE_BREAKDOWN_RESOURCE_LIMIT = 8

/**
 * Max rows accepted by usage `listEvents` / `listGauges` (API range 1–5000).
 * Time-series chart fetches must set this explicitly: the API default is far
 * lower, and with `orderDir: asc` that truncates the newest buckets (e.g. 1h
 * over 30 days needs ~720 points per metric).
 */
export const USAGE_API_MAX_LIMIT = 5000

export type UsageListOrderBy = 'time' | 'value'
export type UsageListOrderDir = 'asc' | 'desc'

/**
 * Resolve `orderBy` / `orderDir` / `limit` for usage.listEvents and listGauges.
 *
 * All console usage fetches must go through this so charts and flat top-N
 * breakdowns do not share the wrong sort:
 *
 * - Time series (`interval` set): `time` + `asc` + high limit (full chart).
 * - Flat top-N (`dimensions` set, no interval): `value` + `desc` + UI limit.
 *   Ascending order returns the lowest-count groups when cardinality exceeds
 *   the limit (e.g. unique paths that each have 1 request).
 * - Flat snapshot (no interval, no dimensions): `time` + `desc` so client-side
 *   "latest value" selection still works if multiple points are returned.
 */
export function resolveUsageListOrder(params: {
  interval?: string | null
  hasDimensions: boolean
  limit?: number
}): {
  orderBy: UsageListOrderBy
  orderDir: UsageListOrderDir
  limit: number
} {
  const limit = params.limit ?? USAGE_API_MAX_LIMIT

  if (params.interval) {
    return { orderBy: 'time', orderDir: 'asc', limit }
  }

  if (params.hasDimensions) {
    return { orderBy: 'value', orderDir: 'desc', limit }
  }

  return { orderBy: 'time', orderDir: 'desc', limit }
}
