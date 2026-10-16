import type { QueryClient } from '@tanstack/react-query'
import { redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { ensureConsoleAccountQueryData } from '@/lib/react-query/hooks'
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'

export type AgentSettingsSectionId = 'models' | 'memory' | 'mcp' | 'usage'

const AGENT_PAGE_PATH_RE = /^\/organizations\/([^/]+)\/agent(?:\/|$)/

/** True for the dedicated org-scoped agent surface (not the docked console pane). */
export function isAgentPagePath(pathname: string): boolean {
  return AGENT_PAGE_PATH_RE.test(pathname)
}

/** Org id when the current path is `/organizations/$orgId/agent...`. */
export function parseAgentOrgIdFromPath(pathname: string): string | undefined {
  const match = pathname.match(AGENT_PAGE_PATH_RE)
  return match?.[1]
}

export function agentIndexPath(orgId: string): string {
  return `/organizations/${orgId}/agent`
}

export function agentConversationPath(orgId: string, agentId: string): string {
  return `/organizations/${orgId}/agent/${agentId}`
}

export function agentAutomationsPath(orgId: string): string {
  return `/organizations/${orgId}/agent/automations`
}

export function agentAutomationCreatePath(orgId: string): string {
  return `/organizations/${orgId}/agent/automations/create`
}

export function agentAutomationDetailPath(
  orgId: string,
  automationId: string,
): string {
  return `/organizations/${orgId}/agent/automations/${automationId}`
}

export function agentSettingsPath(
  orgId: string,
  section: AgentSettingsSectionId = 'models',
): string {
  switch (section) {
    case 'mcp':
      return `/organizations/${orgId}/agent/settings/mcp`
    case 'memory':
      return `/organizations/${orgId}/agent/settings/memory`
    case 'usage':
      return `/organizations/${orgId}/agent/settings/usage`
    case 'models':
    default:
      return `/organizations/${orgId}/agent/settings/models`
  }
}

/** Preferred org from account prefs (sync). */
export function preferredOrganizationId(
  prefs: Record<string, unknown> | null | undefined,
): string | undefined {
  const value = prefs?.[USER_PREFS_KEY_ORGANIZATION]
  return typeof value === 'string' && value.trim() ? value.trim() : undefined
}

/**
 * Map a legacy `/agent...` pathname onto the org-scoped agent tree.
 * Returns a console-relative path (no origin).
 */
export function mapLegacyAgentPathToOrg(
  orgId: string,
  pathname: string,
): string {
  const suffix = pathname.replace(/^\/agent\/?/, '')
  if (!suffix) return agentIndexPath(orgId)
  return `${agentIndexPath(orgId)}/${suffix}`
}

/**
 * Redirect helpers for legacy `/agent` and `/assistant` URLs.
 * Resolves the preferred org (or provisions one) then replaces the location.
 */
export async function redirectLegacyAgentLocation(options: {
  queryClient: QueryClient
  pathname: string
}): Promise<never> {
  const { queryClient, pathname } = options

  if (!getActiveProfileFeatures().agent) {
    throw redirect({ to: '/', replace: true })
  }

  const account = await ensureConsoleAccountQueryData(queryClient)
  if (!account) {
    throw redirect({
      to: '/sign-in',
      search: { redirect: pathname },
      replace: true,
    })
  }

  const orgId = await resolvePostAuthOrganizationId(account, queryClient)
  const href = mapLegacyAgentPathToOrg(orgId, pathname)

  throw redirect({
    to: href as never,
    replace: true,
  })
}
