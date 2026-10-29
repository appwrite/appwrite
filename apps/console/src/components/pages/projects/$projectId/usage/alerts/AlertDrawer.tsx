import { useEffect, useMemo, useState } from 'react'
import { Trash2 } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { formatMetricValue } from '@/lib/alerts/metrics'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useUpdateAlertRule } from '@/lib/react-query/hooks'
import type { AlertRule, AlertRuleInput } from '@/lib/appwrite/alerts'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { StatusPill } from './StatusPill'
import {
  RuleFields,
  emptyRuleForm,
  ruleFormError,
  ruleFormFromRule,
  ruleInputFromForm,
  type RuleForm,
} from './RuleFields'

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

/** Where the rule stood at its last check. */
function RuleStatus({ rule }: { rule: AlertRule }) {
  const t = useT()
  const firing = rule.enabled && rule.status === 'firing'
  const items = [
    {
      label: t('Status'),
      value: <StatusPill status={!rule.enabled ? 'paused' : rule.status} />,
    },
    {
      label: t('Last value'),
      value: (
        <span
          className={
            firing ? 'font-medium text-red-500' : 'font-medium text-foreground'
          }
        >
          {rule.value === null
            ? '-'
            : formatMetricValue(rule.metric, rule.value)}
        </span>
      ),
    },
    {
      label: t('Last checked'),
      value: rule.evaluatedAt ? (
        <DateTooltip date={rule.evaluatedAt} />
      ) : (
        <span className="text-muted-foreground">{t('Never')}</span>
      ),
    },
    {
      label: t('Last fired'),
      value: rule.triggeredAt ? (
        <DateTooltip date={rule.triggeredAt} />
      ) : (
        <span className="text-muted-foreground">{t('Never')}</span>
      ),
    },
  ]
  return (
    <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-4">
      {items.map((item) => (
        <div key={item.label} className="bg-card px-4 py-3">
          <dt className="text-[11px] uppercase tracking-wider text-muted-foreground">
            {item.label}
          </dt>
          <dd className="mt-1 text-[13px]">{item.value}</dd>
        </div>
      ))}
    </dl>
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
  const [enabled, setEnabled] = useState(true)
  const isPending = updateMutation.isPending
  const locked = isPending || readOnly

  useEffect(() => {
    if (open && rule) {
      setForm(ruleFormFromRule(rule))
      setEnabled(rule.enabled)
    }
  }, [open, rule])

  const changes = useMemo(() => {
    if (!rule) return {}
    const diff = changedFields(
      ruleInputFromForm(ruleFormFromRule(rule), t),
      ruleInputFromForm(form, t),
    )
    if (enabled !== rule.enabled) diff.enabled = enabled
    return diff
  }, [rule, form, enabled, t])

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
          onOpenChange(false)
        },
        onError: (err: Error) => {
          toast.error(getErrorMessage(err, t('Failed to update alert rule')))
        },
      },
    )
  }

  const handleRequestDelete = () => {
    if (!rule || isPending) return
    onOpenChange(false)
    openDialogAfterOverlayCloses(() => onDelete(rule))
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={rule?.name ?? t('Alert rule')}
      maxWidth="sm:max-w-3xl"
    >
      <>
        <div className="border-t border-border shrink-0" />
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className="flex-1 overflow-y-auto">
            <div className="space-y-6 px-6 py-6">
              {rule && <RuleStatus rule={rule} />}

              <div className="flex items-center justify-between gap-4 rounded-xl border border-border bg-card/50 px-4 py-3">
                <div>
                  <Label
                    htmlFor="alert-enabled"
                    className="text-[13px] font-medium"
                  >
                    {t('Active')}
                  </Label>
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    {t('Paused rules are not checked and never notify.')}
                  </p>
                </div>
                <Switch
                  id="alert-enabled"
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={locked}
                />
              </div>

              <RuleFields
                projectId={projectId}
                form={form}
                onChange={(next) =>
                  setForm((current) => ({ ...current, ...next }))
                }
                disabled={locked}
              />

              {!readOnly && (
                <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      {t('Delete alert rule')}
                    </h3>
                  </div>
                  <div className="border-t border-destructive/20" />
                  <div className="px-6 py-4">
                    <p className="text-[13px] text-muted-foreground">
                      {t(
                        'Permanently delete this rule. It stops evaluating immediately. This action cannot be undone.',
                      )}
                    </p>
                  </div>
                  <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                    <Button
                      type="button"
                      variant="destructive"
                      size="sm"
                      className="h-9 text-[13px]"
                      onClick={handleRequestDelete}
                      disabled={isPending}
                    >
                      <Trash2 className="me-1.5 h-4 w-4" />
                      {t('Delete alert rule')}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="flex flex-shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            {readOnly ? null : error ? (
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
              onClick={() => handleOpenChange(false)}
              disabled={isPending}
            >
              {readOnly ? t('Close') : t('Cancel')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
