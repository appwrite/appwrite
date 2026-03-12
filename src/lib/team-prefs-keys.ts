/**
 * Team and user preferences key-value format for console settings.
 *
 * Keys and values follow the extendable format documented in AGENTS.md
 * (see "Team and user preferences (key-value format)").
 */

/** Max number of projects that can be pinned per organization */
export const MAX_PINNED_PROJECTS = 6

/**
 * Preference key for pinned project IDs (team/org level).
 * Value: JSON string of string[] (project IDs), max length MAX_PINNED_PROJECTS.
 */
export const TEAM_PREFS_KEY_PINNED_PROJECT_IDS = 'console.pinnedProjectIds'

export type TeamPrefs = Record<string, unknown>

/**
 * Parse pinned project IDs from team prefs.
 * Returns up to MAX_PINNED_PROJECTS IDs in order.
 */
export function parsePinnedProjectIds(
  prefs: TeamPrefs | null | undefined,
): string[] {
  if (!prefs || typeof prefs[TEAM_PREFS_KEY_PINNED_PROJECT_IDS] !== 'string') {
    return []
  }
  try {
    const raw = JSON.parse(prefs[TEAM_PREFS_KEY_PINNED_PROJECT_IDS] as string)
    if (!Array.isArray(raw)) return []
    return raw
      .filter((id): id is string => typeof id === 'string' && id.length > 0)
      .slice(0, MAX_PINNED_PROJECTS)
  } catch {
    return []
  }
}

/**
 * Build prefs object to write pinned project IDs.
 * Merge with existing prefs before calling updatePrefs.
 */
export function buildPinnedProjectIdsPrefs(ids: string[]): TeamPrefs {
  const trimmed = ids.slice(0, MAX_PINNED_PROJECTS)
  return {
    [TEAM_PREFS_KEY_PINNED_PROJECT_IDS]: JSON.stringify(trimmed),
  }
}
