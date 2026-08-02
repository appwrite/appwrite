import { useEffect, useMemo, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  useAssistantModels,
  useCreateAssistantAutomation,
  useUpdateAssistantAutomation,
  type AssistantAutomation,
} from '@/lib/react-query/hooks'

const DEFAULT_MODEL_VALUE = '__default__'

type AutomationFormState = {
  name: string
  prompt: string
  schedule: string
  titlePrefix: string
  modelId: string
  enabled: boolean
}

const emptyForm = (): AutomationFormState => ({
  name: '',
  prompt: '',
  schedule: '0 9 * * 1',
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

export type AssistantAutomationFormProps = {
  automation?: AssistantAutomation | null
  disabled?: boolean
  resolveProjectId?: () => Promise<string | null>
  onAddModel?: () => void
  onCancel: () => void
  onSaved?: () => void
}

export function AssistantAutomationForm({
  automation = null,
  disabled = false,
  resolveProjectId,
  onAddModel,
  onCancel,
  onSaved,
}: AssistantAutomationFormProps) {
  const t = useT()
  const { data: models = [] } = useAssistantModels({ enabled: !disabled })
  const createMutation = useCreateAssistantAutomation()
  const updateMutation = useUpdateAssistantAutomation()
  const [form, setForm] = useState<AutomationFormState>(() =>
    automation ? formFromAutomation(automation) : emptyForm(),
  )

  useEffect(() => {
    setForm(automation ? formFromAutomation(automation) : emptyForm())
  }, [automation])

  const enabledModels = useMemo(
    () => models.filter((model) => model.enabled !== false),
    [models],
  )

  const isSaving = createMutation.isPending || updateMutation.isPending
  const canSave =
    form.name.trim().length > 0 &&
    form.prompt.trim().length > 0 &&
    form.schedule.trim().length > 0
  const isEditing = Boolean(automation?.$id)

  const handleSave = async () => {
    if (!canSave || isSaving || disabled) return
    try {
      const projectId = resolveProjectId ? await resolveProjectId() : null
      if (isEditing && automation) {
        await updateMutation.mutateAsync({
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
      } else {
        await createMutation.mutateAsync({
          name: form.name.trim(),
          prompt: form.prompt.trim(),
          schedule: form.schedule.trim(),
          titlePrefix: form.titlePrefix.trim() || undefined,
          modelId: form.modelId.trim() || undefined,
          enabled: form.enabled,
          contextProjectId: projectId || undefined,
        })
        toast.success(t('Automation created'))
      }
      onSaved?.()
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
    <div className="flex h-full min-h-0 flex-col">
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-3xl space-y-4 px-4 py-4">
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
          <div className="space-y-2">
            <Label htmlFor="automation-schedule">{t('Schedule (cron)')}</Label>
            <Input
              id="automation-schedule"
              value={form.schedule}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  schedule: event.target.value,
                }))
              }
              placeholder="0 9 * * 1"
              className="h-9 font-mono text-[13px]"
              disabled={disabled}
            />
            <p className="text-[11px] text-muted-foreground">
              {t('Example: 0 9 * * 1 runs every Monday at 09:00 UTC.')}
            </p>
          </div>
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
            <Select
              value={form.modelId || DEFAULT_MODEL_VALUE}
              onValueChange={(value) =>
                setForm((current) => ({
                  ...current,
                  modelId: value === DEFAULT_MODEL_VALUE ? '' : value,
                }))
              }
              disabled={disabled}
            >
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder={t('Appwrite default')} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={DEFAULT_MODEL_VALUE}>
                  {t('Appwrite default')}
                </SelectItem>
                {enabledModels.map((model) => (
                  <SelectItem key={model.$id} value={model.$id}>
                    {model.name || model.model}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
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
        </div>
      </div>

      <div className="shrink-0 border-t border-border bg-background p-2">
        <div className="mx-auto flex h-9 w-full max-w-3xl items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9"
            disabled={isSaving}
            onClick={onCancel}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-9"
            disabled={!canSave || isSaving || disabled}
            onClick={() => void handleSave()}
          >
            {isSaving ? (
              <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
            ) : null}
            {isEditing ? t('Update') : t('Create')}
          </Button>
        </div>
      </div>
    </div>
  )
}
