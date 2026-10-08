import { Info } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/**
 * Short definitions for the metrics that aren't self-explanatory. Wording
 * follows the API's own field descriptions so the console never promises a
 * different calculation than the backend performs.
 */
export const ANALYTICS_METRIC_INFO = {
  uniqueVisitors:
    'Distinct visitors in the selected range. Someone who returns several times is counted once.',
  eventVisitors:
    'Distinct visitors who triggered the plotted event. Someone who returns several times is counted once.',
  sessions:
    'Continuous periods of activity by one visitor. One visitor can have several sessions.',
  events:
    'Every tracked event, pageviews and custom events alike, including repeats by the same visitor.',
  visits: 'Sessions with at least one event in the selected range.',
  pageviews: 'Pages viewed, including repeat views of the same page.',
  viewsPerVisit: 'Average pageviews per session (pageviews ÷ visits).',
  bounceRate:
    'Share of sessions with only a single event. Lower usually means visitors explore further.',
  visitDuration:
    'Average session length, from first to last event. Single-event sessions count as 0s.',
  engagementTime:
    'Average time the page was in the foreground per session. Sessions with no engagement are excluded.',
  scrollDepth: 'How far down the page visitors scrolled, on average.',
  channels:
    'Channels group referrers into types such as search, social or direct. Sources show the individual referrer. Campaigns break down the utm_* parameters on landing URLs.',
  customEvents:
    'Events sent by your own tracking calls. Automatic events (pageviews, outbound links, downloads, scroll depth, engagement time, and Flutter screen and lifecycle events) are left out.',
  utm: 'Breakdown of the utm_* query parameters on landing URLs, as tagged in your campaign links.',
  bots: 'Automated traffic recognised from its user agent, such as search engine crawlers and AI agents.',
  humanShare:
    'Share of traffic from people versus automated clients, classified by the API from each request.',
} as const

export type AnalyticsMetricInfoKey = keyof typeof ANALYTICS_METRIC_INFO

export function MetricInfo({
  info,
  className,
  side = 'top',
}: {
  /** A key from `ANALYTICS_METRIC_INFO`, or custom copy. */
  info: AnalyticsMetricInfoKey | (string & {})
  className?: string
  side?: 'top' | 'bottom' | 'left' | 'right'
}) {
  const t = useT()
  const text =
    info in ANALYTICS_METRIC_INFO
      ? ANALYTICS_METRIC_INFO[info as AnalyticsMetricInfoKey]
      : info

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className={cn(
              'inline-flex shrink-0 items-center justify-center rounded text-muted-foreground/70 hover:text-foreground',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              className,
            )}
            aria-label={t('More info')}
            // Hints sit inside clickable tabs and rows; don't trigger them.
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
          >
            <Info className="h-3 w-3" />
          </button>
        </TooltipTrigger>
        <TooltipContent side={side} className="max-w-[240px] text-[12px]">
          {t(text)}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
