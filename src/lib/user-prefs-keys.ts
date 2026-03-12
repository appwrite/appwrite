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
