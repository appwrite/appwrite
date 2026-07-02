import { useEffect, useRef } from 'react'
import { ArrowRight, XCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  formatDomainPricePeriod,
  type DomainSuggestion,
} from '@/lib/domains/search'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const DOMAIN_CARD_PRICE_BLOCK_MIN_H = 'min-h-[4.25rem]'

type DomainSuggestionCardProps = {
  suggestion: DomainSuggestion
  onSelect?: (full: string) => void
  onVisible?: () => void
  disabled?: boolean
  disabledReason?: 'limit' | 'loading'
  actionLabel?: string
}

export function DomainSuggestionCard({
  suggestion,
  onSelect,
  onVisible,
  disabled = false,
  disabledReason,
  actionLabel = 'Add',
}: DomainSuggestionCardProps) {
  const t = useT()
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
    !disabled &&
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
          ? `${full} ${t('is taken')}`
          : disabled && disabledReason === 'limit'
            ? t('Domain limit reached')
            : canSelect
              ? `${t('Select')} ${full}`
              : `${t('Loading price for')} ${full}`
      }
      onClick={() => {
        if (canSelect && onSelect) onSelect(full)
      }}
      className={cn(
        'group flex h-full min-h-[8.75rem] w-full min-w-0 flex-col rounded-xl border px-4 py-3.5 text-start backdrop-blur-sm',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        'disabled:pointer-events-none',
        taken
          ? 'border-border/40 bg-muted/20 opacity-75'
          : isPerfectMatch
            ? 'border-blue-500/25 bg-blue-500/5 dark:bg-blue-500/10 ring-1 ring-blue-500/20 shadow-sm transition-all duration-150 enabled:hover:border-blue-500/35 enabled:hover:bg-blue-500/10 dark:enabled:hover:bg-blue-500/15 enabled:cursor-pointer'
            : 'border-border/60 bg-card/40 transition-all duration-150 enabled:hover:border-foreground/15 enabled:hover:bg-muted/30 enabled:cursor-pointer',
        !canSelect &&
          !taken &&
          (disabled ? 'cursor-not-allowed' : 'cursor-wait'),
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
        {premium ? (
          <Badge
            variant="info"
            className="ms-auto shrink-0 px-1.5 py-0 text-[10px] font-medium"
          >
            {t('Premium')}
          </Badge>
        ) : null}
      </div>

      <div className="mt-2 flex min-h-0 w-full min-w-0 flex-1 flex-row items-end justify-between gap-2">
        <div
          className={cn(
            'flex min-h-0 min-w-0 flex-1 flex-col items-start justify-end gap-1 text-start',
            DOMAIN_CARD_PRICE_BLOCK_MIN_H,
          )}
        >
          {priceLoaded ? (
            taken ? (
              <span className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                <XCircle className="h-3.5 w-3.5 shrink-0" />
                {t('Taken')}
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
                      {formatDomainPricePeriod(periodYears)}
                    </span>
                  </span>
                ) : (
                  <span className="text-[12px] text-muted-foreground animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                    {premium ? t('Contact for price') : '-'}
                  </span>
                )}
                {renewalPrice != null && renewalPrice > 0 ? (
                  <span className="truncate text-[11px] leading-snug text-muted-foreground tabular-nums animate-in fade-in-0 slide-in-from-bottom-1 duration-200">
                    {t('Renewal')}{' '}
                    <span className="font-mono font-medium text-foreground/90">
                      $
                      {renewalPrice.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                      })}
                    </span>
                    <span className="font-normal text-muted-foreground">
                      {formatDomainPricePeriod(
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
              {t(actionLabel)}
              <ArrowRight className="h-3.5 w-3.5" />
            </span>
          </div>
        ) : null}
      </div>
    </button>
  )
}
