import { GraduationCap } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  ReviewsComment,
  ReviewsPerson,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'

const LEARNINGS = [
  {
    body: 'Use early returns instead of nested ifs.',
    repository: 'acme/storefront',
    by: 'ben',
  },
  {
    body: 'Never log tokens, even hashed ones.',
    repository: 'acme/storefront',
    by: 'ana',
  },
  {
    body: 'Read rows with Query.select, not whole documents.',
    repository: 'acme/checkout-api',
    by: 'sam',
  },
] as const

/** A preference stated in a pull request becomes a learning that later reviews of the repository follow. */
export function ReviewsLearningsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[580px] py-4 text-start">
      <div className="space-y-2 sm:me-16">
        <ReviewsComment
          className="product-hero-rise"
          style={riseStyle(40)}
          author={<ReviewsPerson login="ben" meta={t('on #491')} />}
        >
          <p className="text-[12px] leading-[18px] text-foreground/90">
            <code
              dir="ltr"
              className="font-mono text-[11.5px] text-[var(--tone-ink)]"
            >
              @appwrite
            </code>{' '}
            {t('remember: we use early returns instead of nested ifs.')}
          </p>
        </ReviewsComment>
        <ReviewsComment className="product-hero-rise" style={riseStyle(300)}>
          <p className="flex items-start gap-1.5 text-[12px] leading-[18px] text-foreground/90">
            <GraduationCap
              className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-ink)]"
              aria-hidden
            />
            {t(
              'Saved. Later reviews and answers in this repository will follow it.',
            )}
          </p>
        </ReviewsComment>
      </div>

      <ArtPanel
        className="mt-4 sm:ms-16"
        delayMs={600}
        innerClassName="product-tone-shadow p-0"
      >
        <p className="border-b border-border px-3.5 py-2.5 text-[12px] font-semibold text-foreground">
          {t('Learnings')}
        </p>
        <div className="hidden grid-cols-[minmax(0,1fr)_7.5rem_4rem] gap-3 border-b border-border px-3.5 py-1.5 text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground sm:grid">
          <span>{t('Learning')}</span>
          <span>{t('Repository')}</span>
          <span>{t('Asked by')}</span>
        </div>
        <ul className="divide-y divide-border/70">
          {LEARNINGS.map((learning, index) => (
            <li
              key={learning.body}
              className="product-hero-rise grid gap-1 px-3.5 py-2 text-[11px] sm:grid-cols-[minmax(0,1fr)_7.5rem_4rem] sm:items-center sm:gap-3"
              style={riseStyle(760 + index * 110)}
            >
              <span className="text-foreground">{t(learning.body)}</span>
              <span
                dir="ltr"
                className="truncate font-mono text-[10.5px] text-muted-foreground"
              >
                {learning.repository}
              </span>
              <span dir="ltr" className="text-muted-foreground">
                {learning.by}
              </span>
            </li>
          ))}
        </ul>
      </ArtPanel>
    </div>
  )
}
