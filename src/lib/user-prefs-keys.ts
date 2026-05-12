/**
 * User (account) preferences key-value format for console settings.
 *
 * Keys and values follow the extendable format documented in AGENTS.md
 * (see "Team and user preferences (key-value format)").
 */

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
// Storage: image transform wizard — saved presets (account + team prefs)
// ---------------------------------------------------------------------------

/** Full key: `console.imageTransformPresets` — JSON SavedImageTransformPreset[] */
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
 * Full key: `console.databases.sidebarWidth` - JSON-encoded number representing
 * the percentage (0–100) of the resizable group occupied by the tables sidebar
 * inside the database detail view. Shared across all databases and tables.
 */
export const USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH =
  'console.databases.sidebarWidth'

/** Default percent for the tables sidebar pane (matches previous defaultSize). */
export const DATABASES_SIDEBAR_WIDTH_DEFAULT_PERCENT = 20

/** Hard clamp so a corrupted/foreign value can never break the layout. */
export const DATABASES_SIDEBAR_WIDTH_MIN_PERCENT = 5
export const DATABASES_SIDEBAR_WIDTH_MAX_PERCENT = 60

export function parseDatabasesSidebarWidthPercent(
  prefs: UserPrefs | null | undefined,
): number | null {
  if (!prefs) return null
  const raw = prefs[USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH]
  let value: number | null = null
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    value = raw
  } else if (typeof raw === 'string' && raw.length > 0) {
    const parsed = Number(raw)
    if (Number.isFinite(parsed)) value = parsed
  }
  if (value === null) return null
  return Math.min(
    DATABASES_SIDEBAR_WIDTH_MAX_PERCENT,
    Math.max(DATABASES_SIDEBAR_WIDTH_MIN_PERCENT, value),
  )
}

export function buildDatabasesSidebarWidthPrefs(percent: number): UserPrefs {
  const clamped = Math.min(
    DATABASES_SIDEBAR_WIDTH_MAX_PERCENT,
    Math.max(DATABASES_SIDEBAR_WIDTH_MIN_PERCENT, percent),
  )
  // Store as a string for consistency with other JSON-encoded prefs (saved
  // filters, recent impersonation users) – the prefs API treats values as
  // strings.
  return {
    [USER_PREFS_KEY_DATABASES_SIDEBAR_WIDTH]: String(
      Math.round(clamped * 100) / 100,
    ),
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
