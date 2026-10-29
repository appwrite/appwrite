import { useEffect, useMemo, useState } from 'react'
import { Pause, Pencil, Play, Trash2 } from 'lucide-react'
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
import { useUpdateAlertRule } from '@/lib/react-query/hooks'
import type { AlertRule, AlertRuleInput } from '@/lib/appwrite/alerts'
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
  ruleFormFromRule,
  ruleInputFromForm,
  type RuleForm,
} from './RuleFields'

/** A check older than this means the scheduler has stopped evaluating the rule. */
const STALE_AFTER_MS = 60 * 60 * 1000

/** Fields of `next` that differ from `previous`, so the update stays sparse. */
function changedFields(
  previous: AlertRuleInput,
  next: AlertRuleInput,
): Partial<AlertRuleInput> {
  const changes: Partial<AlertRuleInput> = {}
  for (const key of Object.keys(next) as (keyof AlertRuleInput)[]) {
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

/** What the rule last measured and when it started firing. */
function RuleStatus({ rule }: { rule: AlertRule }) {
  const t = useT()
  const firing = ruleState(rule) === 'firing'
  const ratio = firing ? thresholdRatio(rule) : null
  const threshold = formatMetricValue(rule.metric, rule.threshold)
  return (
    <div className="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-[1.3fr_1fr]">
      <Stat
        label={t('Last value')}
        value={
          rule.value === null ? '–' : formatMetricValue(rule.metric, rule.value)
        }
        note={
          rule.value === null
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
          rule.triggeredAt ? (
            <DateTooltip date={rule.triggeredAt} />
          ) : (
            <span className="text-muted-foreground">{t('Never')}</span>
          )
        }
        note={
          rule.evaluatedAt ? (
            <>
              {t('Checked')} <DateTooltip date={rule.evaluatedAt} />
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
  rule: AlertRule | null
  onDelete: (rule: AlertRule) => void
  /** Shows the rule without letting it be changed. */
  readOnly?: boolean
}

export function AlertDrawer({
  open,
  onOpenChange,
  projectId,
  rule,
  onDelete,
  readOnly = false,
}: AlertDrawerProps) {
  const t = useT()
  const updateMutation = useUpdateAlertRule(projectId)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)
  // The drawer opens on what the rule does; the form is one click away.
  const [editing, setEditing] = useState(false)
  const isPending = updateMutation.isPending

  useEffect(() => {
    if (open && rule) {
      setForm(ruleFormFromRule(rule))
      setEditing(false)
    }
  }, [open, rule])

  const changes = useMemo(() => {
    if (!rule) return {}
    return changedFields(
      ruleInputFromForm(ruleFormFromRule(rule), t),
      ruleInputFromForm(form, t),
    )
  }, [rule, form, t])

  const error =
    ruleFormError(form, t) ??
    (Object.keys(changes).length === 0 ? t('No changes to save.') : undefined)

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!rule || error) return
    updateMutation.mutate(
      { ruleId: rule.$id, changes },
      {
        onSuccess: () => {
          toast.success(t('Alert rule has been updated'))
          setEditing(false)
        },
        onError: (err: Error) => {
          toast.error(getErrorMessage(err, t('Failed to update alert rule')))
        },
      },
    )
  }

  const handleToggle = () => {
    if (!rule) return
    updateMutation.mutate(
      { ruleId: rule.$id, changes: { enabled: !rule.enabled } },
      {
        onSuccess: () =>
          toast.success(
            rule.enabled
              ? t('Alert rule has been paused')
              : t('Alert rule has been resumed'),
          ),
        onError: (err: Error) =>
          toast.error(getErrorMessage(err, t('Failed to update alert rule'))),
      },
    )
  }

  const handleRequestDelete = () => {
    if (!rule || isPending) return
    onOpenChange(false)
    openDialogAfterOverlayCloses(() => onDelete(rule))
  }

  const stale =
    !!rule?.enabled &&
    !!rule.evaluatedAt &&
    Date.now() - new Date(rule.evaluatedAt).getTime() > STALE_AFTER_MS

  const condition = rule
    ? [
        t(METRICS[rule.metric]?.label ?? rule.metric),
        COMPARATORS.find((entry) => entry.value === rule.comparator)?.symbol,
        formatMetricValue(rule.metric, rule.threshold),
        METRICS[rule.metric]?.counter
          ? `${t('within')} ${t(WINDOWS.find((entry) => entry.value === rule.window)?.label ?? `${rule.window}s`)}`
          : null,
      ]
        .filter(Boolean)
        .join(' ')
    : ''
  const cooldown = rule
    ? COOLDOWNS.find((entry) => entry.value === rule.cooldown)?.label
    : undefined

  const headerActions =
    rule && !readOnly ? (
      <div className="flex items-center gap-1.5">
        <StatusPill status={ruleState(rule)} />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleToggle}
          disabled={isPending}
        >
          {rule.enabled ? (
            <Pause className="me-1.5 h-3.5 w-3.5" />
          ) : (
            <Play className="me-1.5 h-3.5 w-3.5" />
          )}
          {rule.enabled ? t('Pause') : t('Resume')}
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
              aria-label={`${t('Actions for')} ${rule.name}`}
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
    ) : rule ? (
      <StatusPill status={ruleState(rule)} />
    ) : null

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={rule?.name ?? t('Alert rule')}
      maxWidth="sm:max-w-3xl"
      headerActions={headerActions}
    >
      <>
        <div className="border-t border-border shrink-0" />
        {editing && rule ? (
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
                  setForm(ruleFormFromRule(rule))
                  setEditing(false)
                }}
                disabled={isPending}
              >
                {t('Cancel')}
              </Button>
            </div>
          </form>
        ) : (
          rule && (
            <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
              <RuleStatus rule={rule} />

              {stale && (
                <div className="flex items-start gap-2.5 rounded-lg bg-amber-500/15 px-3 py-2.5 text-[12.5px] text-foreground">
                  <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-amber-600 text-[11px] font-bold text-background">
                    !
                  </span>
                  <span>
                    {t('Last checked')}{' '}
                    <DateTooltip date={rule.evaluatedAt as string} />.{' '}
                    {t(
                      'Checks run every few minutes, so this rule is not being evaluated.',
                    )}
                  </span>
                </div>
              )}

              <RuleChart
                projectId={projectId}
                rule={{
                  metric: rule.metric,
                  resourceType: rule.resourceType,
                  resourceId: rule.resourceId,
                  window: rule.window,
                  comparator: rule.comparator,
                  threshold: rule.threshold,
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
                  {rule.channels
                    .map((channel) =>
                      t(
                        channel === 'email'
                          ? 'Email'
                          : channel === 'console'
                            ? 'Console'
                            : 'Webhook',
                      ),
                    )
                    .join(', ')}
                  {rule.cooldown > 0 && cooldown && (
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
