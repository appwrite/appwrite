import { useEffect, useState } from 'react'
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
import { useCreateMonitor } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
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
}

export function CreateMonitor({
  open,
  onOpenChange,
  projectId,
  initial,
}: CreateAlertProps) {
  const t = useT()
  const createMutation = useCreateMonitor(projectId)
  const [form, setForm] = useState<RuleForm>(emptyRuleForm)

  useEffect(() => {
    if (open) setForm({ ...emptyRuleForm(), ...initial })
  }, [open, initial])

  const error = ruleFormError(form, t)
  const isPending = createMutation.isPending

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleCreate = (event: React.FormEvent) => {
    event.preventDefault()
    if (error) return
    createMutation.mutate(monitorInputFromForm(form, t), {
      onSuccess: () => {
        toast.success(t('Monitor has been created'))
        onOpenChange(false)
      },
      onError: (err: Error) => {
        toast.error(getErrorMessage(err, t('Failed to create monitor')))
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
              {t(
                'Get notified when a usage metric crosses a threshold, and again when it recovers.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <form
            onSubmit={handleCreate}
            className="flex min-h-0 flex-1 flex-col"
          >
            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
              <RuleFields
                projectId={projectId}
                form={form}
                onChange={(changes) =>
                  setForm((current) => ({ ...current, ...changes }))
                }
                disabled={isPending}
              />
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
