/**
 * Buy Domain Wizard
 *
 * Full-screen wizard for buying a domain. Optimistically shows popular TLDs as
 * user types (no backend call). Prices will load with animation once API is connected.
 */

import { useState, useEffect, useMemo, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Search, ArrowRight, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'

// Most popular TLDs - optimistic display without backend
const POPULAR_TLDS = [
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
  'software',
  'digital',
  'world',
  'info',
  'biz',
  'us',
  'uk',
  'eu',
  'de',
  'fr',
  'es',
  'it',
  'nl',
  'in',
  'au',
  'ca',
  'tv',
  'fm',
  'cc',
  'ws',
  'blog',
  'studio',
  'design',
  'agency',
  'live',
  'today',
  'life',
  'team',
  'company',
  'solutions',
  'work',
  'email',
  'cool',
  'guru',
  'expert',
  'zone',
  'space',
  'link',
  'click',
  'name',
  'pro',
  'mobi',
  'top',
  'win',
  'bet',
  'web',
  'host',
  'network',
  'systems',
  'codes',
  'build',
  'run',
  'tools',
  'ventures',
  'capital',
  'media',
  'news',
  'social',
  'club',
  'community',
  'directory',
  'land',
  'house',
  'properties',
  'cars',
  'bike',
  'fitness',
  'health',
  'dog',
  'pet',
  'photo',
  'video',
  'music',
  'art',
  'gallery',
  'education',
  'academy',
  'school',
  'university',
  'law',
  'legal',
  'tax',
  'finance',
  'money',
  'cash',
  'wiki',
]

// Default mock price when TLD not in map (will be replaced by API)
const DEFAULT_PRICE = { price: 12.99, renewal: 14.99 }

// Mock prices (will be replaced by API)
const MOCK_PRICES: Record<string, { price: number; renewal: number }> = {
  com: { price: 12.99, renewal: 14.99 },
  net: { price: 11.99, renewal: 13.99 },
  org: { price: 9.99, renewal: 11.99 },
  io: { price: 39.99, renewal: 44.99 },
  co: { price: 29.99, renewal: 34.99 },
  dev: { price: 14.99, renewal: 16.99 },
  app: { price: 14.99, renewal: 16.99 },
  ai: { price: 89.99, renewal: 99.99 },
  xyz: { price: 1.99, renewal: 14.99 },
  me: { price: 19.99, renewal: 22.99 },
  shop: { price: 3.99, renewal: 19.99 },
  store: { price: 3.99, renewal: 19.99 },
  online: { price: 2.99, renewal: 14.99 },
  site: { price: 2.99, renewal: 14.99 },
  tech: { price: 4.99, renewal: 24.99 },
  cloud: { price: 24.99, renewal: 29.99 },
  software: { price: 19.99, renewal: 24.99 },
  digital: { price: 14.99, renewal: 19.99 },
  world: { price: 14.99, renewal: 19.99 },
  info: { price: 4.99, renewal: 14.99 },
  biz: { price: 12.99, renewal: 14.99 },
  us: { price: 9.99, renewal: 12.99 },
  uk: { price: 9.99, renewal: 12.99 },
  eu: { price: 12.99, renewal: 16.99 },
  de: { price: 9.99, renewal: 12.99 },
  fr: { price: 9.99, renewal: 12.99 },
  tv: { price: 29.99, renewal: 34.99 },
  fm: { price: 19.99, renewal: 24.99 },
  blog: { price: 14.99, renewal: 19.99 },
  pro: { price: 14.99, renewal: 19.99 },
  top: { price: 2.99, renewal: 14.99 },
  live: { price: 14.99, renewal: 19.99 },
}

type DomainSuggestion = {
  full: string
  tld: string
  priceLoaded: boolean
  price?: number
  renewal?: number
  taken?: boolean
  isPerfectMatch?: boolean
}

export function BuyDomainWizard() {
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const [searchValue, setSearchValue] = useState('')
  const [priceLoadedFor, setPriceLoadedFor] = useState<Set<string>>(new Set())

  const fallbackPath = `/organizations/${orgId}/domains/`

  // Normalized search (for exact match comparison)
  const normalizedSearch = useMemo(
    () => searchValue.trim().toLowerCase().replace(/[^a-z0-9.-]/g, ''),
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

  // Mock: domains that appear as taken (will be replaced by API)
  const MOCK_TAKEN = new Set(['com', 'net'])

  // Optimistic suggestions: base + popular TLDs (instant, no backend)
  const suggestions = useMemo((): DomainSuggestion[] => {
    if (!baseName || baseName.length < 2) return []
    const hasExactMatch = POPULAR_TLDS.some((tld) => `${baseName}.${tld}` === normalizedSearch)
    const items = POPULAR_TLDS.map((tld) => {
      const full = `${baseName}.${tld}`
      const mock = MOCK_PRICES[tld] ?? DEFAULT_PRICE
      const loaded = priceLoadedFor.has(full)
      const taken = loaded && MOCK_TAKEN.has(tld)
      const isExactMatch = full === normalizedSearch
      const isPreferredCom = tld === 'com' && normalizedSearch === baseName && !hasExactMatch
      const isPerfectMatch = isExactMatch || isPreferredCom
      return {
        full,
        tld,
        priceLoaded: loaded,
        price: loaded && mock && !taken ? mock.price : undefined,
        renewal: loaded && mock ? mock.renewal : undefined,
        taken,
        isPerfectMatch,
      }
    })
    return [...items].sort((a, b) => {
      const aExact = a.full === normalizedSearch ? 1 : 0
      const bExact = b.full === normalizedSearch ? 1 : 0
      if (aExact !== bExact) return bExact - aExact
      const aCom = a.tld === 'com' ? 1 : 0
      const bCom = b.tld === 'com' ? 1 : 0
      return bCom - aCom
    })
  }, [baseName, normalizedSearch, priceLoadedFor])

  // Simulate price loading (will be replaced by API call)
  const loadPricesForCurrent = useCallback(() => {
    if (!baseName) return
    const timer = setTimeout(() => {
      setPriceLoadedFor((prev) => {
        const next = new Set(prev)
        POPULAR_TLDS.forEach((tld) => next.add(`${baseName}.${tld}`))
        return next
      })
    }, 300)
    return () => clearTimeout(timer)
  }, [baseName])

  useEffect(() => {
    if (!baseName) return
    return loadPricesForCurrent()
  }, [baseName, loadPricesForCurrent])

  const handleSelectDomain = (full: string) => {
    toast.info(
      'Domain purchase will be available soon. The API integration is coming this week.',
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
          <Button
            variant="outline"
            onClick={() =>
              navigate({ to: '/organizations/$orgId/domains/', params: { orgId: orgId! } })
            }
          >
            Cancel
          </Button>
        </div>
      }
    >
      <div className="space-y-6">
        <div className="space-y-2">
          <Label htmlFor="domain-search" className="text-[13px]">
            Domain name
          </Label>
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id="domain-search"
              value={searchValue}
              onChange={(e) => setSearchValue(e.target.value)}
              placeholder="myawesomeproject.com"
              className="h-11 pl-10 font-mono text-[14px] tracking-tight bg-muted/30 border-border/80 focus:bg-background"
              autoFocus
            />
          </div>
        </div>

        {suggestions.length > 0 ? (
          <div className="grid gap-3 grid-cols-3 md:grid-cols-4">
            {suggestions.map((s) => (
              <DomainCard
                key={s.full}
                suggestion={s}
                onSelect={handleSelectDomain}
              />
            ))}
          </div>
        ) : baseName.length > 0 && baseName.length < 2 ? (
          <p className="text-[12px] text-muted-foreground">
            Type at least 2 characters to see suggestions
          </p>
        ) : null}
      </div>
    </WizardLayout>
  )
}

function DomainCard({
  suggestion,
  onSelect,
}: {
  suggestion: DomainSuggestion
  onSelect: (full: string) => void
}) {
  const { full, tld, priceLoaded, price, taken, isPerfectMatch } = suggestion

  return (
    <div
      className={cn(
        'group flex flex-col gap-3 rounded-xl border px-4 py-3.5 backdrop-blur-sm',
        taken
          ? 'border-border/40 bg-muted/20 opacity-75'
          : isPerfectMatch
            ? 'border-blue-500/25 bg-blue-500/5 dark:bg-blue-500/10 ring-1 ring-blue-500/20 shadow-sm transition-all duration-150 hover:border-blue-500/35 hover:bg-blue-500/10 dark:hover:bg-blue-500/15'
            : 'border-border/60 bg-card/40 transition-all duration-150 hover:border-foreground/15 hover:bg-muted/30',
      )}
    >
      <div className="flex items-baseline gap-1 min-w-0">
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
      </div>
      <div className="flex items-center justify-between gap-3">
        {priceLoaded ? (
          taken ? (
            <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
              <XCircle className="h-3.5 w-3.5" />
              Taken
            </span>
          ) : price != null ? (
            <span className="font-mono text-[13px] font-semibold tabular-nums text-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
              ${price.toFixed(2)}
              <span className="font-normal text-[11px] text-muted-foreground">
                /yr
              </span>
            </span>
          ) : null
        ) : (
          <Skeleton className="h-4 w-14 rounded bg-muted/60" />
        )}
        {!taken && (
          <Button
            size="sm"
            variant="ghost"
            className="h-8 gap-1 text-[12px] shrink-0 -mr-1 opacity-70 group-hover:opacity-100 transition-opacity"
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
