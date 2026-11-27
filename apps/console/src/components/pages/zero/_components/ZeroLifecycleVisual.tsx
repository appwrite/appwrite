import { useEffect, useState, type CSSProperties } from 'react'
import { Timer } from 'lucide-react'
import {
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ZERO_BUILD_MINUTES,
  ZERO_CLAIM_HOURS,
  zeroLifecycle,
} from '@/lib/zero/marketing-content'

type StageId = (typeof zeroLifecycle.stages)[number]['id']

/**
 * The hour is 4% of the real 25 hours. It gets a larger share so its label fits;
 * the paused day keeps most of the bar.
 */
const STAGES: Record<StageId, { grow: number; bar: string; dot: string }> = {
  build: {
    grow: 18,
    bar: 'bg-[var(--tone-ink)]',
    dot: 'bg-[var(--tone-ink)]',
  },
  pause: {
    grow: 70,
    bar: 'bg-[rgb(var(--tone2-rgb)/0.45)]',
    dot: 'bg-[rgb(var(--tone2-rgb)/0.8)]',
  },
  delete: {
    grow: 12,
    bar: 'border border-dashed border-foreground/25 bg-muted/40',
    dot: 'border border-dashed border-foreground/40',
  },
}

/** What `npx appwrite zero status` reports for a project started 18 minutes ago. */
const ZERO_STATUS_OUTPUT = [
  ['projectId', '6721f0c4001e8b3a9d52'],
  ['status', 'active'],
  ['buildTimeLeft', '42 min'],
  ['claimTimeLeft', '25h'],
] as const

/** Wall-clock times for a project started now. Client-only, so SSR never guesses a time zone. */
function useLocalTimes(): Record<StageId, string> | null {
  const { language } = useI18n()
  const [times, setTimes] = useState<Record<StageId, string> | null>(null)

  useEffect(() => {
    const time = new Intl.DateTimeFormat(language, {
      hour: 'numeric',
      minute: '2-digit',
    })
    const dayAndTime = new Intl.DateTimeFormat(language, {
      weekday: 'short',
      hour: 'numeric',
      minute: '2-digit',
    })
    const update = () => {
      const now = Date.now()
      const buildEnds = now + ZERO_BUILD_MINUTES * 60_000
      const deletedAt = buildEnds + ZERO_CLAIM_HOURS * 3_600_000
      setTimes({
        build: time.formatRange(now, buildEnds),
        pause: `${time.format(buildEnds)} – ${dayAndTime.format(deletedAt)}`,
        delete: dayAndTime.format(deletedAt),
      })
    }
    update()
    const timer = window.setInterval(update, 30_000)
    return () => window.clearInterval(timer)
  }, [language])

  return times
}

/** The project's clock for a project started now, and the CLI command that reports it. */
export function ZeroLifecycleVisual() {
  const t = useT()
  const times = useLocalTimes()

  return (
    <div className="relative mx-auto grid w-full max-w-[560px] gap-4">
      <ArtPanel innerClassName="px-3.5 py-3.5">
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Timer} />
          <p className="text-[12px] font-semibold text-foreground">
            {t('Project lifecycle')}
          </p>
          <span className="ms-auto flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <ArtLiveDot />
            {t('Active')}
          </span>
        </div>

        <div className="mt-4 flex h-2.5 gap-1" aria-hidden>
          {zeroLifecycle.stages.map((stage, index) => (
            <span
              key={stage.id}
              className="flex min-w-0 overflow-hidden rounded-full"
              style={{ flexGrow: STAGES[stage.id].grow, flexBasis: 0 }}
            >
              <span
                className={cn(
                  'product-hero-fill h-full w-full rounded-full',
                  STAGES[stage.id].bar,
                )}
                style={
                  {
                    '--fill-delay': `${300 + index * 350}ms`,
                    '--fill-duration': '0.9s',
                  } as CSSProperties
                }
              />
            </span>
          ))}
        </div>

        <ol className="mt-4 space-y-3">
          {zeroLifecycle.stages.map((stage, index) => (
            <li
              key={stage.id}
              className="product-hero-rise flex gap-2.5"
              style={riseStyle(400 + index * 150)}
            >
              <span
                className={cn(
                  'mt-1 size-2 shrink-0 rounded-full',
                  STAGES[stage.id].dot,
                )}
                aria-hidden
              />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                  <p className="text-[12px] font-semibold text-foreground">
                    {t(stage.title)}
                  </p>
                  <span
                    dir="ltr"
                    className="font-mono text-[10px] tabular-nums text-muted-foreground"
                  >
                    {times?.[stage.id] ?? '\u00A0'}
                  </span>
                </div>
                <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">
                  {t(stage.description)}
                </p>
              </div>
            </li>
          ))}
        </ol>
        <p className="mt-4 border-t border-border pt-3 text-[11px] text-muted-foreground">
          {t('The times are for a project you start now, in your time zone.')}
        </p>
      </ArtPanel>

      <ArtWindow
        title={t('Terminal')}
        className="product-hero-rise sm:ms-10"
        style={riseStyle(700)}
        bodyClassName="p-3.5"
      >
        <pre
          dir="ltr"
          className="overflow-hidden text-start font-mono text-[11px] leading-[1.7] text-foreground"
        >
          <code className="block">
            <span className="block text-muted-foreground">
              $ npx appwrite zero status
            </span>
            {ZERO_STATUS_OUTPUT.map(([key, value]) => (
              <span key={key} className="flex gap-3">
                <span className="w-28 shrink-0 text-muted-foreground">
                  {key}
                </span>
                <span
                  className={cn(
                    'truncate',
                    value === 'active' &&
                      'text-emerald-600 dark:text-emerald-400',
                  )}
                >
                  {value}
                </span>
              </span>
            ))}
          </code>
        </pre>
      </ArtWindow>
    </div>
  )
}
