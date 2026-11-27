import { MessageSquareReply } from 'lucide-react'
import {
  ArtChip,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  ReviewsComment,
  ReviewsPerson,
  ReviewsSeverityPill,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'

/** A thread on one finding: a developer pushes back, the reviewer checks the code and settles it. */
export function ReviewsChatVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] pb-12 pt-4 text-start">
      <ReviewsComment
        className="product-hero-rise"
        style={riseStyle(40)}
        meta={t('on src/mail/send.ts')}
      >
        <div className="flex items-center gap-1.5">
          <ReviewsSeverityPill severity="minor" />
          <span className="rounded-[5px] border border-border px-1.5 text-[10px] leading-[15px] text-muted-foreground">
            error-handling
          </span>
        </div>
        <p className="mt-1.5 text-[12.5px] font-medium text-foreground">
          {t('Errors from sendEmail are swallowed')}
        </p>
      </ReviewsComment>

      <div className="relative ms-6 mt-2 space-y-2 border-s border-border ps-4">
        <ReviewsComment
          className="product-hero-rise"
          style={riseStyle(320)}
          author={<ReviewsPerson login="ana" meta={t('just now')} />}
        >
          <p className="text-[12px] leading-[18px] text-foreground/90">
            <code
              dir="ltr"
              className="font-mono text-[11.5px] text-[var(--tone-ink)]"
            >
              @appwrite
            </code>{' '}
            {t('this is fine, the queue retries failed sends.')}
          </p>
        </ReviewsComment>

        <ReviewsComment
          className="product-hero-rise border-[rgb(var(--tone-rgb)/0.45)]"
          style={riseStyle(640)}
          meta={t('just now')}
        >
          <p className="text-[12px] leading-[18px] text-foreground/90">
            {t(
              'Confirmed in the mail queue: failed sends retry three times, then land in the dead-letter queue. Dismissing this finding.',
            )}
          </p>
          <span className="mt-2 inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
            {t('Dismissed')}
          </span>
        </ReviewsComment>
      </div>

      <ArtChip
        className="bottom-1 end-[3%] hidden sm:block"
        delayMs={1000}
        floatDelayMs={300}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <MessageSquareReply
            className="size-3.5 text-[var(--tone-ink)]"
            aria-hidden
          />
          {t('Answers use no credits')}
        </span>
      </ArtChip>
    </div>
  )
}
