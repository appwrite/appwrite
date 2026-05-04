import { queryOptions, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { LONG_STALE_TIME } from './constants'

/** Catalog rarely changes; keep in memory after UI unmounts (global default gcTime is 0). */
const PROJECT_SCOPES_GC_TIME = 60 * 60 * 1000 // 1 hour

export async function fetchConsoleProjectScopes(): Promise<Models.ConsoleKeyScopeList> {
  return await sdk.forConsole.console.listProjectScopes()
}

export function consoleProjectScopesQueryOptions() {
  return queryOptions({
    queryKey: ['console', 'project-scopes'],
    queryFn: fetchConsoleProjectScopes,
    staleTime: LONG_STALE_TIME,
    gcTime: PROJECT_SCOPES_GC_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useConsoleProjectScopes() {
  return useQuery(consoleProjectScopesQueryOptions())
}
