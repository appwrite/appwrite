import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import type { Alert } from '@/lib/appwrite/monitors'
import {
  RESOURCE_TYPES,
  formatLength,
  formatMetricValue,
  thresholdRatio,
} from '@/lib/alerts/metrics'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { StatusPill } from '../monitors/StatusPill'
import { alertLength } from '@/lib/alerts/timeline'
import { alertCondition } from './AlertHistory'

function Stat({
  label,
  value,
  note,
  hot = false,
}: {
  label: string
  value: ReactNode
  note?: ReactNode
  hot?: boolean
}) {
  return (
    <div className="min-w-0 bg-card px-4 py-3">
      <p className="text-[11px] text-muted-foreground">{label}</p>
      <p
        className={cn(
          'mt-0.5 truncate text-[22px] font-semibold leading-tight tabular-nums',
          hot ? 'text-red-500' : 'text-foreground',
        )}
      >
        {value}
      </p>
      {note && (
        <p className="truncate text-[12px] text-muted-foreground">{note}</p>
      )}
    </div>
  )
}

function Step({
  tone,
  title,
  children,
}: {
  tone: 'firing' | 'resolved' | 'muted'
  title: string
  children: ReactNode
}) {
  return (
    <li className="relative ps-6">
      <span
        className={cn(
          'absolute start-0 top-1.5 h-2.5 w-2.5 rounded-full',
          tone === 'firing' && 'bg-red-500',
          tone === 'resolved' && 'bg-emerald-500',
          tone === 'muted' && 'bg-muted-foreground/50',
        )}
      />
      <p className="text-[13px] font-medium text-foreground">{title}</p>
      <p className="text-[12px] text-muted-foreground">{children}</p>
    </li>
  )
}

interface AlertDrawerProps {
  projectId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  alert: Alert | null
}

/** One alert: how far over its threshold it went, and its timeline. */
export function AlertDrawer({
  projectId,
  open,
  onOpenChange,
  alert,
}: AlertDrawerProps) {
  const t = useT()
  const firing = alert?.status === 'firing'
  const ratio = alert ? thresholdRatio({ ...alert, value: alert.peak }) : null
  const scope =
    !alert || alert.resourceType === 'project'
      ? t('Project')
      : `${t(RESOURCE_TYPES.find((type) => type.value === alert.resourceType)?.label ?? alert.resourceType)} · ${alert.resourceId}`

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={alert?.name ?? t('Alert')}
      maxWidth="sm:max-w-2xl"
      headerActions={alert ? <StatusPill status={alert.status} /> : null}
    >
      <>
        <div className="border-t border-border shrink-0" />
        {alert && (
          <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
            <div className="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-3">
              <Stat
                label={firing ? t('Latest reading') : t('Peak')}
                value={formatMetricValue(
                  alert.metric,
                  firing ? alert.value : alert.peak,
                )}
                note={
                  ratio
                    ? `${ratio} ${t('the threshold')}`
                    : alertCondition(alert, t)
                }
                hot={firing}
              />
              <Stat
                label={firing ? t('Firing for') : t('Fired for')}
                value={formatLength(alertLength(alert))}
                note={
                  firing ? (
                    t('Ongoing')
                  ) : (
                    <DateTooltip date={alert.$createdAt} />
                  )
                }
              />
              <Stat
                label={t('Notifications')}
                value={alert.notifications}
                note={alert.channels.join(', ')}
              />
            </div>

            <dl className="grid grid-cols-[120px_minmax(0,1fr)] gap-y-2 text-[13px]">
              <dt className="text-muted-foreground">{t('Condition')}</dt>
              <dd className="text-foreground">{alertCondition(alert, t)}</dd>
              <dt className="text-muted-foreground">{t('Watches')}</dt>
              <dd className="truncate text-foreground">{scope}</dd>
              <dt className="text-muted-foreground">{t('Peak')}</dt>
              <dd className="text-foreground">
                {formatMetricValue(alert.metric, alert.peak)}
              </dd>
            </dl>

            <section>
              <h3 className="mb-3 text-[13px] font-semibold text-foreground">
                {t('Timeline')}
              </h3>
              <ol className="space-y-4 border-s border-border ps-0 [&>li]:-ms-[5px]">
                <Step tone="firing" title={t('Started firing')}>
                  <DateTooltip date={alert.$createdAt} />
                </Step>
                {alert.notifications > 1 && (
                  <Step tone="muted" title={t('Still firing')}>
                    {t('{count} notifications sent, one per cooldown').replace(
                      '{count}',
                      String(alert.notifications),
                    )}
                  </Step>
                )}
                {alert.resolvedAt ? (
                  <Step tone="resolved" title={t('Resolved')}>
                    <DateTooltip date={alert.resolvedAt} />
                  </Step>
                ) : (
                  <Step tone="muted" title={t('Waiting for recovery')}>
                    {t("Resolves when the monitor's rules stop holding.")}
                  </Step>
                )}
              </ol>
            </section>

            <Button asChild variant="outline" size="sm">
              <Link
                to="/projects/$projectId/usage/monitors"
                params={{ projectId }}
                search={{ monitor: alert.monitorId }}
                onClick={() => onOpenChange(false)}
              >
                {t('View monitor')}
              </Link>
            </Button>
          </div>
        )}
      </>
    </BaseDrawer>
  )
}
