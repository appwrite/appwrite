import type { UserPrefs } from '@/lib/user-prefs-keys'
import {
  firewallListSearch,
  parseFirewallResourceIdSearch,
  parseFirewallResourceTypeSearch,
  type FirewallResourceSelection,
} from '@/lib/firewall/conditions'

/**
 * Preference key prefix for the last Firewall resource the user managed.
 * Full key: `console.firewall.lastResource.<projectId>`
 * Value: JSON string of `{ "resourceType": "api" }` or
 * `{ "resourceType": "functions"|"sites", "resourceId": "..." }`.
 */
export const USER_PREFS_KEY_FIREWALL_LAST_RESOURCE_PREFIX =
  'console.firewall.lastResource'

const MAX_FIREWALL_LAST_RESOURCE_ID_LENGTH = 128

export type FirewallLastResourcePref =
  | { resourceType: 'api' }
  | { resourceType: 'functions' | 'sites'; resourceId: string }

export function getFirewallLastResourceKey(projectId: string): string {
  return `${USER_PREFS_KEY_FIREWALL_LAST_RESOURCE_PREFIX}.${projectId}`
}

export function normalizeFirewallLastResource(
  selection: {
    resourceType?: unknown
    resourceId?: unknown
  },
): FirewallLastResourcePref | null {
  const resourceType = parseFirewallResourceTypeSearch(selection.resourceType)
  if (!resourceType) return null
  if (resourceType === 'api') return { resourceType: 'api' }
  const resourceId = parseFirewallResourceIdSearch(selection.resourceId)
  if (!resourceId || resourceId.length > MAX_FIREWALL_LAST_RESOURCE_ID_LENGTH) {
    return null
  }
  return { resourceType, resourceId }
}

export function parseFirewallLastResource(
  prefs: UserPrefs | null | undefined,
  projectId: string | null | undefined,
): FirewallLastResourcePref | null {
  if (!prefs || !projectId) return null
  const stored = prefs[getFirewallLastResourceKey(projectId)]
  let raw: unknown = stored
  if (typeof stored === 'string') {
    try {
      raw = JSON.parse(stored)
    } catch {
      return null
    }
  }
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null
  return normalizeFirewallLastResource(raw as Record<string, unknown>)
}

export function mergeFirewallLastResourceIntoPrefs(
  prefs: UserPrefs,
  projectId: string,
  selection: FirewallResourceSelection,
): UserPrefs {
  const normalized = normalizeFirewallLastResource(selection)
  if (!normalized || !projectId) return prefs
  return {
    ...prefs,
    [getFirewallLastResourceKey(projectId)]: JSON.stringify(normalized),
  }
}

export function firewallLastResourcesEqual(
  a: FirewallLastResourcePref | null | undefined,
  b: FirewallLastResourcePref | null | undefined,
): boolean {
  if (!a || !b) return false
  if (a.resourceType !== b.resourceType) return false
  if (a.resourceType === 'api' || b.resourceType === 'api') return true
  return a.resourceId === b.resourceId
}

/**
 * Search to restore when the URL does not name a resource.
 * API is the implicit default, so we only redirect for functions/sites.
 */
export function getFirewallLastResourceRedirectSearch(
  prefs: UserPrefs | null | undefined,
  projectId: string,
  search: { resourceType?: unknown; resourceId?: unknown },
): ReturnType<typeof firewallListSearch> | null {
  if (parseFirewallResourceTypeSearch(search.resourceType)) return null
  const last = parseFirewallLastResource(prefs, projectId)
  if (!last || last.resourceType === 'api') return null
  return firewallListSearch(last)
}
