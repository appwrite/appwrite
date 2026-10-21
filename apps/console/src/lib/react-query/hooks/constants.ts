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

/** Use in query `enabled` to skip browser-only API calls during SSR/prerender. */
export const isClientQueryEnabled = typeof window !== 'undefined'

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
 * Default page size for lists shown in a 3-column grid (buckets, sites, functions, etc.).
 * Divisible by 3 so each row is full. Must match between route loader and View.
 */
export const GRID_DEFAULT_PAGE_SIZE = 12

/**
 * Create function wizard: one API page of starter templates (filtered by use case).
 */
export const CREATE_FUNCTION_WIZARD_STARTER_LIMIT = 24

/**
 * Create function wizard: one API page of browse templates for highlights and language cards.
 */
export const CREATE_FUNCTION_WIZARD_BROWSE_LIMIT = 48

/**
 * Default page size for database rows (table-style data)
 */
export const ROWS_DEFAULT_PAGE_SIZE = 25

/**
 * Storage file inspector: first page of file tokens (permissions tab).
 * Must match `FileSecurity` default page size, `fileTokensQueryOptions`, and the bucket
 * files route loader prefetch so the inspector mounts without a loading flash.
 */
export const FILE_TOKENS_DEFAULT_PAGE_SIZE = 25

/**
 * Table workspace: first-page tables list (header, TableSelector, rows loaders,
 * Export / Import tab). Same limit as database child route `TABLES_PER_PAGE` prefetch.
 * Must match `useProjectTables` in per-product table workspaces and export-import route loader.
 */
export const TABLE_WORKSPACE_TABLES_LIST_LIMIT = 100

/**
 * Default page size for database table columns and indexes lists
 */
export const COLUMNS_INDEXES_DEFAULT_PAGE_SIZE = 100

/**
 * Default page size for activity logs (larger for log-style lists)
 */
export const ACTIVITY_DEFAULT_PAGE_SIZE = 150

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

/**
 * Default page size for billing plan summary project breakdown.
 * When an org has many projects, the breakdown is paginated via getAggregation(limit, offset).
 */
export const DEFAULT_BILLING_PROJECTS_LIMIT = 10

/**
 * Max projects to fetch for billing aggregation when not using pagination (e.g. change-plan flow).
 * Used so project breakdown pagination works without relying on API total.
 */
export const BILLING_BREAKDOWN_FETCH_LIMIT = 100
