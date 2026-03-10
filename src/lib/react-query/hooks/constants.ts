/**
 * Constants for React Query hooks
 *
 * Centralized constants for consistent configuration across all hooks.
 */

/**
 * Default stale time for most queries (30 seconds)
 * Data is considered fresh for this duration, preventing unnecessary refetches.
 */
export const DEFAULT_STALE_TIME = 30 * 1000 // 30 seconds

/**
 * Long stale time for rarely-changing data (5 minutes)
 * Used for data that doesn't change often (e.g., organizations, projects, frameworks).
 */
export const LONG_STALE_TIME = 5 * 60 * 1000 // 5 minutes

/**
 * Default page size for paginated list queries (sites, functions, storage, etc.)
 */
export const DEFAULT_PAGE_SIZE = 10

/**
 * Default page size for database rows (table-style data)
 */
export const ROWS_DEFAULT_PAGE_SIZE = 25

/**
 * Default page size for database table columns and indexes lists
 */
export const COLUMNS_INDEXES_DEFAULT_PAGE_SIZE = 100

/**
 * Default page size for activity logs (larger for log-style lists)
 */
export const ACTIVITY_DEFAULT_PAGE_SIZE = 25

/**
 * Default page size for proxy/domains lists (sites and functions domains tabs).
 * Must match between route loader and View to prevent layout shift.
 */
export const DOMAINS_DEFAULT_PAGE_SIZE = 25

/**
 * Default page size for auth teams list.
 * Must match between route loader and View to prevent layout shift.
 */
export const TEAMS_DEFAULT_PAGE_SIZE = 25

/**
 * Default page size for smaller lists (e.g., variables, API keys)
 */
export const SMALL_PAGE_SIZE = 10

/**
 * Default page size for very small lists (e.g., backup archives)
 */
export const TINY_PAGE_SIZE = 6
