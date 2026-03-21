/**
 * Buy Domain Wizard
 *
 * Full-screen wizard for buying a domain. Renders the configured TLD list and
 * fetches prices only for cards that enter the viewport (initial batch + scroll).
 */

import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { DomainPurchaseStatus } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Search, ArrowRight, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import {
  useDomainPrices,
  finalizeDomainPurchase,
} from '@/lib/react-query/hooks/domains'
import { BuyDomainCheckout, type BuyDomainSelection } from './BuyDomainCheckout'
import type { BuyDomainWizardSearch } from '@/routes/_public/organizations.$orgId.domains.buy'

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
  renewalPrice?: number
  renewalPeriodYears?: number
  taken?: boolean
  premium?: boolean
  isPerfectMatch?: boolean
}

export function BuyDomainWizard({
  routeSearch,
}: {
  routeSearch: BuyDomainWizardSearch
}) {
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [stage, setStage] = useState<'search' | 'checkout'>('search')
  const [checkoutSelection, setCheckoutSelection] =
    useState<BuyDomainSelection | null>(null)
  const paymentReturnHandled = useRef(false)

  const fallbackPath = `/organizations/${orgId}/domains/`

  useEffect(() => {
    if (
      routeSearch.payment !== 'purchase' ||
      !routeSearch.domainId ||
      !orgId ||
      paymentReturnHandled.current
    ) {
      return
    }
    paymentReturnHandled.current = true
    ;(async () => {
      try {
        const result = await finalizeDomainPurchase({
          domainId: routeSearch.domainId!,
          organizationId: orgId,
        })
        if (result.status === DomainPurchaseStatus.Succeeded) {
          await queryClient.refetchQueries({
            queryKey: ['domains', 'organization', orgId],
          })
          toast.success('Payment confirmed')
          navigate({
            to: '/organizations/$orgId/domains/$domainId',
            params: { orgId, domainId: routeSearch.domainId! },
            replace: true,
          })
        } else {
          toast.error('Purchase could not be completed')
          navigate({
            to: '/organizations/$orgId/domains/buy',
            params: { orgId },
            search: {},
            replace: true,
          })
        }
      } catch (e) {
        toast.error(
          e instanceof Error ? e.message : 'Failed to complete purchase',
        )
        navigate({
          to: '/organizations/$orgId/domains/buy',
          params: { orgId },
          search: {},
          replace: true,
        })
      }
    })()
  }, [routeSearch.payment, routeSearch.domainId, orgId, navigate, queryClient])

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

  // API data map: domain -> quotes from getPrice (new + renewal)
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
      const aRank = tldRank(a.tld)
      const bRank = tldRank(b.tld)
      if (aRank !== bRank) return aRank - bRank
      const aCom = a.tld === 'com' ? 1 : 0
      const bCom = b.tld === 'com' ? 1 : 0
      return bCom - aCom
    })
  }, [
    baseName,
    normalizedSearch,
    apiDataByDomain,
    typedTld,
    tldRank,
    showSuggestions,
  ])

  const handleSelectDomain = (
    full: string,
    opts?: {
      price?: number
      periodYears?: number
      premium?: boolean
      renewalPrice?: number
      renewalPeriodYears?: number
    },
  ) => {
    setCheckoutSelection({
      domain: full.toLowerCase(),
      price: opts?.price,
      periodYears: opts?.periodYears ?? 1,
      premium: opts?.premium,
      renewalPrice: opts?.renewalPrice,
      renewalPeriodYears: opts?.renewalPeriodYears,
    })
    setStage('checkout')
  }

  const hasContent = searchValue.trim().length > 0

  if (stage === 'checkout' && checkoutSelection && orgId) {
    return (
      <BuyDomainCheckout
        orgId={orgId}
        selection={checkoutSelection}
        fallbackPath={fallbackPath}
        onBackToSearch={() => {
          setStage('search')
          setCheckoutSelection(null)
        }}
      />
    )
  }

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
              <div className="grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                {suggestions.map((s) => (
                  <DomainCard
                    key={s.full}
                    suggestion={s}
                    onSelect={(full) =>
                      handleSelectDomain(full, {
                        price: s.price,
                        periodYears: s.periodYears,
                        premium: s.premium,
                        renewalPrice: s.renewalPrice,
                        renewalPeriodYears: s.renewalPeriodYears,
                      })
                    }
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

/**
 * Reserved height for registration + renewal line so loading → loaded does not shift
 * card layout.
 */
const DOMAIN_CARD_PRICE_BLOCK_MIN_H = 'min-h-[4.25rem]'

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
    renewalPrice,
    renewalPeriodYears,
    taken,
    premium,
    isPerfectMatch,
  } = suggestion
  const cardRef = useRef<HTMLButtonElement>(null)
  const hasReportedVisible = useRef(false)

  const canSelect =
    !taken &&
    priceLoaded &&
    !(premium && (price == null || price <= 0))

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
    <button
      ref={cardRef}
      type="button"
      disabled={!canSelect}
      aria-label={
        taken
          ? `${full} is taken`
          : canSelect
            ? `Add ${full} to cart`
            : `Loading price for ${full}`
      }
      onClick={() => {
        if (canSelect) onSelect(full)
      }}
      className={cn(
        'group flex h-full min-h-[8.75rem] w-full min-w-0 flex-col rounded-xl border px-4 py-3.5 text-left backdrop-blur-sm',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        'disabled:pointer-events-none',
        taken
          ? 'border-border/40 bg-muted/20 opacity-75'
          : isPerfectMatch
            ? 'border-blue-500/25 bg-blue-500/5 dark:bg-blue-500/10 ring-1 ring-blue-500/20 shadow-sm transition-all duration-150 enabled:hover:border-blue-500/35 enabled:hover:bg-blue-500/10 dark:enabled:hover:bg-blue-500/15 enabled:cursor-pointer'
            : 'border-border/60 bg-card/40 transition-all duration-150 enabled:hover:border-foreground/15 enabled:hover:bg-muted/30 enabled:cursor-pointer',
        !canSelect && !taken && 'cursor-wait',
        taken && 'cursor-not-allowed',
      )}
    >
      <div className="flex shrink-0 items-baseline gap-1.5 min-w-0">
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

      <div className="mt-2 flex min-h-0 w-full min-w-0 flex-1 flex-row items-end justify-between gap-2">
        <div
          className={cn(
            'flex min-h-0 min-w-0 flex-1 flex-col items-start justify-end gap-1 text-left',
            DOMAIN_CARD_PRICE_BLOCK_MIN_H,
          )}
        >
          {priceLoaded ? (
            taken ? (
              <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                <XCircle className="h-3.5 w-3.5 shrink-0" />
                Taken
              </span>
            ) : (
              <>
                {price != null && price > 0 ? (
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
                )}
                {renewalPrice != null && renewalPrice > 0 ? (
                  <span className="truncate text-[11px] leading-snug text-muted-foreground tabular-nums animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                    Renewal{' '}
                    <span className="font-mono font-medium text-foreground/90">
                      $
                      {renewalPrice.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                      })}
                    </span>
                    <span className="font-normal text-muted-foreground">
                      {formatPricePeriod(
                        renewalPeriodYears ?? periodYears ?? 1,
                      )}
                    </span>
                  </span>
                ) : null}
              </>
            )
          ) : (
            <div className="flex w-full flex-col items-start justify-end gap-2">
              <Skeleton className="h-4 w-20 rounded bg-muted/60" />
              <Skeleton className="h-3 w-28 rounded bg-muted/50" />
            </div>
          )}
        </div>

        {!taken ? (
          <div className="flex min-h-8 shrink-0 items-center">
            <span
              className={cn(
                'flex items-center gap-1 text-[12px] font-medium text-muted-foreground transition-colors group-hover:text-foreground',
                !canSelect && 'opacity-50 group-hover:text-muted-foreground',
              )}
            >
              Add
              <ArrowRight className="h-3.5 w-3.5" />
            </span>
          </div>
        ) : null}
      </div>
    </button>
  )
}
