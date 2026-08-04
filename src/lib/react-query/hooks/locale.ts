/**
 * React Query hooks for Locale
 *
 * Handles locale code, countries, and locale information fetching.
 */

import { useMemo } from 'react'
import { queryOptions, useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { buildCountryLookups } from '@/lib/locale/country-lookups'
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

/**
 * Query function to fetch countries
 *
 * Uses the console SDK locale service to get all available countries.
 * @returns Countries list from the API
 */
export async function fetchCountries() {
  const response = await sdk.forConsole.locale.listCountries()
  return response
}

/**
 * Query function to fetch continents
 *
 * Uses the console SDK locale service to get all available continents.
 * @returns Continents list from the API
 */
export async function fetchContinents() {
  const response = await sdk.forConsole.locale.listContinents()
  return response
}

/**
 * Query function to fetch user locale information
 *
 * Uses the console SDK locale service to get user's locale information.
 * @returns Locale information from the API
 */
export async function fetchLocale() {
  const response = await sdk.forConsole.locale.get()
  return response
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Countries list from the console locale API - shared by `useCountries`,
 * activity filters, and route loaders so the cache key stays identical.
 */
export function countriesQueryOptions() {
  return queryOptions({
    queryKey: ['countries', 'console'],
    queryFn: fetchCountries,
    staleTime: LONG_STALE_TIME,
  })
}

/**
 * Continents list from the console locale API - shared by `useContinents`
 * and any route loaders so the cache key stays identical.
 */
export function continentsQueryOptions() {
  return queryOptions({
    queryKey: ['continents', 'console'],
    queryFn: fetchContinents,
    staleTime: LONG_STALE_TIME,
  })
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

/**
 * Hook to fetch countries
 *
 * Uses the console SDK to fetch all available countries.
 */
export function useCountries() {
  return useQuery(countriesQueryOptions())
}

/**
 * Locale countries list plus code/name lookup maps for display resolution.
 * Prefer this over building a local Map from `useCountries()` in UI.
 */
export function useCountryLookups() {
  const { data, isLoading, isFetched, isError, error, refetch } = useCountries()
  const lookups = useMemo(
    () => buildCountryLookups(data?.countries),
    [data?.countries],
  )

  return {
    lookups,
    countries: data?.countries ?? [],
    isLoading,
    isFetched,
    isError,
    error,
    refetch,
  }
}

/**
 * Hook to fetch continents
 *
 * Uses the console SDK to fetch all available continents.
 */
export function useContinents() {
  return useQuery(continentsQueryOptions())
}

/**
 * Query options for the current user's locale (IP-derived country, etc.).
 */
export function localeQueryOptions() {
  return queryOptions({
    queryKey: ['locale', 'console'],
    queryFn: fetchLocale,
    staleTime: LONG_STALE_TIME,
  })
}

/**
 * Hook to fetch user locale information
 *
 * Uses the console SDK to fetch user's locale information.
 */
export function useLocale() {
  return useQuery(localeQueryOptions())
}
