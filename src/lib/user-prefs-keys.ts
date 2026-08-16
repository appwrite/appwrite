/**
 * User (account) preferences key-value format for console settings.
 *
 * Keys and values follow the extendable format documented in AGENTS.md
 * (see "Team and user preferences (key-value format)").
 */

import {
  clampRightPaneWidthPx,
  RIGHT_PANE_DEFAULT_WIDTH_PX,
  RIGHT_PANE_MAX_WIDTH_PX,
  RIGHT_PANE_MIN_WIDTH_PX,
} from '@/lib/right-pane/constants'
import {
  normalizeGeneratorPanelVisibility,
  type GeneratorPanelVisibility,
} from '@/lib/generator/panel-visibility'
import {
  AI_CHAT_CONVERSATIONS_SIDEBAR_DEFAULT_WIDTH_PX,
  clampAIChatConversationsSidebarWidthPx,
  clampCliShellSessionsSidebarWidthPx,
  clampMysqlSqlEditorHeightPx,
  clampPostgresSqlEditorHeightPx,
  clampTableViewSidebarWidthPx,
  CLI_SHELL_SESSIONS_SIDEBAR_DEFAULT_WIDTH_PX,
  normalizeLegacySidebarWidthPrefValue,
  MYSQL_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX,
} from '@/lib/resizable-layout'
import {
  CLI_SHELL_DEFAULT_HEIGHT_PX,
  CLI_SHELL_MAX_HEIGHT_RATIO,
  CLI_SHELL_MIN_HEIGHT_PX,
} from '@/lib/cli-shell/constants'
import type { SerializedUsageChartDateRange } from '@/lib/usage/usage-date-range'
import {
  normalizeUsageDateRangeSelection,
  serializeUsageChartDateRange,
} from '@/lib/usage/usage-date-range'
import { normalizeUsageChartIntervalPref } from '@/lib/usage/chart-interval'
import {
  getUsageDateRangePresetByValue,
  inferUsageDateRangePresetFromStoredRange,
} from '@/lib/usage/usage-date-range-presets'
import type { DateRange } from 'react-day-picker'

export type UserPrefs = Record<string, unknown>

/**
 * Preferred organization ID for post-auth redirects and console context.
 * Value: organization (team) ID string.
 */
export const USER_PREFS_KEY_ORGANIZATION = 'organization'

/**
 * Comma-separated feature IDs the user has dismissed (coming-soon curtains).
 * Value: string (legacy array format may still appear until rewritten).
 */
export const USER_PREFS_KEY_FEATURE_NOTIFICATIONS = 'featureNotifications'

/**
 * Appwrite `Assoc` prefs validator (`new Assoc()`): max JSON body size in bytes.
 * Oversized payloads fail with the same message as a non-object prefs value:
 * "Invalid `prefs` param: Value must be a valid object."
 */
export const APPWRITE_ACCOUNT_PREFS_MAX_BYTES = 65535

/**
 * Drop nested/legacy values before `account.updatePrefs`.
 * Nested objects/arrays bloat the JSON and are not used by this console
 * (structured data is stored as JSON strings on flat keys).
 */
export function sanitizeAccountPrefsForWrite(
  prefs: Record<string, unknown>,
): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {}
  for (const [key, value] of Object.entries(prefs)) {
    if (
      typeof value === 'string' ||
      typeof value === 'number' ||
      typeof value === 'boolean'
    ) {
      // Appwrite rejects NaN / Infinity as preference values.
      if (typeof value === 'number' && !Number.isFinite(value)) continue
      out[key] = value
    }
  }
  return out
}

/** UTF-8 byte size of the sanitized prefs payload Appwrite will validate. */
export function getAccountPrefsPayloadByteSize(
  prefs: Record<string, unknown>,
): number {
  return new TextEncoder().encode(
    JSON.stringify(sanitizeAccountPrefsForWrite(prefs)),
  ).length
}

export function isAccountPrefsPayloadWithinLimit(
  prefs: Record<string, unknown>,
  maxBytes: number = APPWRITE_ACCOUNT_PREFS_MAX_BYTES,
): boolean {
  return getAccountPrefsPayloadByteSize(prefs) <= maxBytes
}

/** Max number of saved filter presets per view scope */
export const MAX_SAVED_FILTERS_PER_SCOPE = 20

/** Max length for a saved filter name */
export const MAX_SAVED_FILTER_NAME_LENGTH = 64

/**
 * Preference key prefix for saved filter presets (per view scope).
 * Full key: console.savedFilters.<scope> (e.g. console.savedFilters.sites).
 * Value: JSON string of SavedFilter[].
 */
export const USER_PREFS_KEY_SAVED_FILTERS_PREFIX = 'console.savedFilters'

export interface SavedFilter {
  id: string
  name: string
  /** Encoded query param (same format as URL query param: encoded JSON array of CompactFilterKey). */
  query: string
  /** Optional sort (e.g. "name_asc", "$createdAt_desc"). When absent, list uses default sort. */
  sort?: string
}

export function getSavedFiltersKey(scope: string): string {
  return `${USER_PREFS_KEY_SAVED_FILTERS_PREFIX}.${scope}`
}

/**
 * Parse saved filters from user prefs for a given scope.
 */
export function parseSavedFilters(
  prefs: UserPrefs | null | undefined,
  scope: string,
): SavedFilter[] {
  const key = getSavedFiltersKey(scope)
  if (!prefs || typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is SavedFilter =>
          item != null &&
          typeof item === 'object' &&
          typeof (item as SavedFilter).id === 'string' &&
          typeof (item as SavedFilter).name === 'string' &&
          typeof (item as SavedFilter).query === 'string',
      )
      .map((item) => {
        const s = item as SavedFilter
        return {
          id: s.id,
          name: String(s.name).slice(0, MAX_SAVED_FILTER_NAME_LENGTH),
          query: s.query,
          ...(typeof s.sort === 'string' ? { sort: s.sort } : {}),
        }
      })
      .slice(0, MAX_SAVED_FILTERS_PER_SCOPE)
  } catch {
    return []
  }
}

/**
 * Build prefs object to write saved filters for a scope.
 * Merge with existing prefs before calling account.updatePrefs.
 */
export function buildSavedFiltersPrefs(
  scope: string,
  list: SavedFilter[],
): UserPrefs {
  const key = getSavedFiltersKey(scope)
  const trimmed = list.slice(0, MAX_SAVED_FILTERS_PER_SCOPE)
  return {
    [key]: JSON.stringify(trimmed),
  }
}

// ---------------------------------------------------------------------------
// Storage: image transform wizard - saved presets (account + team prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.imageTransformPresets` - JSON SavedImageTransformPreset[] */
export const USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS =
  'console.imageTransformPresets'

export const MAX_SAVED_IMAGE_TRANSFORM_PRESETS = 20
export const MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH = 64
/** Guardrail for prefs payload size */
export const MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS = 24000

export interface SavedImageTransformPreset {
  id: string
  name: string
  /** JSON string of transform fields (subset allowed; merged like Parameters JSON). */
  json: string
}

export function parseSavedImageTransformPresets(
  prefs: UserPrefs | null | undefined,
): SavedImageTransformPreset[] {
  if (
    !prefs ||
    typeof prefs[USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS] !== 'string'
  ) {
    return []
  }
  try {
    const raw = JSON.parse(
      prefs[USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS] as string,
    )
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is SavedImageTransformPreset =>
          item != null &&
          typeof item === 'object' &&
          typeof (item as SavedImageTransformPreset).id === 'string' &&
          typeof (item as SavedImageTransformPreset).name === 'string' &&
          typeof (item as SavedImageTransformPreset).json === 'string',
      )
      .map((item) => {
        const p = item as SavedImageTransformPreset
        const json =
          typeof p.json === 'string'
            ? p.json.slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS)
            : ''
        return {
          id: p.id,
          name: String(p.name).slice(
            0,
            MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH,
          ),
          json,
        }
      })
      .slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESETS)
  } catch {
    return []
  }
}

export function buildSavedImageTransformPresetsPrefs(
  list: SavedImageTransformPreset[],
): UserPrefs {
  return {
    [USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS]: JSON.stringify(
      list.slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESETS),
    ),
  }
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL saved SQL queries (account + team prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key prefix for saved PostgreSQL queries.
 * Full key: `console.postgresSavedQueries.<databaseId>`
 * Value: JSON string of SavedPostgresQuery[].
 */
export const USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_PREFIX =
  'console.postgresSavedQueries'

export const MAX_SAVED_POSTGRES_QUERIES = 30
export const MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH = 64
export const MAX_SAVED_POSTGRES_QUERY_SQL_CHARS = 48000

export interface SavedPostgresQuery {
  id: string
  name: string
  sql: string
}

export function getPostgresSavedQueriesKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_PREFIX}.${databaseId}`
}

export function parsePostgresSavedQueries(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): SavedPostgresQuery[] {
  if (!prefs || !databaseId) return []
  const key = getPostgresSavedQueriesKey(databaseId)
  const stored = prefs[key]
  let raw: unknown
  if (typeof stored === 'string') {
    try {
      raw = JSON.parse(stored)
    } catch {
      return []
    }
  } else if (Array.isArray(stored)) {
    raw = stored
  } else {
    return []
  }
  if (!Array.isArray(raw)) return []
  return raw
    .filter(
      (item): item is SavedPostgresQuery =>
        item != null &&
        typeof item === 'object' &&
        typeof (item as SavedPostgresQuery).id === 'string' &&
        typeof (item as SavedPostgresQuery).name === 'string' &&
        typeof (item as SavedPostgresQuery).sql === 'string',
    )
    .map((item) => {
      const query = item as SavedPostgresQuery
      return {
        id: query.id,
        name: String(query.name).slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH),
        sql: String(query.sql).slice(0, MAX_SAVED_POSTGRES_QUERY_SQL_CHARS),
      }
    })
    .slice(0, MAX_SAVED_POSTGRES_QUERIES)
}

export function buildPostgresSavedQueriesPrefs(
  databaseId: string,
  list: SavedPostgresQuery[],
): UserPrefs {
  const key = getPostgresSavedQueriesKey(databaseId)
  return {
    [key]: JSON.stringify(list.slice(0, MAX_SAVED_POSTGRES_QUERIES)),
  }
}

/**
 * Preference key prefix for recent PostgreSQL query runs.
 * Full key: `console.postgresQueryHistory.<databaseId>`
 * Value: JSON string of PostgresQueryHistoryEntry[].
 */
export const USER_PREFS_KEY_POSTGRES_QUERY_HISTORY_PREFIX =
  'console.postgresQueryHistory'

export const MAX_POSTGRES_QUERY_HISTORY_ENTRIES = 30

export interface PostgresQueryHistoryEntry {
  id: string
  sql: string
  ranAt: number
}

export function getPostgresQueryHistoryKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_QUERY_HISTORY_PREFIX}.${databaseId}`
}

export function parsePostgresQueryHistory(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PostgresQueryHistoryEntry[] {
  if (!prefs || !databaseId) return []
  const key = getPostgresQueryHistoryKey(databaseId)
  if (typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is PostgresQueryHistoryEntry =>
          item != null &&
          typeof item === 'object' &&
          typeof (item as PostgresQueryHistoryEntry).id === 'string' &&
          typeof (item as PostgresQueryHistoryEntry).sql === 'string' &&
          typeof (item as PostgresQueryHistoryEntry).ranAt === 'number' &&
          Number.isFinite((item as PostgresQueryHistoryEntry).ranAt),
      )
      .map((item) => {
        const entry = item as PostgresQueryHistoryEntry
        const sql = String(entry.sql).trim()
        if (!sql) return null
        return {
          id: entry.id,
          sql: sql.slice(0, MAX_SAVED_POSTGRES_QUERY_SQL_CHARS),
          ranAt: entry.ranAt,
        }
      })
      .filter((entry): entry is PostgresQueryHistoryEntry => entry != null)
      .slice(0, MAX_POSTGRES_QUERY_HISTORY_ENTRIES)
  } catch {
    return []
  }
}

export function mergePostgresQueryHistoryIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  history: PostgresQueryHistoryEntry[],
): UserPrefs {
  const key = getPostgresQueryHistoryKey(databaseId)
  return {
    ...prefs,
    [key]: JSON.stringify(history.slice(0, MAX_POSTGRES_QUERY_HISTORY_ENTRIES)),
  }
}

export type PostgresSavedQueryScope = 'user' | 'team'

/**
 * Preference key for the saved-queries scope toggle (For me / For team).
 * Full key: `console.postgresSavedQueriesScope.<databaseId>`
 * Value: `"user"` or `"team"`.
 */
export const USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SCOPE_PREFIX =
  'console.postgresSavedQueriesScope'

export function getPostgresSavedQueriesScopeKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SCOPE_PREFIX}.${databaseId}`
}

export function parsePostgresSavedQueriesScope(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PostgresSavedQueryScope | null {
  if (!prefs || !databaseId) return null
  const key = getPostgresSavedQueriesScopeKey(databaseId)
  const value = prefs[key]
  if (value === 'user' || value === 'team') return value
  return null
}

export function buildPostgresSavedQueriesScopePrefs(
  databaseId: string,
  scope: PostgresSavedQueryScope,
): UserPrefs {
  return {
    [getPostgresSavedQueriesScopeKey(databaseId)]: scope,
  }
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL sidebar selected schema (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for the schema shown in the Data sidebar panel.
 * Full key: `console.postgresSelectedSchema.<databaseId>`
 * Value: schema name string (e.g. `"public"`).
 */
export const USER_PREFS_KEY_POSTGRES_SELECTED_SCHEMA_PREFIX =
  'console.postgresSelectedSchema'

export function getPostgresSelectedSchemaKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SELECTED_SCHEMA_PREFIX}.${databaseId}`
}

export function parsePostgresSelectedSchema(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): string | null {
  if (!prefs || !databaseId) return null
  const key = getPostgresSelectedSchemaKey(databaseId)
  const value = prefs[key]
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : null
}

export function buildPostgresSelectedSchemaPrefs(
  databaseId: string,
  schema: string,
): UserPrefs {
  return {
    [getPostgresSelectedSchemaKey(databaseId)]: schema.trim(),
  }
}

export function resolvePostgresSelectedSchema(args: {
  schemas: string[]
  persisted: string | null
}): string | null {
  const { schemas, persisted } = args
  if (persisted) return persisted
  if (schemas.length === 0) return null
  if (schemas.includes('public')) return 'public'
  return schemas[0] ?? null
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL saved queries list sort (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for saved-queries sidebar sort order.
 * Full key: `console.postgresSavedQueriesSort.<databaseId>`
 * Value: PostgresSavedQueriesSort string (e.g. `name_asc`, `saved_desc`).
 */
export const USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SORT_PREFIX =
  'console.postgresSavedQueriesSort'

export type PostgresSavedQueriesSort =
  | 'saved_desc'
  | 'saved_asc'
  | 'name_asc'
  | 'name_desc'

export const POSTGRES_SAVED_QUERIES_DEFAULT_SORT: PostgresSavedQueriesSort =
  'saved_desc'

const POSTGRES_SAVED_QUERIES_SORT_VALUES: PostgresSavedQueriesSort[] = [
  'saved_desc',
  'saved_asc',
  'name_asc',
  'name_desc',
]

export const POSTGRES_SAVED_QUERIES_SORT_OPTIONS: {
  value: PostgresSavedQueriesSort
  label: string
}[] = [
  { value: 'saved_desc', label: 'Newest first' },
  { value: 'saved_asc', label: 'Oldest first' },
  { value: 'name_asc', label: 'Name (A to Z)' },
  { value: 'name_desc', label: 'Name (Z to A)' },
]

export function getPostgresSavedQueriesSortKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SAVED_QUERIES_SORT_PREFIX}.${databaseId}`
}

export function parsePostgresSavedQueriesSort(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PostgresSavedQueriesSort {
  if (!prefs || !databaseId) return POSTGRES_SAVED_QUERIES_DEFAULT_SORT
  const key = getPostgresSavedQueriesSortKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    POSTGRES_SAVED_QUERIES_SORT_VALUES.includes(
      value as PostgresSavedQueriesSort,
    )
  ) {
    return value as PostgresSavedQueriesSort
  }
  return POSTGRES_SAVED_QUERIES_DEFAULT_SORT
}

export function buildPostgresSavedQueriesSortPrefs(
  databaseId: string,
  sort: PostgresSavedQueriesSort,
): UserPrefs {
  return {
    [getPostgresSavedQueriesSortKey(databaseId)]: sort,
  }
}

export function sortSavedPostgresQueries(
  queries: SavedPostgresQuery[],
  sort: PostgresSavedQueriesSort,
): SavedPostgresQuery[] {
  switch (sort) {
    case 'name_asc':
      return [...queries].sort((a, b) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
      )
    case 'name_desc':
      return [...queries].sort((a, b) =>
        b.name.localeCompare(a.name, undefined, { sensitivity: 'base' }),
      )
    case 'saved_asc':
      return [...queries].reverse()
    case 'saved_desc':
    default:
      return queries
  }
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL Data sidebar table list sort (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for table list sort in the Data sidebar panel.
 * Full key: `console.postgresSidebarTablesSort.<databaseId>`
 * Value: PostgresSidebarTablesSort string.
 */
export const USER_PREFS_KEY_POSTGRES_SIDEBAR_TABLES_SORT_PREFIX =
  'console.postgresSidebarTablesSort'

export type PostgresSidebarTablesSort =
  | 'list_desc'
  | 'list_asc'
  | 'name_asc'
  | 'name_desc'

export const POSTGRES_SIDEBAR_TABLES_DEFAULT_SORT: PostgresSidebarTablesSort =
  'name_asc'

const POSTGRES_SIDEBAR_TABLES_SORT_VALUES: PostgresSidebarTablesSort[] = [
  'list_desc',
  'list_asc',
  'name_asc',
  'name_desc',
]

export const POSTGRES_SIDEBAR_TABLES_SORT_OPTIONS: {
  value: PostgresSidebarTablesSort
  label: string
}[] = [
  { value: 'name_asc', label: 'Name (A to Z)' },
  { value: 'name_desc', label: 'Name (Z to A)' },
  { value: 'list_desc', label: 'Default order' },
  { value: 'list_asc', label: 'Reverse order' },
]

export function getPostgresSidebarTablesSortKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SIDEBAR_TABLES_SORT_PREFIX}.${databaseId}`
}

export function parsePostgresSidebarTablesSort(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PostgresSidebarTablesSort {
  if (!prefs || !databaseId) return POSTGRES_SIDEBAR_TABLES_DEFAULT_SORT
  const key = getPostgresSidebarTablesSortKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    POSTGRES_SIDEBAR_TABLES_SORT_VALUES.includes(
      value as PostgresSidebarTablesSort,
    )
  ) {
    return value as PostgresSidebarTablesSort
  }
  return POSTGRES_SIDEBAR_TABLES_DEFAULT_SORT
}

export function buildPostgresSidebarTablesSortPrefs(
  databaseId: string,
  sort: PostgresSidebarTablesSort,
): UserPrefs {
  return {
    [getPostgresSidebarTablesSortKey(databaseId)]: sort,
  }
}

export function sortPostgresSidebarTableRows<T extends { table_name: string }>(
  tables: T[],
  sort: PostgresSidebarTablesSort,
): T[] {
  switch (sort) {
    case 'name_asc':
      return [...tables].sort((a, b) =>
        a.table_name.localeCompare(b.table_name, undefined, {
          sensitivity: 'base',
        }),
      )
    case 'name_desc':
      return [...tables].sort((a, b) =>
        b.table_name.localeCompare(a.table_name, undefined, {
          sensitivity: 'base',
        }),
      )
    case 'list_asc':
      return [...tables].reverse()
    case 'list_desc':
    default:
      return tables
  }
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL sidebar panel (Data / Queries / History)
// ---------------------------------------------------------------------------

/**
 * Preference key for the sidebar panel toggle (Data / Queries / History).
 * Full key: `console.postgresSidebarPanel.<databaseId>`
 * Value: `schemas`, `queries`, or `history`.
 */
export const USER_PREFS_KEY_POSTGRES_SIDEBAR_PANEL_PREFIX =
  'console.postgresSidebarPanel'

export type PostgresSidebarPanelPreference = 'schemas' | 'queries' | 'history'

export const POSTGRES_SIDEBAR_PANEL_DEFAULT: PostgresSidebarPanelPreference =
  'schemas'

const POSTGRES_SIDEBAR_PANEL_VALUES: PostgresSidebarPanelPreference[] = [
  'schemas',
  'queries',
  'history',
]

export function getPostgresSidebarPanelKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SIDEBAR_PANEL_PREFIX}.${databaseId}`
}

export function parsePostgresSidebarPanel(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PostgresSidebarPanelPreference {
  if (!prefs || !databaseId) return POSTGRES_SIDEBAR_PANEL_DEFAULT
  const key = getPostgresSidebarPanelKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    POSTGRES_SIDEBAR_PANEL_VALUES.includes(
      value as PostgresSidebarPanelPreference,
    )
  ) {
    return value as PostgresSidebarPanelPreference
  }
  return POSTGRES_SIDEBAR_PANEL_DEFAULT
}

export function buildPostgresSidebarPanelPrefs(
  databaseId: string,
  panel: PostgresSidebarPanelPreference,
): UserPrefs {
  return {
    [getPostgresSidebarPanelKey(databaseId)]: panel,
  }
}

// ---------------------------------------------------------------------------
// Databases: PostgreSQL SQL editor tabs (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for SQL editor open tabs and active tab.
 * Full key: `console.postgresSqlEditorState.<databaseId>`
 * Value: JSON string of PersistedPostgresSqlEditorState.
 */
export const USER_PREFS_KEY_POSTGRES_SQL_EDITOR_STATE_PREFIX =
  'console.postgresSqlEditorState'

export const MAX_POSTGRES_SQL_EDITOR_TABS = 20
export const MAX_POSTGRES_SQL_EDITOR_TAB_TITLE_LENGTH = 64

export interface PersistedPostgresSqlEditorTab {
  id: string
  title: string
  sql: string
  tableId?: string
}

export interface PersistedPostgresSqlEditorState {
  tabs: PersistedPostgresSqlEditorTab[]
  activeTabId: string
}

export function getPostgresSqlEditorStateKey(databaseId: string): string {
  return `${USER_PREFS_KEY_POSTGRES_SQL_EDITOR_STATE_PREFIX}.${databaseId}`
}

export function parsePostgresSqlEditorState(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PersistedPostgresSqlEditorState | null {
  if (!prefs || !databaseId) return null
  const key = getPostgresSqlEditorStateKey(databaseId)
  const stored = prefs[key]
  let raw: unknown
  if (typeof stored === 'string') {
    try {
      raw = JSON.parse(stored)
    } catch {
      return null
    }
  } else if (stored != null && typeof stored === 'object') {
    raw = stored
  } else {
    return null
  }

  if (!raw || typeof raw !== 'object') return null
  const record = raw as Record<string, unknown>
  if (!Array.isArray(record.tabs)) return null

  const tabs = record.tabs
    .filter(
      (item): item is PersistedPostgresSqlEditorTab =>
        item != null &&
        typeof item === 'object' &&
        typeof (item as PersistedPostgresSqlEditorTab).id === 'string' &&
        typeof (item as PersistedPostgresSqlEditorTab).title === 'string' &&
        typeof (item as PersistedPostgresSqlEditorTab).sql === 'string',
    )
    .map((item) => {
      const tab = item as PersistedPostgresSqlEditorTab
      const tableId =
        typeof tab.tableId === 'string' && tab.tableId.trim()
          ? tab.tableId.trim()
          : undefined
      return {
        id: tab.id,
        title: String(tab.title).slice(
          0,
          MAX_POSTGRES_SQL_EDITOR_TAB_TITLE_LENGTH,
        ),
        sql: String(tab.sql).slice(0, MAX_SAVED_POSTGRES_QUERY_SQL_CHARS),
        ...(tableId ? { tableId } : {}),
      }
    })
    .slice(0, MAX_POSTGRES_SQL_EDITOR_TABS)

  if (tabs.length === 0) return null

  const activeTabId =
    typeof record.activeTabId === 'string' &&
    tabs.some((tab) => tab.id === record.activeTabId)
      ? record.activeTabId
      : tabs[0].id

  return { tabs, activeTabId }
}

export function buildPostgresSqlEditorStatePrefs(
  databaseId: string,
  state: PersistedPostgresSqlEditorState,
): UserPrefs {
  const key = getPostgresSqlEditorStateKey(databaseId)
  const tabs = state.tabs.slice(0, MAX_POSTGRES_SQL_EDITOR_TABS).map((tab) => ({
    id: tab.id,
    title: String(tab.title).slice(0, MAX_POSTGRES_SQL_EDITOR_TAB_TITLE_LENGTH),
    sql: String(tab.sql).slice(0, MAX_SAVED_POSTGRES_QUERY_SQL_CHARS),
    ...(tab.tableId ? { tableId: tab.tableId } : {}),
  }))
  const activeTabId = tabs.some((tab) => tab.id === state.activeTabId)
    ? state.activeTabId
    : (tabs[0]?.id ?? state.activeTabId)

  return {
    [key]: JSON.stringify({ tabs, activeTabId }),
  }
}

export function mergePostgresSqlEditorStateIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  state: PersistedPostgresSqlEditorState,
): UserPrefs {
  return {
    ...prefs,
    ...buildPostgresSqlEditorStatePrefs(databaseId, state),
  }
}

export function resolvePostgresSavedQueriesScope(args: {
  persisted: PostgresSavedQueryScope | null
  hasTeamLevel: boolean
  userQueryCount: number
  teamQueryCount: number
}): PostgresSavedQueryScope {
  const { persisted, hasTeamLevel, userQueryCount, teamQueryCount } = args

  if (persisted === 'user') return 'user'
  if (persisted === 'team' && hasTeamLevel) return 'team'

  if (!hasTeamLevel) return 'user'
  if (userQueryCount > 0 && teamQueryCount === 0) return 'user'
  if (teamQueryCount > 0 && userQueryCount === 0) return 'team'
  return 'user'
}

// ---------------------------------------------------------------------------
// Databases: MySQL saved SQL queries (account + team prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key prefix for saved MySQL queries.
 * Full key: `console.mysqlSavedQueries.<databaseId>`
 * Value: JSON string of SavedMysqlQuery[].
 */
export const USER_PREFS_KEY_MYSQL_SAVED_QUERIES_PREFIX =
  'console.mysqlSavedQueries'

export const MAX_SAVED_MYSQL_QUERIES = 30
export const MAX_SAVED_MYSQL_QUERY_NAME_LENGTH = 64
export const MAX_SAVED_MYSQL_QUERY_SQL_CHARS = 48000

export interface SavedMysqlQuery {
  id: string
  name: string
  sql: string
}

export function getMysqlSavedQueriesKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SAVED_QUERIES_PREFIX}.${databaseId}`
}

export function parseMysqlSavedQueries(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): SavedMysqlQuery[] {
  if (!prefs || !databaseId) return []
  const key = getMysqlSavedQueriesKey(databaseId)
  const stored = prefs[key]
  let raw: unknown
  if (typeof stored === 'string') {
    try {
      raw = JSON.parse(stored)
    } catch {
      return []
    }
  } else if (Array.isArray(stored)) {
    raw = stored
  } else {
    return []
  }
  if (!Array.isArray(raw)) return []
  return raw
    .filter(
      (item): item is SavedMysqlQuery =>
        item != null &&
        typeof item === 'object' &&
        typeof (item as SavedMysqlQuery).id === 'string' &&
        typeof (item as SavedMysqlQuery).name === 'string' &&
        typeof (item as SavedMysqlQuery).sql === 'string',
    )
    .map((item) => {
      const query = item as SavedMysqlQuery
      return {
        id: query.id,
        name: String(query.name).slice(0, MAX_SAVED_MYSQL_QUERY_NAME_LENGTH),
        sql: String(query.sql).slice(0, MAX_SAVED_MYSQL_QUERY_SQL_CHARS),
      }
    })
    .slice(0, MAX_SAVED_MYSQL_QUERIES)
}

export function buildMysqlSavedQueriesPrefs(
  databaseId: string,
  list: SavedMysqlQuery[],
): UserPrefs {
  const key = getMysqlSavedQueriesKey(databaseId)
  return {
    [key]: JSON.stringify(list.slice(0, MAX_SAVED_MYSQL_QUERIES)),
  }
}

/**
 * Preference key prefix for recent MySQL query runs.
 * Full key: `console.mysqlQueryHistory.<databaseId>`
 * Value: JSON string of MysqlQueryHistoryEntry[].
 */
export const USER_PREFS_KEY_MYSQL_QUERY_HISTORY_PREFIX =
  'console.mysqlQueryHistory'

export const MAX_MYSQL_QUERY_HISTORY_ENTRIES = 30

export interface MysqlQueryHistoryEntry {
  id: string
  sql: string
  ranAt: number
}

export function getMysqlQueryHistoryKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_QUERY_HISTORY_PREFIX}.${databaseId}`
}

export function parseMysqlQueryHistory(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): MysqlQueryHistoryEntry[] {
  if (!prefs || !databaseId) return []
  const key = getMysqlQueryHistoryKey(databaseId)
  if (typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is MysqlQueryHistoryEntry =>
          item != null &&
          typeof item === 'object' &&
          typeof (item as MysqlQueryHistoryEntry).id === 'string' &&
          typeof (item as MysqlQueryHistoryEntry).sql === 'string' &&
          typeof (item as MysqlQueryHistoryEntry).ranAt === 'number' &&
          Number.isFinite((item as MysqlQueryHistoryEntry).ranAt),
      )
      .map((item) => {
        const entry = item as MysqlQueryHistoryEntry
        const sql = String(entry.sql).trim()
        if (!sql) return null
        return {
          id: entry.id,
          sql: sql.slice(0, MAX_SAVED_MYSQL_QUERY_SQL_CHARS),
          ranAt: entry.ranAt,
        }
      })
      .filter((entry): entry is MysqlQueryHistoryEntry => entry != null)
      .slice(0, MAX_MYSQL_QUERY_HISTORY_ENTRIES)
  } catch {
    return []
  }
}

export function mergeMysqlQueryHistoryIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  history: MysqlQueryHistoryEntry[],
): UserPrefs {
  const key = getMysqlQueryHistoryKey(databaseId)
  return {
    ...prefs,
    [key]: JSON.stringify(history.slice(0, MAX_MYSQL_QUERY_HISTORY_ENTRIES)),
  }
}

export type MysqlSavedQueryScope = 'user' | 'team'

/**
 * Preference key for the saved-queries scope toggle (For me / For team).
 * Full key: `console.mysqlSavedQueriesScope.<databaseId>`
 * Value: `"user"` or `"team"`.
 */
export const USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SCOPE_PREFIX =
  'console.mysqlSavedQueriesScope'

export function getMysqlSavedQueriesScopeKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SCOPE_PREFIX}.${databaseId}`
}

export function parseMysqlSavedQueriesScope(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): MysqlSavedQueryScope | null {
  if (!prefs || !databaseId) return null
  const key = getMysqlSavedQueriesScopeKey(databaseId)
  const value = prefs[key]
  if (value === 'user' || value === 'team') return value
  return null
}

export function buildMysqlSavedQueriesScopePrefs(
  databaseId: string,
  scope: MysqlSavedQueryScope,
): UserPrefs {
  return {
    [getMysqlSavedQueriesScopeKey(databaseId)]: scope,
  }
}

// ---------------------------------------------------------------------------
// Databases: MySQL sidebar selected schema (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for the schema shown in the Data sidebar panel.
 * Full key: `console.mysqlSelectedSchema.<databaseId>`
 * Value: schema name string (e.g. `"public"`).
 */
export const USER_PREFS_KEY_MYSQL_SELECTED_SCHEMA_PREFIX =
  'console.mysqlSelectedSchema'

export function getMysqlSelectedSchemaKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SELECTED_SCHEMA_PREFIX}.${databaseId}`
}

export function parseMysqlSelectedSchema(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): string | null {
  if (!prefs || !databaseId) return null
  const key = getMysqlSelectedSchemaKey(databaseId)
  const value = prefs[key]
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : null
}

export function buildMysqlSelectedSchemaPrefs(
  databaseId: string,
  schema: string,
): UserPrefs {
  return {
    [getMysqlSelectedSchemaKey(databaseId)]: schema.trim(),
  }
}

export function resolveMysqlSelectedSchema(args: {
  schemas: string[]
  persisted: string | null
}): string | null {
  const { schemas, persisted } = args
  const normalizedSchemas = schemas
    .map((schema) => (typeof schema === 'string' ? schema.trim() : ''))
    .filter((schema) => schema.length > 0)
  if (persisted) {
    const trimmed = persisted.trim()
    if (trimmed) return trimmed
  }
  if (normalizedSchemas.length === 0) return null

  const systemSchemas = new Set([
    'mysql',
    'information_schema',
    'performance_schema',
    'sys',
  ])
  const userSchema = normalizedSchemas.find(
    (schema) => !systemSchemas.has(schema.toLowerCase()),
  )
  if (userSchema) return userSchema

  // MySQL has no Postgres-style `public` default; prefer it only when present.
  if (normalizedSchemas.includes('public')) return 'public'
  return normalizedSchemas[0] ?? null
}

// ---------------------------------------------------------------------------
// Databases: MySQL saved queries list sort (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for saved-queries sidebar sort order.
 * Full key: `console.mysqlSavedQueriesSort.<databaseId>`
 * Value: MysqlSavedQueriesSort string (e.g. `name_asc`, `saved_desc`).
 */
export const USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SORT_PREFIX =
  'console.mysqlSavedQueriesSort'

export type MysqlSavedQueriesSort =
  | 'saved_desc'
  | 'saved_asc'
  | 'name_asc'
  | 'name_desc'

export const MYSQL_SAVED_QUERIES_DEFAULT_SORT: MysqlSavedQueriesSort =
  'saved_desc'

const MYSQL_SAVED_QUERIES_SORT_VALUES: MysqlSavedQueriesSort[] = [
  'saved_desc',
  'saved_asc',
  'name_asc',
  'name_desc',
]

export const MYSQL_SAVED_QUERIES_SORT_OPTIONS: {
  value: MysqlSavedQueriesSort
  label: string
}[] = [
  { value: 'saved_desc', label: 'Newest first' },
  { value: 'saved_asc', label: 'Oldest first' },
  { value: 'name_asc', label: 'Name (A to Z)' },
  { value: 'name_desc', label: 'Name (Z to A)' },
]

export function getMysqlSavedQueriesSortKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SAVED_QUERIES_SORT_PREFIX}.${databaseId}`
}

export function parseMysqlSavedQueriesSort(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): MysqlSavedQueriesSort {
  if (!prefs || !databaseId) return MYSQL_SAVED_QUERIES_DEFAULT_SORT
  const key = getMysqlSavedQueriesSortKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    MYSQL_SAVED_QUERIES_SORT_VALUES.includes(
      value as MysqlSavedQueriesSort,
    )
  ) {
    return value as MysqlSavedQueriesSort
  }
  return MYSQL_SAVED_QUERIES_DEFAULT_SORT
}

export function buildMysqlSavedQueriesSortPrefs(
  databaseId: string,
  sort: MysqlSavedQueriesSort,
): UserPrefs {
  return {
    [getMysqlSavedQueriesSortKey(databaseId)]: sort,
  }
}

export function sortSavedMysqlQueries(
  queries: SavedMysqlQuery[],
  sort: MysqlSavedQueriesSort,
): SavedMysqlQuery[] {
  switch (sort) {
    case 'name_asc':
      return [...queries].sort((a, b) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
      )
    case 'name_desc':
      return [...queries].sort((a, b) =>
        b.name.localeCompare(a.name, undefined, { sensitivity: 'base' }),
      )
    case 'saved_asc':
      return [...queries].reverse()
    case 'saved_desc':
    default:
      return queries
  }
}

// ---------------------------------------------------------------------------
// Databases: MySQL Data sidebar table list sort (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for table list sort in the Data sidebar panel.
 * Full key: `console.mysqlSidebarTablesSort.<databaseId>`
 * Value: MysqlSidebarTablesSort string.
 */
export const USER_PREFS_KEY_MYSQL_SIDEBAR_TABLES_SORT_PREFIX =
  'console.mysqlSidebarTablesSort'

export type MysqlSidebarTablesSort =
  | 'list_desc'
  | 'list_asc'
  | 'name_asc'
  | 'name_desc'

export const MYSQL_SIDEBAR_TABLES_DEFAULT_SORT: MysqlSidebarTablesSort =
  'name_asc'

const MYSQL_SIDEBAR_TABLES_SORT_VALUES: MysqlSidebarTablesSort[] = [
  'list_desc',
  'list_asc',
  'name_asc',
  'name_desc',
]

export const MYSQL_SIDEBAR_TABLES_SORT_OPTIONS: {
  value: MysqlSidebarTablesSort
  label: string
}[] = [
  { value: 'name_asc', label: 'Name (A to Z)' },
  { value: 'name_desc', label: 'Name (Z to A)' },
  { value: 'list_desc', label: 'Default order' },
  { value: 'list_asc', label: 'Reverse order' },
]

export function getMysqlSidebarTablesSortKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SIDEBAR_TABLES_SORT_PREFIX}.${databaseId}`
}

export function parseMysqlSidebarTablesSort(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): MysqlSidebarTablesSort {
  if (!prefs || !databaseId) return MYSQL_SIDEBAR_TABLES_DEFAULT_SORT
  const key = getMysqlSidebarTablesSortKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    MYSQL_SIDEBAR_TABLES_SORT_VALUES.includes(
      value as MysqlSidebarTablesSort,
    )
  ) {
    return value as MysqlSidebarTablesSort
  }
  return MYSQL_SIDEBAR_TABLES_DEFAULT_SORT
}

export function buildMysqlSidebarTablesSortPrefs(
  databaseId: string,
  sort: MysqlSidebarTablesSort,
): UserPrefs {
  return {
    [getMysqlSidebarTablesSortKey(databaseId)]: sort,
  }
}

export function sortMysqlSidebarTableRows<T extends { table_name: string }>(
  tables: T[],
  sort: MysqlSidebarTablesSort,
): T[] {
  switch (sort) {
    case 'name_asc':
      return [...tables].sort((a, b) =>
        a.table_name.localeCompare(b.table_name, undefined, {
          sensitivity: 'base',
        }),
      )
    case 'name_desc':
      return [...tables].sort((a, b) =>
        b.table_name.localeCompare(a.table_name, undefined, {
          sensitivity: 'base',
        }),
      )
    case 'list_asc':
      return [...tables].reverse()
    case 'list_desc':
    default:
      return tables
  }
}

// ---------------------------------------------------------------------------
// Databases: MySQL sidebar panel (Data / Queries / History)
// ---------------------------------------------------------------------------

/**
 * Preference key for the sidebar panel toggle (Data / Queries / History).
 * Full key: `console.mysqlSidebarPanel.<databaseId>`
 * Value: `schemas`, `queries`, or `history`.
 */
export const USER_PREFS_KEY_MYSQL_SIDEBAR_PANEL_PREFIX =
  'console.mysqlSidebarPanel'

export type MysqlSidebarPanelPreference = 'schemas' | 'queries' | 'history'

export const MYSQL_SIDEBAR_PANEL_DEFAULT: MysqlSidebarPanelPreference =
  'schemas'

const MYSQL_SIDEBAR_PANEL_VALUES: MysqlSidebarPanelPreference[] = [
  'schemas',
  'queries',
  'history',
]

export function getMysqlSidebarPanelKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SIDEBAR_PANEL_PREFIX}.${databaseId}`
}

export function parseMysqlSidebarPanel(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): MysqlSidebarPanelPreference {
  if (!prefs || !databaseId) return MYSQL_SIDEBAR_PANEL_DEFAULT
  const key = getMysqlSidebarPanelKey(databaseId)
  const value = prefs[key]
  if (
    typeof value === 'string' &&
    MYSQL_SIDEBAR_PANEL_VALUES.includes(
      value as MysqlSidebarPanelPreference,
    )
  ) {
    return value as MysqlSidebarPanelPreference
  }
  return MYSQL_SIDEBAR_PANEL_DEFAULT
}

export function buildMysqlSidebarPanelPrefs(
  databaseId: string,
  panel: MysqlSidebarPanelPreference,
): UserPrefs {
  return {
    [getMysqlSidebarPanelKey(databaseId)]: panel,
  }
}

// ---------------------------------------------------------------------------
// Databases: MySQL SQL editor tabs (account prefs, per database)
// ---------------------------------------------------------------------------

/**
 * Preference key for SQL editor open tabs and active tab.
 * Full key: `console.mysqlSqlEditorState.<databaseId>`
 * Value: JSON string of PersistedMysqlSqlEditorState.
 */
export const USER_PREFS_KEY_MYSQL_SQL_EDITOR_STATE_PREFIX =
  'console.mysqlSqlEditorState'

export const MAX_MYSQL_SQL_EDITOR_TABS = 20
export const MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH = 64

export interface PersistedMysqlSqlEditorTab {
  id: string
  title: string
  sql: string
  tableId?: string
}

export interface PersistedMysqlSqlEditorState {
  tabs: PersistedMysqlSqlEditorTab[]
  activeTabId: string
}

export function getMysqlSqlEditorStateKey(databaseId: string): string {
  return `${USER_PREFS_KEY_MYSQL_SQL_EDITOR_STATE_PREFIX}.${databaseId}`
}

export function parseMysqlSqlEditorState(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
): PersistedMysqlSqlEditorState | null {
  if (!prefs || !databaseId) return null
  const key = getMysqlSqlEditorStateKey(databaseId)
  const stored = prefs[key]
  let raw: unknown
  if (typeof stored === 'string') {
    try {
      raw = JSON.parse(stored)
    } catch {
      return null
    }
  } else if (stored != null && typeof stored === 'object') {
    raw = stored
  } else {
    return null
  }

  if (!raw || typeof raw !== 'object') return null
  const record = raw as Record<string, unknown>
  if (!Array.isArray(record.tabs)) return null

  const tabs = record.tabs
    .filter(
      (item): item is PersistedMysqlSqlEditorTab =>
        item != null &&
        typeof item === 'object' &&
        typeof (item as PersistedMysqlSqlEditorTab).id === 'string' &&
        typeof (item as PersistedMysqlSqlEditorTab).title === 'string' &&
        typeof (item as PersistedMysqlSqlEditorTab).sql === 'string',
    )
    .map((item) => {
      const tab = item as PersistedMysqlSqlEditorTab
      const tableId =
        typeof tab.tableId === 'string' && tab.tableId.trim()
          ? tab.tableId.trim()
          : undefined
      return {
        id: tab.id,
        title: String(tab.title).slice(
          0,
          MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH,
        ),
        sql: String(tab.sql).slice(0, MAX_SAVED_MYSQL_QUERY_SQL_CHARS),
        ...(tableId ? { tableId } : {}),
      }
    })
    .slice(0, MAX_MYSQL_SQL_EDITOR_TABS)

  if (tabs.length === 0) return null

  const activeTabId =
    typeof record.activeTabId === 'string' &&
    tabs.some((tab) => tab.id === record.activeTabId)
      ? record.activeTabId
      : tabs[0].id

  return { tabs, activeTabId }
}

export function buildMysqlSqlEditorStatePrefs(
  databaseId: string,
  state: PersistedMysqlSqlEditorState,
): UserPrefs {
  const key = getMysqlSqlEditorStateKey(databaseId)
  const tabs = state.tabs.slice(0, MAX_MYSQL_SQL_EDITOR_TABS).map((tab) => ({
    id: tab.id,
    title: String(tab.title).slice(0, MAX_MYSQL_SQL_EDITOR_TAB_TITLE_LENGTH),
    sql: String(tab.sql).slice(0, MAX_SAVED_MYSQL_QUERY_SQL_CHARS),
    ...(tab.tableId ? { tableId: tab.tableId } : {}),
  }))
  const activeTabId = tabs.some((tab) => tab.id === state.activeTabId)
    ? state.activeTabId
    : (tabs[0]?.id ?? state.activeTabId)

  return {
    [key]: JSON.stringify({ tabs, activeTabId }),
  }
}

export function mergeMysqlSqlEditorStateIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  state: PersistedMysqlSqlEditorState,
): UserPrefs {
  return {
    ...prefs,
    ...buildMysqlSqlEditorStatePrefs(databaseId, state),
  }
}

export function resolveMysqlSavedQueriesScope(args: {
  persisted: MysqlSavedQueryScope | null
  hasTeamLevel: boolean
  userQueryCount: number
  teamQueryCount: number
}): MysqlSavedQueryScope {
  const { persisted, hasTeamLevel, userQueryCount, teamQueryCount } = args

  if (persisted === 'user') return 'user'
  if (persisted === 'team' && hasTeamLevel) return 'team'

  if (!hasTeamLevel) return 'user'
  if (userQueryCount > 0 && teamQueryCount === 0) return 'user'
  if (teamQueryCount > 0 && userQueryCount === 0) return 'team'
  return 'user'
}

// ---------------------------------------------------------------------------
// Databases: tables sidebar width (single shared setting across all databases)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.databases.sidebarWidth` - sidebar width in px for
 * `TableViewResizableLayout` (shared across all databases / tables).
 * Legacy values ≤60 were stored as percent and are migrated on read.
 */
export const USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH =
  'console.databases.sidebarWidth'

export function parseDatabasesSidebarWidthPx(
  prefs: UserPrefs | null | undefined,
): number | null {
  return parseSidebarWidthPxForKey(
    prefs,
    USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH,
  )
}

export function buildDatabasesSidebarWidthPrefs(widthPx: number): UserPrefs {
  return buildSidebarWidthPxPrefsForKey(
    USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH,
    widthPx,
  )
}

// ---------------------------------------------------------------------------
// Storage: buckets sidebar width (separate from databases tables sidebar)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.storage.sidebarWidth` - buckets list sidebar width in px.
 */
export const USER_PREFS_KEY_STORAGE_SIDEBAR_WIDTH =
  'console.storage.sidebarWidth'

export function parseStorageSidebarWidthPx(
  prefs: UserPrefs | null | undefined,
): number | null {
  return parseSidebarWidthPxForKey(prefs, USER_PREFS_KEY_STORAGE_SIDEBAR_WIDTH)
}

export function buildStorageSidebarWidthPrefs(widthPx: number): UserPrefs {
  return buildSidebarWidthPxPrefsForKey(
    USER_PREFS_KEY_STORAGE_SIDEBAR_WIDTH,
    widthPx,
  )
}

function parseSidebarWidthPxForKey(
  prefs: UserPrefs | null | undefined,
  key: string,
): number | null {
  if (!prefs) return null
  const raw = prefs[key]
  let value: number | null = null
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    value = raw
  } else if (typeof raw === 'string' && raw.length > 0) {
    const parsed = Number(raw)
    if (Number.isFinite(parsed)) value = parsed
  }
  if (value === null) return null
  return normalizeLegacySidebarWidthPrefValue(value)
}

function buildSidebarWidthPxPrefsForKey(
  key: string,
  widthPx: number,
): UserPrefs {
  return {
    [key]: String(clampTableViewSidebarWidthPx(widthPx)),
  }
}

/** Default when account pref is unset. */
export { TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX as DATABASES_SIDEBAR_DEFAULT_WIDTH_PX }

// ---------------------------------------------------------------------------
// Postgres: SQL editor container height (vertical split in SQL workbench)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.databases.postgresSqlEditorHeight` - SQL editor container
 * height in px (includes toolbar).
 */
export const USER_PREFS_KEY_POSTGRES_SQL_EDITOR_HEIGHT =
  'console.databases.postgresSqlEditorHeight'

export function parsePostgresSqlEditorHeightPx(
  prefs: UserPrefs | null | undefined,
): number | null {
  if (!prefs) return null
  const raw = prefs[USER_PREFS_KEY_POSTGRES_SQL_EDITOR_HEIGHT]
  let value: number | null = null
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    value = raw
  } else if (typeof raw === 'string' && raw.length > 0) {
    const parsed = Number(raw)
    if (Number.isFinite(parsed)) value = parsed
  }
  if (value === null) return null
  return clampPostgresSqlEditorHeightPx(value)
}

export function buildPostgresSqlEditorHeightPrefs(heightPx: number): UserPrefs {
  return {
    [USER_PREFS_KEY_POSTGRES_SQL_EDITOR_HEIGHT]: String(
      clampPostgresSqlEditorHeightPx(heightPx),
    ),
  }
}

export { POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX }

// ---------------------------------------------------------------------------
// MySQL: SQL editor container height (vertical split in SQL workbench)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.databases.mysqlSqlEditorHeight` - SQL editor container
 * height in px (includes toolbar).
 */
export const USER_PREFS_KEY_MYSQL_SQL_EDITOR_HEIGHT =
  'console.databases.mysqlSqlEditorHeight'

export function parseMysqlSqlEditorHeightPx(
  prefs: UserPrefs | null | undefined,
): number | null {
  if (!prefs) return null
  const raw = prefs[USER_PREFS_KEY_MYSQL_SQL_EDITOR_HEIGHT]
  let value: number | null = null
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    value = raw
  } else if (typeof raw === 'string' && raw.length > 0) {
    const parsed = Number(raw)
    if (Number.isFinite(parsed)) value = parsed
  }
  if (value === null) return null
  return clampMysqlSqlEditorHeightPx(value)
}

export function buildMysqlSqlEditorHeightPrefs(heightPx: number): UserPrefs {
  return {
    [USER_PREFS_KEY_MYSQL_SQL_EDITOR_HEIGHT]: String(
      clampMysqlSqlEditorHeightPx(heightPx),
    ),
  }
}

export { MYSQL_SQL_EDITOR_DEFAULT_HEIGHT_PX }

// ---------------------------------------------------------------------------
// Storage: files list column widths (account - same widths for every bucket)
// ---------------------------------------------------------------------------

/** Full key: `console.storageFiles.listColumnWidths` - JSON Record<columnKey, number> (px). */
export const USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS =
  'console.storageFiles.listColumnWidths'

export const STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX = 72
export const STORAGE_FILES_LIST_DATA_COLUMN_MAX_WIDTH_PX = 640

export const STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS = [
  '$id',
  'name',
  'mimeType',
  'sizeOriginal',
  '$createdAt',
  '$updatedAt',
] as const

export type StorageFilesListColumnWidthKey =
  (typeof STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS)[number]

/** File list columns that show a drag resize rail ($id is fixed width). */
export type StorageFilesListResizableColumnWidthKey = Exclude<
  StorageFilesListColumnWidthKey,
  '$id'
>

export const STORAGE_FILES_LIST_RESIZABLE_COLUMN_WIDTH_KEYS =
  STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS.filter(
    (k): k is StorageFilesListResizableColumnWidthKey => k !== '$id',
  )

export const STORAGE_FILES_LIST_COLUMN_DEFAULT_WIDTHS: Record<
  StorageFilesListColumnWidthKey,
  number
> = {
  $id: 180,
  name: 160,
  mimeType: 140,
  sizeOriginal: 120,
  $createdAt: 180,
  $updatedAt: 180,
}

const STORAGE_FILES_LIST_COLUMN_WIDTH_KEY_SET = new Set<string>(
  STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS,
)

export function clampStorageFilesListDataColumnWidthPx(px: number): number {
  return Math.min(
    STORAGE_FILES_LIST_DATA_COLUMN_MAX_WIDTH_PX,
    Math.max(STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX, Math.round(px)),
  )
}

export function mergeStorageFilesListColumnWidthsWithDefaults(
  stored: Record<string, number> | undefined,
): Record<StorageFilesListColumnWidthKey, number> {
  const out = { ...STORAGE_FILES_LIST_COLUMN_DEFAULT_WIDTHS }
  if (!stored) return out
  for (const k of STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS) {
    if (k === '$id') continue
    const v = stored[k]
    if (typeof v === 'number' && Number.isFinite(v)) {
      out[k] = clampStorageFilesListDataColumnWidthPx(v)
    }
  }
  return out
}

export function getStorageFilesListColumnWidthsFromPrefs(
  prefs: UserPrefs | null | undefined,
): Record<string, number> {
  const raw = prefs?.[USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS]
  if (raw == null) return {}

  let parsed: unknown
  if (typeof raw === 'string') {
    const s = raw.trim()
    if (s.length === 0) return {}
    try {
      parsed = JSON.parse(s) as unknown
    } catch {
      return {}
    }
  } else if (typeof raw === 'object' && !Array.isArray(raw)) {
    parsed = raw
  } else {
    return {}
  }

  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return {}
  }

  const out: Record<string, number> = {}
  for (const [k, v] of Object.entries(parsed as Record<string, unknown>)) {
    if (!STORAGE_FILES_LIST_COLUMN_WIDTH_KEY_SET.has(k)) continue
    const n = typeof v === 'number' ? v : Number(v)
    if (!Number.isFinite(n)) continue
    out[k] = clampStorageFilesListDataColumnWidthPx(n)
  }
  return out
}

export function mergeStorageFilesListColumnWidthsIntoPrefs(
  prefs: UserPrefs,
  widths: Record<string, number>,
): UserPrefs {
  const next = { ...prefs }
  const payload: Record<string, number> = {}
  for (const k of STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS) {
    const w = widths[k]
    if (typeof w === 'number' && Number.isFinite(w)) {
      payload[k] = clampStorageFilesListDataColumnWidthPx(w)
    }
  }
  if (Object.keys(payload).length === 0) {
    delete next[USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS]
    return next
  }
  next[USER_PREFS_KEY_STORAGE_FILES_LIST_COLUMN_WIDTHS] =
    JSON.stringify(payload)
  return next
}

// ---------------------------------------------------------------------------
// Storage: files list / inline preview split (account - same width every bucket)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.storageFiles.tablePaneWidthPx` - table (left) pane width in px
 * for the files list + inline preview split. When unset, parsers return
 * {@link STORAGE_FILES_TABLE_PANE_MAX_PX} so the first layout pass keeps a narrow preview.
 */
export const USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX =
  'console.storageFiles.tablePaneWidthPx'

export const STORAGE_FILES_TABLE_PANE_MIN_PX = 260
export const STORAGE_FILES_TABLE_PANE_MAX_PX = 4000

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_STORAGE_FILES_TABLE_PANE_WIDTH =
  'console.storageFilesTablePaneWidthPx'

export function clampStorageFilesTablePaneWidthPx(px: number): number {
  return Math.min(
    STORAGE_FILES_TABLE_PANE_MAX_PX,
    Math.max(STORAGE_FILES_TABLE_PANE_MIN_PX, Math.round(px)),
  )
}

export function parseStorageFilesTablePaneWidthPx(
  prefs: UserPrefs | null | undefined,
): number | null {
  const raw = prefs?.[USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (
    Number.isFinite(n) &&
    n >= STORAGE_FILES_TABLE_PANE_MIN_PX &&
    n <= STORAGE_FILES_TABLE_PANE_MAX_PX
  ) {
    return n
  }
  return null
}

export function hasStorageFilesTablePaneWidthPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX] !== undefined
}

export function mergeStorageFilesTablePaneWidthPxIntoPrefs(
  prefs: UserPrefs,
  widthPx: number,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_STORAGE_FILES_TABLE_PANE_WIDTH_PX]: String(
      clampStorageFilesTablePaneWidthPx(widthPx),
    ),
  }
}

export function readLegacyStorageFilesTablePaneWidthFromLocalStorage():
  | number
  | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(
      LEGACY_LOCAL_STORAGE_STORAGE_FILES_TABLE_PANE_WIDTH,
    )
    if (!raw) return null
    const n = parseInt(raw, 10)
    if (
      Number.isFinite(n) &&
      n >= STORAGE_FILES_TABLE_PANE_MIN_PX &&
      n <= STORAGE_FILES_TABLE_PANE_MAX_PX
    ) {
      return n
    }
    return null
  } catch {
    return null
  }
}

export function clearLegacyStorageFilesTablePaneWidthLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.removeItem(LEGACY_LOCAL_STORAGE_STORAGE_FILES_TABLE_PANE_WIDTH)
  } catch {
    /* private mode */
  }
}

// ---------------------------------------------------------------------------
// Databases: tables DB row grid column widths (per database + table, account)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.databaseTables.rowColumnWidths`
 *
 * Value: JSON string. Preferred shape:
 * `{ databases: Record<databaseId, Record<tableKey, Record<columnKey, number>>> }`
 * where `tableKey` is `tableId`, or `tableId#columns` / `tableId#indexes` for other grids.
 * Column keys are non-system attributes; values are widths in px.
 *
 * Legacy (still read for migration): flat `Record<tableId, Record<columnKey, number>>`
 * with no `databases` key. New writes use the `databases` wrapper only.
 */
export const USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS =
  'console.databaseTables.rowColumnWidths'

type RowColumnWidthsByTable = Record<string, Record<string, number>>

function parseInnerRowColumnWidthMap(cols: unknown): Record<string, number> {
  const inner: Record<string, number> = {}
  if (typeof cols !== 'object' || cols === null || Array.isArray(cols)) {
    return inner
  }
  for (const [ck, w] of Object.entries(cols as Record<string, unknown>)) {
    if (!ck || ck.startsWith('$')) continue
    const n = typeof w === 'number' ? w : Number(w)
    if (!Number.isFinite(n)) continue
    inner[ck] = n
  }
  return inner
}

function parseRowColumnWidthsByDatabase(
  raw: unknown,
): Record<string, RowColumnWidthsByTable> {
  const out: Record<string, RowColumnWidthsByTable> = {}
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return out
  for (const [dbId, tables] of Object.entries(raw as Record<string, unknown>)) {
    if (!dbId) continue
    if (
      typeof tables !== 'object' ||
      tables === null ||
      Array.isArray(tables)
    ) {
      continue
    }
    const bucket: RowColumnWidthsByTable = {}
    for (const [tableKey, colMap] of Object.entries(
      tables as Record<string, unknown>,
    )) {
      if (!tableKey) continue
      bucket[tableKey] = parseInnerRowColumnWidthMap(colMap)
    }
    out[dbId] = bucket
  }
  return out
}

function isLegacyFlatTableColumnMap(v: unknown): boolean {
  if (typeof v !== 'object' || v === null || Array.isArray(v)) return false
  const vals = Object.values(v as Record<string, unknown>)
  if (vals.length === 0) return false
  return vals.every((x) => typeof x === 'number')
}

function parseRowColumnWidthsStorage(prefs: UserPrefs | null | undefined): {
  databases: Record<string, RowColumnWidthsByTable>
  legacyFlatByTable: RowColumnWidthsByTable
} {
  const raw = prefs?.[USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS]
  if (raw == null) {
    return { databases: {}, legacyFlatByTable: {} }
  }

  let parsed: unknown
  if (typeof raw === 'string') {
    const s = raw.trim()
    if (s.length === 0) return { databases: {}, legacyFlatByTable: {} }
    try {
      parsed = JSON.parse(s) as unknown
    } catch {
      return { databases: {}, legacyFlatByTable: {} }
    }
  } else if (typeof raw === 'object' && !Array.isArray(raw)) {
    parsed = raw
  } else {
    return { databases: {}, legacyFlatByTable: {} }
  }

  try {
    if (
      parsed === null ||
      typeof parsed !== 'object' ||
      Array.isArray(parsed)
    ) {
      return { databases: {}, legacyFlatByTable: {} }
    }
    const root = parsed as Record<string, unknown>
    const hasDatabasesWrapper =
      'databases' in root &&
      typeof root.databases === 'object' &&
      root.databases !== null &&
      !Array.isArray(root.databases)

    if (!hasDatabasesWrapper) {
      const legacyOnly: RowColumnWidthsByTable = {}
      for (const [tid, cols] of Object.entries(root)) {
        if (!tid) continue
        if (typeof cols !== 'object' || cols === null || Array.isArray(cols)) {
          continue
        }
        legacyOnly[tid] = parseInnerRowColumnWidthMap(cols)
      }
      return { databases: {}, legacyFlatByTable: legacyOnly }
    }

    const databases = parseRowColumnWidthsByDatabase(root.databases)
    const legacyFlatByTable: RowColumnWidthsByTable = {}
    for (const [k, v] of Object.entries(root)) {
      if (k === 'databases') continue
      if (!isLegacyFlatTableColumnMap(v)) continue
      legacyFlatByTable[k] = parseInnerRowColumnWidthMap(v)
    }
    return { databases, legacyFlatByTable }
  } catch {
    return { databases: {}, legacyFlatByTable: {} }
  }
}

function serializeDatabaseTableRowColumnWidths(
  databases: Record<string, RowColumnWidthsByTable>,
  legacyFlatByTable: RowColumnWidthsByTable,
): string | null {
  const dbIds = Object.keys(databases).filter(
    (id) => Object.keys(databases[id] ?? {}).length > 0,
  )
  const legacyIds = Object.keys(legacyFlatByTable).filter(
    (id) => Object.keys(legacyFlatByTable[id] ?? {}).length > 0,
  )
  if (dbIds.length === 0 && legacyIds.length === 0) return null

  if (dbIds.length === 0) {
    const flat: Record<string, unknown> = {}
    for (const id of legacyIds) flat[id] = legacyFlatByTable[id]
    return JSON.stringify(flat)
  }

  const payload: Record<string, unknown> = {
    databases: {} as Record<string, unknown>,
  }
  for (const id of dbIds) {
    ;(payload.databases as Record<string, unknown>)[id] = databases[id]
  }
  for (const id of legacyIds) {
    payload[id] = legacyFlatByTable[id]
  }
  return JSON.stringify(payload)
}

/** Widths for one table’s row grid (data columns), from account prefs. */
export function getDatabaseTableRowColumnWidthsFromPrefs(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
  tableId: string,
): Record<string, number> {
  const { databases, legacyFlatByTable } = parseRowColumnWidthsStorage(prefs)
  const bucket = databases[databaseId]
  if (bucket && Object.prototype.hasOwnProperty.call(bucket, tableId)) {
    return parseInnerRowColumnWidthMap(bucket[tableId])
  }
  const leg = legacyFlatByTable[tableId]
  return leg ? parseInnerRowColumnWidthMap(leg) : {}
}

/**
 * @deprecated Prefer `getDatabaseTableRowColumnWidthsFromPrefs(prefs, databaseId, tableId)`.
 * Flattened map for backwards compatibility: legacy top-level table keys plus every
 * `databases[*][tableKey]` entry. If the same `tableKey` appears in multiple databases,
 * the last one iterated wins.
 */
export function parseDatabaseTableRowColumnWidthsMap(
  prefs: UserPrefs | null | undefined,
): Record<string, Record<string, number>> {
  const { databases, legacyFlatByTable } = parseRowColumnWidthsStorage(prefs)
  const out: Record<string, Record<string, number>> = { ...legacyFlatByTable }
  for (const bucket of Object.values(databases)) {
    for (const [tableKey, cols] of Object.entries(bucket)) {
      out[tableKey] = { ...cols }
    }
  }
  return out
}

export function mergeDatabaseTableRowColumnWidthsTableIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  tableId: string,
  widths: Record<string, number>,
): UserPrefs {
  const { databases, legacyFlatByTable } = parseRowColumnWidthsStorage(prefs)
  const nextLegacy = { ...legacyFlatByTable }
  delete nextLegacy[tableId]
  delete nextLegacy[`${tableId}#columns`]
  delete nextLegacy[`${tableId}#indexes`]

  const nextDatabases = {
    ...databases,
    [databaseId]: {
      ...(databases[databaseId] || {}),
      [tableId]: widths,
    },
  }

  const encoded = serializeDatabaseTableRowColumnWidths(
    nextDatabases,
    nextLegacy,
  )
  const next = { ...prefs }
  if (!encoded) {
    delete next[USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS]
    return next
  }
  next[USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS] = encoded
  return next
}

export function deleteDatabaseTableRowColumnWidthsFromPrefs(
  prefs: UserPrefs,
  databaseId: string,
  tableId: string,
): UserPrefs {
  const { databases, legacyFlatByTable } = parseRowColumnWidthsStorage(prefs)
  const nextLegacy = { ...legacyFlatByTable }
  delete nextLegacy[tableId]
  delete nextLegacy[`${tableId}#columns`]
  delete nextLegacy[`${tableId}#indexes`]

  const nextDatabases = { ...databases }
  const bucket = { ...(nextDatabases[databaseId] || {}) }
  delete bucket[tableId]
  delete bucket[`${tableId}#columns`]
  delete bucket[`${tableId}#indexes`]
  if (Object.keys(bucket).length === 0) delete nextDatabases[databaseId]
  else nextDatabases[databaseId] = bucket

  const encoded = serializeDatabaseTableRowColumnWidths(
    nextDatabases,
    nextLegacy,
  )
  const next = { ...prefs }
  if (!encoded) {
    delete next[USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS]
    return next
  }
  next[USER_PREFS_KEY_DATABASE_TABLE_ROW_COLUMN_WIDTHS] = encoded
  return next
}

// ---------------------------------------------------------------------------
// Databases: Tables DB - which row attributes to fetch & column order (account)
// ---------------------------------------------------------------------------

/**
 * Per-table preference key: `console.tablesDb.rowsListColumns.<databaseId>.<tableId>`
 *
 * Value: JSON string `string[]` - ordered column keys: optional system fields
 * (`$sequence`, `$id`, `$createdAt`, `$updatedAt`) plus attribute keys.
 * Prefix `!` on a key means it is hidden but keeps its position in the list.
 * Absent or invalid: fetch and show all columns (default).
 */
export const USER_PREFS_KEY_TABLESDB_ROWS_LIST_COLUMNS_PREFIX =
  'console.tablesDb.rowsListColumns'

export const MAX_TABLESDB_ROWS_LIST_COLUMN_KEYS = 200

/** System keys allowed in Tables DB row grid column prefs (order + visibility). */
const TABLESDB_ROWS_LIST_COLUMN_ALLOWED_SYSTEM_KEYS = new Set([
  '$sequence',
  '$id',
  '$createdAt',
  '$updatedAt',
])

export function getTablesDbRowsListColumnsPrefsKey(
  databaseId: string,
  tableId: string,
): string {
  return `${USER_PREFS_KEY_TABLESDB_ROWS_LIST_COLUMNS_PREFIX}.${databaseId}.${tableId}`
}

/** Stored prefix for a column that is in order but hidden in the grid. */
export const TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX = '!'

export function parseStoredTablesDbRowsListColumnEntry(
  item: string,
): { key: string; hidden: boolean } | null {
  const trimmed = item.trim()
  if (!trimmed) return null
  const hidden = trimmed.startsWith(TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX)
  const key = hidden
    ? trimmed.slice(TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX.length).trim()
    : trimmed
  if (!key) return null
  if (key.startsWith('$')) {
    if (!TABLESDB_ROWS_LIST_COLUMN_ALLOWED_SYSTEM_KEYS.has(key)) return null
  }
  return { key, hidden }
}

/** Raw stored column list (may include `!` hidden markers), or `null` when unset. */
export function readTablesDbRowsListColumnsRawFromPrefs(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
  tableId: string,
): string[] | null {
  if (!prefs || !databaseId || !tableId) return null
  const key = getTablesDbRowsListColumnsPrefsKey(databaseId, tableId)
  const raw = prefs[key]
  if (raw == null) return null
  let parsed: unknown
  if (typeof raw === 'string') {
    const s = raw.trim()
    if (!s) return null
    try {
      parsed = JSON.parse(s) as unknown
    } catch {
      return null
    }
  } else if (Array.isArray(raw)) {
    parsed = raw
  } else {
    return null
  }
  if (!Array.isArray(parsed) || parsed.length === 0) return null
  const out: string[] = []
  for (const item of parsed) {
    if (typeof item !== 'string') continue
    const entry = parseStoredTablesDbRowsListColumnEntry(item)
    if (!entry) continue
    const stored = entry.hidden
      ? `${TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX}${entry.key}`
      : entry.key
    if (out.includes(stored)) continue
    out.push(stored)
    if (out.length >= MAX_TABLESDB_ROWS_LIST_COLUMN_KEYS) break
  }
  return out.length > 0 ? out : null
}

/**
 * Visible column keys in display order (for grid + Query.select), or `null` when unset.
 */
export function parseTablesDbRowsListColumnsFromPrefs(
  prefs: UserPrefs | null | undefined,
  databaseId: string,
  tableId: string,
): string[] | null {
  const stored = readTablesDbRowsListColumnsRawFromPrefs(
    prefs,
    databaseId,
    tableId,
  )
  if (!stored) return null
  const out: string[] = []
  for (const item of stored) {
    const entry = parseStoredTablesDbRowsListColumnEntry(item)
    if (!entry || entry.hidden) continue
    if (out.includes(entry.key)) continue
    out.push(entry.key)
    if (out.length >= MAX_TABLESDB_ROWS_LIST_COLUMN_KEYS) break
  }
  return out.length > 0 ? out : null
}

export type TablesDbRowsListColumnLayout = {
  orderedKeys: string[]
  hiddenKeys: Set<string>
}

/**
 * Column order for the columns popover (visible + hidden interleaved).
 * Legacy prefs without `!` markers treat omitted keys as hidden and append them after saved keys.
 */
export function parseTablesDbRowsListColumnLayout(
  stored: string[] | null,
  allKeys: string[],
): TablesDbRowsListColumnLayout {
  const allKeysSet = new Set(allKeys)
  if (!allKeys.length) {
    return { orderedKeys: [], hiddenKeys: new Set() }
  }
  if (!stored?.length) {
    return { orderedKeys: [...allKeys], hiddenKeys: new Set() }
  }

  const hasHiddenMarkers = stored.some((item) =>
    item.trim().startsWith(TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX),
  )

  if (hasHiddenMarkers) {
    const orderedKeys: string[] = []
    const hiddenKeys = new Set<string>()
    const seen = new Set<string>()
    for (const item of stored) {
      const entry = parseStoredTablesDbRowsListColumnEntry(item)
      if (!entry || !allKeysSet.has(entry.key) || seen.has(entry.key)) continue
      seen.add(entry.key)
      orderedKeys.push(entry.key)
      if (entry.hidden) hiddenKeys.add(entry.key)
    }
    for (const k of allKeys) {
      if (!seen.has(k)) orderedKeys.push(k)
    }
    return { orderedKeys, hiddenKeys }
  }

  const seen = new Set<string>()
  const visibleOrdered: string[] = []
  for (const item of stored) {
    const entry = parseStoredTablesDbRowsListColumnEntry(item)
    if (
      !entry ||
      entry.hidden ||
      !allKeysSet.has(entry.key) ||
      seen.has(entry.key)
    )
      continue
    seen.add(entry.key)
    visibleOrdered.push(entry.key)
  }

  if (visibleOrdered.length === 0) {
    return { orderedKeys: [...allKeys], hiddenKeys: new Set() }
  }

  const hiddenKeys = new Set(allKeys.filter((k) => !seen.has(k)))
  const orderedKeys = [
    ...visibleOrdered,
    ...allKeys.filter((k) => hiddenKeys.has(k)),
  ]
  return { orderedKeys, hiddenKeys }
}

export function serializeTablesDbRowsListColumnLayout(
  orderedKeys: string[],
  hiddenKeys: ReadonlySet<string>,
): string[] {
  return orderedKeys.map((key) =>
    hiddenKeys.has(key)
      ? `${TABLESDB_ROWS_LIST_COLUMN_HIDDEN_PREFIX}${key}`
      : key,
  )
}

export function isDefaultTablesDbRowsListColumnLayout(
  orderedKeys: string[],
  hiddenKeys: ReadonlySet<string>,
  allKeys: string[],
): boolean {
  if (hiddenKeys.size > 0) return false
  if (orderedKeys.length !== allKeys.length) return false
  return orderedKeys.every((k, i) => k === allKeys[i])
}

export function mergeTablesDbRowsListColumnsIntoPrefs(
  prefs: UserPrefs,
  databaseId: string,
  tableId: string,
  keys: string[] | null,
): UserPrefs {
  const next = { ...prefs }
  const prefKey = getTablesDbRowsListColumnsPrefsKey(databaseId, tableId)
  if (!keys?.length) {
    delete next[prefKey]
    return next
  }
  next[prefKey] = JSON.stringify(
    keys.slice(0, MAX_TABLESDB_ROWS_LIST_COLUMN_KEYS),
  )
  return next
}

export function deleteTablesDbRowsListColumnsFromPrefs(
  prefs: UserPrefs,
  databaseId: string,
  tableId: string,
): UserPrefs {
  return mergeTablesDbRowsListColumnsIntoPrefs(prefs, databaseId, tableId, null)
}

// ---------------------------------------------------------------------------
// Navigation sidebar collapsed (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.sidebarCollapsed` - sidebar collapsed when true / `"true"`. */
export const USER_PREFS_KEY_SIDEBAR_COLLAPSED = 'console.sidebarCollapsed'

/** @deprecated Migrated to `console.sidebarCollapsed`. */
const LEGACY_USER_PREFS_KEY_SIDEBAR_COLLAPSED = 'sidebarCollapsed'

export function parseSidebarCollapsed(
  prefs: UserPrefs | null | undefined,
): boolean {
  const current = parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_SIDEBAR_COLLAPSED])
  if (current !== null) return current
  return parseBooleanAccountPref(prefs?.[LEGACY_USER_PREFS_KEY_SIDEBAR_COLLAPSED]) ?? false
}

export function mergeSidebarCollapsedIntoPrefs(
  prefs: UserPrefs,
  collapsed: boolean,
): UserPrefs {
  const next = {
    ...prefs,
    [USER_PREFS_KEY_SIDEBAR_COLLAPSED]: collapsed,
  }
  delete next[LEGACY_USER_PREFS_KEY_SIDEBAR_COLLAPSED]
  return next
}

// ---------------------------------------------------------------------------
// Connect project dialog tab (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.connect.tab` - last selected Connect modal tab. */
export const USER_PREFS_KEY_CONNECT_PROJECT_TAB = 'console.connect.tab'

const CONNECT_PROJECT_TAB_PREF_VALUES = [
  'mcp',
  'app',
  'cli',
  'skills',
  'terraform',
  's3',
] as const

export type ConnectProjectTabPref =
  (typeof CONNECT_PROJECT_TAB_PREF_VALUES)[number]

const CONNECT_PROJECT_TAB_PREF_SET = new Set<string>(
  CONNECT_PROJECT_TAB_PREF_VALUES,
)

export const DEFAULT_CONNECT_PROJECT_TAB_PREF: ConnectProjectTabPref = 'mcp'

export function parseConnectProjectTab(
  prefs: UserPrefs | null | undefined,
): ConnectProjectTabPref {
  const raw = prefs?.[USER_PREFS_KEY_CONNECT_PROJECT_TAB]
  if (typeof raw === 'string' && CONNECT_PROJECT_TAB_PREF_SET.has(raw)) {
    return raw as ConnectProjectTabPref
  }
  return DEFAULT_CONNECT_PROJECT_TAB_PREF
}

export function mergeConnectProjectTabIntoPrefs(
  prefs: UserPrefs,
  tab: ConnectProjectTabPref,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_CONNECT_PROJECT_TAB]: tab,
  }
}

// ---------------------------------------------------------------------------
// AI assistant panel (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.aiChat.panelOpen` - panel open when true / `"true"`. */
export const USER_PREFS_KEY_AI_CHAT_PANEL_OPEN = 'console.aiChat.panelOpen'

/** Full key: `console.aiChat.expanded` - fullscreen chat when true / `"true"`. */
export const USER_PREFS_KEY_AI_CHAT_EXPANDED = 'console.aiChat.expanded'

/**
 * Full key: `console.aiChat.activeConversationId` - last viewed assistant
 * conversation id (empty string clears).
 */
export const USER_PREFS_KEY_AI_CHAT_ACTIVE_CONVERSATION_ID =
  'console.aiChat.activeConversationId'

/**
 * Full key: `console.aiChat.pinnedConversationIds` - pinned assistant
 * conversation ids as a JSON string array. Array order is the pin sort order.
 */
export const USER_PREFS_KEY_AI_CHAT_PINNED_CONVERSATION_IDS =
  'console.aiChat.pinnedConversationIds'

/**
 * Full key: `console.aiChat.conversationsWidthPx` - conversations sidebar width
 * in fullscreen chat (string number, px).
 */
export const USER_PREFS_KEY_AI_CHAT_CONVERSATIONS_WIDTH_PX =
  'console.aiChat.conversationsWidthPx'

/** Full key: `console.aiChat.panelWidthPx` - panel width in pixels (string number). */
export const USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX =
  'console.aiChat.panelWidthPx'

export const AI_CHAT_PANEL_MIN_WIDTH_PX = 320
export const AI_CHAT_PANEL_MAX_WIDTH_PX = 600
export const AI_CHAT_PANEL_DEFAULT_WIDTH_PX = 400

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_OPEN = 'ai-chat-panel-open'

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_WIDTH = 'ai-chat-panel-width'

function parseBooleanAccountPref(raw: unknown): boolean | null {
  if (raw === true || raw === 'true') return true
  if (raw === false || raw === 'false') return false
  return null
}

export function parseAIChatPanelOpen(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_AI_CHAT_PANEL_OPEN]) ?? false
  )
}

export function hasAIChatPanelOpenPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_AI_CHAT_PANEL_OPEN] !== undefined
}

export function parseAIChatPanelWidthPx(
  prefs: UserPrefs | null | undefined,
): number {
  const raw = prefs?.[USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (
    Number.isFinite(n) &&
    n >= AI_CHAT_PANEL_MIN_WIDTH_PX &&
    n <= AI_CHAT_PANEL_MAX_WIDTH_PX
  ) {
    return n
  }
  return AI_CHAT_PANEL_DEFAULT_WIDTH_PX
}

export function hasAIChatPanelWidthPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX] !== undefined
}

export function mergeAIChatPanelOpenIntoPrefs(
  prefs: UserPrefs,
  open: boolean,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_PANEL_OPEN]: open,
  }
}

export function parseAIChatExpanded(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_AI_CHAT_EXPANDED]) ?? false
  )
}

export function mergeAIChatExpandedIntoPrefs(
  prefs: UserPrefs,
  expanded: boolean,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_EXPANDED]: expanded,
  }
}

export function parseAIChatActiveConversationId(
  prefs: UserPrefs | null | undefined,
): string | null {
  const raw = prefs?.[USER_PREFS_KEY_AI_CHAT_ACTIVE_CONVERSATION_ID]
  if (typeof raw !== 'string') return null
  const trimmed = raw.trim()
  return trimmed.length > 0 ? trimmed : null
}

export function mergeAIChatActiveConversationIdIntoPrefs(
  prefs: UserPrefs,
  conversationId: string | null,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_ACTIVE_CONVERSATION_ID]: conversationId?.trim() || '',
  }
}

const EMPTY_AI_CHAT_PINNED_CONVERSATION_IDS: string[] = []

export function parseAIChatPinnedConversationIds(
  prefs: UserPrefs | null | undefined,
): string[] {
  const raw = prefs?.[USER_PREFS_KEY_AI_CHAT_PINNED_CONVERSATION_IDS]
  if (typeof raw !== 'string' || !raw.trim()) {
    return EMPTY_AI_CHAT_PINNED_CONVERSATION_IDS
  }
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return EMPTY_AI_CHAT_PINNED_CONVERSATION_IDS
    const seen = new Set<string>()
    const ids: string[] = []
    for (const entry of parsed) {
      if (typeof entry !== 'string') continue
      const id = entry.trim()
      if (!id || seen.has(id)) continue
      seen.add(id)
      ids.push(id)
    }
    return ids.length > 0 ? ids : EMPTY_AI_CHAT_PINNED_CONVERSATION_IDS
  } catch {
    return EMPTY_AI_CHAT_PINNED_CONVERSATION_IDS
  }
}

export function mergeAIChatPinnedConversationIdsIntoPrefs(
  prefs: UserPrefs,
  conversationIds: string[],
): UserPrefs {
  const seen = new Set<string>()
  const ids: string[] = []
  for (const entry of conversationIds) {
    const id = entry.trim()
    if (!id || seen.has(id)) continue
    seen.add(id)
    ids.push(id)
  }
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_PINNED_CONVERSATION_IDS]: JSON.stringify(ids),
  }
}

export function parseAIChatConversationsWidthPx(
  prefs: UserPrefs | null | undefined,
): number {
  const raw = prefs?.[USER_PREFS_KEY_AI_CHAT_CONVERSATIONS_WIDTH_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (Number.isFinite(n)) {
    return clampAIChatConversationsSidebarWidthPx(n)
  }
  return AI_CHAT_CONVERSATIONS_SIDEBAR_DEFAULT_WIDTH_PX
}

export function mergeAIChatConversationsWidthPxIntoPrefs(
  prefs: UserPrefs,
  widthPx: number,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_CONVERSATIONS_WIDTH_PX]: String(
      clampAIChatConversationsSidebarWidthPx(widthPx),
    ),
  }
}

export function mergeAIChatPanelWidthPxIntoPrefs(
  prefs: UserPrefs,
  widthPx: number,
): UserPrefs {
  const clamped = Math.min(
    AI_CHAT_PANEL_MAX_WIDTH_PX,
    Math.max(AI_CHAT_PANEL_MIN_WIDTH_PX, Math.round(widthPx)),
  )
  return {
    ...prefs,
    [USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX]: String(clamped),
  }
}

// ---------------------------------------------------------------------------
// Console right pane width (account prefs) - shared by docs, assistant, etc.
// ---------------------------------------------------------------------------

/** Full key: `console.rightPane.widthPx` - shared right pane width in pixels. */
export const USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX = 'console.rightPane.widthPx'

export function parseRightPaneWidthPx(
  prefs: UserPrefs | null | undefined,
): number {
  const raw = prefs?.[USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (
    Number.isFinite(n) &&
    n >= RIGHT_PANE_MIN_WIDTH_PX &&
    n <= RIGHT_PANE_MAX_WIDTH_PX
  ) {
    return n
  }

  if (hasAIChatPanelWidthPref(prefs)) {
    return clampRightPaneWidthPx(parseAIChatPanelWidthPx(prefs))
  }

  return RIGHT_PANE_DEFAULT_WIDTH_PX
}

export function hasRightPaneWidthPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX] !== undefined
}

export function mergeRightPaneWidthPxIntoPrefs(
  prefs: UserPrefs,
  widthPx: number,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_RIGHT_PANE_WIDTH_PX]: String(
      clampRightPaneWidthPx(widthPx),
    ),
  }
}

// ---------------------------------------------------------------------------
// Auth password strength compliance panel (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.auth.passwordStrengthComplianceOpen` - compliance section expanded when true. */
export const USER_PREFS_KEY_AUTH_PASSWORD_STRENGTH_COMPLIANCE_OPEN =
  'console.auth.passwordStrengthComplianceOpen'

export function parseAuthPasswordStrengthComplianceOpen(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(
      prefs?.[USER_PREFS_KEY_AUTH_PASSWORD_STRENGTH_COMPLIANCE_OPEN],
    ) ?? false
  )
}

export function mergeAuthPasswordStrengthComplianceOpenIntoPrefs(
  prefs: UserPrefs,
  open: boolean,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_AUTH_PASSWORD_STRENGTH_COMPLIANCE_OPEN]: open,
  }
}

export function readLegacyAIChatPanelOpenFromLocalStorage(): boolean | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_OPEN)
    if (raw === 'true') return true
    if (raw === 'false') return false
    return null
  } catch {
    return null
  }
}

export function readLegacyAIChatPanelWidthFromLocalStorage(): number | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_WIDTH)
    if (!raw) return null
    const n = parseInt(raw, 10)
    if (
      Number.isFinite(n) &&
      n >= AI_CHAT_PANEL_MIN_WIDTH_PX &&
      n <= AI_CHAT_PANEL_MAX_WIDTH_PX
    ) {
      return n
    }
    return null
  } catch {
    return null
  }
}

export function clearLegacyAIChatLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.removeItem(LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_OPEN)
    localStorage.removeItem(LEGACY_LOCAL_STORAGE_AI_CHAT_PANEL_WIDTH)
  } catch {
    /* private mode */
  }
}

// ---------------------------------------------------------------------------
// CLI shell panel (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.cliShell.open` - terminal expanded when true / `"true"`. */
export const USER_PREFS_KEY_CLI_SHELL_OPEN = 'console.cliShell.open'

/** Full key: `console.cliShell.heightPx` - terminal height in pixels (string number). */
export const USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX = 'console.cliShell.heightPx'

/** Full key: `console.cliShell.sessionsSidebarWidthPx` - sessions list width in px. */
export const USER_PREFS_KEY_CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PX =
  'console.cliShell.sessionsSidebarWidthPx'

/** Prefix: `console.cliShell.history.<projectId>` - JSON string[] of recent commands. */
export const USER_PREFS_KEY_CLI_SHELL_HISTORY_PREFIX =
  'console.cliShell.history'

/** Prefix: `console.cliShell.sessions.<projectId>` - JSON session layout. */
export const USER_PREFS_KEY_CLI_SHELL_SESSIONS_PREFIX =
  'console.cliShell.sessions'

export const MAX_CLI_SHELL_HISTORY_ENTRIES = 200
export const MAX_CLI_SHELL_SESSIONS = 10
export const MAX_CLI_SHELL_SESSION_NAME_LENGTH = 48

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_CLI_SHELL_HEIGHT = 'console.cliShellHeight'

export function clampCliShellHeightPx(
  px: number,
  viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 800,
): number {
  const maxHeight = Math.floor(viewportHeight * CLI_SHELL_MAX_HEIGHT_RATIO)
  return Math.min(
    Math.max(Math.round(px), CLI_SHELL_MIN_HEIGHT_PX),
    Math.max(maxHeight, CLI_SHELL_MIN_HEIGHT_PX),
  )
}

export function parseCliShellOpen(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_CLI_SHELL_OPEN]) ?? false
  )
}

export function hasCliShellOpenPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_CLI_SHELL_OPEN] !== undefined
}

export function parseCliShellHeightPx(
  prefs: UserPrefs | null | undefined,
): number {
  const raw = prefs?.[USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (Number.isFinite(n) && n >= CLI_SHELL_MIN_HEIGHT_PX) {
    return clampCliShellHeightPx(n)
  }
  return CLI_SHELL_DEFAULT_HEIGHT_PX
}

export function hasCliShellHeightPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX] !== undefined
}

export function mergeCliShellOpenIntoPrefs(
  prefs: UserPrefs,
  open: boolean,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_CLI_SHELL_OPEN]: open,
  }
}

export function mergeCliShellHeightPxIntoPrefs(
  prefs: UserPrefs,
  heightPx: number,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_CLI_SHELL_HEIGHT_PX]: String(
      clampCliShellHeightPx(heightPx),
    ),
  }
}

export function parseCliShellSessionsSidebarWidthPx(
  prefs: UserPrefs | null | undefined,
): number {
  const raw = prefs?.[USER_PREFS_KEY_CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PX]
  const n =
    typeof raw === 'number'
      ? raw
      : typeof raw === 'string'
        ? parseInt(raw, 10)
        : NaN
  if (Number.isFinite(n)) {
    return clampCliShellSessionsSidebarWidthPx(n)
  }
  return CLI_SHELL_SESSIONS_SIDEBAR_DEFAULT_WIDTH_PX
}

export function mergeCliShellSessionsSidebarWidthPxIntoPrefs(
  prefs: UserPrefs,
  widthPx: number,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PX]: String(
      clampCliShellSessionsSidebarWidthPx(widthPx),
    ),
  }
}

export function readLegacyCliShellHeightFromLocalStorage(): number | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(LEGACY_LOCAL_STORAGE_CLI_SHELL_HEIGHT)
    if (!raw) return null
    const n = parseInt(raw, 10)
    if (Number.isFinite(n) && n >= CLI_SHELL_MIN_HEIGHT_PX) {
      return clampCliShellHeightPx(n)
    }
    return null
  } catch {
    return null
  }
}

export function getCliShellHistoryKey(projectId: string): string {
  return `${USER_PREFS_KEY_CLI_SHELL_HISTORY_PREFIX}.${projectId}`
}

export function getCliShellSessionsKey(projectId: string): string {
  return `${USER_PREFS_KEY_CLI_SHELL_SESSIONS_PREFIX}.${projectId}`
}

export type PersistedCliShellSession = {
  id: string
  name: string
  parentSessionId?: string | null
}

export type PersistedCliShellSessionsState = {
  sessions: PersistedCliShellSession[]
  activeSessionId: string
  splitPaneSessionIds?: string[]
}

function isPersistableCliShellHistoryCommand(command: string): boolean {
  const trimmed = command.trim()
  if (!trimmed) return false
  if (!/[a-zA-Z0-9]/.test(trimmed)) return false
  return /^[\x20-\x7e]+$/.test(trimmed)
}

export function parseCliShellHistory(
  prefs: UserPrefs | null | undefined,
  projectId: string,
): string[] {
  const key = getCliShellHistoryKey(projectId)
  if (!prefs || typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is string =>
          typeof item === 'string' && isPersistableCliShellHistoryCommand(item),
      )
      .slice(-MAX_CLI_SHELL_HISTORY_ENTRIES)
  } catch {
    return []
  }
}

export function mergeCliShellHistoryIntoPrefs(
  prefs: UserPrefs,
  projectId: string,
  history: string[],
): UserPrefs {
  const key = getCliShellHistoryKey(projectId)
  const trimmed = history.slice(-MAX_CLI_SHELL_HISTORY_ENTRIES)
  return {
    ...prefs,
    [key]: JSON.stringify(trimmed),
  }
}

export function parseCliShellSessions(
  prefs: UserPrefs | null | undefined,
  projectId: string,
): PersistedCliShellSessionsState | null {
  const key = getCliShellSessionsKey(projectId)
  if (!prefs || typeof prefs[key] !== 'string') return null
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!raw || typeof raw !== 'object') return null
    const sessions = Array.isArray(
      (raw as PersistedCliShellSessionsState).sessions,
    )
      ? (raw as PersistedCliShellSessionsState).sessions
          .filter(
            (item): item is PersistedCliShellSession =>
              item != null &&
              typeof item === 'object' &&
              typeof item.id === 'string' &&
              typeof item.name === 'string',
          )
          .map((item) => ({
            id: item.id,
            name: String(item.name).slice(0, MAX_CLI_SHELL_SESSION_NAME_LENGTH),
            parentSessionId:
              typeof item.parentSessionId === 'string'
                ? item.parentSessionId
                : null,
          }))
          .slice(0, MAX_CLI_SHELL_SESSIONS)
      : []
    const activeSessionId =
      typeof (raw as PersistedCliShellSessionsState).activeSessionId ===
      'string'
        ? (raw as PersistedCliShellSessionsState).activeSessionId
        : ''
    const splitPaneSessionIds = Array.isArray(
      (raw as PersistedCliShellSessionsState).splitPaneSessionIds,
    )
      ? (raw as PersistedCliShellSessionsState)
          .splitPaneSessionIds!.filter(
            (id): id is string =>
              typeof id === 'string' &&
              sessions.some((session) => session.id === id),
          )
          .slice(0, MAX_CLI_SHELL_SESSIONS)
      : []
    if (sessions.length === 0) return null
    const activeExists = sessions.some(
      (session) => session.id === activeSessionId,
    )
    return {
      sessions,
      activeSessionId: activeExists ? activeSessionId : sessions[0].id,
      splitPaneSessionIds:
        splitPaneSessionIds.length > 1 ? splitPaneSessionIds : undefined,
    }
  } catch {
    return null
  }
}

/**
 * Canonical form written to account prefs. Use for equality checks so in-memory
 * state (e.g. omitted `parentSessionId`) matches the stored JSON shape.
 */
export function normalizeCliShellSessionsState(
  state: PersistedCliShellSessionsState,
): PersistedCliShellSessionsState {
  const sessions = state.sessions
    .slice(0, MAX_CLI_SHELL_SESSIONS)
    .map((session) => ({
      id: session.id,
      name: String(session.name).slice(0, MAX_CLI_SHELL_SESSION_NAME_LENGTH),
      parentSessionId: session.parentSessionId ?? null,
    }))
  const activeExists = sessions.some(
    (session) => session.id === state.activeSessionId,
  )
  const splitPaneSessionIds = Array.isArray(state.splitPaneSessionIds)
    ? state.splitPaneSessionIds
        .filter((id) => sessions.some((session) => session.id === id))
        .slice(0, MAX_CLI_SHELL_SESSIONS)
    : []
  return {
    sessions,
    activeSessionId: activeExists
      ? state.activeSessionId
      : (sessions[0]?.id ?? ''),
    splitPaneSessionIds:
      splitPaneSessionIds.length > 1 ? splitPaneSessionIds : undefined,
  }
}

export function serializeCliShellSessionsState(
  state: PersistedCliShellSessionsState,
): string {
  return JSON.stringify(normalizeCliShellSessionsState(state))
}

export function mergeCliShellSessionsIntoPrefs(
  prefs: UserPrefs,
  projectId: string,
  state: PersistedCliShellSessionsState,
): UserPrefs {
  const key = getCliShellSessionsKey(projectId)
  return {
    ...prefs,
    [key]: serializeCliShellSessionsState(state),
  }
}

export function clearLegacyCliShellHeightLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.removeItem(LEGACY_LOCAL_STORAGE_CLI_SHELL_HEIGHT)
  } catch {
    /* private mode */
  }
}

// ---------------------------------------------------------------------------
// Diagram generator: saved generations (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.diagramGenerator.generations` - JSON SavedDiagramGeneration[] */
export {
  USER_PREFS_KEY_DIAGRAM_GENERATIONS,
  MAX_SAVED_DIAGRAM_GENERATIONS,
  MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH,
  type SavedDiagramGeneration,
} from '@/lib/diagram-generator/generation-prefs'

// ---------------------------------------------------------------------------
// API Explorer panel layouts (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.coverGenerator.columnsLayout` - JSON `[templates%, canvas%, properties%]`. */
export const USER_PREFS_KEY_COVER_GENERATOR_COLUMNS_LAYOUT =
  'console.coverGenerator.columnsLayout'

/** Full key: `console.apiExplorer.columnsLayout` - JSON `[services%, methods%, request%]`. */
export const USER_PREFS_KEY_API_EXPLORER_COLUMNS_LAYOUT =
  'console.apiExplorer.columnsLayout'

/** Full key: `console.apiExplorer.responseSplitLayout` - JSON `[request%, response%]`. */
export const USER_PREFS_KEY_API_EXPLORER_RESPONSE_SPLIT_LAYOUT =
  'console.apiExplorer.responseSplitLayout'

/** Full key: `console.diagramGenerator.propertiesSplitLayout` - JSON `[properties%, layers%]`. */
export const USER_PREFS_KEY_DIAGRAM_GENERATOR_PROPERTIES_SPLIT_LAYOUT =
  'console.diagramGenerator.propertiesSplitLayout'

/** Full key: `console.generator.panelVisibility` - JSON `{ left, right }`. */
export const USER_PREFS_KEY_GENERATOR_PANEL_VISIBILITY =
  'console.generator.panelVisibility'

export function parseGeneratorPanelVisibility(
  prefs: UserPrefs | null | undefined,
): GeneratorPanelVisibility | null {
  const raw = prefs?.[USER_PREFS_KEY_GENERATOR_PANEL_VISIBILITY]
  if (typeof raw !== 'string' || raw.length === 0) return null

  try {
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object') return null
    return normalizeGeneratorPanelVisibility(parsed as GeneratorPanelVisibility)
  } catch {
    return null
  }
}

export function mergeGeneratorPanelVisibilityIntoPrefs(
  prefs: UserPrefs,
  visibility: GeneratorPanelVisibility,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_GENERATOR_PANEL_VISIBILITY]: JSON.stringify(
      normalizeGeneratorPanelVisibility(visibility),
    ),
  }
}

function parsePanelLayoutPref(
  prefs: UserPrefs | null | undefined,
  key: string,
  expectedLength: number,
): number[] | null {
  const raw = prefs?.[key]
  if (typeof raw !== 'string' || raw.length === 0) return null
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed) || parsed.length !== expectedLength) return null
    const sizes = parsed.map((value) =>
      typeof value === 'number' ? value : Number(value),
    )
    if (sizes.some((value) => !Number.isFinite(value))) return null
    return sizes
  } catch {
    return null
  }
}

export function parseCoverGeneratorColumnsLayout(
  prefs: UserPrefs | null | undefined,
): number[] | null {
  return parsePanelLayoutPref(
    prefs,
    USER_PREFS_KEY_COVER_GENERATOR_COLUMNS_LAYOUT,
    3,
  )
}

export function mergeCoverGeneratorColumnsLayoutIntoPrefs(
  prefs: UserPrefs,
  layout: number[],
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_COVER_GENERATOR_COLUMNS_LAYOUT]: JSON.stringify(layout),
  }
}

export function parseApiExplorerColumnsLayout(
  prefs: UserPrefs | null | undefined,
): number[] | null {
  return parsePanelLayoutPref(
    prefs,
    USER_PREFS_KEY_API_EXPLORER_COLUMNS_LAYOUT,
    3,
  )
}

export function parseApiExplorerResponseSplitLayout(
  prefs: UserPrefs | null | undefined,
): number[] | null {
  return parsePanelLayoutPref(
    prefs,
    USER_PREFS_KEY_API_EXPLORER_RESPONSE_SPLIT_LAYOUT,
    2,
  )
}

export function mergeApiExplorerColumnsLayoutIntoPrefs(
  prefs: UserPrefs,
  layout: number[],
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_API_EXPLORER_COLUMNS_LAYOUT]: JSON.stringify(layout),
  }
}

export function mergeApiExplorerResponseSplitLayoutIntoPrefs(
  prefs: UserPrefs,
  layout: number[],
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_API_EXPLORER_RESPONSE_SPLIT_LAYOUT]: JSON.stringify(layout),
  }
}

export function parseDiagramGeneratorPropertiesSplitLayout(
  prefs: UserPrefs | null | undefined,
): number[] | null {
  return parsePanelLayoutPref(
    prefs,
    USER_PREFS_KEY_DIAGRAM_GENERATOR_PROPERTIES_SPLIT_LAYOUT,
    2,
  )
}

export function mergeDiagramGeneratorPropertiesSplitLayoutIntoPrefs(
  prefs: UserPrefs,
  layout: number[],
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_DIAGRAM_GENERATOR_PROPERTIES_SPLIT_LAYOUT]:
      JSON.stringify(layout),
  }
}

/** Full key: `console.apiExplorer.expandedProductGroup` - id of the open services product group. */
export const USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP =
  'console.apiExplorer.expandedProductGroup'

export function parseApiExplorerExpandedProductGroup(
  prefs: UserPrefs | null | undefined,
): string | null {
  const raw = prefs?.[USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP]
  return typeof raw === 'string' && raw.length > 0 ? raw : null
}

export function mergeApiExplorerExpandedProductGroupIntoPrefs(
  prefs: UserPrefs,
  groupId: string | null | undefined,
): UserPrefs {
  if (!groupId) {
    const next = { ...prefs }
    delete next[USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP]
    return next
  }
  return {
    ...prefs,
    [USER_PREFS_KEY_API_EXPLORER_EXPANDED_PRODUCT_GROUP]: groupId,
  }
}

// ---------------------------------------------------------------------------
// API reference explorer UI (account prefs + localStorage for guests)
// ---------------------------------------------------------------------------

/** Full key: `console.apiReference.ui` - JSON UI state for docs API reference. */
export { USER_PREFS_KEY_API_REFERENCE_UI } from '@/lib/docs/references/api-reference-ui-prefs'

// ---------------------------------------------------------------------------
// Build completion browser notifications (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.buildNotifications.optedOut` - user dismissed the enable prompt. */
export const USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT =
  'console.buildNotifications.optedOut'

// ---------------------------------------------------------------------------
// Community support prompt (account prefs)
// ---------------------------------------------------------------------------

/**
 * Full key: `console.communitySupport` - JSON state for the skippable
 * community-support wizard (unique active days, show count, last shown, action).
 */
export const USER_PREFS_KEY_COMMUNITY_SUPPORT = 'console.communitySupport'

export type CommunitySupportPrefs = {
  uniqueDayCount: number
  lastActiveDay: string | null
  /** How many times the wizard was presented to this user. */
  shownCount: number
  lastShownAt: string | null
  actionTakenAt: string | null
  actionId: string | null
}

export const EMPTY_COMMUNITY_SUPPORT_PREFS: CommunitySupportPrefs = {
  uniqueDayCount: 0,
  lastActiveDay: null,
  shownCount: 0,
  lastShownAt: null,
  actionTakenAt: null,
  actionId: null,
}

function parseNonNegativeInt(value: unknown): number {
  return typeof value === 'number' &&
    Number.isFinite(value) &&
    value >= 0
    ? Math.floor(value)
    : 0
}

export function parseCommunitySupportPrefs(
  prefs: UserPrefs | null | undefined,
): CommunitySupportPrefs {
  const raw = prefs?.[USER_PREFS_KEY_COMMUNITY_SUPPORT]
  if (typeof raw !== 'string' || !raw.trim()) {
    return { ...EMPTY_COMMUNITY_SUPPORT_PREFS }
  }
  try {
    const parsed = JSON.parse(raw) as Partial<CommunitySupportPrefs>
    return {
      uniqueDayCount: parseNonNegativeInt(parsed.uniqueDayCount),
      lastActiveDay:
        typeof parsed.lastActiveDay === 'string' ? parsed.lastActiveDay : null,
      shownCount: parseNonNegativeInt(parsed.shownCount),
      lastShownAt:
        typeof parsed.lastShownAt === 'string' ? parsed.lastShownAt : null,
      actionTakenAt:
        typeof parsed.actionTakenAt === 'string' ? parsed.actionTakenAt : null,
      actionId: typeof parsed.actionId === 'string' ? parsed.actionId : null,
    }
  } catch {
    return { ...EMPTY_COMMUNITY_SUPPORT_PREFS }
  }
}

export function mergeCommunitySupportPrefsIntoPrefs(
  prefs: UserPrefs,
  value: CommunitySupportPrefs,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_COMMUNITY_SUPPORT]: JSON.stringify(value),
  }
}

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_BUILD_NOTIFICATIONS_OPTED_OUT =
  'appwrite.buildNotifications.optedOut' // pragma: allowlist secret

export function parseBuildNotificationsOptedOut(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(
      prefs?.[USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT],
    ) ?? false
  )
}

export function hasBuildNotificationsOptedOutPref(
  prefs: UserPrefs | null | undefined,
): boolean {
  return prefs?.[USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT] !== undefined
}

export function mergeBuildNotificationsOptedOutIntoPrefs(
  prefs: UserPrefs,
  optedOut: boolean,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT]: optedOut,
  }
}

export function readLegacyBuildNotificationsOptedOutFromLocalStorage(): boolean {
  if (typeof window === 'undefined') return false
  try {
    return (
      localStorage.getItem(
        LEGACY_LOCAL_STORAGE_BUILD_NOTIFICATIONS_OPTED_OUT,
      ) === '1'
    )
  } catch {
    return false
  }
}

export function clearLegacyBuildNotificationsOptedOutLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.removeItem(LEGACY_LOCAL_STORAGE_BUILD_NOTIFICATIONS_OPTED_OUT)
  } catch {
    /* private mode */
  }
}

// ---------------------------------------------------------------------------
// Usage chart date range + interval (account prefs, shared overview + usage)
// ---------------------------------------------------------------------------

/** Full key: `console.usageChart.dateRange` - JSON `{ preset }` or `{ from, to }` ISO strings. */
export const USER_PREFS_KEY_USAGE_CHART_DATE_RANGE =
  'console.usageChart.dateRange'

/** Full key: `console.usageChart.interval` - `"15m"`, `"1h"`, or `"1d"`. */
export const USER_PREFS_KEY_USAGE_CHART_INTERVAL = 'console.usageChart.interval'

const USAGE_CHART_INTERVAL_PREF_VALUES = ['15m', '1h', '1d'] as const

export type UsageChartIntervalPref = (typeof USAGE_CHART_INTERVAL_PREF_VALUES)[number]

export function isUsageChartIntervalPref(
  value: string,
): value is UsageChartIntervalPref {
  return (USAGE_CHART_INTERVAL_PREF_VALUES as readonly string[]).includes(value)
}

export function parseUsageChartDateRangeFromPrefs(
  prefs: UserPrefs | null | undefined,
): SerializedUsageChartDateRange | null {
  const raw = prefs?.[USER_PREFS_KEY_USAGE_CHART_DATE_RANGE]
  if (typeof raw !== 'string' || !raw.trim()) return null
  try {
    const parsed = JSON.parse(raw) as {
      preset?: string
      from?: string
      to?: string
    }
    if (typeof parsed?.preset === 'string' && parsed.preset.trim()) {
      const preset = getUsageDateRangePresetByValue(parsed.preset.trim())
      if (preset) {
        return { preset: preset.value }
      }
    }
    if (typeof parsed?.from !== 'string' || typeof parsed?.to !== 'string') {
      return null
    }
    const from = new Date(parsed.from)
    const to = new Date(parsed.to)
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return null
    if (from.getTime() > to.getTime()) return null

    const storedRange: DateRange = { from, to }
    const inferred = inferUsageDateRangePresetFromStoredRange(storedRange)
    if (inferred) {
      return { preset: inferred.value }
    }

    const normalized = normalizeUsageDateRangeSelection(storedRange)
    if (normalized?.from && normalized.to) {
      return serializeUsageChartDateRange({
        from: normalized.from,
        to: normalized.to,
      })
    }

    return { from: parsed.from, to: parsed.to }
  } catch {
    return null
  }
}

export function parseUsageChartIntervalFromPrefs(
  prefs: UserPrefs | null | undefined,
): UsageChartIntervalPref | null {
  const raw = prefs?.[USER_PREFS_KEY_USAGE_CHART_INTERVAL]
  if (typeof raw !== 'string') return null
  const normalized = normalizeUsageChartIntervalPref(raw)
  if (normalized && isUsageChartIntervalPref(normalized)) return normalized
  return null
}

export function mergeUsageChartFiltersIntoPrefs(
  prefs: UserPrefs,
  serializedDateRange: SerializedUsageChartDateRange,
  chartInterval: UsageChartIntervalPref,
): UserPrefs {
  return {
    ...prefs,
    [USER_PREFS_KEY_USAGE_CHART_DATE_RANGE]: JSON.stringify(serializedDateRange),
    [USER_PREFS_KEY_USAGE_CHART_INTERVAL]: chartInterval,
  }
}

// ---------------------------------------------------------------------------
// Service list view mode (account prefs, one key per page across all projects)
// ---------------------------------------------------------------------------

export type ServiceListViewMode = 'list' | 'grid'

export const SERVICE_LIST_VIEW_MODE_DEFAULT: ServiceListViewMode = 'grid'

export type ServiceListViewModeScope =
  | 'functions'
  | 'sites'
  | 'projects'
  | 'stores'

/** Full key: `console.functions.listViewMode` - `"list"` or `"grid"`. */
export const USER_PREFS_KEY_FUNCTIONS_LIST_VIEW_MODE =
  'console.functions.listViewMode'

/** Full key: `console.sites.listViewMode` - `"list"` or `"grid"`. */
export const USER_PREFS_KEY_SITES_LIST_VIEW_MODE = 'console.sites.listViewMode'

/** Full key: `console.organizations.projects.listViewMode` - org projects tab. */
export const USER_PREFS_KEY_ORG_PROJECTS_LIST_VIEW_MODE =
  'console.organizations.projects.listViewMode'

/** Full key: `console.stores.listViewMode` - `"list"` or `"grid"`. */
export const USER_PREFS_KEY_STORES_LIST_VIEW_MODE =
  'console.stores.listViewMode'

function getServiceListViewModeKey(scope: ServiceListViewModeScope): string {
  switch (scope) {
    case 'functions':
      return USER_PREFS_KEY_FUNCTIONS_LIST_VIEW_MODE
    case 'sites':
      return USER_PREFS_KEY_SITES_LIST_VIEW_MODE
    case 'projects':
      return USER_PREFS_KEY_ORG_PROJECTS_LIST_VIEW_MODE
    case 'stores':
      return USER_PREFS_KEY_STORES_LIST_VIEW_MODE
  }
}

export function parseServiceListViewMode(
  prefs: UserPrefs | null | undefined,
  scope: ServiceListViewModeScope,
): ServiceListViewMode {
  const raw = prefs?.[getServiceListViewModeKey(scope)]
  if (raw === 'list' || raw === 'grid') return raw
  return SERVICE_LIST_VIEW_MODE_DEFAULT
}

export function mergeServiceListViewModeIntoPrefs(
  prefs: UserPrefs,
  scope: ServiceListViewModeScope,
  mode: ServiceListViewMode,
): UserPrefs {
  return {
    ...prefs,
    [getServiceListViewModeKey(scope)]: mode,
  }
}

// ---------------------------------------------------------------------------
// Console operator impersonation - recent targets (quick access in picker)
//
// Account prefs store only ordered user ID references (small). Display fields
// (name/email) live in localStorage so we never write avatars, data URLs, or
// other large blobs into Appwrite account prefs.
// ---------------------------------------------------------------------------

/**
 * Full key: `console.impersonation.recentUsers`
 * Value: JSON string[] of user IDs (legacy: JSON RecentImpersonationUser[] is still read).
 */
export const USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT =
  'console.impersonation.recentUsers'

export const MAX_RECENT_IMPERSONATION_USERS = 5

/** Caps for labels stored in local/session storage (never images / data URLs). */
export const MAX_RECENT_IMPERSONATION_LABEL_LENGTH = 128

export interface RecentImpersonationUser {
  $id: string
  name?: string
  email?: string
}

type RecentImpersonationDetails = {
  name?: string
  email?: string
}

const SESSION_STORAGE_RECENT_BY_OPERATOR_KEY =
  'console.impersonation.recentByOperator'

/** localStorage: Record<operatorId, Record<userId, { name?, email? }>> */
const LOCAL_STORAGE_RECENT_DETAILS_BY_OPERATOR_KEY =
  'console.impersonation.recentUserDetails'

function isOversizedOrBinaryLabel(value: string): boolean {
  const trimmed = value.trim()
  if (!trimmed) return true
  if (trimmed.length > MAX_RECENT_IMPERSONATION_LABEL_LENGTH) return true
  // Reject data URLs / base64 payloads that must never enter prefs or local details.
  if (/^data:/i.test(trimmed)) return true
  if (/^blob:/i.test(trimmed)) return true
  return false
}

function sanitizeRecentImpersonationLabel(
  value: unknown,
): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  if (!trimmed || isOversizedOrBinaryLabel(trimmed)) return undefined
  return trimmed.slice(0, MAX_RECENT_IMPERSONATION_LABEL_LENGTH)
}

/** Keep only `$id` + short name/email. Drops avatar/image/photo and any other keys. */
export function sanitizeRecentImpersonationUser(
  raw: unknown,
): RecentImpersonationUser | null {
  if (raw == null || typeof raw !== 'object') return null
  const record = raw as Record<string, unknown>
  const id =
    typeof record.$id === 'string'
      ? record.$id.trim()
      : typeof record.id === 'string'
        ? record.id.trim()
        : ''
  if (!id || id.length > 64) return null
  const entry: RecentImpersonationUser = { $id: id }
  const name = sanitizeRecentImpersonationLabel(record.name)
  const email = sanitizeRecentImpersonationLabel(record.email)
  if (name) entry.name = name
  if (email) entry.email = email
  return entry
}

function sanitizeRecentImpersonationList(
  list: unknown,
): RecentImpersonationUser[] {
  if (!Array.isArray(list)) return []
  const out: RecentImpersonationUser[] = []
  const seen = new Set<string>()
  for (const item of list) {
    const user = sanitizeRecentImpersonationUser(item)
    if (!user || seen.has(user.$id)) continue
    seen.add(user.$id)
    out.push(user)
    if (out.length >= MAX_RECENT_IMPERSONATION_USERS) break
  }
  return out
}

function readRecentByOperatorMap(): Record<string, RecentImpersonationUser[]> {
  if (typeof sessionStorage === 'undefined') return {}
  try {
    const raw = sessionStorage.getItem(SESSION_STORAGE_RECENT_BY_OPERATOR_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (typeof parsed !== 'object' || parsed === null) return {}
    const out: Record<string, RecentImpersonationUser[]> = {}
    for (const [operatorId, list] of Object.entries(
      parsed as Record<string, unknown>,
    )) {
      const sanitized = sanitizeRecentImpersonationList(list)
      if (sanitized.length > 0) out[operatorId] = sanitized
    }
    return out
  } catch {
    return {}
  }
}

function writeRecentByOperatorMap(
  map: Record<string, RecentImpersonationUser[]>,
) {
  if (typeof sessionStorage === 'undefined') return
  try {
    const sanitized: Record<string, RecentImpersonationUser[]> = {}
    for (const [operatorId, list] of Object.entries(map)) {
      sanitized[operatorId] = sanitizeRecentImpersonationList(list)
    }
    sessionStorage.setItem(
      SESSION_STORAGE_RECENT_BY_OPERATOR_KEY,
      JSON.stringify(sanitized),
    )
  } catch {
    /* private mode / quota */
  }
}

function readRecentDetailsByOperatorMap(): Record<
  string,
  Record<string, RecentImpersonationDetails>
> {
  if (typeof localStorage === 'undefined') return {}
  try {
    const raw = localStorage.getItem(
      LOCAL_STORAGE_RECENT_DETAILS_BY_OPERATOR_KEY,
    )
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (typeof parsed !== 'object' || parsed === null) return {}
    const out: Record<string, Record<string, RecentImpersonationDetails>> = {}
    for (const [operatorId, users] of Object.entries(
      parsed as Record<string, unknown>,
    )) {
      if (typeof users !== 'object' || users === null) continue
      const byUser: Record<string, RecentImpersonationDetails> = {}
      for (const [userId, details] of Object.entries(
        users as Record<string, unknown>,
      )) {
        const id = userId.trim()
        if (!id) continue
        const sanitized = sanitizeRecentImpersonationUser({
          $id: id,
          ...(typeof details === 'object' && details !== null
            ? (details as Record<string, unknown>)
            : {}),
        })
        if (!sanitized) continue
        const entry: RecentImpersonationDetails = {}
        if (sanitized.name) entry.name = sanitized.name
        if (sanitized.email) entry.email = sanitized.email
        if (entry.name || entry.email) byUser[id] = entry
      }
      if (Object.keys(byUser).length > 0) out[operatorId] = byUser
    }
    return out
  } catch {
    return {}
  }
}

function writeRecentDetailsByOperatorMap(
  map: Record<string, Record<string, RecentImpersonationDetails>>,
) {
  if (typeof localStorage === 'undefined') return
  try {
    localStorage.setItem(
      LOCAL_STORAGE_RECENT_DETAILS_BY_OPERATOR_KEY,
      JSON.stringify(map),
    )
  } catch {
    /* private mode / quota */
  }
}

/**
 * Persist short display labels for recent targets in localStorage (not account prefs).
 * Call whenever the operator's recent list changes so the picker can show names offline.
 */
export function writeRecentImpersonationDetails(
  operatorId: string,
  list: RecentImpersonationUser[],
) {
  const id = operatorId?.trim()
  if (!id) return
  const map = readRecentDetailsByOperatorMap()
  const previous = map[id] ?? {}
  const byUser: Record<string, RecentImpersonationDetails> = {}
  const sanitizedList = sanitizeRecentImpersonationList(list)

  for (const user of sanitizedList) {
    const prev = previous[user.$id]
    const details: RecentImpersonationDetails = {}
    const name = user.name || prev?.name
    const email = user.email || prev?.email
    if (name) details.name = name
    if (email) details.email = email
    if (details.name || details.email) byUser[user.$id] = details
  }

  if (Object.keys(byUser).length === 0) {
    delete map[id]
  } else {
    map[id] = byUser
  }
  writeRecentDetailsByOperatorMap(map)
}

function enrichRecentImpersonationUsers(
  operatorId: string,
  list: RecentImpersonationUser[],
): RecentImpersonationUser[] {
  const id = operatorId?.trim()
  const detailsByUser = id ? readRecentDetailsByOperatorMap()[id] : undefined
  return list.map((user) => {
    const details = detailsByUser?.[user.$id]
    if (!details) return user
    return {
      $id: user.$id,
      name: user.name || details.name,
      email: user.email || details.email,
    }
  })
}

/** While impersonating, prefs belong to the target user - store recents per operator here until exit. */
export function readRecentImpersonationSessionList(
  operatorId: string,
): RecentImpersonationUser[] {
  const id = operatorId?.trim()
  if (!id) return []
  const map = readRecentByOperatorMap()
  return enrichRecentImpersonationUsers(
    id,
    sanitizeRecentImpersonationList(map[id]),
  )
}

export function writeRecentImpersonationSessionList(
  operatorId: string,
  list: RecentImpersonationUser[],
) {
  const id = operatorId?.trim()
  if (!id) return
  const sanitized = sanitizeRecentImpersonationList(list)
  const map = readRecentByOperatorMap()
  map[id] = sanitized
  writeRecentByOperatorMap(map)
  writeRecentImpersonationDetails(id, sanitized)
}

export function clearRecentImpersonationSessionList(operatorId: string) {
  const id = operatorId?.trim()
  if (!id) return
  const map = readRecentByOperatorMap()
  delete map[id]
  writeRecentByOperatorMap(map)
}

/**
 * Parse recent impersonation targets from account prefs.
 * Prefs hold ID references only; name/email are filled from localStorage when `operatorId` is passed.
 * Legacy prefs that stored full `{ $id, name, email }` objects are still accepted (IDs used; labels migrated to localStorage).
 */
export function parseRecentImpersonationUsers(
  prefs: UserPrefs | null | undefined,
  operatorId?: string,
): RecentImpersonationUser[] {
  const key = USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT
  if (!prefs || typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []

    const fromPrefs: RecentImpersonationUser[] = []
    for (const item of raw) {
      if (typeof item === 'string') {
        const id = item.trim()
        if (id && id.length <= 64) fromPrefs.push({ $id: id })
        continue
      }
      const sanitized = sanitizeRecentImpersonationUser(item)
      if (sanitized) fromPrefs.push(sanitized)
      if (fromPrefs.length >= MAX_RECENT_IMPERSONATION_USERS) break
    }

    const limited = fromPrefs.slice(0, MAX_RECENT_IMPERSONATION_USERS)
    const opId = operatorId?.trim()
    if (opId) {
      // Migrate any legacy labels that were still embedded in prefs into localStorage.
      const withLabels = limited.filter((u) => u.name || u.email)
      if (withLabels.length > 0) {
        writeRecentImpersonationDetails(opId, limited)
      }
      return enrichRecentImpersonationUsers(
        opId,
        limited.map((u) => ({ $id: u.$id })),
      )
    }
    return limited.map((u) => ({
      $id: u.$id,
      ...(u.name ? { name: u.name } : {}),
      ...(u.email ? { email: u.email } : {}),
    }))
  } catch {
    return []
  }
}

export function mergeRecentImpersonationLists(
  a: RecentImpersonationUser[],
  b: RecentImpersonationUser[],
): RecentImpersonationUser[] {
  const seen = new Set<string>()
  const out: RecentImpersonationUser[] = []
  for (const u of [...a, ...b]) {
    const sanitized = sanitizeRecentImpersonationUser(u)
    if (!sanitized || seen.has(sanitized.$id)) continue
    seen.add(sanitized.$id)
    out.push(sanitized)
    if (out.length >= MAX_RECENT_IMPERSONATION_USERS) break
  }
  return out
}

export function appendRecentImpersonationUser(
  current: RecentImpersonationUser[],
  user: { $id: string; name?: string | null; email?: string | null },
): RecentImpersonationUser[] {
  const entry = sanitizeRecentImpersonationUser(user)
  if (!entry) return sanitizeRecentImpersonationList(current)
  return mergeRecentImpersonationLists(
    [entry],
    current.filter((u) => u.$id !== entry.$id),
  )
}

/**
 * Write only ordered user ID references into account prefs.
 * Display labels must be stored via `writeRecentImpersonationDetails` (localStorage).
 */
export function mergeRecentImpersonationIntoAccountPrefs(
  prefs: UserPrefs | null | undefined,
  list: RecentImpersonationUser[],
): Record<string, unknown> {
  const ids = sanitizeRecentImpersonationList(list).map((u) => u.$id)
  return {
    ...(prefs ?? {}),
    [USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT]: JSON.stringify(ids),
  }
}
