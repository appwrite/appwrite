/**
 * Buy Domain Wizard
 *
 * Full-screen wizard for buying a domain. Shows all TLDs; fetches prices only
 * for those visible (initial batch + when scrolled into view).
 */

import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Search, ArrowRight, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { useDomainPrices } from '@/lib/react-query/hooks/domains'

/** Number of TLDs to fetch on first paint (above the fold) */
const INITIAL_VISIBLE_COUNT = 24

/** All TLDs shown in the wizard; prices are fetched only for visible cards */
const ALL_TLDS = [
  'com',
  'net',
  'org',
  'io',
  'co',
  'dev',
  'app',
  'ai',
  'xyz',
  'me',
  'shop',
  'store',
  'online',
  'site',
  'tech',
  'cloud',
  'blog',
  'pro',
  'top',
  'live',
  'info',
  'biz',
  'name',
  'mobi',
  'asia',
  'tel',
  'travel',
  'jobs',
  'academy',
  'agency',
  'art',
  'bar',
  'cafe',
  'camp',
  'capital',
  'care',
  'careers',
  'center',
  'cheap',
  'church',
  'city',
  'claims',
  'cleaning',
  'clinic',
  'club',
  'codes',
  'coffee',
  'community',
  'company',
  'computer',
  'construction',
  'contractors',
  'cooking',
  'cool',
  'coupons',
  'credit',
  'creditcard',
  'dental',
  'digital',
  'direct',
  'directory',
  'discount',
  'education',
  'email',
  'energy',
  'engineer',
  'engineering',
  'enterprises',
  'equipment',
  'estate',
  'events',
  'exchange',
  'expert',
  'express',
  'family',
  'finance',
  'financial',
  'fish',
  'fitness',
  'fund',
  'furniture',
  'gallery',
  'garden',
  'gifts',
  'gratis',
  'graphics',
  'guru',
  'health',
  'healthcare',
  'hockey',
  'holdings',
  'hospital',
  'house',
  'immo',
  'immobilien',
  'industries',
  'international',
  'investments',
  'law',
  'legal',
  'life',
  'loan',
  'loans',
  'maison',
  'management',
  'market',
  'marketing',
  'media',
  'memorial',
  'money',
  'movie',
  'network',
  'news',
  'ninja',
  'partners',
  'parts',
  'photo',
  'photography',
  'photos',
  'pictures',
  'pizza',
  'place',
  'plumbing',
  'plus',
  'productions',
  'properties',
  'property',
  'recipes',
  'rent',
  'repair',
  'report',
  'reviews',
  'run',
  'sale',
  'school',
  'services',
  'solutions',
  'space',
  'studio',
  'style',
  'supplies',
  'supply',
  'support',
  'surgery',
  'systems',
  'tax',
  'taxi',
  'team',
  'technology',
  'theater',
  'tips',
  'today',
  'tools',
  'training',
  'ventures',
  'video',
  'villas',
  'vision',
  'watch',
  'website',
  'wiki',
  'works',
  'world',
  'wtf',
  'zone',
]

type DomainSuggestion = {
  full: string
  tld: string
  priceLoaded: boolean
  price?: number
  /** Number of years the price covers (from getPrice periodYears; default 1) */
  periodYears?: number
  renewal?: number
  taken?: boolean
  premium?: boolean
  isPerfectMatch?: boolean
}

export function BuyDomainWizard() {
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const [searchValue, setSearchValue] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const fallbackPath = `/organizations/${orgId}/domains/`

  // Only fetch prices for TLDs that have been visible (initial batch + when scrolled into view)
  const [requestedTlds, setRequestedTlds] = useState<string[]>(() =>
    ALL_TLDS.slice(0, INITIAL_VISIBLE_COUNT),
  )
  const addRequestedTld = useCallback((tld: string) => {
    setRequestedTlds((prev) => (prev.includes(tld) ? prev : [...prev, tld]))
  }, [])

  // Normalized search (for exact match comparison)
  const normalizedSearch = useMemo(
    () =>
      searchValue
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9.-]/g, ''),
    [searchValue],
  )

  // Parse base name (e.g. "myapp" from "myapp.com" or just "myapp")
  const baseName = useMemo(() => {
    const v = normalizedSearch
    if (!v) return ''
    const dotIdx = v.indexOf('.')
    if (dotIdx > 0) return v.slice(0, dotIdx)
    return v
  }, [normalizedSearch])

  // When user typed a domain (e.g. "x.net"), the TLD part for similarity ranking
  const typedTld = useMemo(() => {
    if (!normalizedSearch.includes('.')) return ''
    const afterDot = normalizedSearch.slice(normalizedSearch.indexOf('.') + 1)
    return afterDot.replace(/\./g, '') // in case of multiple dots
  }, [normalizedSearch])

  // Debounce baseName for API calls (wait for typing to settle before fetching prices)
  const DEBOUNCE_MS = 500
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(baseName), DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [baseName])

  // Allow single-char base when user typed a full domain (e.g. "x.net" → show x.com, x.net, …)
  const showSuggestions =
    baseName.length >= 2 ||
    (baseName.length === 1 && normalizedSearch.includes('.'))

  // Only fetch prices when we show suggestions (avoids fetching for bare "x" without a TLD)
  const { pricesByDomain, error } = useDomainPrices(
    showSuggestions ? debouncedSearch : '',
    requestedTlds,
  )

  // API data map: domain -> { price, available, periodYears, premium } from getPrice
  const apiDataByDomain = useMemo(() => {
    const map = new Map<
      string,
      {
        price?: number
        available: boolean
        periodYears?: number
        premium?: boolean
      }
    >()
    pricesByDomain.forEach((data, domain) => {
      map.set(domain, {
        price: data.price,
        available: data.available,
        periodYears: data.periodYears ?? 1,
        premium: data.premium,
      })
    })
    return map
  }, [pricesByDomain])

  // Rank tier: 0 = exact match, 1 = TLD starts with typed (e.g. net → network), 2 = TLD contains typed, 3 = .com, 4 = rest
  const tldRank = useCallback(
    (tld: string) => {
      if (!typedTld) return 4
      const lower = tld.toLowerCase()
      if (lower === typedTld) return 0
      if (lower.startsWith(typedTld)) return 1
      if (lower.includes(typedTld)) return 2
      if (lower === 'com') return 3
      return 4
    },
    [typedTld],
  )

  // Optimistic: show suggestions immediately (baseName + TLDs), merge API data when it arrives
  const suggestions = useMemo((): DomainSuggestion[] => {
    if (!baseName || !showSuggestions) return []
    const hasExactMatch = ALL_TLDS.some(
      (tld) => `${baseName}.${tld}` === normalizedSearch,
    )
    return ALL_TLDS.map((tld) => {
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
        taken: apiData ? !apiData.available : undefined,
        premium: apiData?.premium,
        isPerfectMatch: isExactMatch || isPreferredCom,
      }
    }).sort((a, b) => {
      const aExact = a.full === normalizedSearch ? 1 : 0
      const bExact = b.full === normalizedSearch ? 1 : 0
      if (aExact !== bExact) return bExact - aExact
      const aRank = tldRank(a.tld)
      const bRank = tldRank(b.tld)
      if (aRank !== bRank) return aRank - bRank
      const aCom = a.tld === 'com' ? 1 : 0
      const bCom = b.tld === 'com' ? 1 : 0
      return bCom - aCom
    })
  }, [baseName, normalizedSearch, apiDataByDomain, typedTld, tldRank])

  const handleSelectDomain = (_full: string) => {
    toast.info(
      'Domain purchase will be available soon. The API integration is coming this week.',
    )
  }

  const hasContent = searchValue.trim().length > 0

  return (
    <WizardLayout
      title="Buy domain"
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      footer={
        <div className="flex gap-2 justify-end w-full">
          <Button variant="outline" onClick={() => navigate({ to: '..' })}>
            Cancel
          </Button>
        </div>
      }
    >
      <div className="flex min-h-full flex-col">
        <div
          className={cn(
            'transition-[min-height] duration-300 ease-out',
            hasContent
              ? 'min-h-0'
              : 'flex min-h-[50vh] flex-1 items-center justify-center',
          )}
        >
          <div className="w-full max-w-md space-y-2">
            <Label htmlFor="domain-search" className="text-[13px]">
              Domain name
            </Label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                id="domain-search"
                value={searchValue}
                onChange={(e) => setSearchValue(e.target.value)}
                placeholder="e.g. mycompany or mycompany.com"
                className="h-11 pl-10 font-mono text-[14px] tracking-tight bg-muted/30 border-border/80 focus:bg-background"
                autoFocus
              />
            </div>
          </div>
        </div>

        {hasContent && (
          <div className="mt-6 flex-1 min-h-0">
            {suggestions.length > 0 ? (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {suggestions.map((s) => (
                  <DomainCard
                    key={s.full}
                    suggestion={s}
                    onSelect={handleSelectDomain}
                    onVisible={() => addRequestedTld(s.tld)}
                  />
                ))}
              </div>
            ) : baseName.length > 0 && baseName.length < 2 ? (
              <p className="text-[12px] text-muted-foreground">
                Type at least 2 characters to see suggestions
              </p>
            ) : baseName.length >= 2 && error ? (
              <p className="text-[12px] text-destructive">
                Failed to load domain prices. Please try again.
              </p>
            ) : null}
          </div>
        )}
      </div>
    </WizardLayout>
  )
}

function formatPricePeriod(periodYears: number): string {
  if (periodYears <= 1) return '/yr'
  return `/${periodYears} yrs`
}

function DomainCard({
  suggestion,
  onSelect,
  onVisible,
}: {
  suggestion: DomainSuggestion
  onSelect: (full: string) => void
  onVisible?: () => void
}) {
  const {
    full,
    tld,
    priceLoaded,
    price,
    periodYears = 1,
    taken,
    premium,
    isPerfectMatch,
  } = suggestion
  const cardRef = useRef<HTMLDivElement>(null)
  const hasReportedVisible = useRef(false)

  useEffect(() => {
    if (!onVisible || hasReportedVisible.current) return
    const el = cardRef.current
    if (!el) return
    const observer = new IntersectionObserver(
      (entries) => {
        if (hasReportedVisible.current) return
        if (entries[0]?.isIntersecting) {
          hasReportedVisible.current = true
          onVisible()
        }
      },
      { rootMargin: '100px', threshold: 0 },
    )
    observer.observe(el)
    return () => observer.disconnect()
  }, [onVisible])

  return (
    <div
      ref={cardRef}
      className={cn(
        'group flex min-w-0 flex-col gap-3 rounded-xl border px-4 py-3.5 backdrop-blur-sm',
        taken
          ? 'border-border/40 bg-muted/20 opacity-75'
          : isPerfectMatch
            ? 'border-blue-500/25 bg-blue-500/5 dark:bg-blue-500/10 ring-1 ring-blue-500/20 shadow-sm transition-all duration-150 hover:border-blue-500/35 hover:bg-blue-500/10 dark:hover:bg-blue-500/15'
            : 'border-border/60 bg-card/40 transition-all duration-150 hover:border-foreground/15 hover:bg-muted/30',
      )}
    >
      <div className="flex items-baseline gap-1.5 min-w-0">
        <span
          className={cn(
            'font-mono text-[14px] font-medium tracking-tight truncate',
            taken ? 'text-muted-foreground line-through' : 'text-foreground',
          )}
        >
          {full.split('.')[0]}
        </span>
        <span
          className={cn(
            'shrink-0 font-mono text-[12px]',
            taken ? 'text-muted-foreground/70' : 'text-muted-foreground/90',
          )}
        >
          .{tld}
        </span>
        {premium && (
          <Badge
            variant="info"
            className="ml-auto shrink-0 px-1.5 py-0 text-[10px] font-medium"
          >
            Premium
          </Badge>
        )}
      </div>
      <div className="flex min-w-0 items-center justify-between gap-2 min-h-8">
        <div className="min-h-5 flex min-w-0 items-center overflow-hidden">
          {priceLoaded ? (
            taken ? (
              <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                <XCircle className="h-3.5 w-3.5" />
                Taken
              </span>
            ) : price != null && price > 0 ? (
              <span className="truncate font-mono text-[13px] font-semibold tabular-nums text-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                $
                {price.toLocaleString('en-US', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}
                <span className="font-normal text-[11px] text-muted-foreground">
                  {formatPricePeriod(periodYears)}
                </span>
              </span>
            ) : (
              <span className="text-[12px] text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                {premium ? 'Contact for price' : '—'}
              </span>
            )
          ) : (
            <Skeleton className="h-4 w-14 rounded bg-muted/60" />
          )}
        </div>
        {!taken && (
          <Button
            size="sm"
            variant="ghost"
            className="h-8 shrink-0 gap-1 text-[12px] -mr-1 opacity-70 group-hover:opacity-100 transition-opacity"
            onClick={() => onSelect(full)}
            disabled={!priceLoaded}
          >
            Add
            <ArrowRight className="h-3.5 w-3.5" />
          </Button>
        )}
      </div>
    </div>
  )
}
