import { queryOptions, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  getOAuth2AppIdFromIdentity,
  getOAuth2GrantIdentities,
} from '@/lib/account-oauth2-grants'
import {
  matchKnownOAuthClient,
  type KnownOAuthClient,
} from '@/lib/oauth-known-clients'
import { DEFAULT_STALE_TIME } from './constants'
import { fetchAccountIdentities } from './auth'

export type AccountConnectedApp = {
  identity: Models.Identity
  appId: string
  app: Models.App | null
}

/**
 * A set of OAuth2 grants that belong to the same client. DCR clients (most
 * MCP clients) register a brand-new app on every connect, so a single tool
 * like Claude Code can accumulate many grants with distinct app IDs but
 * identical registration metadata. Grouping lets users revoke them together.
 */
export type AccountConnectedAppGroup = {
  /** Stable grouping key (known client id, app name, or app ID). */
  key: string
  displayName: string
  /** Matched well-known client, used for logo fallback and canonical naming. */
  knownClient: KnownOAuthClient | null
  /** Representative app metadata (from the most recent grant). */
  app: Models.App | null
  /** All grants for this client, most recently authorized first. */
  grants: AccountConnectedApp[]
  latestAuthorizedAt: string
}

export type AccountConnectedAppsData = Awaited<
  ReturnType<typeof fetchAccountConnectedApps>
>

function getGrantTime(connectedApp: AccountConnectedApp): number {
  return new Date(connectedApp.identity.$createdAt).getTime() || 0
}

function getGroupKey(
  connectedApp: AccountConnectedApp,
  knownClient: KnownOAuthClient | null,
): string {
  const { app, appId } = connectedApp
  if (!app) return `app:${appId}`
  if (knownClient) return `client:${knownClient.id}`

  const normalizedName = app.name.trim().toLowerCase()
  return normalizedName ? `name:${normalizedName}` : `app:${appId}`
}

export function groupConnectedApps(
  connectedApps: AccountConnectedApp[],
): AccountConnectedAppGroup[] {
  const sorted = [...connectedApps].sort((a, b) => getGrantTime(b) - getGrantTime(a))

  const groups = new Map<string, AccountConnectedAppGroup>()
  for (const connectedApp of sorted) {
    const knownClient = connectedApp.app
      ? matchKnownOAuthClient(connectedApp.app)
      : null
    const key = getGroupKey(connectedApp, knownClient)
    const existing = groups.get(key)
    if (existing) {
      existing.grants.push(connectedApp)
      continue
    }

    groups.set(key, {
      key,
      displayName:
        connectedApp.app?.name?.trim() ||
        knownClient?.name ||
        connectedApp.appId,
      knownClient,
      app: connectedApp.app,
      grants: [connectedApp],
      latestAuthorizedAt: connectedApp.identity.$createdAt,
    })
  }

  // Map preserves insertion order and grants are sorted newest-first, so
  // groups already come out ordered by most recently authorized.
  return [...groups.values()]
}

export async function fetchAccountConnectedApps(): Promise<{
  connectedApps: AccountConnectedApp[]
  groups: AccountConnectedAppGroup[]
  total: number
}> {
  const { identities } = await fetchAccountIdentities()
  const grants = getOAuth2GrantIdentities(identities)

  const connectedApps = await Promise.all(
    grants.map(async (identity) => {
      const appId = getOAuth2AppIdFromIdentity(identity) ?? identity.$id
      const app = await sdk.forConsole.apps
        .get({ appId })
        .catch(() => null)
      return { identity, appId, app }
    }),
  )

  connectedApps.sort((a, b) => getGrantTime(b) - getGrantTime(a))

  return {
    connectedApps,
    groups: groupConnectedApps(connectedApps),
    total: connectedApps.length,
  }
}

export function accountConnectedAppsQueryOptions() {
  return queryOptions({
    queryKey: ['applications', 'account'],
    queryFn: fetchAccountConnectedApps,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useAccountConnectedApps() {
  return useQuery(accountConnectedAppsQueryOptions())
}
