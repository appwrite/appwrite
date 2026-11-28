import { GitPullRequest } from 'lucide-react'
import {
  ArtAppwriteMark,
  ArtPanel,
  ArtToken,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  ReviewsGrade,
  ReviewsSeverityPill,
} from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LINES = [
  {
    number: 18,
    added: false,
    code: (
      <>
        <ArtToken tone="keyword">for</ArtToken> (
        <ArtToken tone="keyword">let</ArtToken> attempt ={' '}
        <ArtToken tone="number">0</ArtToken>; ; attempt++) {'{'}
      </>
    ),
  },
  {
    number: 19,
    added: true,
    code: (
      <>
        {'  '}
        <ArtToken tone="keyword">await</ArtToken>{' '}
        <ArtToken tone="function">signIn</ArtToken>(email, password)
      </>
    ),
  },
  { number: 20, added: false, code: '}' },
] as const

/**
 * One pull request, one card: the changed line, the finding the reviewer
 * left on it, and the grade the review gave the pull request.
 */
export function ReviewsHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-6 text-start">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 -z-10 size-[480px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.2),transparent)] blur-2xl"
        aria-hidden
      />
      <ArtPanel
        delayMs={0}
        innerClassName="product-tone-shadow overflow-hidden p-0"
      >
        <div className="flex items-center gap-3 px-5 py-4">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <GitPullRequest className="size-4" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="truncate text-[14px] font-semibold text-foreground">
              {t('Retry sign-in with backoff')}
              <span
                dir="ltr"
                className="ms-1.5 font-mono text-[12px] font-normal text-muted-foreground"
              >
                #482
              </span>
            </p>
            <p
              dir="ltr"
              className="mt-0.5 truncate font-mono text-[11px] text-muted-foreground"
            >
              src/auth/retry.ts
            </p>
          </div>
        </div>

        <div
          dir="ltr"
          className="border-t border-border/70 py-2 font-mono text-[12px] leading-[22px]"
        >
          {LINES.map((line) => (
            <div
              key={line.number}
              className={cn(
                'grid grid-cols-[3rem_1rem_minmax(0,1fr)] pe-5',
                line.added && 'bg-emerald-500/[0.08]',
              )}
            >
              <span className="select-none pe-3 text-end tabular-nums text-muted-foreground/60">
                {line.number}
              </span>
              <span
                className={cn(
                  'select-none',
                  line.added && 'text-emerald-600 dark:text-emerald-400',
                )}
              >
                {line.added ? '+' : ''}
              </span>
              <span className="min-w-0 truncate whitespace-pre text-foreground/90">
                {line.code}
              </span>
            </div>
          ))}
        </div>

        <div
          className="product-hero-rise px-5 pb-5 pt-3"
          style={riseStyle(420)}
        >
          <div className="rounded-xl bg-[rgb(var(--tone-rgb)/0.07)] p-4 ring-1 ring-[rgb(var(--tone-rgb)/0.3)]">
            <p className="flex items-center gap-2 text-[12px]">
              <ArtAppwriteMark className="size-4" />
              <span dir="ltr" className="font-semibold text-foreground">
                appwrite
              </span>
              <ReviewsSeverityPill severity="major" className="ms-auto" />
            </p>
            <p className="mt-3 text-[14px] font-medium text-foreground">
              {t('A wrong password retries forever')}
            </p>
            <p className="mt-1 text-[12.5px] leading-5 text-muted-foreground">
              {t('Stop on a 401 and cap the attempts.')}
            </p>
          </div>
        </div>

        <div
          className="product-hero-rise flex items-center gap-2.5 border-t border-border/70 px-5 py-3.5 text-[12.5px]"
          style={riseStyle(700)}
        >
          <ReviewsGrade grade="B" />
          <span className="text-foreground">
            {t('Needs changes before merging')}
          </span>
          <span className="ms-auto text-rose-600 dark:text-rose-400">
            {t('Changes requested')}
          </span>
        </div>
      </ArtPanel>
    </div>
  )
}
