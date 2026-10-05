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
 * Cloud's passkeys rollout flag, on organization (team) prefs for project
 * passkeys and on account prefs for console passkeys. Written by Cloud's
 * `task-manage-flags`; the console only reads it. Value: `true` when allowed.
 */
export const PREFS_KEY_PASSKEYS_FLAG = 'flags-passkeys'

export function parsePasskeysFlag(
  prefs: Record<string, unknown> | null | undefined,
): boolean {
  return Boolean(prefs?.[PREFS_KEY_PASSKEYS_FLAG])
}

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

/**
 * Reorder pinned project IDs (e.g. after drag-and-drop).
 */
export function reorderPinnedProjectIds(
  ids: string[],
  fromIndex: number,
  toIndex: number,
): string[] {
  if (
    fromIndex === toIndex ||
    fromIndex < 0 ||
    toIndex < 0 ||
    fromIndex >= ids.length ||
    toIndex >= ids.length
  ) {
    return ids
  }
  const next = [...ids]
  const [removed] = next.splice(fromIndex, 1)
  next.splice(toIndex, 0, removed)
  return next.slice(0, MAX_PINNED_PROJECTS)
}
