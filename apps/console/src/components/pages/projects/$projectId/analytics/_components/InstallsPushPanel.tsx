import { useMemo } from 'react'
import { useQueries } from '@tanstack/react-query'
import { AnalyticsDimension } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { Smartphone } from 'lucide-react'
import {
  analyticsEventMetricsQueryOptions,
  useAnalyticsBreakdown,
  type AnalyticsChartInterval,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { equalFilterEntry } from '@/lib/analytics/analytics-filters'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { OverviewStatTiles } from '../../shared/OverviewStatTiles'
import {
  BreakdownHeader,
  BreakdownMessage,
  BreakdownSkeleton,
} from './BreakdownPanel'
import { BreakdownRow } from './BreakdownRow'
import { useAnalyticsFilters } from './analytics-filters-context'
import { buildAnalyticsChartPoints } from './chart-series'
import { formatNumber } from './format'

const TILES = [
  { event: 'first_open', label: 'Installs' },
  { event: 'app_updated', label: 'Updates' },
  { event: 'push_received', label: 'Push received' },
  { event: 'push_opened', label: 'Push opened' },
] as const

const MESSAGE_ROWS = 5

const PUSH_OPENED_FILTER = {
  attribute: 'eventName',
  query: equalFilterEntry('eventName', 'push_opened').query,
} as const

type InstallsPushPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  /** Opens the tracking setup, for the empty state. */
  onOpenSetup: () => void
}

/**
 * Installs, updates and push notifications from the mobile tracking SDKs'
 * automatic events, with push opens per Messaging message.
 */
export function InstallsPushPanel({
  projectId,
  propertyId,
  range,
  interval,
  onOpenSetup,
}: InstallsPushPanelProps) {
  const t = useT()
  const { filters } = useAnalyticsFilters()

  const series = useQueries({
    queries: TILES.map((tile) =>
      analyticsEventMetricsQueryOptions(
        projectId,
        propertyId,
        tile.event,
        range,
        interval,
        filters,
      ),
    ),
  })
  const tiles = TILES.map((tile, index) => {
    const points = buildAnalyticsChartPoints(
      series[index].data?.points ?? [],
      range,
      interval,
    ).map((point) => point.events)
    return {
      ...tile,
      points,
      total: points.reduce((sum, value) => sum + value, 0),
      loading: series[index].data === undefined && !series[index].isError,
    }
  })

  const { breakdown, isLoading: messagesLoading } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.Props,
    range,
    undefined,
    true,
    [...filters, PUSH_OPENED_FILTER],
    'messageId',
  )
  // The API ranks by visitors; opens are events.
  const messages = useMemo(
    () =>
      breakdown
        .filter((entry) => isKnownBreakdownValue(entry.value))
        .sort((a, b) => b.events - a.events),
    [breakdown],
  )
  const maxOpens = messages[0]?.events ?? 0
  const totalOpens = messages.reduce((sum, entry) => sum + entry.events, 0)

  const loading = tiles.some((tile) => tile.loading)
  // A filter can match none of these events; only unfiltered zeros mean no SDK.
  const isEmpty =
    filters.length === 0 && !loading && tiles.every((tile) => tile.total === 0)

  return (
    <div className="flex flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader
        title="Installs and push"
        description="Installs, updates and push notifications from the tracking SDK"
      />
      {isEmpty ? (
        <div className="h-40 p-4">
          <BreakdownMessage>
            <span className="flex max-w-sm flex-col items-center gap-2">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                <Smartphone
                  className="h-4 w-4 text-muted-foreground"
                  aria-hidden
                />
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {t('No installs tracked yet')}
              </span>
              <span className="text-[12px] leading-relaxed text-muted-foreground">
                {t(
                  'Add the latest tracking SDK to your app to count installs, updates and push notifications.',
                )}
              </span>
              <Button
                variant="outline"
                size="sm"
                className="mt-1 h-8 text-[12px]"
                onClick={onOpenSetup}
              >
                {t('Add the tracking SDK')}
              </Button>
            </span>
          </BreakdownMessage>
        </div>
      ) : (
        <div className="space-y-4 p-4">
          <OverviewStatTiles
            items={tiles.map((tile) => ({
              label: t(tile.label),
              value: formatNumber(tile.total),
              points: tile.points,
              loading: tile.loading,
            }))}
          />
          <div>
            <p className="mb-2 text-[12px] font-medium text-muted-foreground">
              {t('Push opened by message')}
            </p>
            {messages.length === 0 ? (
              messagesLoading ? (
                <BreakdownSkeleton rows={MESSAGE_ROWS} />
              ) : (
                <p className="py-2 text-[12px] text-muted-foreground">
                  {t('No opened pushes with a message ID in this range')}
                </p>
              )
            ) : (
              <div className="space-y-0.5">
                {messages.slice(0, MESSAGE_ROWS).map((entry) => (
                  <BreakdownRow
                    key={entry.value}
                    label={entry.value!}
                    value={entry.events}
                    share={
                      totalOpens > 0 ? (entry.events / totalOpens) * 100 : 0
                    }
                    barPercent={
                      maxOpens > 0 ? (entry.events / maxOpens) * 100 : 0
                    }
                    mono
                  />
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
