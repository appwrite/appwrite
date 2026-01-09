/**
 * React Query hooks for Locale
 * 
 * Handles locale code fetching.
 */

import { useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { LONG_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch locale codes
 * 
 * Uses the console SDK locale service to get all available locale codes.
 * @returns Locale codes from the API
 */
export async function fetchLocaleCodes() {
  const response = await sdk.forConsole.locale.listCodes()
  return response
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch locale codes
 * 
 * Uses the console SDK to fetch all available locale codes.
 */
export function useLocaleCodes() {
  return useQuery({
    queryKey: ['localeCodes', 'console'],
    queryFn: fetchLocaleCodes,
    staleTime: LONG_STALE_TIME, // Locale codes don't change often
  })
}

