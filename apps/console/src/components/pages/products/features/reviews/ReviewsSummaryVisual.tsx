import { ChevronRight, Lightbulb } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  REVIEWS_TIERS,
  ReviewsComment,
  ReviewsGrade,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SECTIONS = [
  { label: 'Findings', count: '2' },
  { label: 'Fix with agent prompt', count: '' },
  { label: 'Walkthrough', count: '4 files' },
  { label: 'Fixed since the last review', count: '1' },
] as const

/** The review's summary comment, edited in place on every new commit, beside the grade scale it uses. */
export function ReviewsSummaryVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto grid w-full max-w-[600px] items-start gap-4 py-4 text-start sm:grid-cols-[minmax(0,1fr)_168px]">
      <ReviewsComment
        className="product-hero-rise product-tone-shadow"
        style={riseStyle(40)}
        meta={t('edited')}
      >
        <p className="text-[13px] font-semibold text-foreground">
          {t('Code review')}
        </p>
        <div className="mt-2 grid grid-cols-[auto_auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 rounded-lg border border-border px-2.5 py-2 text-[11px]">
          <span className="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Commit')}
          </span>
          <span className="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Tier')}
          </span>
          <span className="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Verdict')}
          </span>
          <code
            dir="ltr"
            className="font-mono text-[10.5px] text-muted-foreground"
          >
            e4f5a6b
          </code>
          <span className="flex items-center gap-1.5 text-foreground">
            <ReviewsGrade grade="A" />
            {t('Mergeable after minor fixes')}
          </span>
          <span className="truncate text-sky-700 dark:text-sky-300">
            {t('Commented')}
          </span>
        </div>
        <p className="mt-2.5 text-[11.5px] leading-[18px] text-foreground/85">
          {t(
            'Sign-in now stops on a wrong password and caps retries at five. One minor finding is left: the backoff ignores the Retry-After header.',
          )}
        </p>
        <p className="mt-2 text-[10.5px] font-semibold text-foreground">
          {t('Latest changes')}
        </p>
        <p className="mt-0.5 text-[11px] leading-[17px] text-muted-foreground">
          {t(
            'Stops retrying on 401 responses and adds a limit of five attempts.',
          )}
        </p>
        <ul className="mt-2.5 divide-y divide-border/70 overflow-hidden rounded-lg border border-border">
          {SECTIONS.map((section, index) => (
            <li
              key={section.label}
              className="product-hero-rise flex items-center gap-2 px-2.5 py-1.5 text-[11px]"
              style={riseStyle(260 + index * 90)}
            >
              <ChevronRight
                className="size-3 text-muted-foreground rtl:rotate-180"
                aria-hidden
              />
              <span className="flex-1 text-foreground">{t(section.label)}</span>
              {section.count ? (
                <span className="text-[10px] text-muted-foreground">
                  {t(section.count)}
                </span>
              ) : null}
            </li>
          ))}
        </ul>
        <p className="mt-2.5 flex items-start gap-1.5 text-[10.5px] leading-4 text-muted-foreground">
          <Lightbulb className="mt-px size-3 shrink-0" aria-hidden />
          <span>
            {t('To re-run it, comment:')}{' '}
            <code dir="ltr" className="font-mono text-foreground">
              @appwrite review
            </code>
          </span>
        </p>
      </ReviewsComment>

      <ArtPanel
        className="hidden sm:block"
        delayMs={500}
        innerClassName="p-2.5"
      >
        <p className="px-1 text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Merge confidence')}
        </p>
        <ul className="mt-1.5 space-y-0.5">
          {REVIEWS_TIERS.map((tier) => (
            <li
              key={tier.grade}
              className={cn(
                'flex items-center gap-2 rounded-md px-1 py-1 text-[10.5px] leading-[13px]',
                tier.grade === 'A'
                  ? 'bg-[rgb(var(--tone-rgb)/0.12)] text-foreground'
                  : 'text-muted-foreground',
              )}
            >
              <ReviewsGrade grade={tier.grade} />
              {t(tier.meaning)}
            </li>
          ))}
        </ul>
      </ArtPanel>
    </div>
  )
}
