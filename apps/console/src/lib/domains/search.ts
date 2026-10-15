import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useDomainPrices } from '@/lib/react-query/hooks/domains'
import { DOMAIN_SEARCH_TLDS } from '@/lib/domains/tlds'

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

export const DOMAIN_SEARCH_DEBOUNCE_MS = 200
/** Non-priority TLDs to fetch as soon as a search is ready (about four grid rows). */
export const DOMAIN_SEARCH_INITIAL_PRELOAD = 16
/** Extra TLDs in display order to fetch ahead of the scroll position. */
export const DOMAIN_SEARCH_PRELOAD_AHEAD = 20
/** IntersectionObserver margin so prices load before cards enter the viewport. */
export const DOMAIN_SEARCH_VISIBLE_ROOT_MARGIN = '480px'

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

export function useDomainSearch(
  initialSearch = '',
  onSearchValueChange?: (value: string) => void,
) {
  const [localSearchValue, setLocalSearchValue] = useState(initialSearch)
  // Public search is controlled by the route so shared links and browser
  // history restore the input. Purchase wizards keep their local search state.
  const searchValue = onSearchValueChange ? initialSearch : localSearchValue
  const setSearchValue = onSearchValueChange ?? setLocalSearchValue
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [requested, setRequested] = useState<{
    baseName: string
    tlds: string[]
  }>({
    baseName: '',
    tlds: [],
  })

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

  const priorityTlds = useMemo(
    () =>
      DOMAIN_SEARCH_TLDS.some((tld) => tld === typedTld) &&
      normalizedSearch === `${baseName}.${typedTld}`
        ? [typedTld]
        : ['com', 'dev', 'app', 'io'],
    [baseName, normalizedSearch, typedTld],
  )

  const showSuggestions =
    baseName.length >= 2 ||
    (baseName.length === 1 && normalizedSearch.includes('.'))

  const displayOrderTlds = useMemo(() => {
    if (!baseName || !showSuggestions) return []
    return buildDomainSuggestions({
      baseName,
      normalizedSearch,
      apiDataByDomain: new Map(),
      typedTld,
      showSuggestions: true,
    }).map((suggestion) => suggestion.tld)
  }, [baseName, normalizedSearch, typedTld, showSuggestions])

  const priorityTldSet = useMemo(
    () => new Set(priorityTlds),
    [priorityTlds],
  )

  const mergeRequestedThroughIndex = useCallback(
    (throughIndex: number) => {
      if (throughIndex < 0 || displayOrderTlds.length === 0) return
      const capped = Math.min(throughIndex, displayOrderTlds.length - 1)
      const tldsToAdd = displayOrderTlds
        .slice(0, capped + 1)
        .filter((tld) => !priorityTldSet.has(tld))
      if (tldsToAdd.length === 0) return
      setRequested((prev) => {
        const existing =
          prev.baseName === baseName ? new Set(prev.tlds) : new Set<string>()
        let changed = prev.baseName !== baseName
        for (const tld of tldsToAdd) {
          if (!existing.has(tld)) {
            existing.add(tld)
            changed = true
          }
        }
        if (!changed) return prev
        return { baseName, tlds: [...existing] }
      })
    },
    [baseName, displayOrderTlds, priorityTldSet],
  )

  const indexThroughNonPriorityPreload = useCallback(
    (nonPriorityCount: number) => {
      if (nonPriorityCount <= 0) return -1
      let seen = 0
      for (let i = 0; i < displayOrderTlds.length; i++) {
        if (!priorityTldSet.has(displayOrderTlds[i])) seen++
        if (seen >= nonPriorityCount) return i
      }
      return displayOrderTlds.length - 1
    },
    [displayOrderTlds, priorityTldSet],
  )

  const addRequestedTld = useCallback(
    (tld: string) => {
      const index = displayOrderTlds.indexOf(tld)
      const through =
        index >= 0
          ? Math.min(
              displayOrderTlds.length - 1,
              index + DOMAIN_SEARCH_PRELOAD_AHEAD,
            )
          : index
      mergeRequestedThroughIndex(through)
    },
    [displayOrderTlds, mergeRequestedThroughIndex],
  )

  useEffect(() => {
    const timer = setTimeout(
      () => setDebouncedSearch(normalizedSearch),
      DOMAIN_SEARCH_DEBOUNCE_MS,
    )
    return () => clearTimeout(timer)
  }, [normalizedSearch])

  useEffect(() => {
    setRequested({ baseName, tlds: [] })
  }, [baseName])

  const searchReady =
    showSuggestions &&
    debouncedSearch === normalizedSearch &&
    baseName.length > 0

  useEffect(() => {
    if (!searchReady) return
    const through = indexThroughNonPriorityPreload(
      DOMAIN_SEARCH_INITIAL_PRELOAD + DOMAIN_SEARCH_PRELOAD_AHEAD,
    )
    mergeRequestedThroughIndex(through)
  }, [
    searchReady,
    baseName,
    displayOrderTlds,
    indexThroughNonPriorityPreload,
    mergeRequestedThroughIndex,
  ])

  const submitSearch = useCallback(() => {
    setDebouncedSearch(normalizedSearch)
  }, [normalizedSearch])

  const { pricesByDomain, error, retry, isRetrying } = useDomainPrices(
    showSuggestions && debouncedSearch === normalizedSearch ? baseName : '',
    requested.baseName === baseName ? requested.tlds : [],
    priorityTlds,
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
    submitSearch,
    suggestions,
    error,
    retry,
    isRetrying,
    showSuggestions,
    hasContent: searchValue.trim().length > 0,
    baseName,
    addRequestedTld,
  }
}
