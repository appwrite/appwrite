import { useEffect, useMemo, useState } from 'react'
import { Pause, Pencil, Play, Trash2 } from 'lucide-react'
import { MonitorChannel } from '@appwrite.io/console'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useUpdateMonitor } from '@/lib/react-query/hooks'
import type { ParsedMonitor, MonitorInput } from '@/lib/appwrite/monitors'
import {
  COMPARATORS,
  COOLDOWNS,
  METRICS,
  WINDOWS,
  formatMetricValue,
  thresholdRatio,
} from '@/lib/alerts/metrics'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { RuleChart } from './RuleChart'
import { StatusPill, ruleState } from './StatusPill'
import {
  RuleFields,
  emptyRuleForm,
  ruleFormError,
  ruleFormFromMonitor,
  monitorInputFromForm,
  type RuleForm,
} from './RuleFields'

/** A check older than this means the scheduler has stopped evaluating the monitor. */
const STALE_AFTER_MS = 60 * 60 * 1000

/** Fields of `next` that differ from `previous`, so the update stays sparse. */
function changedFields(
  previous: MonitorInput,
  next: MonitorInput,
): Partial<MonitorInput> {
  const changes: Partial<MonitorInput> = {}
  for (const key of Object.keys(next) as (keyof MonitorInput)[]) {
    const before = previous[key]
    const after = next[key]
    const same =
      Array.isArray(before) && Array.isArray(after)
        ? [...before].sort().join() === [...after].sort().join()
        : before === after
    if (!same) {
      Object.assign(changes, { [key]: after })
    }
  }
  return changes
}

function Stat({
  label,
  value,
  note,
  hot = false,
}: {
  label: string
  value: React.ReactNode
  note?: React.ReactNode
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

/** What the monitor last measured and when it started firing. */
function MonitorStatusSummary({ monitor }: { monitor: ParsedMonitor }) {
  const t = useT()
  const firing = ruleState(monitor) === 'firing'
  const ratio = firing ? thresholdRatio(monitor) : null
  const threshold = formatMetricValue(monitor.metric, monitor.threshold)
  return (
    <div className="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-[1.3fr_1fr]">
      <Stat
        label={t('Last value')}
        value={
          monitor.value == null
            ? '–'
            : formatMetricValue(monitor.metric, monitor.value)
        }
        note={
          monitor.value == null
            ? t('Waiting for the first check')
            : ratio
              ? t('{ratio} the {threshold} threshold')
                  .replace('{ratio}', ratio)
                  .replace('{threshold}', threshold)
              : t('Threshold {threshold}').replace('{threshold}', threshold)
        }
        hot={firing}
      />
      <Stat
        label={firing ? t('Firing since') : t('Last fired')}
        value={
          monitor.triggeredAt ? (
            <DateTooltip date={monitor.triggeredAt} />
          ) : (
            <span className="text-muted-foreground">{t('Never')}</span>
          )
        }
        note={
          monitor.evaluatedAt ? (
            <>
              {t('Checked')} <DateTooltip date={monitor.evaluatedAt} />
            </>
          ) : undefined
        }
      />
    </div>
  )
}

interface AlertDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  monitor: ParsedMonitor | null
  onDelete: (monitor: ParsedMonitor) => void
  /** Shows the monitor without letting it be changed. */
  readOnly?: boolean
}

export function MonitorDrawer({
  open,
  onOpenChange,
  projectId,
  monitor,
  onDelete,
  readOnly = false,
}: AlertDrawerProps) {
  const t = useT()
  const updateMutation = useUpdateMonitor(projectId)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)
  // The drawer opens on what the monitor does; the form is one click away.
  const [editing, setEditing] = useState(false)
  const isPending = updateMutation.isPending

  useEffect(() => {
    if (open && monitor) {
      setForm(ruleFormFromMonitor(monitor))
      setEditing(false)
    }
  }, [open, monitor])

  const changes = useMemo(() => {
    if (!monitor) return {}
    return changedFields(
      monitorInputFromForm(ruleFormFromMonitor(monitor), t),
      monitorInputFromForm(form, t),
    )
  }, [monitor, form, t])

  const error =
    ruleFormError(form, t) ??
    (Object.keys(changes).length === 0 ? t('No changes to save.') : undefined)

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!monitor || error) return
    updateMutation.mutate(
      { monitorId: monitor.$id, changes },
      {
        onSuccess: () => {
          toast.success(t('Monitor has been updated'))
          setEditing(false)
        },
        onError: (err: Error) => {
          toast.error(getErrorMessage(err, t('Failed to update monitor')))
        },
      },
    )
  }

  const handleToggle = () => {
    if (!monitor) return
    updateMutation.mutate(
      { monitorId: monitor.$id, changes: { enabled: !monitor.enabled } },
      {
        onSuccess: () =>
          toast.success(
            monitor.enabled
              ? t('Monitor has been paused')
              : t('Monitor has been resumed'),
          ),
        onError: (err: Error) =>
          toast.error(getErrorMessage(err, t('Failed to update monitor'))),
      },
    )
  }

  const handleRequestDelete = () => {
    if (!monitor || isPending) return
    onOpenChange(false)
    openDialogAfterOverlayCloses(() => onDelete(monitor))
  }

  const stale =
    !!monitor?.enabled &&
    !!monitor.evaluatedAt &&
    Date.now() - new Date(monitor.evaluatedAt).getTime() > STALE_AFTER_MS

  const condition = monitor
    ? [
        t(METRICS[monitor.metric]?.label ?? monitor.metric),
        COMPARATORS.find((entry) => entry.value === monitor.comparator)?.symbol,
        formatMetricValue(monitor.metric, monitor.threshold),
        METRICS[monitor.metric]?.counter
          ? `${t('within')} ${t(WINDOWS.find((entry) => entry.value === monitor.window)?.label ?? `${monitor.window}s`)}`
          : null,
      ]
        .filter(Boolean)
        .join(' ')
    : ''
  const cooldown = monitor
    ? COOLDOWNS.find((entry) => entry.value === monitor.cooldown)?.label
    : undefined

  const headerActions =
    monitor && !readOnly ? (
      <div className="flex items-center gap-1.5">
        <StatusPill status={ruleState(monitor)} />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleToggle}
          disabled={isPending}
        >
          {monitor.enabled ? (
            <Pause className="me-1.5 h-3.5 w-3.5" />
          ) : (
            <Play className="me-1.5 h-3.5 w-3.5" />
          )}
          {monitor.enabled ? t('Pause') : t('Resume')}
        </Button>
        {!editing && (
          <Button
            type="button"
            size="sm"
            className="h-8"
            onClick={() => setEditing(true)}
          >
            <Pencil className="me-1.5 h-3.5 w-3.5" />
            {t('Edit')}
          </Button>
        )}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <RowActionsMenuTrigger
              aria-label={`${t('Actions for')} ${monitor.name}`}
            />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem
              className="text-destructive focus:text-destructive"
              onClick={handleRequestDelete}
            >
              <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    ) : monitor ? (
      <StatusPill status={ruleState(monitor)} />
    ) : null

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={monitor?.name ?? t('Monitor')}
      maxWidth="sm:max-w-3xl"
      headerActions={headerActions}
    >
      <>
        <div className="border-t border-border shrink-0" />
        {editing && monitor ? (
          <form
            onSubmit={handleSubmit}
            className="flex min-h-0 flex-1 flex-col"
          >
            <div className="flex-1 overflow-y-auto px-6 py-6">
              <RuleFields
                projectId={projectId}
                form={form}
                onChange={(next) =>
                  setForm((current) => ({ ...current, ...next }))
                }
                disabled={isPending}
              />
            </div>
            <div className="flex flex-shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
              {error ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button type="submit" disabled>
                        {t('Update')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent side="top">{error}</TooltipContent>
                </Tooltip>
              ) : (
                <Button type="submit" disabled={isPending}>
                  {t('Update')}
                </Button>
              )}
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setForm(ruleFormFromMonitor(monitor))
                  setEditing(false)
                }}
                disabled={isPending}
              >
                {t('Cancel')}
              </Button>
            </div>
          </form>
        ) : (
          monitor && (
            <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
              <MonitorStatusSummary monitor={monitor} />

              {stale && (
                <div className="flex items-start gap-2.5 rounded-lg bg-amber-500/15 px-3 py-2.5 text-[12.5px] text-foreground">
                  <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-amber-600 text-[11px] font-bold text-background">
                    !
                  </span>
                  <span>
                    {t('Last checked')}{' '}
                    <DateTooltip date={monitor.evaluatedAt as string} />.{' '}
                    {t(
                      'Checks run every few minutes, so this monitor is not being evaluated.',
                    )}
                  </span>
                </div>
              )}

              <RuleChart
                projectId={projectId}
                rule={{
                  metric: monitor.metric,
                  resourceType: monitor.resourceType,
                  resourceId: monitor.resourceId,
                  window: monitor.window,
                  comparator: monitor.comparator,
                  threshold: monitor.threshold,
                }}
              />

              <dl className="grid grid-cols-[110px_1fr] text-[13px]">
                <dt className="border-t border-border py-2 text-muted-foreground">
                  {t('Condition')}
                </dt>
                <dd className="m-0 min-w-0 border-t border-border py-2 text-foreground">
                  {condition}
                </dd>
                <dt className="border-t border-border py-2 text-muted-foreground">
                  {t('Notifies')}
                </dt>
                <dd className="m-0 min-w-0 border-t border-border py-2 text-foreground">
                  {monitor.channels
                    .map((channel) =>
                      t(
                        channel === MonitorChannel.Email
                          ? 'Email'
                          : channel === MonitorChannel.Console
                            ? 'Console'
                            : 'Webhook',
                      ),
                    )
                    .join(', ')}
                  {monitor.cooldown > 0 && cooldown && (
                    <span className="text-muted-foreground">
                      {' · '}
                      {t('at most once per {cooldown}').replace(
                        '{cooldown}',
                        t(cooldown),
                      )}
                    </span>
                  )}
                </dd>
              </dl>
            </div>
          )
        )}
      </>
    </BaseDrawer>
  )
}
