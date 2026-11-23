/**
 * React Query hooks for Regions
 *
 * Handles region fetching from the console service.
 */

import { useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { LONG_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch available regions from the console SDK
 *
 * @returns Regions list response from the API
 */
export async function fetchRegions() {
  const response = await sdk.forConsole.console.listRegions()

  // Response is Models.ConsoleRegionList which has a regions property
  return {
    regions: response.regions || [],
  }
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch available regions
 *
 * @returns Regions list with loading state
 */
export function useRegions(enabled: boolean = true) {
  const {
    data: regionsData,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['regions'],
    queryFn: fetchRegions,
    enabled,
    staleTime: LONG_STALE_TIME, // Regions don't change often
    retry: false, // Don't retry on failure - if it fails, let it fail
    refetchOnWindowFocus: false, // Don't refetch when window regains focus
    refetchOnMount: false, // Don't refetch on mount if data exists
    refetchOnReconnect: false, // Don't refetch on reconnect
  })

  return {
    regions: regionsData?.regions || [],
    isLoading,
    error,
    refetch,
  }
}
