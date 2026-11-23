import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  loadDebugOverrides,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import {
  readMockLocaleCountryCookie,
  readSsrVisitorCountryFromWindow,
  readStoredVisitorCountry,
  readVisitorCountryCookie,
  subscribeStoredVisitorCountry,
} from '@/lib/locale/visitor-country'
import {
  localeQueryOptions,
  visitorCountryQueryOptions,
} from '@/lib/react-query/hooks/locale'
import { usePrefetchedLocale } from '@/lib/locale/prefetch-locale'
import {
  isVisitorCountryResolutionComplete,
  mergeVisitorCountryCode,
} from '@/lib/pricing/visitor-country-resolution'
import { getSsrVisitorCountry } from '@/lib/ssr-visitor-country'

export function readRequestVisitorCountry(): string | null {
  if (typeof window === 'undefined') {
    return getSsrVisitorCountry()
  }
  return (
    readMockLocaleCountryCookie() ??
    readVisitorCountryCookie() ??
    readSsrVisitorCountryFromWindow()
  )
}

function useMockVisitorCountry(): string | null {
  const [allowDomStorage, setAllowDomStorage] = useState(false)

  useEffect(() => {
    setAllowDomStorage(true)
    const bumpAllow = () => setAllowDomStorage(true)
    const unsubStore = subscribeStoredVisitorCountry(bumpAllow)
    const unsubDebug = subscribeToDebugOverrides(() => {
      setAllowDomStorage(true)
    })
    return () => {
      unsubStore()
      unsubDebug()
    }
  }, [])

  return allowDomStorage
    ? (loadDebugOverrides().mockLocaleCountry ?? readMockLocaleCountryCookie())
    : readMockLocaleCountryCookie()
}

/** False on SSR and the hydrating client paint so we never mismatch the empty grid. */
function useHasClientPainted(): boolean {
  const [painted, setPainted] = useState(false)
  useEffect(() => {
    setPainted(true)
  }, [])
  return painted
}

function useVisitorCountryQueries() {
  const localeQuery = useQuery(localeQueryOptions())
  const localeFailed =
    localeQuery.isError && !localeQuery.isFetching && !localeQuery.isSuccess
  const visitorQuery = useQuery({
    ...visitorCountryQueryOptions(),
    enabled: typeof window !== 'undefined' && localeFailed,
  })

  return { localeQuery, visitorQuery, localeFailed }
}

/**
 * Visitor country used for location-gated plans. locale.get() wins over CDN/cookie
 * geo so Indian VPN visitors are not stuck on a stale Cloudflare country.
 *
 * CDN/cookie geo is applied only after locale.get() has failed, so we never paint
 * the 3-plan grid from Cloudflare and then swap to the Indian 4-plan grid.
 */
export function useVisitorCountryCode(): string | null {
  const [allowDomStorage, setAllowDomStorage] = useState(false)
  const { localeQuery, visitorQuery, localeFailed } = useVisitorCountryQueries()
  const mockCountry = useMockVisitorCountry()
  const prefetchedLocale = usePrefetchedLocale()

  useEffect(() => {
    setAllowDomStorage(true)
  }, [])

  const visitorCountry =
    typeof visitorQuery.data === 'string' ? visitorQuery.data : null

  return mergeVisitorCountryCode({
    mockCountry,
    localeCountry:
      localeQuery.isSuccess
        ? localeQuery.data?.countryCode
        : prefetchedLocale?.countryCode,
    visitorQueryCountry: localeFailed ? visitorCountry : null,
    requestCountry: localeFailed ? readRequestVisitorCountry() : null,
    storedCountry:
      localeFailed && allowDomStorage ? readStoredVisitorCountry() : null,
  })
}

export function useVisitorCountryQueryState() {
  const { localeQuery, visitorQuery } = useVisitorCountryQueries()
  const prefetchedLocale = usePrefetchedLocale()

  return {
    visitorFetched: visitorQuery.isFetched,
    visitorError: visitorQuery.isError,
    visitorFetching: visitorQuery.isFetching,
    localeSuccess: localeQuery.isSuccess || !!prefetchedLocale,
    localeError: localeQuery.isError,
    localeFetching: localeQuery.isFetching && !prefetchedLocale,
  }
}

export function useVisitorCountryResolutionComplete(): boolean {
  const clientPainted = useHasClientPainted()
  const countryCode = useVisitorCountryCode()
  const requestCountry = readRequestVisitorCountry()
  const queries = useVisitorCountryQueryState()
  const mockCountry = useMockVisitorCountry()

  if (!clientPainted) return false

  return isVisitorCountryResolutionComplete(countryCode, requestCountry, {
    ...queries,
    mockCountry,
  })
}

/** @deprecated Prefer useVisitorCountryResolutionComplete (requires both queries). */
export function useVisitorCountryQuerySettled(): boolean {
  return useVisitorCountryResolutionComplete()
}
