/**
 * React Query hooks for Console Variables
 *
 * Fetches console environment variables (CNAME, A, AAAA, nameservers) for domain verification.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'

/**
 * Fetch console variables. For cloud, use region-specific endpoint when provided.
 */
export async function fetchConsoleVariables(
  region?: string,
): Promise<Models.ConsoleVariables> {
  const consoleSdk = region ? sdk.forConsoleIn(region) : sdk.forConsole
  return await consoleSdk.console.variables()
}

export function consoleVariablesQueryOptions(region?: string) {
  return queryOptions({
    queryKey: ['console-variables', region ?? 'default'],
    queryFn: () => fetchConsoleVariables(region),
    staleTime: Infinity, // Load once per session - variables don't change at runtime
    gcTime: 60 * 60 * 1000, // Keep in cache for 1 hour
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useConsoleVariables(region?: string) {
  const query = useQuery(consoleVariablesQueryOptions(region))
  const vars = query.data
  return {
    ...query,
    cname: vars?._APP_DOMAIN_TARGET_CNAME,
    a: vars?._APP_DOMAIN_TARGET_A,
    aaaa: vars?._APP_DOMAIN_TARGET_AAAA,
    caa: (vars as unknown as Record<string, string | undefined>)
      ?._APP_DOMAIN_TARGET_CAA,
    nameservers: vars?._APP_DOMAINS_NAMESERVERS
      ? vars._APP_DOMAINS_NAMESERVERS
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean)
      : ([] as string[]),
  }
}
