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
