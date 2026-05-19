/**
 * User (account) preferences key-value format for console settings.
 *
 * Keys and values follow the extendable format documented in AGENTS.md
 * (see "Team and user preferences (key-value format)").
 */

import {
  clampTableViewSidebarWidthPx,
  normalizeLegacySidebarWidthPrefValue,
  TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX,
} from '@/lib/resizable-layout'

export type UserPrefs = Record<string, unknown>

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
  if (!prefs || typeof prefs[USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS] !== 'string') {
    return []
  }
  try {
    const raw = JSON.parse(prefs[USER_PREFS_KEY_IMAGE_TRANSFORM_PRESETS] as string)
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
          name: String(p.name).slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH),
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
  return parseSidebarWidthPxForKey(prefs, USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH)
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
    Math.max(
      STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
      Math.round(px),
    ),
  )
}

export function mergeStorageFilesListColumnWidthsWithDefaults(
  stored: Record<string, number> | undefined,
): Record<StorageFilesListColumnWidthKey, number> {
  const out = { ...STORAGE_FILES_LIST_COLUMN_DEFAULT_WIDTHS }
  if (!stored) return out
  for (const k of STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS) {
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

export function readLegacyStorageFilesTablePaneWidthFromLocalStorage(): number | null {
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
    if (typeof tables !== 'object' || tables === null || Array.isArray(tables)) {
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
        if (
          typeof cols !== 'object' ||
          cols === null ||
          Array.isArray(cols)
        ) {
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

  const payload: Record<string, unknown> = { databases: {} as Record<string, unknown> }
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
// Databases: Tables DB — which row attributes to fetch & column order (account)
// ---------------------------------------------------------------------------

/**
 * Per-table preference key: `console.tablesDb.rowsListColumns.<databaseId>.<tableId>`
 *
 * Value: JSON string `string[]` — ordered column keys: optional system fields
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
    if (!entry || entry.hidden || !allKeysSet.has(entry.key) || seen.has(entry.key))
      continue
    seen.add(entry.key)
    visibleOrdered.push(entry.key)
  }

  if (visibleOrdered.length === 0) {
    return { orderedKeys: [...allKeys], hiddenKeys: new Set() }
  }

  const hiddenKeys = new Set(allKeys.filter((k) => !seen.has(k)))
  const orderedKeys = [...visibleOrdered, ...allKeys.filter((k) => hiddenKeys.has(k))]
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
// AI assistant panel (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.aiChat.panelOpen` - panel open when true / `"true"`. */
export const USER_PREFS_KEY_AI_CHAT_PANEL_OPEN = 'console.aiChat.panelOpen'

/** Full key: `console.aiChat.panelWidthPx` - panel width in pixels (string number). */
export const USER_PREFS_KEY_AI_CHAT_PANEL_WIDTH_PX = 'console.aiChat.panelWidthPx'

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
  return parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_AI_CHAT_PANEL_OPEN]) ?? false
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
// Build completion browser notifications (account prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.buildNotifications.optedOut` - user dismissed the enable prompt. */
export const USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT =
  'console.buildNotifications.optedOut'

/** @deprecated Migrated to account prefs; cleared after first sync. */
export const LEGACY_LOCAL_STORAGE_BUILD_NOTIFICATIONS_OPTED_OUT =
  'appwrite.buildNotifications.optedOut'

export function parseBuildNotificationsOptedOut(
  prefs: UserPrefs | null | undefined,
): boolean {
  return (
    parseBooleanAccountPref(prefs?.[USER_PREFS_KEY_BUILD_NOTIFICATIONS_OPTED_OUT]) ??
    false
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
      localStorage.getItem(LEGACY_LOCAL_STORAGE_BUILD_NOTIFICATIONS_OPTED_OUT) ===
      '1'
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
// Console operator impersonation - recent targets (quick access in picker)
// ---------------------------------------------------------------------------

/** Full key: `console.impersonation.recentUsers` - JSON RecentImpersonationUser[] */
export const USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT =
  'console.impersonation.recentUsers'

export const MAX_RECENT_IMPERSONATION_USERS = 5

export interface RecentImpersonationUser {
  $id: string
  name?: string
  email?: string
}

const SESSION_STORAGE_RECENT_BY_OPERATOR_KEY =
  'console.impersonation.recentByOperator'

function readRecentByOperatorMap(): Record<string, RecentImpersonationUser[]> {
  if (typeof sessionStorage === 'undefined') return {}
  try {
    const raw = sessionStorage.getItem(SESSION_STORAGE_RECENT_BY_OPERATOR_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (typeof parsed !== 'object' || parsed === null) return {}
    return parsed as Record<string, RecentImpersonationUser[]>
  } catch {
    return {}
  }
}

function writeRecentByOperatorMap(
  map: Record<string, RecentImpersonationUser[]>,
) {
  if (typeof sessionStorage === 'undefined') return
  try {
    sessionStorage.setItem(
      SESSION_STORAGE_RECENT_BY_OPERATOR_KEY,
      JSON.stringify(map),
    )
  } catch {
    /* private mode */
  }
}

/** While impersonating, prefs belong to the target user - store recents per operator here until exit. */
export function readRecentImpersonationSessionList(
  operatorId: string,
): RecentImpersonationUser[] {
  const id = operatorId?.trim()
  if (!id) return []
  const map = readRecentByOperatorMap()
  const list = map[id]
  if (!Array.isArray(list)) return []
  return list
    .filter(
      (u): u is RecentImpersonationUser =>
        u != null &&
        typeof u === 'object' &&
        typeof (u as RecentImpersonationUser).$id === 'string',
    )
    .map((u) => ({
      $id: String(u.$id).trim(),
      ...(typeof u.name === 'string' ? { name: u.name } : {}),
      ...(typeof u.email === 'string' ? { email: u.email } : {}),
    }))
    .filter((u) => u.$id.length > 0)
    .slice(0, MAX_RECENT_IMPERSONATION_USERS)
}

export function writeRecentImpersonationSessionList(
  operatorId: string,
  list: RecentImpersonationUser[],
) {
  const id = operatorId?.trim()
  if (!id) return
  const map = readRecentByOperatorMap()
  map[id] = list.slice(0, MAX_RECENT_IMPERSONATION_USERS)
  writeRecentByOperatorMap(map)
}

export function clearRecentImpersonationSessionList(operatorId: string) {
  const id = operatorId?.trim()
  if (!id) return
  const map = readRecentByOperatorMap()
  delete map[id]
  writeRecentByOperatorMap(map)
}

export function parseRecentImpersonationUsers(
  prefs: UserPrefs | null | undefined,
): RecentImpersonationUser[] {
  const key = USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT
  if (!prefs || typeof prefs[key] !== 'string') return []
  try {
    const raw = JSON.parse(prefs[key] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter(
        (item): item is RecentImpersonationUser =>
          item != null &&
          typeof item === 'object' &&
          typeof (item as RecentImpersonationUser).$id === 'string',
      )
      .map((item) => {
        const u = item as RecentImpersonationUser
        return {
          $id: String(u.$id).trim(),
          ...(typeof u.name === 'string' ? { name: u.name } : {}),
          ...(typeof u.email === 'string' ? { email: u.email } : {}),
        }
      })
      .filter((u) => u.$id.length > 0)
      .slice(0, MAX_RECENT_IMPERSONATION_USERS)
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
    const id = u.$id?.trim()
    if (!id || seen.has(id)) continue
    seen.add(id)
    out.push(u)
    if (out.length >= MAX_RECENT_IMPERSONATION_USERS) break
  }
  return out
}

export function appendRecentImpersonationUser(
  current: RecentImpersonationUser[],
  user: { $id: string; name?: string | null; email?: string | null },
): RecentImpersonationUser[] {
  const id = user.$id?.trim()
  if (!id) return current
  const entry: RecentImpersonationUser = { $id: id }
  const name = user.name?.trim()
  const email = user.email?.trim()
  if (name) entry.name = name
  if (email) entry.email = email
  return mergeRecentImpersonationLists(
    [entry],
    current.filter((u) => u.$id !== id),
  )
}

export function mergeRecentImpersonationIntoAccountPrefs(
  prefs: UserPrefs | null | undefined,
  list: RecentImpersonationUser[],
): Record<string, unknown> {
  return {
    ...(prefs ?? {}),
    [USER_PREFS_KEY_CONSOLE_IMPERSONATION_RECENT]: JSON.stringify(
      list.slice(0, MAX_RECENT_IMPERSONATION_USERS),
    ),
  }
}
