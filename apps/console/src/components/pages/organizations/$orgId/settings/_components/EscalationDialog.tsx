import { useEffect, useMemo, useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { RecipientsField } from '@/components/global/shared/RecipientsField'
import {
  ESCALATION_ROLES,
  MAX_DELAY,
  MAX_REPEAT,
} from '@/lib/alerts/escalation'
import { alertingLimits } from '@/lib/alerts/plan-limits'
import type { Escalation, EscalationInput } from '@/lib/appwrite/monitors'
import { useT } from '@/lib/i18n/translate'
import {
  useCreateEscalation,
  useOrganizationPlan,
  useUpdateEscalation,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** A step as the form edits it: the wait in seconds. */
type StepForm = { seconds: number; roles: string[]; userIds: string[] }

type EscalationForm = { name: string; steps: StepForm[]; repeat: number }

function emptyForm(): EscalationForm {
  return {
    name: '',
    steps: [{ seconds: 0, roles: ['owner'], userIds: [] }],
    repeat: 0,
  }
}

function formFromEscalation(escalation: Escalation): EscalationForm {
  return {
    name: escalation.name,
    steps: escalation.steps.map((step) => ({
      seconds: step.delay,
      roles: step.roles,
      userIds: step.userIds,
    })),
    repeat: escalation.repeat,
  }
}

function inputFromForm(form: EscalationForm): EscalationInput {
  return {
    name: form.name.trim(),
    steps: form.steps.map((step) => ({
      delay: step.seconds,
      roles: step.roles,
      userIds: step.userIds,
    })),
    repeat: form.repeat,
  }
}

interface EscalationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  /** The escalation to edit; null creates one. */
  escalation: Escalation | null
}

/** Creates an escalation, or edits one. */
export function EscalationDialog({
  open,
  onOpenChange,
  organizationId,
  escalation,
}: EscalationDialogProps) {
  const t = useT()
  const createMutation = useCreateEscalation(organizationId)
  const updateMutation = useUpdateEscalation(organizationId)
  const isPending = createMutation.isPending || updateMutation.isPending
  const [form, setForm] = useState<EscalationForm>(emptyForm)
  const { plan } = useOrganizationPlan(organizationId)
  const limits = alertingLimits(plan)
  const stepLimit = limits.escalationSteps
  const repeatLimit = Math.min(
    MAX_REPEAT,
    limits.escalationRepeat ?? MAX_REPEAT,
  )
  // A repeat saved before a downgrade stays selectable, so an unrelated edit keeps it.
  const repeatOptions = Math.max(repeatLimit, escalation?.repeat ?? 0)
  const atStepLimit = stepLimit !== null && form.steps.length >= stepLimit

  useEffect(() => {
    if (open) setForm(escalation ? formFromEscalation(escalation) : emptyForm())
  }, [open, escalation])

  const changes = useMemo(() => {
    if (!escalation) return inputFromForm(form)
    const before = inputFromForm(formFromEscalation(escalation))
    const after = inputFromForm(form)
    const sparse: Partial<EscalationInput> = {}
    if (before.name !== after.name) sparse.name = after.name
    if (JSON.stringify(before.steps) !== JSON.stringify(after.steps)) {
      sparse.steps = after.steps
    }
    if (before.repeat !== after.repeat) sparse.repeat = after.repeat
    return sparse
  }, [form, escalation])

  const error = !form.name.trim()
    ? t('Enter a name.')
    : form.steps.some(
          (step) => step.roles.length === 0 && step.userIds.length === 0,
        )
      ? t('Each step needs a role or a member.')
      : stepLimit !== null &&
          form.steps.length > stepLimit &&
          'steps' in changes
        ? t('Your plan allows up to {count} steps per escalation.').replace(
            '{count}',
            String(stepLimit),
          )
        : escalation && Object.keys(changes).length === 0
          ? t('No changes to save.')
          : undefined

  const updateStep = (index: number, next: Partial<StepForm>) =>
    setForm((current) => ({
      ...current,
      steps: current.steps.map((step, position) =>
        position === index ? { ...step, ...next } : step,
      ),
    }))

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (error) return
    const options = {
      onSuccess: () => {
        toast.success(
          escalation
            ? t('Escalation has been updated')
            : t('Escalation has been created'),
        )
        onOpenChange(false)
      },
      onError: (err: Error) =>
        toast.error(
          getErrorMessage(
            err,
            escalation
              ? t('Failed to update escalation')
              : t('Failed to create escalation'),
          ),
        ),
    }
    if (escalation) {
      updateMutation.mutate({ escalationId: escalation.$id, changes }, options)
    } else {
      createMutation.mutate(inputFromForm(form), options)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {escalation ? t('Edit escalation') : t('Create escalation')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Steps are notified in turn until someone acknowledges the alert.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
            <div>
              <Label
                htmlFor="escalation-name"
                className="text-[13px] font-semibold"
              >
                {t('Name')}
              </Label>
              <Input
                id="escalation-name"
                className="mt-2"
                placeholder={t('On-call')}
                value={form.name}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
                disabled={isPending}
                maxLength={128}
              />
            </div>

            <section>
              <h3 className="mb-3 text-[13px] font-semibold text-foreground">
                {t('Steps')}
              </h3>
              <ol className="space-y-3">
                {form.steps.map((step, index) => (
                  <li
                    key={index}
                    className="space-y-3 rounded-lg border border-border bg-card/50 px-4 py-3"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-[13px] font-medium text-foreground">
                        {t('Step {step}').replace('{step}', String(index + 1))}
                      </p>
                      {form.steps.length > 1 && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-muted-foreground"
                          aria-label={t('Remove step {step}').replace(
                            '{step}',
                            String(index + 1),
                          )}
                          onClick={() =>
                            setForm((current) => ({
                              ...current,
                              steps: current.steps.filter(
                                (_, position) => position !== index,
                              ),
                            }))
                          }
                          disabled={isPending}
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[13px] text-muted-foreground">
                        {t('Wait')}
                      </span>
                      <NumberInput
                        aria-label={t('Seconds to wait')}
                        className="h-9 w-24"
                        min={0}
                        max={MAX_DELAY}
                        step={1}
                        value={step.seconds}
                        onValueChange={(seconds) =>
                          updateStep(index, {
                            seconds: Math.max(0, Math.round(seconds)),
                          })
                        }
                        disabled={isPending}
                      />
                      <span className="text-[13px] text-muted-foreground">
                        {index === 0
                          ? t('seconds after the alert fires')
                          : t('seconds after the previous step')}
                      </span>
                    </div>
                    <RecipientsField
                      organizationId={organizationId}
                      roles={ESCALATION_ROLES}
                      label={t('Add who step {step} notifies').replace(
                        '{step}',
                        String(index + 1),
                      )}
                      value={{
                        roles: step.roles,
                        userIds: step.userIds,
                        escalationId: '',
                      }}
                      onChange={({ roles, userIds }) =>
                        updateStep(index, { roles, userIds })
                      }
                      disabled={isPending}
                    />
                  </li>
                ))}
              </ol>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() =>
                    setForm((current) => ({
                      ...current,
                      steps: [
                        ...current.steps,
                        { seconds: 900, roles: [], userIds: [] },
                      ],
                    }))
                  }
                  disabled={isPending || atStepLimit}
                >
                  <Plus className="me-1.5 h-3.5 w-3.5" />
                  {t('Add step')}
                </Button>
                {atStepLimit && (
                  <span className="text-[12px] text-muted-foreground">
                    {t(
                      'Your plan allows up to {count} steps per escalation.',
                    ).replace('{count}', String(stepLimit))}
                  </span>
                )}
              </div>
            </section>

            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[13px] font-semibold text-foreground">
                {t('Repeat')}
              </span>
              <Select
                value={String(form.repeat)}
                onValueChange={(next) =>
                  setForm((current) => ({ ...current, repeat: Number(next) }))
                }
                disabled={isPending}
              >
                <SelectTrigger
                  className="h-9 w-auto min-w-32"
                  aria-label={t('Repeat')}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Array.from({ length: repeatOptions + 1 }, (_, count) => (
                    <SelectItem
                      key={count}
                      value={String(count)}
                      disabled={
                        count > repeatLimit && count !== escalation?.repeat
                      }
                    >
                      {count === 0
                        ? t('Never')
                        : count === 1
                          ? t('Once')
                          : t('{count} times').replace(
                              '{count}',
                              String(count),
                            )}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <span className="text-[12px] text-muted-foreground">
                {repeatLimit === 0
                  ? t('Upgrade your plan to repeat escalations.')
                  : t(
                      "Starts again from the first step, a monitor's cooldown after the last one.",
                    )}
              </span>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isPending}
            >
              {t('Cancel')}
            </Button>
            {error ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">
                    <Button type="submit" disabled className="w-full">
                      {escalation ? t('Update') : t('Create')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent side="top">{error}</TooltipContent>
              </Tooltip>
            ) : (
              <Button type="submit" disabled={isPending}>
                {escalation ? t('Update') : t('Create')}
              </Button>
            )}
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
