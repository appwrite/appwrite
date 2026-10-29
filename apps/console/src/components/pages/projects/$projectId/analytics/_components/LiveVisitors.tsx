import { useState } from 'react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDocumentVisible } from '@/hooks/use-document-visible'
import { AnimatedCounter } from '@/components/global/shared/AnimatedCounter'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  ANALYTICS_LIVE_POLL_INTERVAL_MS,
  ANALYTICS_LIVE_WINDOW_MINUTES,
  useAnalyticsLivePages,
  useAnalyticsLiveVisitors,
} from '@/lib/react-query/hooks'
import { formatNumber } from './format'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'

function LiveDot({ active }: { active: boolean }) {
  return (
    <span className="relative flex h-2 w-2 shrink-0" aria-hidden>
      {active ? (
        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
      ) : null}
      <span
        className={cn(
          'relative inline-flex h-2 w-2 rounded-full',
          active ? 'bg-emerald-500' : 'bg-muted-foreground/40',
        )}
      />
    </span>
  )
}

type LiveVisitorsProps = {
  projectId: string
  propertyId: string
  /** Disabled properties don't ingest, so there is nothing to poll. */
  enabled?: boolean
  /**
   * `pill` (default) is the standalone header control. `inline` drops the
   * chrome (dot + count as plain text) for dense surfaces like the
   * properties list, where a pill on every card or row reads as noise.
   */
  variant?: 'pill' | 'inline'
}

/**
 * "N online" pill: unique visitors with activity in the last few minutes,
 * re-read on an interval while the tab is visible. Clicking it opens the
 * pages those visitors are on right now.
 */
export function LiveVisitors({
  projectId,
  propertyId,
  enabled = true,
  variant = 'pill',
}: LiveVisitorsProps) {
  const t = useT()
  const isDocumentVisible = useDocumentVisible()
  const [open, setOpen] = useState(false)
  const polling = enabled && isDocumentVisible

  const { visitors, checkedAt, error } = useAnalyticsLiveVisitors(
    enabled ? projectId : null,
    propertyId,
    polling,
  )
  const { pages: livePages, isLoading: pagesLoading } = useAnalyticsLivePages(
    projectId,
    propertyId,
    open && polling,
  )
  const pages = livePages.filter((page) => isKnownBreakdownValue(page.value))

  const count = visitors ?? 0
  const hasValue = visitors !== undefined && !error
  const isLive = hasValue && count > 0
  const maxPageVisitors = pages.reduce(
    (max, page) => Math.max(max, page.visitors),
    0,
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        {variant === 'inline' ? (
          <button
            type="button"
            className={cn(
              'group/live inline-flex h-6 shrink-0 items-center gap-1.5 rounded-md px-1.5 -mx-1.5 text-[12px] text-muted-foreground transition-colors',
              'hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              open && 'bg-muted/60',
            )}
            aria-label={t('Visitors online now')}
          >
            <LiveDot active={isLive && polling} />
            {hasValue ? (
              <AnimatedCounter
                value={count}
                className={cn(
                  'font-medium tabular-nums',
                  isLive ? 'text-foreground' : 'text-muted-foreground',
                )}
              />
            ) : (
              <span>-</span>
            )}
            <span>{t('online')}</span>
          </button>
        ) : (
          <button
            type="button"
            className={cn(
              'inline-flex h-7 shrink-0 items-center gap-2 rounded-full border px-2.5 text-[12px] transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              isLive
                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/15 dark:text-emerald-300'
                : 'border-border text-muted-foreground hover:bg-muted/50',
            )}
            aria-label={t('Visitors online now')}
          >
            <LiveDot active={isLive && polling} />
            {hasValue ? (
              <AnimatedCounter
                value={count}
                className="font-semibold tabular-nums"
              />
            ) : (
              <span className="font-semibold">-</span>
            )}
            <span className="hidden sm:inline">{t('online')}</span>
          </button>
        )}
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={6} className="w-[300px] p-0">
        <div className="border-b border-border px-3 py-2.5">
          <div className="flex items-center gap-2">
            <LiveDot active={isLive && polling} />
            <p className="text-[13px] font-semibold text-foreground">
              {hasValue ? formatNumber(count) : '-'}{' '}
              {count === 1 ? t('visitor online') : t('visitors online')}
            </p>
          </div>
          <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
            {t('Unique visitors active in the last')}{' '}
            {ANALYTICS_LIVE_WINDOW_MINUTES} {t('minutes')}.{' '}
            {polling
              ? `${t('Updates every')} ${ANALYTICS_LIVE_POLL_INTERVAL_MS / 1000}s.`
              : t('Paused while this tab is hidden.')}
          </p>
          {error ? (
            <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
              {error instanceof Error
                ? error.message
                : t('Could not load live visitors')}
            </p>
          ) : null}
        </div>
        <div className="px-3 py-2.5">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Active pages')}
          </p>
          {pagesLoading && pages.length === 0 ? (
            <div className="space-y-1.5" aria-hidden>
              {Array.from({ length: 3 }).map((_, index) => (
                <div
                  key={index}
                  className="h-6 animate-pulse rounded bg-muted/50"
                />
              ))}
            </div>
          ) : pages.length === 0 ? (
            <p className="py-2 text-[12px] text-muted-foreground">
              {t('No one is browsing right now.')}
            </p>
          ) : (
            <div className="space-y-0.5">
              {pages.map((page, index) => (
                <div
                  key={`${page.value}-${index}`}
                  className="relative flex h-6 items-center gap-2 overflow-hidden rounded px-1.5"
                >
                  <span
                    className="absolute inset-y-0 start-0 rounded bg-emerald-500/10"
                    style={{
                      width: `${maxPageVisitors > 0 ? (page.visitors / maxPageVisitors) * 100 : 0}%`,
                    }}
                    aria-hidden
                  />
                  <span className="relative min-w-0 flex-1 truncate font-mono text-[11px] text-foreground">
                    {page.value || t('Unknown')}
                  </span>
                  <span className="relative shrink-0 text-[11px] font-medium tabular-nums text-foreground">
                    {formatNumber(page.visitors)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
        {checkedAt ? (
          <div className="border-t border-border px-3 py-1.5 text-[10px] text-muted-foreground">
            {t('Last checked')}{' '}
            {new Date(checkedAt).toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
            })}
          </div>
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
