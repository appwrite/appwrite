import { useEffect, useMemo, useRef, useState } from 'react'
import { Cpu, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import {
  ASSISTANT_MODEL_PROVIDERS,
  getAssistantModelIconPath,
  getAssistantModelProvider,
  getAssistantProviderDefaultBaseUrl,
  getAssistantProviderModelLabel,
  getAssistantProviderModels,
  type AssistantModelProviderId,
} from '@/lib/assistant/model-providers'
import {
  useCreateAssistantModel,
  useUpdateAssistantModel,
  type AssistantModel,
} from '@/lib/react-query/hooks'

const CUSTOM_MODEL_VALUE = '__custom__'

type ModelFormState = {
  name: string
  provider: string
  model: string
  apiKey: string
  baseUrl: string
  enabled: boolean
}

const emptyForm = (): ModelFormState => ({
  name: '',
  provider: 'openai',
  model: 'gpt-4o',
  apiKey: '',
  baseUrl: getAssistantProviderDefaultBaseUrl('openai') ?? '',
  enabled: true,
})

function formFromModel(model: AssistantModel): ModelFormState {
  return {
    name: model.name || '',
    provider: model.provider || 'openai',
    model: model.model || '',
    apiKey: '',
    baseUrl:
      model.baseUrl ||
      getAssistantProviderDefaultBaseUrl(model.provider) ||
      '',
    enabled: model.enabled !== false,
  }
}

function ProviderIcon({
  providerId,
  className,
}: {
  providerId: string
  className?: string
}) {
  const provider = getAssistantModelProvider(providerId)
  if (provider?.icon) {
    return (
      <img
        src={provider.icon}
        alt=""
        className={cn('h-4 w-4', PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }
  return (
    <Cpu
      className={cn('h-4 w-4 text-muted-foreground', className)}
      aria-hidden
    />
  )
}

function ModelIcon({
  providerId,
  modelId,
  className,
}: {
  providerId: string
  modelId: string
  className?: string
}) {
  const icon = getAssistantModelIconPath(providerId, modelId)
  if (icon) {
    return (
      <img
        src={icon}
        alt=""
        className={cn('h-4 w-4', PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }
  return (
    <Cpu
      className={cn('h-4 w-4 text-muted-foreground', className)}
      aria-hidden
    />
  )
}

export type AssistantModelFormProps = {
  model?: AssistantModel | null
  disabled?: boolean
  onCancel: () => void
  onSaved?: (model: AssistantModel) => void
}

export function AssistantModelForm({
  model = null,
  disabled = false,
  onCancel,
  onSaved,
}: AssistantModelFormProps) {
  const t = useT()
  const createMutation = useCreateAssistantModel()
  const updateMutation = useUpdateAssistantModel()
  const [form, setForm] = useState<ModelFormState>(() =>
    model ? formFromModel(model) : emptyForm(),
  )
  const autoNameRef = useRef(
    model
      ? getAssistantProviderModelLabel(model.provider, model.model) || ''
      : getAssistantProviderModelLabel('openai', 'gpt-4o') || '',
  )

  useEffect(() => {
    const next = model ? formFromModel(model) : emptyForm()
    setForm(next)
    autoNameRef.current = model
      ? getAssistantProviderModelLabel(model.provider, model.model) ||
        model.name ||
        ''
      : getAssistantProviderModelLabel('openai', 'gpt-4o') || ''
  }, [model])

  const isEditing = Boolean(model?.$id)
  const isSaving = createMutation.isPending || updateMutation.isPending
  const providerModels = useMemo(
    () => getAssistantProviderModels(form.provider),
    [form.provider],
  )
  const knownModelIds = useMemo(
    () => new Set(providerModels.map((entry) => entry.id)),
    [providerModels],
  )
  const modelSelectValue =
    form.model && knownModelIds.has(form.model)
      ? form.model
      : form.model
        ? CUSTOM_MODEL_VALUE
        : providerModels[0]?.id || CUSTOM_MODEL_VALUE
  const showCustomModelInput =
    form.provider === 'custom' || modelSelectValue === CUSTOM_MODEL_VALUE

  const canSave =
    form.name.trim().length > 0 &&
    form.provider.trim().length > 0 &&
    form.model.trim().length > 0 &&
    (isEditing || form.apiKey.trim().length > 0)

  const applyProvider = (providerId: AssistantModelProviderId) => {
    const nextProvider = getAssistantModelProvider(providerId)
    const previousDefault = getAssistantProviderDefaultBaseUrl(form.provider)
    const nextDefault = nextProvider?.defaultBaseUrl ?? ''
    const nextModels = nextProvider?.models ?? []
    const keepModel =
      form.model && nextModels.some((entry) => entry.id === form.model)
        ? form.model
        : (nextModels[0]?.id ?? '')
    const nextModelLabel =
      getAssistantProviderModelLabel(providerId, keepModel) ||
      nextProvider?.label ||
      ''
    const shouldUpdateName =
      !form.name.trim() || form.name.trim() === autoNameRef.current

    setForm((current) => ({
      ...current,
      provider: providerId,
      model: keepModel,
      baseUrl:
        !current.baseUrl.trim() || current.baseUrl === previousDefault
          ? nextDefault
          : current.baseUrl,
      name: shouldUpdateName ? nextModelLabel : current.name,
    }))
    if (shouldUpdateName) autoNameRef.current = nextModelLabel
  }

  const applyKnownModel = (modelId: string) => {
    const label =
      getAssistantProviderModelLabel(form.provider, modelId) || modelId
    const shouldUpdateName =
      !form.name.trim() || form.name.trim() === autoNameRef.current
    setForm((current) => ({
      ...current,
      model: modelId,
      name: shouldUpdateName ? label : current.name,
    }))
    if (shouldUpdateName) autoNameRef.current = label
  }

  const handleSave = async () => {
    if (!canSave || isSaving || disabled) return
    try {
      if (isEditing && model) {
        const updated = await updateMutation.mutateAsync({
          modelId: model.$id,
          name: form.name.trim(),
          provider: form.provider.trim(),
          model: form.model.trim(),
          apiKey: form.apiKey.trim() || undefined,
          baseUrl: form.baseUrl.trim(),
          enabled: form.enabled,
        })
        toast.success(t('Model updated'))
        onSaved?.(updated)
      } else {
        const created = await createMutation.mutateAsync({
          name: form.name.trim(),
          provider: form.provider.trim(),
          model: form.model.trim(),
          apiKey: form.apiKey.trim(),
          baseUrl: form.baseUrl.trim() || undefined,
          enabled: form.enabled,
        })
        toast.success(t('Model created'))
        onSaved?.(created)
      }
    } catch (error) {
      toast.error(
        getErrorMessage(
          error,
          isEditing ? t('Failed to update model') : t('Failed to create model'),
        ),
      )
    }
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-3xl space-y-5 px-4 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Configure the provider, model ID, and API key.')}
          </p>

          <div className="space-y-2">
            <Label>{t('Provider')}</Label>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {ASSISTANT_MODEL_PROVIDERS.map((provider) => {
                const selected = form.provider === provider.id
                return (
                  <button
                    key={provider.id}
                    type="button"
                    disabled={disabled}
                    onClick={() => applyProvider(provider.id)}
                    className={cn(
                      'flex items-center gap-2 rounded-lg border px-3 py-2.5 text-start transition-colors',
                      selected
                        ? 'border-foreground/20 bg-accent'
                        : 'border-border bg-background hover:bg-accent/50',
                      disabled && 'pointer-events-none opacity-60',
                    )}
                  >
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted">
                      <ProviderIcon providerId={provider.id} />
                    </span>
                    <span className="min-w-0 truncate text-[13px] font-medium text-foreground">
                      {provider.label}
                    </span>
                  </button>
                )
              })}
            </div>
          </div>

          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="space-y-4 px-4 py-4">
              {providerModels.length > 0 ? (
                <div className="space-y-2">
                  <Label>{t('Model')}</Label>
                  <Select
                    value={modelSelectValue}
                    onValueChange={(value) => {
                      if (value === CUSTOM_MODEL_VALUE) {
                        setForm((current) => ({
                          ...current,
                          model: knownModelIds.has(current.model)
                            ? ''
                            : current.model,
                        }))
                        return
                      }
                      applyKnownModel(value)
                    }}
                    disabled={disabled}
                  >
                    <SelectTrigger className="h-9 text-[13px]">
                      <SelectValue placeholder={t('Select a model')} />
                    </SelectTrigger>
                    <SelectContent>
                      {providerModels.map((entry) => (
                        <SelectItem key={entry.id} value={entry.id}>
                          <span className="flex items-center gap-2">
                            <ModelIcon
                              providerId={form.provider}
                              modelId={entry.id}
                            />
                            <span>{entry.label}</span>
                          </span>
                        </SelectItem>
                      ))}
                      <SelectItem value={CUSTOM_MODEL_VALUE}>
                        {t('Custom model ID')}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              ) : null}

              {showCustomModelInput ? (
                <div className="space-y-2">
                  <Label htmlFor="assistant-model-id">
                    {providerModels.length > 0
                      ? t('Custom model ID')
                      : t('Model ID')}
                  </Label>
                  <Input
                    id="assistant-model-id"
                    value={form.model}
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        model: event.target.value,
                      }))
                    }
                    placeholder={
                      form.provider === 'openrouter'
                        ? 'openai/gpt-4o'
                        : 'gpt-4o'
                    }
                    className="h-9 font-mono text-[13px]"
                    disabled={disabled}
                  />
                </div>
              ) : null}

              <div className="space-y-2">
                <Label htmlFor="assistant-model-name">{t('Display name')}</Label>
                <Input
                  id="assistant-model-name"
                  value={form.name}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      name: event.target.value,
                    }))
                  }
                  placeholder={t('My OpenAI key')}
                  className="h-9 text-[13px]"
                  disabled={disabled}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="assistant-model-api-key">{t('API key')}</Label>
                <Input
                  id="assistant-model-api-key"
                  type="password"
                  autoComplete="off"
                  value={form.apiKey}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      apiKey: event.target.value,
                    }))
                  }
                  placeholder={
                    isEditing && model?.hasApiKey
                      ? `••••${model.hint || '····'}`
                      : t('Enter API key')
                  }
                  className="h-9 font-mono text-[13px]"
                  disabled={disabled}
                />
                {isEditing ? (
                  <p className="text-[11px] text-muted-foreground">
                    {t('Leave blank to keep the existing key.')}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label htmlFor="assistant-model-base-url">
                  {t('Base URL (optional)')}
                </Label>
                <Input
                  id="assistant-model-base-url"
                  value={form.baseUrl}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      baseUrl: event.target.value,
                    }))
                  }
                  placeholder={
                    getAssistantProviderDefaultBaseUrl(form.provider) ||
                    'https://api.example.com/v1'
                  }
                  className="h-9 font-mono text-[13px]"
                  disabled={disabled}
                />
              </div>
            </div>
            <div className="border-t border-border" />
            <div className="flex items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  {t('Enabled')}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {t('Allow this model in the agent composer.')}
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
