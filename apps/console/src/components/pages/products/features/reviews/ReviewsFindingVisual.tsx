import { ChevronRight, Reply } from 'lucide-react'
import {
  ArtChip,
  ArtToken,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  ReviewsDiff,
  ReviewsFinding,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'

/** A finding on the line it concerns, with a suggested change small enough to commit as is. */
export function ReviewsFindingVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[580px] py-4 text-start">
      <ReviewsDiff
        file="src/auth/session.ts"
        className="product-hero-rise product-tone-shadow"
        lines={[
          {
            kind: 'context',
            number: 31,
            code: (
              <>
                <ArtToken tone="keyword">const</ArtToken> token ={' '}
                <ArtToken tone="function">createToken</ArtToken>()
              </>
            ),
          },
          {
            kind: 'add',
            number: 32,
            marked: true,
            code: (
              <>
                logger.<ArtToken tone="function">info</ArtToken>(
                <ArtToken tone="string">'session'</ArtToken>, {'{'} token {'}'})
              </>
            ),
          },
          {
            kind: 'context',
            number: 33,
            code: (
              <>
                <ArtToken tone="keyword">const</ArtToken> hash ={' '}
                <ArtToken tone="keyword">await</ArtToken>{' '}
                <ArtToken tone="function">hashToken</ArtToken>(token)
              </>
            ),
          },
        ]}
      >
        <div className="border-t border-border bg-muted/20 p-3">
          <ReviewsFinding
            className="product-hero-rise"
            style={riseStyle(360)}
            severity="major"
            category="security"
            title="Token is logged before it is hashed"
            body="The raw session token reaches your logs on every sign-in. Log the session ID instead."
            suggestion={
              <div
                dir="ltr"
                className="overflow-hidden rounded-lg border border-border font-mono text-[11px] leading-[19px]"
              >
                <p className="border-b border-border bg-muted/30 px-2.5 py-1 font-sans text-[10px] text-muted-foreground">
                  {t('Suggested change')}
                </p>
                <p className="bg-rose-500/[0.08] px-2.5 text-foreground/80">
                  <span className="me-2 text-rose-600 dark:text-rose-400">
                    -
                  </span>
                  {"logger.info('session', { token })"}
                </p>
                <p className="bg-emerald-500/[0.09] px-2.5 text-foreground">
                  <span className="me-2 text-emerald-600 dark:text-emerald-400">
                    +
                  </span>
                  {"logger.info('session', { sessionId })"}
                </p>
              </div>
            }
            footer={
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[10.5px] text-muted-foreground">
                <span className="flex items-center gap-1">
                  <ChevronRight className="size-3 rtl:rotate-180" aria-hidden />
                  {t('Prompt to fix with AI')}
                </span>
                <span className="flex items-center gap-1">
                  <Reply className="size-3" aria-hidden />
                  {t('Reply if this doesn’t apply.')}
                </span>
              </div>
            }
          />
        </div>
      </ReviewsDiff>

      <ArtChip
        className="-top-1 end-[3%] hidden sm:block"
        delayMs={900}
        floatDelayMs={400}
      >
        <span className="text-[11px] text-muted-foreground">
          {t('Up to 15 inline findings per review')}
        </span>
      </ArtChip>
    </div>
  )
}
