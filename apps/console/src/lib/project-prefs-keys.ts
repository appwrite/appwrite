/**
 * Preference keys the Console stores on a project (`/project/prefs`), in the
 * `console.<feature>.<subKey>` format of team and account prefs. Values are
 * strings; anything structured is a JSON string, parsed where it is read.
 */

export type ProjectPrefs = Record<string, unknown>

/**
 * Environment IDs in the order the switcher shows them, saved on the
 * production project. Value: JSON string of environment ID strings.
 */
export const PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER = 'console.environments.order'

/** One page of the environments list covers every plan cap. */
const MAX_ENVIRONMENTS_ORDER = 100

function parseIdList(raw: unknown): string[] {
  if (typeof raw === 'string') {
    const trimmed = raw.trim()
    if (!trimmed) return []
    try {
      const parsed = JSON.parse(trimmed) as unknown
      if (Array.isArray(parsed)) {
        return parsed.filter(
          (id): id is string => typeof id === 'string' && id.length > 0,
        )
      }
    } catch {
      return []
    }
    return []
  }
  if (Array.isArray(raw)) {
    return raw.filter(
      (id): id is string => typeof id === 'string' && id.length > 0,
    )
  }
  return []
}

export function parseEnvironmentsOrder(
  prefs: ProjectPrefs | null | undefined,
): string[] {
  return Array.from(
    new Set(parseIdList(prefs?.[PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER])),
  ).slice(0, MAX_ENVIRONMENTS_ORDER)
}

export function mergeEnvironmentsOrderPrefs(
  prefs: ProjectPrefs | null | undefined,
  order: readonly string[],
): ProjectPrefs {
  const next = Array.from(new Set(order.filter(Boolean))).slice(
    0,
    MAX_ENVIRONMENTS_ORDER,
  )
  return {
    ...(prefs ?? {}),
    [PROJECT_PREFS_KEY_ENVIRONMENTS_ORDER]: JSON.stringify(next),
  }
}
