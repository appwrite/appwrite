import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { AgentModelPicker } from '@/components/global/providers/agent/AgentModelPicker'
import { CronScheduleEditor } from '@/components/pages/projects/$projectId/functions/CronScheduleEditor'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useCreateAssistantAutomation,
  useDeleteAssistantAutomation,
  useUpdateAssistantAutomation,
  type AssistantAutomation,
} from '@/lib/react-query/hooks'

type AutomationFormState = {
  name: string
  prompt: string
  schedule: string
  titlePrefix: string
  modelId: string
  enabled: boolean
}

/** Matches CronScheduleEditor `weekly-monday` ("Weekly on Monday"). */
const DEFAULT_AUTOMATION_SCHEDULE = '0 0 * * 1'

const emptyForm = (): AutomationFormState => ({
  name: '',
  prompt: '',
  schedule: DEFAULT_AUTOMATION_SCHEDULE,
  titlePrefix: '',
  modelId: '',
  enabled: true,
})

function formFromAutomation(
  automation: AssistantAutomation,
): AutomationFormState {
  return {
    name: automation.name || '',
    prompt: automation.prompt || '',
    schedule: automation.schedule || '',
    titlePrefix: automation.titlePrefix || '',
    modelId: automation.modelId || '',
    enabled: automation.enabled !== false,
  }
}

export type AgentAutomationFormProps = {
  automation?: AssistantAutomation | null
  disabled?: boolean
  resolveProjectId?: () => Promise<string | null>
  onAddModel?: () => void
  onCancel: () => void
  /** Called after create/update with the saved automation, or after delete with null. */
  onSaved?: (automation?: AssistantAutomation | null) => void
}

export function AgentAutomationForm({
  automation = null,
  disabled = false,
  resolveProjectId,
  onAddModel,
  onCancel,
  onSaved,
}: AgentAutomationFormProps) {
  const t = useT()
  const createMutation = useCreateAssistantAutomation()
  const updateMutation = useUpdateAssistantAutomation()
  const deleteMutation = useDeleteAssistantAutomation()
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [form, setForm] = useState<AutomationFormState>(() =>
    automation ? formFromAutomation(automation) : emptyForm(),
  )

  useEffect(() => {
    setForm(automation ? formFromAutomation(automation) : emptyForm())
  }, [automation])

  const isSaving = createMutation.isPending || updateMutation.isPending
  const isDeleting = deleteMutation.isPending
  const canSave =
    form.name.trim().length > 0 &&
    form.prompt.trim().length > 0 &&
    form.schedule.trim().length > 0
  const isEditing = Boolean(automation?.$id)

  const closeDeleteDialog = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
  }

  const handleDelete = async () => {
    if (!automation?.$id || isDeleting || disabled) return
    try {
      await deleteMutation.mutateAsync(automation.$id)
      toast.success(t('Automation deleted'))
      closeDeleteDialog()
      onSaved?.(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete automation')))
    }
  }

  const handleSave = async () => {
    if (!canSave || isSaving || disabled) return
    try {
      const projectId = resolveProjectId ? await resolveProjectId() : null
      if (isEditing && automation) {
        const updated = await updateMutation.mutateAsync({
          automationId: automation.$id,
          name: form.name.trim(),
          prompt: form.prompt.trim(),
          schedule: form.schedule.trim(),
          titlePrefix: form.titlePrefix.trim() || undefined,
          modelId: form.modelId.trim() || '',
          enabled: form.enabled,
          contextProjectId: projectId || undefined,
        })
        toast.success(t('Automation updated'))
        onSaved?.(updated)
      } else {
        const created = await createMutation.mutateAsync({
          name: form.name.trim(),
          prompt: form.prompt.trim(),
          schedule: form.schedule.trim(),
          titlePrefix: form.titlePrefix.trim() || undefined,
          modelId: form.modelId.trim() || undefined,
          enabled: form.enabled,
          contextProjectId: projectId || undefined,
        })
        toast.success(t('Automation created'))
        onSaved?.(created)
      }
    } catch (error) {
      toast.error(
        getErrorMessage(
          error,
          isEditing
            ? t('Failed to update automation')
            : t('Failed to create automation'),
        ),
      )
    }
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="space-y-5 px-6 py-6">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Run a prompt on a schedule. Each run creates a new agent conversation.',
            )}
          </p>
          <div className="space-y-2">
            <Label htmlFor="automation-name">{t('Name')}</Label>
            <Input
              id="automation-name"
              value={form.name}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  name: event.target.value,
                }))
              }
              placeholder={t('Weekly project review')}
              className="h-9 text-[13px]"
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="automation-prompt">{t('Prompt')}</Label>
            <Textarea
              id="automation-prompt"
              value={form.prompt}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  prompt: event.target.value,
                }))
              }
              placeholder={t(
                'Summarize project activity and suggest next steps.',
              )}
              className="min-h-28 text-[13px]"
              disabled={disabled}
            />
          </div>
          <CronScheduleEditor
            value={form.schedule}
            onChange={(schedule) =>
              setForm((current) => ({
                ...current,
                schedule,
              }))
            }
            disabled={disabled}
            allowDisabled={false}
          />
          <div className="space-y-2">
            <Label htmlFor="automation-title-prefix">
              {t('Title prefix (optional)')}
            </Label>
            <Input
              id="automation-title-prefix"
              value={form.titlePrefix}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  titlePrefix: event.target.value,
                }))
              }
              placeholder={t('Weekly review')}
              className="h-9 text-[13px]"
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <Label>{t('Model')}</Label>
              {onAddModel ? (
                <button
                  type="button"
                  className="text-[11px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                  onClick={onAddModel}
                >
                  {t('Add model')}
                </button>
              ) : null}
            </div>
            <AgentModelPicker
              value={form.modelId}
              onChange={(modelId) =>
                setForm((current) => ({
                  ...current,
                  modelId,
                }))
              }
              disabled={disabled}
              size="form"
            />
          </div>
          <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
            <div>
              <p className="text-[13px] font-medium text-foreground">
                {t('Enabled')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Run this automation on its schedule.')}
              </p>
            </div>
            <Switch
              checked={form.enabled}
              onCheckedChange={(checked) =>
                setForm((current) => ({ ...current, enabled: checked }))
              }
              disabled={disabled}
            />
          </div>

          {isEditing ? (
            <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
              <div className="px-4 py-3">
                <h3 className="text-[13px] font-semibold text-foreground">
                  {t('Delete automation')}
                </h3>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  {t('This permanently deletes the automation.')}{' '}
                  {t('This action cannot be undone.')}
                </p>
              </div>
              <div className="border-t border-destructive/20 px-4 py-3 bg-destructive/5">
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={disabled || isDeleting || isSaving}
                  onClick={() => setDeleteOpen(true)}
                >
                  {t('Delete')}
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      </div>

      <div className="flex shrink-0 items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
        <Button
          type="button"
          disabled={!canSave || isSaving || isDeleting || disabled}
          onClick={() => void handleSave()}
        >
          {isEditing ? t('Update') : t('Create')}
        </Button>
        <Button
          type="button"
          variant="outline"
          disabled={isSaving || isDeleting}
          onClick={onCancel}
        >
          {t('Cancel')}
        </Button>
      </div>

      <Dialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeDeleteDialog()
            return
          }
          setDeleteOpen(true)
        }}
      >
        <DialogContent
          className="z-[140] sm:max-w-md p-0"
          overlayClassName="z-[140]"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete automation')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the automation.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={closeDeleteDialog}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
