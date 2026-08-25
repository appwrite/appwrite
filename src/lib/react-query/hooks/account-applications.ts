import { queryOptions, useQuery } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getOAuth2App } from '@/lib/oauth2/cimd'
import {
  matchKnownOAuthClient,
  type KnownOAuthClient,
} from '@/lib/oauth-known-clients'
import { DEFAULT_STALE_TIME } from './constants'

const PAGE_SIZE = 100
// Backstop so a runaway/growing result set can't spin forever; 100 pages of
// 100 covers any realistic number of consents or token families per account.
const MAX_PAGES = 100

/**
 * Follow a cursor-paginated list endpoint to completion. `list` receives the
 * queries for one page and returns that page's items; we page until a short
 * page (or the backstop) is hit.
 */
async function fetchAllPages<T extends { $id: string }>(
  list: (queries: string[]) => Promise<T[]>,
): Promise<T[]> {
  const items: T[] = []
  let cursor: string | null = null

  for (let page = 0; page < MAX_PAGES; page++) {
    const queries = [Query.limit(PAGE_SIZE)]
    if (cursor) queries.push(Query.cursorAfter(cursor))

    const batch = await list(queries)
    items.push(...batch)

    if (batch.length < PAGE_SIZE) break
    cursor = batch[batch.length - 1].$id
  }

  return items
}

export type AccountConnectedApp = {
  consent: Models.Oauth2Consent
  /** Registered app ID or CIMD URL - whichever identifies the client. */
  clientId: string
  /** Client ID metadata document URL for URL-form clients, null otherwise. */
  cimdUrl: string | null
  /**
   * Resolved client metadata. For registered apps this is the app record;
   * for CIMD clients the browser fetches the metadata document directly
   * (with hostname-only branding as the fallback). Null when resolution
   * fails (app deleted) - the consent is still real and revocable.
   */
  app: Models.App | null
}

/**
 * A set of OAuth2 consents that belong to the same client. The server keeps
 * one consent per (user, client), but DCR clients (most MCP clients)
 * register a brand-new app on every connect, so a single tool like Claude
 * Code can accumulate many consents with distinct app IDs but identical
 * registration metadata. Grouping lets users revoke them together. CIMD
 * clients are identified by their URL and never duplicate.
 */
export type AccountConnectedAppGroup = {
  /** Stable grouping key (known client id, CIMD URL, app name, or app ID). */
  key: string
  displayName: string
  /** Matched well-known client, used for logo fallback and canonical naming. */
  knownClient: KnownOAuthClient | null
  /** Representative client metadata (from the most recent consent). */
  app: Models.App | null
  /** All consents for this client, most recently authorized first. */
  grants: AccountConnectedApp[]
  latestAuthorizedAt: string
}

export type AccountConnectedAppsData = Awaited<
  ReturnType<typeof fetchAccountConnectedApps>
>

export function cimdUrlHost(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

function getGrantTime(connectedApp: AccountConnectedApp): number {
  return new Date(connectedApp.consent.$createdAt).getTime() || 0
}

function getGroupKey(
  connectedApp: AccountConnectedApp,
  knownClient: KnownOAuthClient | null,
): string {
  // CIMD clients are keyed by their URL, never by known-client id. The server
  // keeps one consent per (user, URL), so a URL never duplicates; grouping by
  // known-client id instead would merge distinct URL-form clients that happen
  // to resolve to the same canonical name (e.g. two claude.ai documents) into
  // a single "revoke all" row. Known-client matching still drives the icon and
  // display name for these rows - it just doesn't drive grouping.
  if (connectedApp.cimdUrl) return `cimd:${connectedApp.cimdUrl}`
  if (knownClient) return `client:${knownClient.id}`

  const { app, clientId } = connectedApp
  if (!app) return `app:${clientId}`

  const normalizedName = app.name.trim().toLowerCase()
  return normalizedName ? `name:${normalizedName}` : `app:${clientId}`
}

function getDisplayName(
  connectedApp: AccountConnectedApp,
  knownClient: KnownOAuthClient | null,
): string {
  return (
    connectedApp.app?.name?.trim() ||
    knownClient?.name ||
    (connectedApp.cimdUrl
      ? cimdUrlHost(connectedApp.cimdUrl)
      : connectedApp.clientId)
  )
}

export function groupConnectedApps(
  connectedApps: AccountConnectedApp[],
): AccountConnectedAppGroup[] {
  const sorted = [...connectedApps].sort(
    (a, b) => getGrantTime(b) - getGrantTime(a),
  )

  const groups = new Map<string, AccountConnectedAppGroup>()
  for (const connectedApp of sorted) {
    const knownClient = matchKnownOAuthClient(
      connectedApp.app,
      connectedApp.cimdUrl,
    )
    const key = getGroupKey(connectedApp, knownClient)
    const existing = groups.get(key)
    if (existing) {
      existing.grants.push(connectedApp)
      continue
    }

    groups.set(key, {
      key,
      displayName: getDisplayName(connectedApp, knownClient),
      knownClient,
      app: connectedApp.app,
      grants: [connectedApp],
      latestAuthorizedAt: connectedApp.consent.$createdAt,
    })
  }

  // Map preserves insertion order and consents are sorted newest-first, so
  // groups already come out ordered by most recently authorized.
  return [...groups.values()]
}

export async function fetchAccountConnectedApps(): Promise<{
  connectedApps: AccountConnectedApp[]
  groups: AccountConnectedAppGroup[]
  total: number
}> {
  const consents = await fetchAllPages((queries) =>
    sdk.forConsole.account
      .listConsents({ queries })
      .then((response) => response.consents),
  )

  const connectedApps = await Promise.all(
    consents.map(async (consent) => {
      const cimdUrl = consent.cimdUrl || null
      const clientId = consent.appId || consent.cimdUrl
      // Registered apps resolve via apps.get; CIMD URLs are fetched directly
      // from the browser (the API no longer resolves them server-side).
      const app = await getOAuth2App(clientId).catch(() => null)
      return { consent, clientId, cimdUrl, app }
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

export function consentTokensQueryOptions(consentId: string) {
  return queryOptions({
    queryKey: ['applications', 'account', consentId, 'tokens'],
    queryFn: () =>
      fetchAllPages((queries) =>
        sdk.forConsole.account
          .listConsentTokens({ consentId, queries })
          .then((response) => response.tokens),
      ),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  })
}

export function useConsentTokens(consentId: string, enabled = true) {
  return useQuery({ ...consentTokensQueryOptions(consentId), enabled })
}
