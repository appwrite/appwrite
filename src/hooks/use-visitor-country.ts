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

/**
 * Visitor country used for location-gated plans. SSR/CDN geo and cookies win over
 * async locale.get() so prerendered pricing pages never paint the wrong plan count.
 */
export function useVisitorCountryCode(): string | null {
  const [allowDomStorage, setAllowDomStorage] = useState(false)
  const { data: visitorCountry } = useQuery(visitorCountryQueryOptions())
  const { data: locale } = useQuery(localeQueryOptions())

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

  const mockCountry = allowDomStorage
    ? (loadDebugOverrides().mockLocaleCountry ?? readMockLocaleCountryCookie())
    : readMockLocaleCountryCookie()

  const requestCountry = readRequestVisitorCountry()

  return mergeVisitorCountryCode({
    mockCountry,
    requestCountry,
    visitorQueryCountry:
      typeof visitorCountry === 'string' ? visitorCountry : null,
    localeCountry: locale?.countryCode,
    storedCountry: allowDomStorage ? readStoredVisitorCountry() : null,
  })
}

export function useVisitorCountryQueryState() {
  const visitorQuery = useQuery(visitorCountryQueryOptions())
  const localeQuery = useQuery(localeQueryOptions())

  return {
    visitorFetched: visitorQuery.isFetched,
    visitorError: visitorQuery.isError,
    localeFetched: localeQuery.isFetched,
    localeError: localeQuery.isError,
  }
}

export function useVisitorCountryResolutionComplete(): boolean {
  const countryCode = useVisitorCountryCode()
  const requestCountry = readRequestVisitorCountry()
  const queries = useVisitorCountryQueryState()

  return isVisitorCountryResolutionComplete(
    countryCode,
    requestCountry,
    queries,
  )
}

/** @deprecated Prefer useVisitorCountryResolutionComplete (requires both queries). */
export function useVisitorCountryQuerySettled(): boolean {
  return useVisitorCountryResolutionComplete()
}
