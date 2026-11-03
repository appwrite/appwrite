'use client'

import { useEffect, useRef, useState, type CSSProperties } from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { ExternalLink, Star, X } from 'lucide-react'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { AnimatedCounter } from '@/components/global/shared/AnimatedCounter'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { GitHubBrandIcon } from '@/lib/community/GitHubBrandIcon'
import { useT } from '@/lib/i18n/translate'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { starHistoryGrowthSince } from '@/lib/marketing/github-stars-history'
import {
  GITHUB_STARS_COUNT,
  GITHUB_STARS_HISTORY,
  MARKETING_SOCIAL_STATS,
} from '@/lib/marketing/social-stats'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { cn } from '@/lib/utils'
import { StarsChart } from './StarsChart'
import './star-wizard.css'

const GITHUB_REPO_LABEL = 'appwrite/appwrite'
const GITHUB_REPO_API = 'https://api.github.com/repos/appwrite/appwrite'
const STAR_COUNT_ANIMATION_MS = 1800

type StarTone = 'brand' | 'accent' | 'neutral'

type FieldStar = {
  top: string
  left: string
  size: number
  delayMs: number
  twinkleMs: number
  floatMs: number
  tone: StarTone
}

/**
 * Deterministic decorative star field: stable positions, each star twinkling
 * and drifting at its own rhythm, most neutral with brand pink and purple
 * accents sprinkled through.
 */
const STAR_FIELD: readonly FieldStar[] = [
  {
    top: '8%',
    left: '12%',
    size: 3,
    delayMs: 0,
    twinkleMs: 3400,
    floatMs: 9000,
    tone: 'brand',
  },
  {
    top: '16%',
    left: '78%',
    size: 2,
    delayMs: 600,
    twinkleMs: 4200,
    floatMs: 12000,
    tone: 'neutral',
  },
  {
    top: '24%',
    left: '32%',
    size: 2,
    delayMs: 1500,
    twinkleMs: 3000,
    floatMs: 10000,
    tone: 'neutral',
  },
  {
    top: '12%',
    left: '55%',
    size: 3,
    delayMs: 900,
    twinkleMs: 3800,
    floatMs: 13000,
    tone: 'accent',
  },
  {
    top: '34%',
    left: '88%',
    size: 3,
    delayMs: 300,
    twinkleMs: 3200,
    floatMs: 8600,
    tone: 'neutral',
  },
  {
    top: '42%',
    left: '6%',
    size: 2,
    delayMs: 1200,
    twinkleMs: 4600,
    floatMs: 11200,
    tone: 'neutral',
  },
  {
    top: '58%',
    left: '14%',
    size: 3,
    delayMs: 1800,
    twinkleMs: 3600,
    floatMs: 9600,
    tone: 'brand',
  },
  {
    top: '52%',
    left: '92%',
    size: 2,
    delayMs: 700,
    twinkleMs: 2800,
    floatMs: 12600,
    tone: 'neutral',
  },
  {
    top: '72%',
    left: '9%',
    size: 2,
    delayMs: 400,
    twinkleMs: 4000,
    floatMs: 10400,
    tone: 'neutral',
  },
  {
    top: '78%',
    left: '84%',
    size: 3,
    delayMs: 1000,
    twinkleMs: 3400,
    floatMs: 8800,
    tone: 'neutral',
  },
  {
    top: '88%',
    left: '28%',
    size: 2,
    delayMs: 1600,
    twinkleMs: 3100,
    floatMs: 11800,
    tone: 'neutral',
  },
  {
    top: '84%',
    left: '64%',
    size: 3,
    delayMs: 200,
    twinkleMs: 4400,
    floatMs: 9400,
    tone: 'accent',
  },
  {
    top: '28%',
    left: '68%',
    size: 2,
    delayMs: 2000,
    twinkleMs: 3700,
    floatMs: 12200,
    tone: 'neutral',
  },
  {
    top: '64%',
    left: '76%',
    size: 2,
    delayMs: 1300,
    twinkleMs: 2900,
    floatMs: 10800,
    tone: 'brand',
  },
  {
    top: '6%',
    left: '36%',
    size: 1.5,
    delayMs: 2300,
    twinkleMs: 5200,
    floatMs: 14000,
    tone: 'neutral',
  },
  {
    top: '20%',
    left: '5%',
    size: 1.5,
    delayMs: 800,
    twinkleMs: 4800,
    floatMs: 13400,
    tone: 'neutral',
  },
  {
    top: '38%',
    left: '46%',
    size: 1.5,
    delayMs: 1700,
    twinkleMs: 5600,
    floatMs: 15000,
    tone: 'neutral',
  },
  {
    top: '48%',
    left: '70%',
    size: 1.5,
    delayMs: 500,
    twinkleMs: 5000,
    floatMs: 14400,
    tone: 'neutral',
  },
  {
    top: '68%',
    left: '42%',
    size: 1.5,
    delayMs: 2600,
    twinkleMs: 5400,
    floatMs: 13800,
    tone: 'neutral',
  },
  {
    top: '92%',
    left: '48%',
    size: 2,
    delayMs: 1100,
    twinkleMs: 4300,
    floatMs: 11400,
    tone: 'neutral',
  },
  {
    top: '82%',
    left: '16%',
    size: 1.5,
    delayMs: 2100,
    twinkleMs: 5100,
    floatMs: 14800,
    tone: 'neutral',
  },
  {
    top: '44%',
    left: '96%',
    size: 1.5,
    delayMs: 1400,
    twinkleMs: 4700,
    floatMs: 13200,
    tone: 'neutral',
  },
]

/** Two streaks on long, offset loops so the sky stays quietly alive. */
const SHOOTING_STARS = [
  { top: '14%', right: '6%', delayMs: 2500, durationMs: 11000 },
  { top: '8%', right: '42%', delayMs: 9400, durationMs: 17000 },
] as const

export type GithubStarWizardContentProps = {
  onStar: () => void
  onSkip: () => void
}

function StatTile({ value, label }: { value: string; label: string }) {
  return (
    <div className="rounded-xl border border-border bg-card/50 px-3 py-2.5 backdrop-blur-sm">
      <p
        className={cn(
          'font-aeonik-pro text-[17px] leading-tight tabular-nums text-foreground',
          FORCE_LTR_CLASS,
        )}
      >
        {value}
      </p>
      <p className="mt-0.5 text-[11px] text-muted-foreground">{label}</p>
    </div>
  )
}

export function GithubStarWizardContent({
  onStar,
  onSkip,
}: GithubStarWizardContentProps) {
  const t = useT()
  const skipButtonRef = useRef<HTMLButtonElement | null>(null)
  const [starCount, setStarCount] = useState(0)
  const [forkStat, setForkStat] = useState<string>(
    MARKETING_SOCIAL_STATS.github.forks,
  )

  // Count up from zero once the takeover is on screen, then settle on the
  // live GitHub count when the API answers; otherwise keep the build-time one.
  useEffect(() => {
    const frame = window.requestAnimationFrame(() => {
      // Only start the count-up if a fast live response has not landed yet.
      setStarCount((current) => (current === 0 ? GITHUB_STARS_COUNT : current))
    })
    const controller = new AbortController()
    void fetch(GITHUB_REPO_API, {
      signal: controller.signal,
      headers: { Accept: 'application/vnd.github+json' },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then(
        (
          repo: { stargazers_count?: unknown; forks_count?: unknown } | null,
        ) => {
          const live = repo?.stargazers_count
          if (typeof live === 'number' && Number.isFinite(live) && live > 0) {
            setStarCount(Math.round(live))
          }
          const forks = repo?.forks_count
          if (
            typeof forks === 'number' &&
            Number.isFinite(forks) &&
            forks > 0
          ) {
            setForkStat(`${formatCompactCount(Math.round(forks))}+`)
          }
        },
      )
      .catch(() => {
        // Offline or rate limited: the generated count stays on screen.
      })
    return () => {
      window.cancelAnimationFrame(frame)
      controller.abort()
    }
  }, [])

  const chartData = GITHUB_STARS_HISTORY.filter(
    (point) => point.date && Number.isFinite(point.stars),
  )
  const yearlyGrowth = starHistoryGrowthSince(chartData, 365)
  const { github } = MARKETING_SOCIAL_STATS

  return (
    // Modal: the takeover covers the whole console, so trap focus and hide
    // the background from assistive tech while it is up.
    <DialogPrimitive.Root open>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Content
          onInteractOutside={(event) => event.preventDefault()}
          onPointerDownOutside={(event) => event.preventDefault()}
          onFocusOutside={(event) => event.preventDefault()}
          onEscapeKeyDown={(event) => {
            event.preventDefault()
            onSkip()
          }}
          onOpenAutoFocus={(event) => {
            event.preventDefault()
            skipButtonRef.current?.focus({ preventScroll: true })
          }}
          className="fixed inset-0 z-[100] flex h-dvh w-full flex-col overflow-y-auto bg-background text-foreground outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 duration-500"
        >
          <div
            className="pointer-events-none absolute inset-0 overflow-hidden"
            aria-hidden
          >
            <div className="star-wizard-nebula star-wizard-nebula--pink" />
            <div className="star-wizard-nebula star-wizard-nebula--purple" />
            {STAR_FIELD.map((star, index) => (
              <span
                key={index}
                className={cn(
                  'star-wizard-star absolute rounded-full',
                  star.tone === 'brand' && 'star-wizard-star--brand',
                  star.tone === 'accent' && 'star-wizard-star--accent',
                  star.tone === 'neutral' && 'star-wizard-star--neutral',
                )}
                style={
                  {
                    top: star.top,
                    left: star.left,
                    width: star.size,
                    height: star.size,
                    '--star-wizard-delay': `${star.delayMs}ms`,
                    '--star-wizard-duration': `${star.twinkleMs}ms`,
                    '--star-wizard-float': `${star.floatMs}ms`,
                  } as CSSProperties
                }
              />
            ))}
            {SHOOTING_STARS.map((streak, index) => (
              <span
                key={index}
                className="star-wizard-shooting-star absolute"
                style={
                  {
                    top: streak.top,
                    right: streak.right,
                    '--star-wizard-shoot-delay': `${streak.delayMs}ms`,
                    '--star-wizard-shoot-duration': `${streak.durationMs}ms`,
                  } as CSSProperties
                }
              />
            ))}
          </div>

          <div className="relative z-10 flex shrink-0 items-center justify-between px-5 py-4 sm:px-8">
            <AppwriteLogo className="h-5 w-auto shrink-0" />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-label={t('Close')}
              className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
              {...analyticsAttrs('github-star-wizard-skip')}
              onClick={onSkip}
            >
              <X className="h-4 w-4" aria-hidden />
            </Button>
          </div>

          <div className="relative z-10 flex w-full flex-1 items-center justify-center px-4 pb-10 pt-2 sm:px-6">
            <div className="w-full max-w-xl space-y-7 text-center">
              <div className="inline-flex items-center gap-2 rounded-full border border-border bg-card/60 px-3.5 py-1.5 backdrop-blur-sm">
                <GitHubBrandIcon className="h-3.5 w-3.5 text-muted-foreground" />
                <span
                  className={cn(
                    'text-[12px] font-medium text-muted-foreground',
                    FORCE_LTR_CLASS,
                  )}
                >
                  {GITHUB_REPO_LABEL}
                </span>
              </div>

              <div className="space-y-3">
                <DialogPrimitive.Title className="text-balance text-[28px] font-semibold tracking-tight text-foreground sm:text-[34px]">
                  {t('Enjoying Appwrite?')}
                </DialogPrimitive.Title>
                <DialogPrimitive.Description className="mx-auto max-w-md text-[14px] leading-relaxed text-muted-foreground">
                  {t(
                    'Appwrite is open source and built in the open. A star helps more developers discover it.',
                  )}
                </DialogPrimitive.Description>
              </div>

              <div className="relative space-y-1.5">
                <span
                  className="star-wizard-halo absolute left-1/2 top-1/2 h-[220%] w-[80%] -translate-x-1/2 -translate-y-1/2 rounded-full"
                  aria-hidden
                />
                <div
                  className={cn(
                    'relative flex items-center justify-center gap-2.5',
                    FORCE_LTR_CLASS,
                  )}
                >
                  <Star
                    className="h-7 w-7 shrink-0 fill-amber-400 text-amber-400"
                    aria-hidden
                  />
                  <AnimatedCounter
                    value={starCount}
                    durationMs={STAR_COUNT_ANIMATION_MS}
                    className="font-aeonik-pro text-[52px] leading-none tabular-nums tracking-tight text-foreground sm:text-[64px]"
                  />
                </div>
                <p className="relative text-[13px] text-muted-foreground">
                  {t('stars')}
                  {yearlyGrowth ? (
                    <>
                      {' · '}
                      <span
                        className={cn(
                          'font-medium tabular-nums text-emerald-600 dark:text-emerald-400',
                          FORCE_LTR_CLASS,
                        )}
                      >
                        +{formatCompactCount(yearlyGrowth)}
                      </span>{' '}
                      {t('in the last year')}
                    </>
                  ) : null}
                </p>
              </div>

              {chartData.length >= 2 ? (
                <div className="rounded-xl border border-border bg-card/50 p-4 pb-2 backdrop-blur-sm">
                  <StarsChart data={chartData} />
                </div>
              ) : null}

              <div className="grid grid-cols-3 gap-2.5">
                <StatTile
                  value={github.contributors}
                  label={t('Contributors')}
                />
                <StatTile value={forkStat} label={t('Forks')} />
                <StatTile value={github.commits} label={t('Commits')} />
              </div>

              <div className="space-y-3">
                <Button
                  asChild
                  size="lg"
                  className="h-11 w-full text-[14px] sm:w-auto sm:min-w-[260px]"
                >
                  <a
                    href={github.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    {...analyticsAttrs('github-star-wizard-star')}
                    onClick={onStar}
                  >
                    {t('Star on GitHub')}
                    <ExternalLink className="h-4 w-4" aria-hidden />
                  </a>
                </Button>
                <div>
                  <Button
                    ref={skipButtonRef}
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="text-[13px] text-muted-foreground hover:text-foreground"
                    {...analyticsAttrs('github-star-wizard-skip')}
                    onClick={onSkip}
                  >
                    {t('No thanks')}
                  </Button>
                </div>
                <p className="text-[11px] text-muted-foreground/70">
                  {t('One-time ask, promise. May your builds be ever green.')}
                </p>
              </div>
            </div>
          </div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  )
}
