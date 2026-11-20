import { useEffect, useMemo, useState } from 'react'
import { Check, ChevronDown, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
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
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
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
import { roleLabel } from '@/components/pages/projects/$projectId/monitors/RuleFields'
import {
  ESCALATION_ROLES,
  MAX_DELAY,
  MAX_REPEAT,
} from '@/lib/alerts/escalation'
import type {
  EscalationPolicy,
  EscalationPolicyInput,
} from '@/lib/appwrite/monitors'
import { useT } from '@/lib/i18n/translate'
import { useModalAwarePopover } from '@/lib/layout/modal-portal-host'
import {
  useCreateEscalationPolicy,
  useEscalationMembers,
  useUpdateEscalationPolicy,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** A step as the form edits it: the wait in minutes. */
type StepForm = { minutes: number; roles: string[]; userIds: string[] }

type PolicyForm = { name: string; steps: StepForm[]; repeat: number }

const MAX_MINUTES = MAX_DELAY / 60

function emptyForm(): PolicyForm {
  return {
    name: '',
    steps: [{ minutes: 0, roles: ['owner'], userIds: [] }],
    repeat: 0,
  }
}

function formFromPolicy(policy: EscalationPolicy): PolicyForm {
  return {
    name: policy.name,
    steps: policy.steps.map((step) => ({
      minutes: Math.round(step.delay / 60),
      roles: step.roles,
      userIds: step.userIds,
    })),
    repeat: policy.repeat,
  }
}

function inputFromForm(form: PolicyForm): EscalationPolicyInput {
  return {
    name: form.name.trim(),
    steps: form.steps.map((step) => ({
      delay: step.minutes * 60,
      roles: step.roles,
      userIds: step.userIds,
    })),
    repeat: form.repeat,
  }
}

/** Members picked from the organization, searchable by name or email. */
function MemberSelect({
  organizationId,
  value,
  onChange,
  disabled,
}: {
  organizationId: string
  value: string[]
  onChange: (userIds: string[]) => void
  disabled?: boolean
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { rootRef, portalContainer, modal, handleOpenChange } =
    useModalAwarePopover()
  const { members, memberName, isLoading } =
    useEscalationMembers(organizationId)

  return (
    <div ref={rootRef} className="contents">
      <Popover
        open={open}
        modal={modal}
        onOpenChange={(next) => {
          handleOpenChange(next)
          setOpen(next)
        }}
      >
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            aria-label={t('Members')}
            disabled={disabled}
            className="h-9 w-full justify-between gap-2 text-[13px] font-normal"
          >
            <span
              className={cn(
                'truncate',
                value.length === 0 && 'text-muted-foreground',
              )}
            >
              {value.length === 0
                ? t('Add members')
                : value.map(memberName).join(', ')}
            </span>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          container={portalContainer}
          className="max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0"
          align="start"
          onWheelCapture={(event) => event.stopPropagation()}
          onCloseAutoFocus={(event) => {
            if (portalContainer) event.preventDefault()
          }}
        >
          <Command>
            <CommandInput
              placeholder={t('Search members...')}
              className="h-9 text-[13px]"
              onKeyDown={(event) => event.stopPropagation()}
            />
            <CommandList className="max-h-[240px] overflow-y-auto overscroll-contain p-1">
              <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                {isLoading ? t('Loading...') : t('No members found')}
              </CommandEmpty>
              <CommandGroup>
                {members.map((member) => {
                  const selected = value.includes(member.userId)
                  return (
                    <CommandItem
                      key={member.userId}
                      value={`${member.name} ${member.email} ${member.userId}`}
                      className="rounded-md px-2 py-2 aria-selected:bg-accent"
                      onSelect={() =>
                        onChange(
                          selected
                            ? value.filter((entry) => entry !== member.userId)
                            : [...value, member.userId],
                        )
                      }
                    >
                      <Check
                        className={cn(
                          'me-2 h-4 w-4 shrink-0',
                          selected ? 'opacity-100' : 'opacity-0',
                        )}
                      />
                      <span className="min-w-0 flex-1 truncate text-[13px]">
                        {member.name}
                      </span>
                      {member.email && member.email !== member.name && (
                        <span className="ms-2 truncate text-[12px] text-muted-foreground">
                          {member.email}
                        </span>
                      )}
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

interface EscalationPolicyDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  /** The policy to edit; null creates one. */
  policy: EscalationPolicy | null
}

/** Creates an escalation policy, or edits one. */
export function EscalationPolicyDialog({
  open,
  onOpenChange,
  organizationId,
  policy,
}: EscalationPolicyDialogProps) {
  const t = useT()
  const createMutation = useCreateEscalationPolicy(organizationId)
  const updateMutation = useUpdateEscalationPolicy(organizationId)
  const isPending = createMutation.isPending || updateMutation.isPending
  const [form, setForm] = useState<PolicyForm>(emptyForm)

  useEffect(() => {
    if (open) setForm(policy ? formFromPolicy(policy) : emptyForm())
  }, [open, policy])

  const changes = useMemo(() => {
    if (!policy) return inputFromForm(form)
    const before = inputFromForm(formFromPolicy(policy))
    const after = inputFromForm(form)
    const sparse: Partial<EscalationPolicyInput> = {}
    if (before.name !== after.name) sparse.name = after.name
    if (JSON.stringify(before.steps) !== JSON.stringify(after.steps)) {
      sparse.steps = after.steps
    }
    if (before.repeat !== after.repeat) sparse.repeat = after.repeat
    return sparse
  }, [form, policy])

  const error = !form.name.trim()
    ? t('Enter a name.')
    : form.steps.some(
          (step) => step.roles.length === 0 && step.userIds.length === 0,
        )
      ? t('Each step needs a role or a member.')
      : policy && Object.keys(changes).length === 0
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
          policy
            ? t('Escalation policy has been updated')
            : t('Escalation policy has been created'),
        )
        onOpenChange(false)
      },
      onError: (err: Error) =>
        toast.error(
          getErrorMessage(
            err,
            policy
              ? t('Failed to update escalation policy')
              : t('Failed to create escalation policy'),
          ),
        ),
    }
    if (policy) {
      updateMutation.mutate(
        { escalationPolicyId: policy.$id, changes },
        options,
      )
    } else {
      createMutation.mutate(inputFromForm(form), options)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] flex flex-col">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {policy
              ? t('Edit escalation policy')
              : t('Create escalation policy')}
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
                htmlFor="escalation-policy-name"
                className="text-[13px] font-semibold"
              >
                {t('Name')}
              </Label>
              <Input
                id="escalation-policy-name"
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
                        aria-label={t('Minutes to wait')}
                        className="h-9 w-24"
                        min={0}
                        max={MAX_MINUTES}
                        step={1}
                        value={step.minutes}
                        onValueChange={(minutes) =>
                          updateStep(index, {
                            minutes: Math.max(0, Math.round(minutes)),
                          })
                        }
                        disabled={isPending}
                      />
                      <span className="text-[13px] text-muted-foreground">
                        {index === 0
                          ? t('minutes after the alert fires')
                          : t('minutes after the previous step')}
                      </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {ESCALATION_ROLES.map((role) => {
                        const selected = step.roles.includes(role)
                        return (
                          <button
                            key={role}
                            type="button"
                            role="checkbox"
                            aria-checked={selected}
                            disabled={isPending}
                            onClick={() =>
                              updateStep(index, {
                                roles: selected
                                  ? step.roles.filter((entry) => entry !== role)
                                  : [...step.roles, role],
                              })
                            }
                            className={cn(
                              'inline-flex h-8 items-center gap-2 rounded-md border px-3 text-[13px] transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                              selected
                                ? 'border-foreground text-foreground ring-1 ring-inset ring-foreground'
                                : 'border-border text-muted-foreground hover:bg-accent/40',
                            )}
                          >
                            <span
                              aria-hidden
                              className={cn(
                                'flex h-3.5 w-3.5 items-center justify-center rounded-[4px] border-[1.5px]',
                                selected
                                  ? 'border-foreground bg-foreground text-background'
                                  : 'border-muted-foreground/60',
                              )}
                            >
                              {selected && (
                                <Check
                                  className="h-2.5 w-2.5"
                                  strokeWidth={3}
                                />
                              )}
                            </span>
                            {t(roleLabel(role))}
                          </button>
                        )
                      })}
                    </div>
                    <MemberSelect
                      organizationId={organizationId}
                      value={step.userIds}
                      onChange={(userIds) => updateStep(index, { userIds })}
                      disabled={isPending}
                    />
                  </li>
                ))}
              </ol>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-3 h-8 text-[12px]"
                onClick={() =>
                  setForm((current) => ({
                    ...current,
                    steps: [
                      ...current.steps,
                      { minutes: 15, roles: [], userIds: [] },
                    ],
                  }))
                }
                disabled={isPending}
              >
                <Plus className="me-1.5 h-3.5 w-3.5" />
                {t('Add step')}
              </Button>
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
                  {Array.from({ length: MAX_REPEAT + 1 }, (_, count) => (
                    <SelectItem key={count} value={String(count)}>
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
                {t(
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
                      {policy ? t('Update') : t('Create')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent side="top">{error}</TooltipContent>
              </Tooltip>
            ) : (
              <Button type="submit" disabled={isPending}>
                {policy ? t('Update') : t('Create')}
              </Button>
            )}
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
