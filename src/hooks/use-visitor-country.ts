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
import { normalizeCountryCode } from '@/lib/pricing/start-plan'
import { getSsrVisitorCountry } from '@/lib/ssr-visitor-country'

function readRequestVisitorCountry(): string | null {
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
 * Visitor country used for location-gated plans. First paint uses dehydratable
 * query data, SSR/CDN geo, and cookies (including the debug mock cookie).
 * localStorage/sessionStorage join after mount so SSR HTML can hydrate.
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

  return (
    mockCountry ??
    normalizeCountryCode(
      typeof visitorCountry === 'string' ? visitorCountry : null,
    ) ??
    normalizeCountryCode(locale?.countryCode) ??
    (allowDomStorage ? readStoredVisitorCountry() : null) ??
    readRequestVisitorCountry()
  )
}

export function useVisitorCountryQuerySettled(): boolean {
  const visitorQuery = useQuery(visitorCountryQueryOptions())
  const localeQuery = useQuery(localeQueryOptions())
  return (
    visitorQuery.isFetched ||
    visitorQuery.isError ||
    localeQuery.isFetched ||
    localeQuery.isError
  )
}
