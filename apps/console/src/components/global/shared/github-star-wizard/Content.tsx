'use client'

import { useEffect, useRef, useState, type CSSProperties } from 'react'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { Star, X } from 'lucide-react'
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

/**
 * Deterministic decorative star field: stable positions, staggered twinkle,
 * most stars neutral with a few in the brand pink and purple.
 */
const STAR_FIELD = [
  {
    top: '8%',
    left: '12%',
    size: 3,
    delayMs: 0,
    durationMs: 3400,
    tone: 'brand',
  },
  {
    top: '16%',
    left: '78%',
    size: 2,
    delayMs: 600,
    durationMs: 4200,
    tone: 'neutral',
  },
  {
    top: '24%',
    left: '32%',
    size: 2,
    delayMs: 1500,
    durationMs: 3000,
    tone: 'neutral',
  },
  {
    top: '12%',
    left: '55%',
    size: 3,
    delayMs: 900,
    durationMs: 3800,
    tone: 'accent',
  },
  {
    top: '34%',
    left: '88%',
    size: 3,
    delayMs: 300,
    durationMs: 3200,
    tone: 'brand',
  },
  {
    top: '42%',
    left: '6%',
    size: 2,
    delayMs: 1200,
    durationMs: 4600,
    tone: 'neutral',
  },
  {
    top: '58%',
    left: '14%',
    size: 3,
    delayMs: 1800,
    durationMs: 3600,
    tone: 'brand',
  },
  {
    top: '52%',
    left: '92%',
    size: 2,
    delayMs: 700,
    durationMs: 2800,
    tone: 'accent',
  },
  {
    top: '72%',
    left: '9%',
    size: 2,
    delayMs: 400,
    durationMs: 4000,
    tone: 'neutral',
  },
  {
    top: '78%',
    left: '84%',
    size: 3,
    delayMs: 1000,
    durationMs: 3400,
    tone: 'brand',
  },
  {
    top: '88%',
    left: '28%',
    size: 2,
    delayMs: 1600,
    durationMs: 3100,
    tone: 'neutral',
  },
  {
    top: '84%',
    left: '64%',
    size: 3,
    delayMs: 200,
    durationMs: 4400,
    tone: 'accent',
  },
  {
    top: '28%',
    left: '68%',
    size: 2,
    delayMs: 2000,
    durationMs: 3700,
    tone: 'neutral',
  },
  {
    top: '64%',
    left: '76%',
    size: 2,
    delayMs: 1300,
    durationMs: 2900,
    tone: 'brand',
  },
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

  // Count up from zero once the takeover is on screen, then settle on the
  // live GitHub count when the API answers; otherwise keep the build-time one.
  useEffect(() => {
    const frame = window.requestAnimationFrame(() => {
      setStarCount(GITHUB_STARS_COUNT)
    })
    const controller = new AbortController()
    void fetch(GITHUB_REPO_API, {
      signal: controller.signal,
      headers: { Accept: 'application/vnd.github+json' },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((repo: { stargazers_count?: unknown } | null) => {
        const live = repo?.stargazers_count
        if (typeof live === 'number' && Number.isFinite(live) && live > 0) {
          setStarCount(Math.round(live))
        }
      })
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
    // Non-modal so the permanent-dismiss handlers below stay the only way out.
    <DialogPrimitive.Root modal={false} open>
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
            <div className="star-wizard-glow absolute left-1/2 top-[-22%] h-[55%] w-[85%] -translate-x-1/2 rounded-full blur-2xl" />
            <div className="star-wizard-glow-accent absolute bottom-[-18%] left-[-12%] h-[45%] w-[55%] rounded-full blur-2xl" />
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
                    '--star-wizard-duration': `${star.durationMs}ms`,
                  } as CSSProperties
                }
              />
            ))}
            <span className="star-wizard-shooting-star absolute right-[6%] top-[14%]" />
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
                    'Appwrite is open source and built in the open. A GitHub star helps more developers discover Appwrite and grows the community behind it.',
                  )}
                </DialogPrimitive.Description>
              </div>

              <div className="space-y-1.5">
                <div
                  className={cn(
                    'flex items-center justify-center gap-2.5',
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
                <p className="text-[13px] text-muted-foreground">
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
                <StatTile value={github.forks} label={t('Forks')} />
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
