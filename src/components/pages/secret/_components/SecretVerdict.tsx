import { ArrowRight } from 'lucide-react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonMark,
  ComparisonSection,
  CompetitorMonogram,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { SECRET_SUSPECTS, SECRET_VERDICT_ROWS } from '@/lib/campaigns/secret/content'
import type { SecretVariantContent, SecretVerdictCell } from '@/lib/campaigns/secret/content'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { SuspectLabel, TryAppwriteButton } from './SecretParts'

const APPWRITE_COLUMN_TINT = 'bg-[rgb(var(--tone-rgb)/0.05)]'

const LINK_CLASS =
  'link-unstyled group inline-flex items-center gap-2 text-[14px] font-medium text-foreground transition-colors hover:text-[var(--tone-ink)]'

function LinkArrow() {
  return (
    <ArrowRight
      className="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5"
      aria-hidden
    />
  )
}

function VerdictCell({ cell, side }: { cell: SecretVerdictCell; side: 'appwrite' | 'competitor' }) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex min-w-0 flex-col justify-center gap-1 px-3 pb-4 pt-2 sm:px-5 sm:py-4',
        side === 'appwrite' && APPWRITE_COLUMN_TINT,
      )}
    >
      <ComparisonMark value={cell.value} side={side} />
      {cell.note ? <p className="text-[11px] leading-4 text-muted-foreground">{t(cell.note)}</p> : null}
    </div>
  )
}

/** Side by side on what decides a backend, then the honest exceptions and the full comparisons. */
export function SecretVerdict({ content }: { content: SecretVariantContent }) {
  const t = useT()
  // Phones: the row label spans the row and the values share it. Wider: label column plus one per platform.
  const gridClass =
    content.suspects.length > 1
      ? 'grid-cols-3 sm:grid-cols-[minmax(0,1.25fr)_repeat(3,minmax(0,1fr))]'
      : 'grid-cols-2 sm:grid-cols-[minmax(0,1.25fr)_repeat(2,minmax(0,1fr))]'

  return (
    <ComparisonSection
      id="your-call"
      className="scroll-mt-16"
      backdrop={<div className="product-dot-grid product-dot-grid-fade pointer-events-none absolute inset-0 opacity-60" aria-hidden />}
    >
      <div className="grid min-w-0 gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.7fr)] lg:gap-14">
        <div className="min-w-0 lg:sticky lg:top-28 lg:self-start">
          <ComparisonHeading
            eyebrow="Your call"
            title="Line them up side by side"
            description="The short version. The full comparisons cover every product, price, and source."
            size="md"
          />
          <ul className="mt-7 space-y-3">
            {content.suspects.map((suspect) => (
              <li key={suspect}>
                <MarketingSiteLink
                  href={SECRET_SUSPECTS[suspect].comparisonHref}
                  className={LINK_CLASS}
                  {...analyticsAttrs('secret-full-comparison')}
                >
                  <CompetitorMonogram name={SECRET_SUSPECTS[suspect].name} />
                  {t(SECRET_SUSPECTS[suspect].comparisonLabel)}
                  <LinkArrow />
                </MarketingSiteLink>
              </li>
            ))}
            <li>
              <MarketingSiteLink href="/pricing" className={LINK_CLASS} {...analyticsAttrs('secret-view-pricing')}>
                <span className="flex size-5 items-center justify-center rounded-md bg-[var(--brand-cta)]/10">
                  <AppwriteMark className="size-3" />
                </span>
                {t('Compare plans')}
                <LinkArrow />
              </MarketingSiteLink>
            </li>
          </ul>
          <TryAppwriteButton className="mt-8" />
        </div>

        <div className="min-w-0">
          <div className="overflow-hidden rounded-2xl border border-border bg-background/70 shadow-[0_1px_2px_rgba(0,0,0,0.03),0_24px_48px_-28px_rgba(0,0,0,0.18)] dark:bg-card/30">
            <div className={cn('grid border-b border-border', gridClass)}>
              <div className="hidden items-end px-5 py-4 font-mono text-[10px] uppercase tracking-[0.2em] text-muted-foreground sm:flex">
                {t('Compared')}
              </div>
              <div className={cn('relative flex items-center gap-2 px-3 py-4 sm:px-5', APPWRITE_COLUMN_TINT)}>
                <span className="product-tone-hairline absolute inset-x-0 top-0 h-px" aria-hidden />
                <AppwriteMark className="size-4" />
                <span className="truncate font-aeonik-pro text-[15px] text-foreground sm:text-[16px]">Appwrite</span>
              </div>
              {content.suspects.map((suspect) => (
                <div key={suspect} className="flex min-w-0 items-center px-3 py-4 sm:px-5">
                  <SuspectLabel
                    suspect={suspect}
                    className="min-w-0 truncate font-aeonik-pro text-[15px] text-muted-foreground sm:text-[16px]"
                  />
                </div>
              ))}
            </div>

            {SECRET_VERDICT_ROWS.map((row, rowIndex) => (
              <div
                key={row.label}
                className={cn(
                  'grid transition-colors duration-200 hover:bg-muted/15',
                  gridClass,
                  rowIndex < SECRET_VERDICT_ROWS.length - 1 && 'border-b border-border',
                )}
              >
                <div className="col-span-full flex items-center px-3 pt-4 text-[13px] font-medium leading-5 text-foreground sm:col-span-1 sm:px-5 sm:py-4">
                  {t(row.label)}
                </div>
                <VerdictCell cell={row.appwrite} side="appwrite" />
                {content.suspects.map((suspect) => (
                  <VerdictCell key={suspect} cell={row[suspect]} side="competitor" />
                ))}
              </div>
            ))}
          </div>

          <div className="mt-12">
            <p className="font-mono text-[11px] uppercase tracking-[0.2em] text-muted-foreground">
              {t('In fairness, they might still be the right call if')}
              <span className="text-[var(--brand-cta)]">_</span>
            </p>
            <ul className={cn('mt-6 grid gap-8', content.suspects.length > 1 && 'md:grid-cols-2')}>
              {content.suspects.map((suspect) => (
                <li key={suspect} className="relative min-w-0 border-t border-foreground/15 pt-5">
                  <span className="absolute -top-px start-0 h-px w-10 bg-[var(--tone-ink)]" aria-hidden />
                  <SuspectLabel suspect={suspect} className="text-[13px] font-medium text-foreground" />
                  <ul className="mt-3 space-y-2.5">
                    {SECRET_SUSPECTS[suspect].fairPlay.map((point) => (
                      <li key={point} className="text-[14px] leading-6 text-muted-foreground">
                        {t(point)}
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </ComparisonSection>
  )
}
