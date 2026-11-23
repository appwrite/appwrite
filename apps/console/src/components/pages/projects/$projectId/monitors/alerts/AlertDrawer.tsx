import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { Loader2, UserCheck } from 'lucide-react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import type { Alert } from '@/lib/appwrite/monitors'
import { RESOURCE_TYPES, formatLength } from '@/lib/alerts/metrics'
import { PROBE_TYPES } from '@/lib/alerts/probes'
import { useT } from '@/lib/i18n/translate'
import {
  useEscalations,
  useProject,
  useUpdateAlert,
} from '@/lib/react-query/hooks'
import { escalationState } from '@/lib/alerts/escalation'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { StatusPill } from '../StatusPill'
import { alertLength } from '@/lib/alerts/timeline'
import { alertCondition, alertRatio, formatAlertValue } from './AlertHistory'

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

/** Who acknowledged the alert: a member by name, or an API key, which has none. */
export function acknowledgedBy(
  alert: Alert,
  t: (text: string) => string,
): string {
  if (alert.acknowledgedByName) {
    return t('Acknowledged by {name}').replace(
      '{name}',
      alert.acknowledgedByName,
    )
  }
  return alert.acknowledgedBy
    ? t('Acknowledged by a member')
    : t('Acknowledged with an API key')
}

/**
 * Lets a member tell the team they have a firing alert, or take that back.
 * Hidden on servers whose alerts carry no acknowledgement.
 */
function Acknowledgement({
  projectId,
  alert,
  onAlertChange,
}: {
  projectId: string
  alert: Alert
  onAlertChange: (alert: Alert) => void
}) {
  const t = useT()
  const update = useUpdateAlert(projectId)
  if (!('acknowledgedAt' in alert) || alert.status !== 'firing') return null
  const acknowledged = !!alert.acknowledgedAt

  const submit = () =>
    update.mutate(
      { alertId: alert.$id, acknowledged: !acknowledged },
      {
        onSuccess: onAlertChange,
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
    <div
      className={cn(
        'flex items-center gap-3 rounded-xl border px-4 py-3',
        acknowledged
          ? 'border-border bg-muted/40'
          : 'border-red-500/30 bg-red-500/5',
      )}
    >
      <UserCheck
        aria-hidden
        className={cn(
          'h-4 w-4 shrink-0',
          acknowledged ? 'text-foreground' : 'text-red-500',
        )}
      />
      <div className="min-w-0 flex-1">
        {acknowledged ? (
          <p className="truncate text-[13px] text-foreground">
            {acknowledgedBy(alert, t)}
            <span className="text-muted-foreground">
              {' · '}
              <DateTooltip date={alert.acknowledgedAt!} />
            </span>
          </p>
        ) : (
          <>
            <p className="text-[13px] font-medium text-foreground">
              {t('Nobody has acknowledged this alert')}
            </p>
            <p className="text-[12px] text-muted-foreground">
              {alert.escalatesAt
                ? t(
                    'Acknowledging stops the escalation. It keeps firing until its monitor recovers.',
                  )
                : t(
                    'Let your team know someone is on it. It keeps firing until its monitor recovers.',
                  )}
            </p>
          </>
        )}
      </div>
      <Button
        type="button"
        size="sm"
        variant={acknowledged ? 'ghost' : 'default'}
        disabled={update.isPending}
        onClick={submit}
      >
        {update.isPending && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
        {acknowledged ? t('Undo') : t('Acknowledge')}
      </Button>
    </div>
  )
}

/** Where an escalating alert's escalation has got to, and what happens next. */
function Escalation({ projectId, alert }: { projectId: string; alert: Alert }) {
  const t = useT()
  const { project } = useProject(projectId)
  const { escalations } = useEscalations(
    alert.escalationId ? project?.teamId : null,
  )
  const state = escalationState(alert)
  if (!state) return null
  const escalation = escalations.find(
    (entry) => entry.$id === alert.escalationId,
  )
  const total = escalation?.steps.length
  const step =
    alert.escalationStep === 0
      ? t('No step notified yet')
      : total
        ? t('Step {step} of {total}')
            .replace('{step}', String(Math.min(alert.escalationStep, total)))
            .replace('{total}', String(total))
        : t('Step {step}').replace('{step}', String(alert.escalationStep))
  const round =
    alert.escalationRound > 0
      ? t('round {round}').replace('{round}', String(alert.escalationRound + 1))
      : null

  return (
    <dl className="grid grid-cols-[120px_minmax(0,1fr)] gap-y-2 text-[13px]">
      <dt className="text-muted-foreground">{t('Escalation')}</dt>
      <dd className="min-w-0 truncate text-foreground">
        {project?.teamId ? (
          <Link
            to="/organizations/$orgId/settings/escalations"
            params={{ orgId: project.teamId }}
            className="underline underline-offset-2"
          >
            {escalation?.name ?? t('Escalation')}
          </Link>
        ) : (
          (escalation?.name ?? t('Escalation'))
        )}
      </dd>
      <dt className="text-muted-foreground">{t('Notified')}</dt>
      <dd className="text-foreground">
        {step}
        {round && (
          <span className="text-muted-foreground">{` · ${round}`}</span>
        )}
      </dd>
      <dt className="text-muted-foreground">{t('Next')}</dt>
      <dd className="text-foreground">
        {state === 'waiting' ? (
          <DateTooltip date={alert.escalatesAt!} />
        ) : state === 'acknowledged' ? (
          t('Stopped, acknowledged')
        ) : state === 'resolved' ? (
          t('Stopped, resolved')
        ) : (
          t('All steps notified')
        )}
      </dd>
    </dl>
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
  /** The alert as the server returns it after an acknowledgement. */
  onAlertChange: (alert: Alert) => void
}

/** One alert: how far over its threshold it went, and its timeline. */
export function AlertDrawer({
  projectId,
  open,
  onOpenChange,
  alert,
  onAlertChange,
}: AlertDrawerProps) {
  const t = useT()
  const firing = alert?.status === 'firing'
  const ratio = alert ? alertRatio(alert) : null
  const probe = PROBE_TYPES.find((entry) => entry.value === alert?.type)
  const scope = probe
    ? t('{type} check').replace('{type}', probe.label)
    : !alert || alert.resourceType === 'project'
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
                label={
                  probe
                    ? firing
                      ? t('Latest latency')
                      : t('Peak latency')
                    : firing
                      ? t('Latest reading')
                      : t('Peak')
                }
                value={formatAlertValue(
                  alert,
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
              <Stat label={t('Notifications')} value={alert.notifications} />
            </div>

            <Acknowledgement
              projectId={projectId}
              alert={alert}
              onAlertChange={onAlertChange}
            />

            <Escalation projectId={projectId} alert={alert} />

            <dl className="grid grid-cols-[120px_minmax(0,1fr)] gap-y-2 text-[13px]">
              <dt className="text-muted-foreground">
                {probe ? t('Result') : t('Condition')}
              </dt>
              <dd className="text-foreground">{alertCondition(alert, t)}</dd>
              <dt className="text-muted-foreground">{t('Watches')}</dt>
              <dd className="truncate text-foreground">{scope}</dd>
              <dt className="text-muted-foreground">
                {probe ? t('Peak latency') : t('Peak')}
              </dt>
              <dd className="text-foreground">
                {formatAlertValue(alert, alert.peak)}
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
                {alert.acknowledgedAt && (
                  <Step tone="muted" title={acknowledgedBy(alert, t)}>
                    <DateTooltip date={alert.acknowledgedAt} />
                  </Step>
                )}
                {alert.resolvedAt ? (
                  <Step tone="resolved" title={t('Resolved')}>
                    <DateTooltip date={alert.resolvedAt} />
                  </Step>
                ) : (
                  <Step tone="muted" title={t('Waiting for recovery')}>
                    {probe
                      ? t('Resolves when a check passes again.')
                      : t("Resolves when the monitor's rules stop holding.")}
                  </Step>
                )}
              </ol>
            </section>

            <Button asChild variant="outline" size="sm">
              <Link
                to="/projects/$projectId/monitors"
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
