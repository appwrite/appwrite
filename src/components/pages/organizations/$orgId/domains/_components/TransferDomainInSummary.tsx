import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import type { DomainTransferPriceQuote } from '@/lib/react-query/hooks/domains'
import { useT } from '@/lib/i18n/translate'

function formatUsd(amount: number) {
  return amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function renewalPeriodSuffix(periodYears: number) {
  if (periodYears <= 1) return '/yr'
  return `/${periodYears} yrs`
}

type TransferDomainInSummaryProps = {
  /** Normalized domain used for the quote (empty if none yet). */
  quotedDomain: string
  isPriceLoading: boolean
  priceError: boolean
  quote: DomainTransferPriceQuote | undefined
}

/**
 * Right-column summary for the transfer-in wizard (matches buy checkout order summary).
 */
export function TransferDomainInSummary({
  quotedDomain,
  isPriceLoading,
  priceError,
  quote,
}: TransferDomainInSummaryProps) {
  const t = useT()
  const hasDomain = quotedDomain.length > 0
  const hasTransferPrice = quote != null && quote.price > 0
  const periodYears = quote?.periodYears ?? 1
  const periodLabel =
    periodYears === 1 ? t('1 year') : `${periodYears} ${t('years')}`
  const renewalYears = quote?.renewalPeriodYears ?? quote?.periodYears ?? 1
  const hasRenewal =
    quote != null && quote.renewalPrice != null && quote.renewalPrice > 0

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      <div className="border-b border-border bg-muted/30 px-5 py-3.5">
        <h3 className="text-[13px] font-semibold tracking-tight text-foreground">
          {t('Transfer summary')}
        </h3>
        <p className="mt-0.5 text-[12px] text-muted-foreground leading-snug">
          {t('Estimated fees from the registry before you pay.')}
        </p>
      </div>

      <div className="px-5 py-5">
        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
          {t('Domain')}
        </p>
        {hasDomain ? (
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <p className="min-w-0 break-all font-mono text-[15px] font-medium leading-snug tracking-tight text-foreground">
              {quotedDomain}
            </p>
            {quote?.premium ? (
              <Badge
                variant="info"
                className="shrink-0 px-1.5 py-0 text-[10px] font-medium"
              >
                {t('Premium')}
              </Badge>
            ) : null}
          </div>
        ) : (
          <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
            {t('Enter your full domain name to load a price quote.')}
          </p>
        )}

        <div className="mt-6 space-y-3 border-t border-border pt-5">
          {!hasDomain ? null : isPriceLoading ? (
            <div className="space-y-3">
              <Skeleton className="h-12 w-full rounded-md bg-muted/60" />
              <Skeleton className="h-10 w-full rounded-md bg-muted/50" />
            </div>
          ) : priceError ? (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {t(
                "We couldn't load a quote for this domain. You can still continue - the amount due is confirmed when you complete payment.",
              )}
            </p>
          ) : hasTransferPrice ? (
            <>
              <div className="flex items-start justify-between gap-4 text-[13px] leading-snug">
                <div className="min-w-0">
                  <p className="font-medium text-foreground">
                    {t('Transfer')}
                  </p>
                  <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {t('Registry transfer')} · {periodLabel}
                  </p>
                </div>
                <p className="shrink-0 tabular-nums font-semibold text-foreground">
                  ${formatUsd(quote.price)}
                </p>
              </div>
              {hasRenewal ? (
                <div className="flex items-start justify-between gap-4 text-[13px] leading-snug">
                  <div className="min-w-0">
                    <p className="font-medium text-foreground">
                      {t('Renewal')}
                    </p>
                    <p className="mt-0.5 text-[12px] text-muted-foreground">
                      {t('When you renew after the initial term')}
                    </p>
                  </div>
                  <p className="shrink-0 tabular-nums font-semibold text-foreground">
                    ${formatUsd(quote.renewalPrice!)}
                    <span className="ms-1 text-[12px] font-normal text-muted-foreground">
                      {renewalPeriodSuffix(renewalYears)}
                    </span>
                  </p>
                </div>
              ) : null}
            </>
          ) : quote?.premium ? (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {t(
                'This name is listed as premium. Final transfer pricing is confirmed when you submit payment.',
              )}
            </p>
          ) : (
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {t('Pricing is confirmed when you submit payment.')}
            </p>
          )}
        </div>

        {hasDomain && hasTransferPrice ? (
          <div className="mt-6 flex items-baseline justify-between gap-4 border-t border-border pt-5">
            <p className="text-[13px] font-semibold text-foreground">
              {t('Total due today')}
            </p>
            <p className="text-[22px] font-semibold tabular-nums tracking-tight text-foreground">
              ${formatUsd(quote.price)}
            </p>
          </div>
        ) : null}
      </div>
    </div>
  )
}
