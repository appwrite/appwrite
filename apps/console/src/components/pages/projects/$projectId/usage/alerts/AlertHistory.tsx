import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Bell, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type { Alert } from '@/lib/appwrite/monitors'
import {
  COMPARATORS,
  METRICS,
  formatLength,
  formatMetricValue,
  thresholdRatio,
} from '@/lib/alerts/metrics'
import {
  alertLength,
  barWidths,
  groupAlertsByDay,
  type AlertDay,
} from '@/lib/alerts/timeline'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { alertsQueryOptions, fetchAlert } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { AlertDrawer } from './AlertDrawer'

type Filter = 'all' | Alert['status']

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'firing', label: 'Firing' },
  { value: 'resolved', label: 'Resolved' },
]

/** `Outbound bandwidth > 10 GB`: the condition the alert fired on. */
export function alertCondition(
  alert: Alert,
  t: (text: string) => string,
): string {
  const symbol =
    COMPARATORS.find((entry) => entry.value === alert.comparator)?.symbol ??
    alert.comparator
  return `${t(METRICS[alert.metric]?.label ?? alert.metric)} ${symbol} ${formatMetricValue(alert.metric, alert.threshold)}`
}

interface AlertHistoryProps {
  projectId: string
  /** Opens this alert's drawer, as alert emails link to it. */
  openAlertId?: string
  onOpenAlertConsumed?: () => void
}

/** Every alert the project's monitors raised, newest first, as a feed grouped by day. */
export function AlertHistory({
  projectId,
  openAlertId,
  onOpenAlertConsumed,
}: AlertHistoryProps) {
  const t = useT()
  const [filter, setFilter] = useState<Filter>('all')
  const { data, isLoading } = useQuery(
    alertsQueryOptions(projectId, {
      status: filter === 'all' ? undefined : filter,
    }),
  )
  const alerts = useMemo(() => data?.alerts ?? [], [data])
  // One clock for the whole feed, so ongoing bars and day labels agree.
  const now = useMemo(() => (data ? Date.now() : 0), [data])
  const days = useMemo(() => groupAlertsByDay(alerts, now), [alerts, now])
  const widths = useMemo(() => barWidths(alerts, now), [alerts, now])
  const { formatDateTime } = useLocalizedDateFormat()
  const dayLabel = (day: AlertDay<Alert>) =>
    day.daysAgo === 0
      ? t('Today')
      : day.daysAgo === 1
        ? t('Yesterday')
        : formatDateTime(day.date, {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            ...(day.date.getFullYear() !== new Date(now).getFullYear()
              ? { year: 'numeric' }
              : {}),
          })
  const [selected, setSelected] = useState<Alert | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)

  useEffect(() => {
    if (!openAlertId) return
    onOpenAlertConsumed?.()
    // Fetched on its own: a linked alert can be beyond the first page or the filter.
    fetchAlert(projectId, openAlertId).then(
      (alert) => {
        setSelected(alert)
        setDrawerOpen(true)
      },
      (err: Error) => toast.error(getErrorMessage(err, t('Alert not found'))),
    )
  }, [openAlertId, onOpenAlertConsumed, projectId, t])

  const handleOpen = (alert: Alert) =>
    openDialogAfterOverlayCloses(() => {
      setSelected(alert)
      setDrawerOpen(true)
    })

  return (
    <div>
      <div
        role="tablist"
        aria-label={t('Filter alerts')}
        className="mb-4 flex flex-wrap gap-1.5"
      >
        {FILTERS.map(({ value, label }) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={filter === value}
            onClick={() => setFilter(value)}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-[12px] font-medium transition-colors',
              filter === value
                ? 'border-foreground/20 bg-accent text-foreground'
                : 'border-border text-muted-foreground hover:text-foreground',
            )}
          >
            {t(label)}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : alerts.length === 0 ? (
        filter === 'all' ? (
          <div className="rounded-lg border border-dashed border-border px-6 py-12 text-center">
            <Bell className="mx-auto h-6 w-6 text-muted-foreground" />
            <p className="mt-3 text-[14px] font-medium text-foreground">
              {t('No alerts yet')}
            </p>
            <p className="mx-auto mt-1 max-w-sm text-[13px] text-muted-foreground">
              {t(
                'A monitor raises an alert when its rules hold, and resolves it when usage recovers.',
              )}
            </p>
            <Link
              to="/projects/$projectId/usage/monitors"
              params={{ projectId }}
              search={{}}
              className="mt-3 inline-block text-[13px] font-medium text-foreground underline underline-offset-4"
            >
              {t('View monitors')}
            </Link>
          </div>
        ) : (
          <EmptyState icon={Bell} hasFilters variant="card" />
        )
      ) : (
        <div className="space-y-6">
          {days.map((day) => (
            <section key={day.key} aria-label={dayLabel(day)}>
              <div className="mb-1 flex items-center gap-3">
                <h3 className="text-[12px] font-medium text-muted-foreground">
                  {dayLabel(day)}
                </h3>
                <span className="h-px flex-1 bg-border" />
              </div>
              <ol>
                {day.alerts.map((alert) => (
                  <AlertEntry
                    key={alert.$id}
                    alert={alert}
                    now={now}
                    width={widths.get(alert) ?? 0}
                    time={formatDateTime(new Date(alert.$createdAt), {
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                    onOpen={() => handleOpen(alert)}
                  />
                ))}
              </ol>
            </section>
          ))}
        </div>
      )}

      <AlertDrawer
        projectId={projectId}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        alert={selected}
      />
    </div>
  )
}

interface AlertEntryProps {
  alert: Alert
  now: number
  /** Bar width in percent of the track, on the feed's shared scale. */
  width: number
  time: string
  onOpen: () => void
}

/** One alert in the feed: when it started, how long it fired, and how far it went. */
function AlertEntry({ alert, now, width, time, onOpen }: AlertEntryProps) {
  const t = useT()
  const firing = alert.status === 'firing'
  const ratio = thresholdRatio({ ...alert, value: alert.peak })
  const length = formatLength(alertLength(alert, now))
  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        className="grid w-full grid-cols-[60px_10px_minmax(0,1fr)] items-start gap-x-3 rounded-md px-2 py-2.5 text-start transition-colors hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none sm:grid-cols-[68px_10px_minmax(0,1fr)_minmax(120px,200px)]"
      >
        <span className="whitespace-nowrap pt-px text-[12px] tabular-nums text-muted-foreground">
          {time}
        </span>
        <span
          aria-hidden
          className={cn(
            'mt-[5px] h-2.5 w-2.5 rounded-full',
            firing
              ? 'bg-red-500'
              : 'border-[1.5px] border-emerald-500 dark:border-emerald-400',
          )}
        />
        <span className="min-w-0">
          <span className="flex items-baseline gap-2">
            <span className="truncate text-[13px] font-medium text-foreground">
              {alert.name}
            </span>
            <span
              className={cn(
                'shrink-0 text-[11px] font-medium',
                firing
                  ? 'text-red-600 dark:text-red-400'
                  : 'text-emerald-700 dark:text-emerald-400',
              )}
            >
              {firing ? t('Firing') : t('Resolved')}
            </span>
          </span>
          <span className="mt-0.5 flex flex-wrap text-[12px] text-muted-foreground sm:block sm:truncate">
            {/* Each part stays whole with the dot after it, so a narrow screen wraps between parts. */}
            {[
              alertCondition(alert, t),
              <>
                {t('peak')}{' '}
                <span
                  className={cn(firing && 'text-red-600 dark:text-red-400')}
                >
                  {formatMetricValue(alert.metric, alert.peak)}
                  {ratio ? ` (${ratio})` : ''}
                </span>
              </>,
              t('{count} sent').replace('{count}', String(alert.notifications)),
              firing ? t('ongoing') : length,
            ].map((part, index, parts) => (
              <span
                key={index}
                className={cn(
                  'whitespace-nowrap',
                  index === parts.length - 1 && 'sm:hidden',
                )}
              >
                {part}
                {index < parts.length - 1 && (
                  <span
                    className={cn(
                      'mx-1.5',
                      index === parts.length - 2 && 'sm:hidden',
                    )}
                  >
                    ·
                  </span>
                )}
              </span>
            ))}
          </span>
        </span>
        <span className="hidden items-center gap-2 pt-1 sm:flex">
          <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted rtl:-scale-x-100">
            <span
              className={cn(
                'block h-full rounded-full',
                firing
                  ? 'bg-gradient-to-r from-red-500 from-50% to-red-500/15'
                  : 'bg-foreground/35',
              )}
              style={{ width: `${width}%` }}
            />
          </span>
          <span
            className={cn(
              'w-14 shrink-0 text-end text-[12px] tabular-nums',
              firing
                ? 'text-red-600 dark:text-red-400'
                : 'text-muted-foreground',
            )}
          >
            {firing ? t('ongoing') : length}
          </span>
        </span>
      </button>
    </li>
  )
}
