import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { Bell, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type { Alert } from '@/lib/appwrite/monitors'
import {
  COMPARATORS,
  METRICS,
  formatLength,
  formatMetricValue,
  thresholdRatio,
} from '@/lib/alerts/metrics'
import { alertsQueryOptions, fetchAlert } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { StatusPill } from '../monitors/StatusPill'
import { AlertDrawer } from './AlertDrawer'

type Filter = 'all' | Alert['status']

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'firing', label: 'Firing' },
  { value: 'resolved', label: 'Resolved' },
]

/** Seconds an alert fired for, up to now while it still is. */
export function alertLength(alert: Alert, now = Date.now()): number {
  const end = alert.resolvedAt ? Date.parse(alert.resolvedAt) : now
  return (end - Date.parse(alert.$createdAt)) / 1000
}

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

/** Every alert the project's monitors raised, newest first. */
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
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
          {alerts.map((alert) => {
            const firing = alert.status === 'firing'
            const ratio = thresholdRatio({ ...alert, value: alert.peak })
            return (
              <li
                key={alert.$id}
                className={cn(
                  'relative flex cursor-pointer items-center gap-4 px-4 py-3 transition-colors hover:bg-accent/30',
                  firing &&
                    'before:absolute before:inset-y-0 before:start-0 before:w-0.5 before:bg-red-500',
                )}
                onClick={() => handleOpen(alert)}
              >
                <StatusPill
                  status={alert.status}
                  className="w-[76px] shrink-0"
                />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-foreground">
                    {alert.name}
                  </p>
                  <p className="truncate text-[12px] text-muted-foreground">
                    {alertCondition(alert, t)}
                    <span className="mx-1.5">·</span>
                    <DateTooltip date={alert.$createdAt} />
                  </p>
                </div>
                <div className="hidden w-28 shrink-0 text-end sm:block">
                  <p className="text-[13px] font-medium tabular-nums text-foreground">
                    {formatLength(alertLength(alert))}
                  </p>
                  <p className="text-[11px] text-muted-foreground">
                    {firing ? t('Ongoing') : t('Duration')}
                  </p>
                </div>
                <div className="w-32 shrink-0 text-end">
                  <p
                    className={cn(
                      'text-[13px] font-medium tabular-nums',
                      firing ? 'text-red-500' : 'text-foreground',
                    )}
                  >
                    {formatMetricValue(alert.metric, alert.peak)}
                  </p>
                  <p className="text-[11px] text-muted-foreground">
                    {ratio ? `${t('Peak')} · ${ratio}` : t('Peak')}
                  </p>
                </div>
                <div className="hidden w-16 shrink-0 text-end md:block">
                  <p className="text-[13px] tabular-nums text-foreground">
                    {alert.notifications}
                  </p>
                  <p className="text-[11px] text-muted-foreground">
                    {t('Sent')}
                  </p>
                </div>
              </li>
            )
          })}
        </ul>
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
