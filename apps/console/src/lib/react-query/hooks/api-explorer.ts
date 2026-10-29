/**
 * React Query hooks for the project API explorer.
 */

import { queryOptions, useQuery } from '@tanstack/react-query'
import {
  loadParsedApiSpec,
  type ApiSpecPlatform,
} from '@/lib/api-explorer'

export async function fetchParsedApiSpec(platform: ApiSpecPlatform) {
  return loadParsedApiSpec(platform)
}

/**
 * Parsed OpenAPI spec for the API explorer. Shared by route loaders and
 * `ApiExplorer` so navigation can block until the spec is ready.
 */
export function apiExplorerSpecQueryOptions(platform: ApiSpecPlatform) {
  return queryOptions({
    queryKey: ['api-explorer', 'spec', platform],
    queryFn: () => fetchParsedApiSpec(platform),
    staleTime: Infinity,
    // Default gcTime is 0 in root provider; keep prefetched specs until the
    // explorer mounts so loader cache is not garbage-collected mid-navigation.
    gcTime: Infinity,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useApiExplorerSpec(platform: ApiSpecPlatform) {
  return useQuery(apiExplorerSpecQueryOptions(platform))
}
