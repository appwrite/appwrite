import { useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  CircleAlert,
  CircleCheck,
  CircleDashed,
  CirclePause,
  type LucideIcon,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  alertsInfiniteQueryOptions,
  monitorQueryOptions,
  useUpdateAlert,
} from '@/lib/react-query/hooks'
import type { Alert, ParsedMonitor } from '@/lib/appwrite/monitors'
import {
  COMPARATORS,
  WINDOWS,
  formatLength,
  formatMetricValue,
  metricDefinition,
} from '@/lib/alerts/metrics'
import {
  formatLatency,
  isProbeType,
  probeTarget,
  resultLabel,
} from '@/lib/alerts/probes'
import { alertLength } from '@/lib/alerts/timeline'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { RuleChart } from '../RuleChart'
import { SEVERITIES } from '../RuleFields'
import { ruleState, type RuleState } from '../StatusPill'
import { AlertDrawer, acknowledgedBy } from '../alerts/AlertDrawer'
import { alertRatio, formatAlertValue } from '../alerts/AlertHistory'
import { STALE_AFTER_MS } from './Details'
import { SettingsCard } from './settings/Card'
import {
  OverviewStatTiles,
  type OverviewStatTile,
} from '../../shared/OverviewStatTiles'

/** How many past alerts the page lists before pointing to the alerts feed. */
const RECENT = 10

/** What a monitor is doing now, where it fired, and its recent alerts. */
export function MonitorOverview({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  const { data: monitor } = useQuery(monitorQueryOptions(projectId, monitorId))
  const { data } = useInfiniteQuery(
    alertsInfiniteQueryOptions(projectId, { monitorId }),
  )
  const alerts = useMemo(
    () => data?.pages.flatMap((page) => page.alerts) ?? [],
    [data],
  )
  const [selected, setSelected] = useState<Alert | null>(null)
  const [drawerOpen, setDrawerOpen] = useState(false)

  if (!monitor) return null

  const probe = isProbeType(monitor.type)
  const condition = conditionText(monitor, t)
  const open = alerts.filter((alert) => alert.status === 'firing')
  const recent = alerts.slice(0, RECENT)
  const openAlert = (alert: Alert) => {
    setSelected(alert)
    setDrawerOpen(true)
  }

  return (
    <div className="w-full min-w-0 space-y-6 overflow-x-hidden">
      <MonitorStats monitor={monitor} />

      {open.length > 0 && (
        <SettingsCard
          title={t('Open alerts')}
          description={t(
            'Alerts still firing. Acknowledge one to tell the team someone is on it.',
          )}
          flush
        >
          <ul className="divide-y divide-border">
            {open.map((alert) => (
              <OpenAlert
                key={alert.$id}
                projectId={projectId}
                alert={alert}
                onOpen={() => openAlert(alert)}
              />
            ))}
          </ul>
        </SettingsCard>
      )}

      {!probe && (
        <SettingsCard
          title={t('History')}
          description={`${t('Fires when')} ${condition}. ${t('Shaded where it fired.')}`}
        >
          <RuleChart
            projectId={projectId}
            height={240}
            rule={{
              metric: monitor.metric,
              resourceType: monitor.resourceType,
              resourceId: monitor.resourceId,
              window: monitor.window,
              comparator: monitor.comparator,
              threshold: monitor.threshold,
            }}
            incidents={alerts.map((alert) => ({
              start: Date.parse(alert.$createdAt),
              end: alert.resolvedAt ? Date.parse(alert.resolvedAt) : Date.now(),
            }))}
          />
        </SettingsCard>
      )}

      <SettingsCard
        title={t('Recent alerts')}
        description={
          alerts.length > RECENT ? (
            <>
              {t('The latest alerts this monitor raised.')}{' '}
              <Link
                to="/projects/$projectId/monitors/alerts"
                params={{ projectId }}
                className="text-foreground underline underline-offset-2"
              >
                {t('View all')}
              </Link>
            </>
          ) : (
            t('The latest alerts this monitor raised.')
          )
        }
        flush
      >
        {recent.length === 0 ? (
          <p className="px-6 py-8 text-center text-[13px] text-muted-foreground">
            {t('This monitor has not fired yet.')}
          </p>
        ) : (
          <table className="w-full text-[13px]">
            <thead>
              <tr className="border-b border-border text-[12px] text-muted-foreground">
                <th className="px-6 py-2.5 text-start font-medium">
                  {t('Started')}
                </th>
                <th className="px-6 py-2.5 text-start font-medium">
                  {t('Duration')}
                </th>
                <th className="hidden px-6 py-2.5 text-start font-medium sm:table-cell">
                  {probe ? t('Peak latency') : t('Peak')}
                </th>
                <th className="hidden px-6 py-2.5 text-start font-medium md:table-cell">
                  {t('Notifications')}
                </th>
                <th className="px-6 py-2.5 text-end font-medium">
                  {t('Status')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {recent.map((alert) => {
                const firing = alert.status === 'firing'
                const ratio = alertRatio(alert)
                return (
                  <tr
                    key={alert.$id}
                    onClick={() => openAlert(alert)}
                    className="cursor-pointer transition-colors hover:bg-accent/30"
                  >
                    <td className="px-6 py-3 text-foreground">
                      <DateTooltip date={alert.$createdAt} />
                    </td>
                    <td className="px-6 py-3 tabular-nums text-muted-foreground">
                      {firing ? t('ongoing') : formatLength(alertLength(alert))}
                    </td>
                    <td className="hidden px-6 py-3 tabular-nums text-muted-foreground sm:table-cell">
                      {formatAlertValue(alert, alert.peak)}
                      {ratio ? ` (${ratio})` : ''}
                    </td>
                    <td className="hidden px-6 py-3 tabular-nums text-muted-foreground md:table-cell">
                      {alert.notifications}
                    </td>
                    <td className="px-6 py-3 text-end">
                      <span
                        className={cn(
                          'inline-flex items-center gap-1.5 text-[12px]',
                          firing
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-muted-foreground',
                        )}
                      >
                        <span
                          aria-hidden
                          className={cn(
                            'h-1.5 w-1.5 rounded-full',
                            firing ? 'bg-red-500' : 'bg-emerald-500',
                          )}
                        />
                        {firing ? t('Firing') : t('Resolved')}
                      </span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </SettingsCard>

      <AlertDrawer
        projectId={projectId}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        alert={selected}
        onAlertChange={setSelected}
      />
    </div>
  )
}

const STATES: Record<
  RuleState,
  { label: string; icon: LucideIcon; tone: string }
> = {
  firing: {
    label: 'Firing',
    icon: CircleAlert,
    tone: 'text-red-600 dark:text-red-400',
  },
  ok: {
    label: 'OK',
    icon: CircleCheck,
    tone: 'text-emerald-600 dark:text-emerald-400',
  },
  pending: {
    label: 'Pending',
    icon: CircleDashed,
    tone: 'text-blue-600 dark:text-blue-400',
  },
  paused: {
    label: 'Paused',
    icon: CirclePause,
    tone: 'text-muted-foreground',
  },
}

/** The monitor at a glance, as the top tiles of a function or a site. */
function MonitorStats({ monitor }: { monitor: ParsedMonitor }) {
  const t = useT()
  const probe = isProbeType(monitor.type)
  const state = ruleState(monitor)
  const firing = state === 'firing'
  const status = STATES[state]
  const StatusIcon = status.icon
  const level = SEVERITIES.find(({ value }) => value === monitor.severity)
  const LevelIcon = level?.icon
  // A probe with a long interval is not stale until it misses a few checks.
  const stale =
    monitor.enabled &&
    !!monitor.evaluatedAt &&
    Date.now() - new Date(monitor.evaluatedAt).getTime() >
      Math.max(STALE_AFTER_MS, monitor.interval * 3 * 1000)

  const reading = probe
    ? monitor.result
      ? monitor.latency != null
        ? formatLatency(monitor.latency)
        : t(resultLabel(monitor.result))
      : null
    : monitor.value != null
      ? formatMetricValue(monitor.metric, monitor.value)
      : null
  const threshold = probe
    ? monitor.result
      ? t(resultLabel(monitor.result))
      : undefined
    : `${COMPARATORS.find((entry) => entry.value === monitor.comparator)?.symbol ?? ''} ${formatMetricValue(monitor.metric, monitor.threshold)}`.trim()
  const iconValue = (Icon: LucideIcon, tone: string, text: string) => (
    <span className="inline-flex min-w-0 items-center gap-2">
      <Icon aria-hidden className={cn('h-[18px] w-[18px] shrink-0', tone)} />
      <span className="truncate">{text}</span>
    </span>
  )

  const items: OverviewStatTile[] = [
    {
      label: t('Status'),
      value: iconValue(StatusIcon, status.tone, t(status.label)),
    },
    // A check has no chart to say what it calls, so its target leads.
    ...(probe
      ? [
          {
            label: t('Target'),
            value: (
              <span
                dir="ltr"
                title={probeTarget(monitor)}
                className="block truncate font-mono text-[15px]"
              >
                {probeTarget(monitor)}
              </span>
            ),
          },
        ]
      : []),
    {
      label: probe ? t('Last check') : t('Last value'),
      hint: threshold,
      value: reading ? (
        <span className={cn(firing && 'text-red-600 dark:text-red-400')}>
          {reading}
        </span>
      ) : (
        '—'
      ),
    },
    {
      label: firing ? t('Firing since') : t('Last fired'),
      value: monitor.triggeredAt ? (
        <DateTooltip
          date={monitor.triggeredAt}
          live
          className="text-[20px] font-semibold text-foreground"
        />
      ) : (
        t('Never')
      ),
    },
    {
      label: t('Severity'),
      value: LevelIcon
        ? iconValue(LevelIcon, level.tone, t(level.label))
        : monitor.severity,
    },
  ]

  return (
    <div className="space-y-3">
      <OverviewStatTiles items={items} />
      {stale && (
        <p className="rounded-md bg-amber-500/10 px-3 py-2 text-[12px] text-foreground">
          {probe
            ? t(
                'Checks run on the monitor interval, so this monitor is not being checked.',
              )
            : t(
                'Checks run every few minutes, so this monitor is not being evaluated.',
              )}
        </p>
      )}
    </div>
  )
}

/** What the monitor watches, in words: its condition, or a check's target. */
function conditionText(
  monitor: ParsedMonitor,
  t: ReturnType<typeof useT>,
): string {
  if (isProbeType(monitor.type)) return probeTarget(monitor)
  return [
    t(metricDefinition(monitor.metric).label),
    COMPARATORS.find((entry) => entry.value === monitor.comparator)?.symbol,
    formatMetricValue(monitor.metric, monitor.threshold),
    metricDefinition(monitor.metric).counter
      ? `${t('within')} ${t(WINDOWS.find((entry) => entry.value === monitor.window)?.label ?? `${monitor.window}s`)}`
      : null,
  ]
    .filter(Boolean)
    .join(' ')
}

/** A firing alert with who has it, and the action to take it on. */
function OpenAlert({
  projectId,
  alert,
  onOpen,
}: {
  projectId: string
  alert: Alert
  onOpen: () => void
}) {
  const t = useT()
  const update = useUpdateAlert(projectId)
  const acknowledged = !!alert.acknowledgedAt
  const ratio = alertRatio(alert)
  // Servers whose alerts carry no acknowledgement leave the action out.
  const acknowledgeable = 'acknowledgedAt' in alert

  const toggle = () =>
    update.mutate(
      { alertId: alert.$id, acknowledged: !acknowledged },
      {
        onError: (error) =>
          toast.error(
            getErrorMessage(
              error,
              acknowledged
                ? t('Failed to undo the acknowledgement')
                : t('Failed to acknowledge the alert'),
            ),
          ),
      },
    )

  return (
    <li className="flex items-center gap-3 px-6 py-3">
      <span aria-hidden className="h-2 w-2 shrink-0 rounded-full bg-red-500" />
      <button
        type="button"
        onClick={onOpen}
        className="min-w-0 flex-1 text-start"
      >
        <span className="block truncate text-[13px] font-medium text-foreground">
          {t('Firing since')} <DateTooltip date={alert.$createdAt} />
        </span>
        <span className="block truncate text-[12px] text-muted-foreground">
          {t('peak')} {formatAlertValue(alert, alert.peak)}
          {ratio ? ` (${ratio})` : ''}
          {' · '}
          {acknowledged
            ? acknowledgedBy(alert, t)
            : t('Nobody has acknowledged this alert')}
        </span>
      </button>
      {acknowledgeable && (
        <Button
          type="button"
          size="sm"
          variant={acknowledged ? 'outline' : 'default'}
          className="h-9 shrink-0 text-[13px]"
          onClick={toggle}
          disabled={update.isPending}
        >
          {acknowledged ? t('Undo') : t('Acknowledge')}
        </Button>
      )}
    </li>
  )
}
