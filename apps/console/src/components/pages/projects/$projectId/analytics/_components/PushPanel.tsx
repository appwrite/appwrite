import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { useQueries, useQuery } from '@tanstack/react-query'
import { AnalyticsDimension, Query, type Models } from '@appwrite.io/console'
import { BellRing } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { sdk } from '@/lib/appwrite/sdk'
import {
  analyticsEventMetricsQueryOptions,
  useAnalyticsBreakdown,
  useAnalyticsEvents,
  type AnalyticsChartInterval,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import {
  equalFilterEntry,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { BreakdownHeader, BreakdownMessage } from './BreakdownPanel'
import { BREAKDOWN_DIALOG_MAX_ROWS } from './BreakdownDialog'
import { ChangeBadge } from './ChangeBadge'
import { useAnalyticsFilters } from './analytics-filters-context'
import {
  analyticsChangePercent,
  buildAnalyticsChartPoints,
} from './chart-series'
import { formatNumber, formatPercent } from './format'
import { MOBILE_CARD_BODY_CLASS } from './InstallsPanel'

const RECEIVED_EVENT = 'push_received'
const OPENED_EVENT = 'push_opened'
/** The Messaging message a push came from, sent as a prop by the SDK. */
const MESSAGE_PROP = 'messageId'
// Asked without the page's filters (mobile opens on humans only), which can
// match none of these events even when the SDK sends them.
const SDK_EVENTS_FILTERS = [
  {
    attribute: 'eventName',
    query: Query.equal('eventName', [RECEIVED_EVENT, OPENED_EVENT]),
  },
] as const

/** Messages listed under the funnel. */
const MESSAGE_ROWS = 4

function eventFilter(eventName: string): AnalyticsFilter {
  return {
    attribute: 'eventName',
    query: equalFilterEntry('eventName', eventName).query,
  }
}

type PushPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  /** The window the change badges compare against; none when comparison is off. */
  comparisonRange: AnalyticsRange | null
}

/**
 * Push notifications as a funnel: received on the device, then opened, with
 * the open rate, and the messages that drove the opens, named and linked as
 * Messaging messages, with how many Messaging delivered.
 */
export function PushPanel({
  projectId,
  propertyId,
  range,
  interval,
  comparisonRange,
}: PushPanelProps) {
  const t = useT()
  const { filters } = useAnalyticsFilters()

  const windows = comparisonRange ? [range, comparisonRange] : [range]
  const series = useQueries({
    queries: windows.flatMap((window) =>
      [RECEIVED_EVENT, OPENED_EVENT].map((event) =>
        analyticsEventMetricsQueryOptions(
          projectId,
          propertyId,
          event,
          window,
          interval,
          filters,
        ),
      ),
    ),
  })
  const totalAt = (index: number, window: AnalyticsRange) =>
    buildAnalyticsChartPoints(
      series[index]?.data?.points ?? [],
      window,
      interval,
    ).reduce((sum, point) => sum + point.events, 0)
  const received = totalAt(0, range)
  const opened = totalAt(1, range)
  const rate = received > 0 ? opened / received : null
  const previous = comparisonRange
    ? {
        received: totalAt(2, comparisonRange),
        opened: totalAt(3, comparisonRange),
      }
    : null
  const previousRate =
    previous && previous.received > 0
      ? previous.opened / previous.received
      : null
  const change = (current: number, before: number | undefined) =>
    before && before > 0 ? analyticsChangePercent(current, before) : undefined

  // Receipts and opens per message, joined on the message ID.
  const openedBy = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.Props,
    range,
    BREAKDOWN_DIALOG_MAX_ROWS,
    true,
    [...filters, eventFilter(OPENED_EVENT)],
    MESSAGE_PROP,
  )
  const receivedBy = useAnalyticsBreakdown(
    projectId,
    propertyId,
    AnalyticsDimension.Props,
    range,
    BREAKDOWN_DIALOG_MAX_ROWS,
    true,
    [...filters, eventFilter(RECEIVED_EVENT)],
    MESSAGE_PROP,
  )
  const messages = useMemo(() => {
    const rows = new Map<string, { received: number; opened: number }>()
    for (const entry of receivedBy.breakdown) {
      if (!isKnownBreakdownValue(entry.value)) continue
      rows.set(entry.value!, { received: entry.events, opened: 0 })
    }
    for (const entry of openedBy.breakdown) {
      if (!isKnownBreakdownValue(entry.value)) continue
      const row = rows.get(entry.value!) ?? { received: 0, opened: 0 }
      rows.set(entry.value!, { ...row, opened: entry.events })
    }
    return [...rows.entries()]
      .map(([messageId, counts]) => ({ messageId, ...counts }))
      .sort((a, b) => b.opened - a.opened || b.received - a.received)
      .slice(0, MESSAGE_ROWS)
  }, [receivedBy.breakdown, openedBy.breakdown])

  const messageIds = messages.map((row) => row.messageId)
  const { data: messageDocs } = useQuery({
    queryKey: ['messaging', 'messages', 'by-id', projectId, ...messageIds],
    queryFn: async () => {
      const response = await sdk.forProject(projectId).messaging.listMessages({
        queries: [
          Query.equal('$id', messageIds),
          Query.limit(messageIds.length),
        ],
        total: false,
      })
      return new Map(response.messages.map((message) => [message.$id, message]))
    },
    enabled: messageIds.length > 0,
    staleTime: 60_000,
    // No messages.read, or IDs from another sender: rows stay IDs.
    retry: false,
  })

  const loading = series
    .slice(0, 2)
    .some((query) => query.data === undefined && !query.isError)
  const error = series.find((query) => query.isError)?.error
  // Filtered zeros only mean no pushes once the SDK's own push events,
  // unfiltered, come back empty too; an error there is unknown, not empty.
  const sdkEvents = useAnalyticsEvents(
    projectId,
    filters.length > 0 ? propertyId : null,
    range,
    undefined,
    SDK_EVENTS_FILTERS,
  )
  const isEmpty =
    !loading &&
    received + opened === 0 &&
    (filters.length === 0 ||
      (!sdkEvents.isLoading &&
        !sdkEvents.error &&
        sdkEvents.events.length === 0))

  return (
    <div className="flex h-full flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader
        title="Push notifications"
        description="Pushes received on devices, and how many were opened"
      />
      <div className={cn('p-4', MOBILE_CARD_BODY_CLASS)}>
        {error ? (
          <BreakdownMessage>
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </BreakdownMessage>
        ) : isEmpty ? (
          <BreakdownMessage>
            <span className="flex max-w-sm flex-col items-center gap-2">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                <BellRing
                  className="h-4 w-4 text-muted-foreground"
                  aria-hidden
                />
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {t('No push notifications tracked yet')}
              </span>
              <span className="text-[12px] leading-relaxed text-muted-foreground">
                {t(
                  'Pushes sent with Messaging show their reach and opens here once the tracking SDK is in your app.',
                )}
              </span>
              <Button
                variant="outline"
                size="sm"
                className="mt-1 h-8 text-[12px]"
                asChild
              >
                <Link
                  to="/projects/$projectId/messaging"
                  params={{ projectId }}
                >
                  {t('Open Messaging')}
                </Link>
              </Button>
            </span>
          </BreakdownMessage>
        ) : (
          <div className="flex h-full flex-col gap-4">
            <div className="grid grid-cols-3 gap-4">
              <Stat
                label={t('Received')}
                value={formatNumber(received)}
                change={change(received, previous?.received)}
                loading={loading}
              />
              <Stat
                label={t('Opened')}
                value={formatNumber(opened)}
                change={change(opened, previous?.opened)}
                loading={loading}
              />
              <Stat
                label={t('Open rate')}
                value={rate === null ? '—' : formatPercent(rate * 100)}
                change={
                  rate !== null && previousRate
                    ? analyticsChangePercent(rate, previousRate)
                    : undefined
                }
                loading={loading}
              />
            </div>

            {/* Received is the whole bar; opened is its share. */}
            <div className="space-y-1.5" aria-hidden={loading}>
              <FunnelBar
                label={t('Received')}
                value={received}
                percent={received > 0 ? 100 : 0}
                tone="muted"
              />
              <FunnelBar
                label={t('Opened')}
                value={opened}
                percent={received > 0 ? (opened / received) * 100 : 0}
                tone="brand"
              />
            </div>

            <div className="min-h-0 flex-1">
              <div className="mb-1 grid grid-cols-[minmax(0,1fr)_repeat(3,56px)] gap-2 px-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                <span>{t('Message')}</span>
                <span className="text-end">{t('Received')}</span>
                <span className="text-end">{t('Opened')}</span>
                <span className="text-end">{t('Rate')}</span>
              </div>
              {messages.length === 0 ? (
                <p className="px-1.5 py-2 text-[12px] text-muted-foreground">
                  {openedBy.isLoading || receivedBy.isLoading
                    ? t('Loading…')
                    : t('No pushes with a message ID in this range')}
                </p>
              ) : (
                <div className="space-y-0.5">
                  {messages.map((row) => (
                    <MessageRow
                      key={row.messageId}
                      projectId={projectId}
                      messageId={row.messageId}
                      message={messageDocs?.get(row.messageId)}
                      received={row.received}
                      opened={row.opened}
                    />
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function Stat({
  label,
  value,
  change,
  loading,
}: {
  label: string
  value: string
  change?: number
  loading: boolean
}) {
  return (
    <div className="min-w-0">
      <p className="truncate text-[12px] text-muted-foreground">{label}</p>
      <div className="mt-1 flex h-7 items-baseline gap-2">
        {loading ? (
          <span className="h-6 w-14 animate-pulse self-center rounded bg-muted" />
        ) : (
          <>
            <span className="text-[22px] font-semibold leading-none tabular-nums text-foreground">
              {value}
            </span>
            <ChangeBadge change={change} />
          </>
        )}
      </div>
    </div>
  )
}

function FunnelBar({
  label,
  value,
  percent,
  tone,
}: {
  label: string
  value: number
  percent: number
  tone: 'muted' | 'brand'
}) {
  return (
    <div className="relative flex h-6 items-center overflow-hidden rounded-md bg-muted/30 px-2">
      <span
        className={cn(
          'absolute inset-y-0 start-0 rounded-md',
          tone === 'brand' ? 'bg-[var(--chart-brand)]/25' : 'bg-muted',
        )}
        style={{ width: `${Math.max(0, Math.min(100, percent))}%` }}
      />
      <span className="relative flex-1 text-[11px] text-foreground">
        {label}
      </span>
      <span className="relative text-[11px] font-medium tabular-nums text-foreground">
        {formatNumber(value)}
      </span>
    </div>
  )
}

/**
 * One message: its push title (or ID, for pushes not sent by Messaging), the
 * devices that received and opened it, and the open rate. Messaging messages
 * link to their page and show how many devices Messaging delivered to.
 */
function MessageRow({
  projectId,
  messageId,
  message,
  received,
  opened,
}: {
  projectId: string
  messageId: string
  message: Models.Message | undefined
  received: number
  opened: number
}) {
  const t = useT()
  const title = (message?.data as { title?: string } | undefined)?.title
  const label = title || messageId
  const cells = (
    <>
      <span className="min-w-0">
        <span
          className={cn(
            'block truncate text-[12px] text-foreground',
            !title && 'font-mono text-[11px]',
          )}
          title={label}
        >
          {label}
        </span>
        {message ? (
          <span className="block truncate text-[10px] text-muted-foreground">
            {t('{count} delivered').replace(
              '{count}',
              formatNumber(message.deliveredTotal),
            )}
          </span>
        ) : null}
      </span>
      <span className="text-end text-[12px] tabular-nums text-muted-foreground">
        {formatNumber(received)}
      </span>
      <span className="text-end text-[12px] font-medium tabular-nums text-foreground">
        {formatNumber(opened)}
      </span>
      <span className="text-end text-[12px] tabular-nums text-muted-foreground">
        {received > 0 ? formatPercent((opened / received) * 100) : '—'}
      </span>
    </>
  )
  const className =
    'grid min-h-7 grid-cols-[minmax(0,1fr)_repeat(3,56px)] items-center gap-2 rounded-md px-1.5 py-1'
  return message ? (
    <Link
      to="/projects/$projectId/messaging/$messageId"
      params={{ projectId, messageId }}
      className={cn(
        className,
        'transition-colors hover:bg-accent/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      )}
      title={t('View message')}
    >
      {cells}
    </Link>
  ) : (
    <div className={className}>{cells}</div>
  )
}
