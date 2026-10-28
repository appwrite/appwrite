import { useEffect, useMemo, useState } from 'react'
import { Trash2 } from 'lucide-react'
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

interface AlertDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  rule: AlertRule | null
  onDelete: (rule: AlertRule) => void
}

export function AlertDrawer({
  open,
  onOpenChange,
  projectId,
  rule,
  onDelete,
}: AlertDrawerProps) {
  const t = useT()
  const updateMutation = useUpdateAlertRule(projectId)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)
  const [enabled, setEnabled] = useState(true)
  const isPending = updateMutation.isPending

  useEffect(() => {
    if (open && rule) {
      setForm(ruleFormFromRule(rule))
      setEnabled(rule.enabled)
    }
  }, [open, rule])

  const changes = useMemo(() => {
    if (!rule) return {}
    const diff = changedFields(
      ruleInputFromForm(ruleFormFromRule(rule)),
      ruleInputFromForm(form),
    )
    if (enabled !== rule.enabled) diff.enabled = enabled
    return diff
  }, [rule, form, enabled])

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
      title={t('Update alert rule')}
      maxWidth="sm:max-w-2xl"
    >
      <>
        <div className="border-t border-border shrink-0" />
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className="flex-1 overflow-y-auto">
            <div className="space-y-6 px-6 py-6">
              <div className="flex items-center justify-between gap-4 rounded-xl border border-border bg-card/50 px-4 py-3">
                <div>
                  <Label
                    htmlFor="alert-enabled"
                    className="text-[13px] font-medium"
                  >
                    {t('Enabled')}
                  </Label>
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    {t('Disabled rules are not evaluated and never notify.')}
                  </p>
                </div>
                <Switch
                  id="alert-enabled"
                  checked={enabled}
                  onCheckedChange={setEnabled}
                  disabled={isPending}
                />
              </div>

              <RuleFields
                projectId={projectId}
                form={form}
                onChange={(next) =>
                  setForm((current) => ({ ...current, ...next }))
                }
                disabled={isPending}
              />

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
            </div>
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
              onClick={() => handleOpenChange(false)}
              disabled={isPending}
            >
              {t('Cancel')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
