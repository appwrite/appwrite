import { useCallback, useEffect, useMemo, useState } from 'react'
import { useDomainPrices } from '@/lib/react-query/hooks/domains'
import {
  DOMAIN_SEARCH_INITIAL_VISIBLE_COUNT,
  DOMAIN_SEARCH_TLDS,
} from '@/lib/domains/tlds'

export type DomainSuggestion = {
  full: string
  tld: string
  priceLoaded: boolean
  price?: number
  periodYears?: number
  renewalPrice?: number
  renewalPeriodYears?: number
  taken?: boolean
  premium?: boolean
  isPerfectMatch?: boolean
}

export type DomainSelectionQuote = {
  price?: number
  periodYears?: number
  premium?: boolean
  renewalPrice?: number
  renewalPeriodYears?: number
}

export const DOMAIN_SEARCH_DEBOUNCE_MS = 500

export function normalizeDomainSearchInput(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9.-]/g, '')
}

export function parseDomainBaseName(normalizedSearch: string): string {
  if (!normalizedSearch) return ''
  const dotIdx = normalizedSearch.indexOf('.')
  if (dotIdx > 0) return normalizedSearch.slice(0, dotIdx)
  return normalizedSearch
}

export function parseTypedDomainTld(normalizedSearch: string): string {
  if (!normalizedSearch.includes('.')) return ''
  const afterDot = normalizedSearch.slice(normalizedSearch.indexOf('.') + 1)
  return afterDot.replace(/\./g, '')
}

export function formatDomainPricePeriod(periodYears: number): string {
  if (periodYears <= 1) return '/yr'
  return `/${periodYears} yrs`
}

function tldRank(tld: string, typedTld: string): number {
  if (!typedTld) return 4
  const lower = tld.toLowerCase()
  if (lower === typedTld) return 0
  if (lower.startsWith(typedTld)) return 1
  if (lower.includes(typedTld)) return 2
  if (lower === 'com') return 3
  return 4
}

export function buildDomainSuggestions({
  baseName,
  normalizedSearch,
  apiDataByDomain,
  typedTld,
  showSuggestions,
}: {
  baseName: string
  normalizedSearch: string
  apiDataByDomain: Map<
    string,
    {
      price?: number
      available: boolean
      periodYears?: number
      premium?: boolean
      renewalPrice?: number
      renewalPeriodYears?: number
    }
  >
  typedTld: string
  showSuggestions: boolean
}): DomainSuggestion[] {
  if (!baseName || !showSuggestions) return []

  const hasExactMatch = DOMAIN_SEARCH_TLDS.some(
    (tld) => `${baseName}.${tld}` === normalizedSearch,
  )

  return DOMAIN_SEARCH_TLDS.map((tld) => {
    const full = `${baseName}.${tld}`
    const apiData = apiDataByDomain.get(full)
    const isExactMatch = full === normalizedSearch
    const isPreferredCom =
      tld === 'com' && normalizedSearch === baseName && !hasExactMatch

    return {
      full,
      tld,
      priceLoaded: apiData != null,
      price: apiData?.price ?? undefined,
      periodYears: apiData?.periodYears ?? 1,
      renewalPrice: apiData?.renewalPrice,
      renewalPeriodYears: apiData?.renewalPeriodYears,
      taken: apiData ? !apiData.available : undefined,
      premium: apiData?.premium,
      isPerfectMatch: isExactMatch || isPreferredCom,
    }
  }).sort((a, b) => {
    const aExact = a.full === normalizedSearch ? 1 : 0
    const bExact = b.full === normalizedSearch ? 1 : 0
    if (aExact !== bExact) return bExact - aExact

    const aRank = tldRank(a.tld, typedTld)
    const bRank = tldRank(b.tld, typedTld)
    if (aRank !== bRank) return aRank - bRank

    const aCom = a.tld === 'com' ? 1 : 0
    const bCom = b.tld === 'com' ? 1 : 0
    return bCom - aCom
  })
}

export function useDomainSearch(initialSearch = '') {
  const [searchValue, setSearchValue] = useState(initialSearch)
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [requestedTlds, setRequestedTlds] = useState<string[]>(() =>
    DOMAIN_SEARCH_TLDS.slice(0, DOMAIN_SEARCH_INITIAL_VISIBLE_COUNT),
  )

  const addRequestedTld = useCallback((tld: string) => {
    setRequestedTlds((prev) => (prev.includes(tld) ? prev : [...prev, tld]))
  }, [])

  const normalizedSearch = useMemo(
    () => normalizeDomainSearchInput(searchValue),
    [searchValue],
  )

  const baseName = useMemo(
    () => parseDomainBaseName(normalizedSearch),
    [normalizedSearch],
  )

  const typedTld = useMemo(
    () => parseTypedDomainTld(normalizedSearch),
    [normalizedSearch],
  )

  useEffect(() => {
    const timer = setTimeout(
      () => setDebouncedSearch(baseName),
      DOMAIN_SEARCH_DEBOUNCE_MS,
    )
    return () => clearTimeout(timer)
  }, [baseName])

  const showSuggestions =
    baseName.length >= 2 ||
    (baseName.length === 1 && normalizedSearch.includes('.'))

  const { pricesByDomain, error } = useDomainPrices(
    showSuggestions ? debouncedSearch : '',
    requestedTlds,
  )

  const apiDataByDomain = useMemo(() => {
    const map = new Map<
      string,
      {
        price?: number
        available: boolean
        periodYears?: number
        premium?: boolean
        renewalPrice?: number
        renewalPeriodYears?: number
      }
    >()
    pricesByDomain.forEach((data, domain) => {
      map.set(domain, {
        price: data.price,
        available: data.available,
        periodYears: data.periodYears ?? 1,
        premium: data.premium,
        renewalPrice: data.renewalPrice,
        renewalPeriodYears: data.renewalPeriodYears,
      })
    })
    return map
  }, [pricesByDomain])

  const suggestions = useMemo(
    () =>
      buildDomainSuggestions({
        baseName,
        normalizedSearch,
        apiDataByDomain,
        typedTld,
        showSuggestions,
      }),
    [baseName, normalizedSearch, apiDataByDomain, typedTld, showSuggestions],
  )

  return {
    searchValue,
    setSearchValue,
    suggestions,
    error,
    showSuggestions,
    hasContent: searchValue.trim().length > 0,
    baseName,
    addRequestedTld,
  }
}
