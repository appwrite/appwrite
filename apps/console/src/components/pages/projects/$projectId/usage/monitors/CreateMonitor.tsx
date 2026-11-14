import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useCreateMonitor, useProject } from '@/lib/react-query/hooks'
import { getErrorMessage, isPlanLimitError } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { PROBE_TYPES } from '@/lib/alerts/probes'
import type { ProbeType } from '@/lib/appwrite/monitors'
import {
  MonitorTypePicker,
  ProbeFields,
  emptyProbeForm,
  probeFormError,
  probeInputFromForm,
  type ProbeForm,
} from './ProbeFields'
import {
  RuleFields,
  emptyRuleForm,
  ruleFormError,
  monitorInputFromForm,
  type RuleForm,
} from './RuleFields'

interface CreateAlertProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Fields a template or a usage page fills in before the form opens. */
  initial?: Partial<RuleForm>
  /** What the new monitor checks when the form opens; usage by default. */
  initialType?: 'usage' | ProbeType
}

export function CreateMonitor({
  open,
  onOpenChange,
  projectId,
  initial,
  initialType = 'usage',
}: CreateAlertProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const createMutation = useCreateMonitor(projectId)
  const [type, setType] = useState<'usage' | ProbeType>(initialType)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)
  const [probe, setProbe] = useState<ProbeForm>(() => emptyProbeForm('http'))

  useEffect(() => {
    if (!open) return
    setType(initialType)
    setForm({ ...emptyRuleForm(), ...initial })
    setProbe(emptyProbeForm(initialType === 'usage' ? 'http' : initialType))
  }, [open, initial, initialType])

  const handleTypeChange = (next: 'usage' | ProbeType) => {
    setType(next)
    // Notification settings carry over; the target starts over for the new type.
    if (next !== 'usage') {
      setProbe((current) => ({
        ...emptyProbeForm(next),
        name: current.name,
        notify: current.notify,
        severity: current.severity,
        cooldown: current.cooldown,
        interval: current.interval,
        timeout: current.timeout,
      }))
    }
  }

  const error =
    type === 'usage' ? ruleFormError(form, t) : probeFormError(probe, t)
  const isPending = createMutation.isPending

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleCreate = (event: React.FormEvent) => {
    event.preventDefault()
    if (error) return
    const input =
      type === 'usage'
        ? monitorInputFromForm(form, t)
        : probeInputFromForm(probe)
    createMutation.mutate(input, {
      onSuccess: () => {
        toast.success(t('Monitor has been created'))
        onOpenChange(false)
      },
      onError: (err: Error) => {
        const message = getErrorMessage(err, t('Failed to create monitor'))
        const orgId = project?.teamId
        if (isPlanLimitError(err) && orgId) {
          toast.error(message, {
            action: {
              label: t('Upgrade'),
              onClick: () =>
                void navigate({ to: '/upgrade', search: { orgId } }),
            },
          })
          return
        }
        toast.error(message)
      },
    })
  }

  return (
    <>
      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent className="sm:max-w-3xl p-0 max-h-[90dvh] flex flex-col">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Create monitor')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {type === 'usage'
                ? t(
                    'Get notified when a usage metric crosses a threshold, and again when it recovers.',
                  )
                : t(
                    PROBE_TYPES.find((entry) => entry.value === type)
                      ?.description ?? '',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <form
            onSubmit={handleCreate}
            className="flex min-h-0 flex-1 flex-col"
          >
            <div className="min-h-0 flex-1 space-y-7 overflow-y-auto px-6 py-5">
              <MonitorTypePicker
                value={type}
                onChange={handleTypeChange}
                disabled={isPending}
              />
              {type === 'usage' ? (
                <RuleFields
                  projectId={projectId}
                  form={form}
                  onChange={(changes) =>
                    setForm((current) => ({ ...current, ...changes }))
                  }
                  disabled={isPending}
                />
              ) : (
                <ProbeFields
                  form={probe}
                  onChange={(changes) =>
                    setProbe((current) => ({ ...current, ...changes }))
                  }
                  disabled={isPending}
                />
              )}
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
                        {t('Create')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent side="top">{error}</TooltipContent>
                </Tooltip>
              ) : (
                <Button type="submit" disabled={isPending}>
                  {t('Create')}
                </Button>
              )}
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </>
  )
}
