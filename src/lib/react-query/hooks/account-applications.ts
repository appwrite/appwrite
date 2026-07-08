import { queryOptions, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  getOAuth2AppIdFromIdentity,
  getOAuth2GrantIdentities,
} from '@/lib/account-oauth2-grants'
import { DEFAULT_STALE_TIME } from './constants'
import { fetchAccountIdentities } from './auth'

export type AccountConnectedApp = {
  identity: Models.Identity
  appId: string
  app: Models.App | null
}

export type AccountConnectedAppsData = Awaited<
  ReturnType<typeof fetchAccountConnectedApps>
>

export async function fetchAccountConnectedApps(): Promise<{
  connectedApps: AccountConnectedApp[]
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

  return {
    connectedApps,
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
