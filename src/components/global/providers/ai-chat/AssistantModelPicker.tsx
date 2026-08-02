import { useMemo, useState } from 'react'
import {
  Check,
  ChevronDown,
  Cpu,
  Loader2,
  Pencil,
  Plus,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { getAssistantModelIconPath } from '@/lib/assistant/model-providers'
import {
  useAssistantModels,
  useDeleteAssistantModel,
  useUpdateAssistantModel,
  type AssistantModel,
} from '@/lib/react-query/hooks'

function ModelIcon({
  providerId,
  modelId,
  className,
}: {
  providerId?: string | null
  modelId?: string | null
  className?: string
}) {
  const icon = getAssistantModelIconPath(providerId, modelId)
  if (icon) {
    return (
      <img
        src={icon}
        alt=""
        className={cn('h-3.5 w-3.5 shrink-0', PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }
  return (
    <Cpu
      className={cn('h-3.5 w-3.5 shrink-0 text-muted-foreground', className)}
      aria-hidden
    />
  )
}

const DEFAULT_MODEL_ID = ''

type AssistantModelPickerProps = {
  value: string
  onChange: (modelId: string) => void
  disabled?: boolean
  className?: string
  onAddModel?: () => void
  onEditModel?: (model: AssistantModel) => void
}

function modelLabel(model: AssistantModel): string {
  return model.name?.trim() || model.model?.trim() || model.$id
}

export function AssistantModelPicker({
  value,
  onChange,
  disabled = false,
  className,
  onAddModel,
  onEditModel,
}: AssistantModelPickerProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const { data: models = [], isLoading } = useAssistantModels({
    enabled: !disabled,
  })
  const updateMutation = useUpdateAssistantModel()
  const deleteMutation = useDeleteAssistantModel()

  const selectedModel = useMemo(
    () => (value ? models.find((model) => model.$id === value) : undefined),
    [models, value],
  )
  const selectedLabel = useMemo(() => {
    if (!value) return t('Appwrite default')
    return selectedModel ? modelLabel(selectedModel) : t('Custom model')
  }, [selectedModel, t, value])

  const selectModel = (modelId: string) => {
    onChange(modelId)
    setOpen(false)
  }

  const handleToggleEnabled = async (
    model: AssistantModel,
    enabled: boolean,
  ) => {
    try {
      await updateMutation.mutateAsync({
        modelId: model.$id,
        enabled,
      })
      if (!enabled && value === model.$id) {
        onChange(DEFAULT_MODEL_ID)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update model')))
    }
  }

  const handleDelete = async (modelId: string) => {
    setDeletingId(modelId)
    try {
      await deleteMutation.mutateAsync(modelId)
      if (value === modelId) onChange(DEFAULT_MODEL_ID)
      toast.success(t('Model deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete model')))
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          disabled={disabled}
          className={cn(
            'h-7 max-w-[180px] gap-1 px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground',
            className,
          )}
          aria-label={t('Model')}
        >
          {selectedModel ? (
            <ModelIcon
              providerId={selectedModel.provider}
              modelId={selectedModel.model}
            />
          ) : null}
          <span className="truncate">{selectedLabel}</span>
          <ChevronDown className="h-3 w-3 shrink-0 opacity-70" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-[300px] p-1">
        <button
          type="button"
          className={cn(
            'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-[12px] transition-colors hover:bg-accent',
            !value && 'bg-accent/60',
          )}
          onClick={() => selectModel(DEFAULT_MODEL_ID)}
        >
          <img
            src="/icons/appwrite.svg"
            alt=""
            className={cn('h-3.5 w-3.5 shrink-0', PUBLIC_ICON_MUTED_CLASSES)}
          />
          <span className="min-w-0 flex-1 truncate">
            {t('Appwrite default')}
          </span>
          {!value ? (
            <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
          ) : null}
        </button>

        {isLoading ? (
          <p className="px-2 py-2 text-[11px] text-muted-foreground">
            {t('Loading...')}
          </p>
        ) : null}

        {models.length === 0 && !isLoading ? (
          <div className="px-2 py-3 text-center">
            <p className="text-[12px] font-medium text-foreground">
              {t('No custom models')}
            </p>
            <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
              {t('Add your own provider and API key to choose a different model.')}
            </p>
          </div>
        ) : null}

        {models.map((model) => {
          const selected = value === model.$id
          const enabled = model.enabled !== false
          return (
            <div
              key={model.$id}
              className={cn(
                'flex items-start gap-1 rounded-md px-1 py-1',
                selected && 'bg-accent/60',
              )}
            >
              <button
                type="button"
                className={cn(
                  'min-w-0 flex-1 rounded-md px-1 py-0.5 text-start text-[12px] transition-colors',
                  enabled
                    ? 'hover:bg-accent'
                    : 'cursor-not-allowed opacity-60',
                )}
                disabled={!enabled}
                onClick={() => {
                  if (!enabled) return
                  selectModel(model.$id)
                }}
              >
                <span className="flex items-center gap-1.5">
                  <ModelIcon
                    providerId={model.provider}
                    modelId={model.model}
                  />
                  <span className="min-w-0 flex-1 truncate font-medium">
                    {modelLabel(model)}
                  </span>
                  {selected ? (
                    <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
                  ) : null}
                </span>
                <span className="block truncate ps-5 text-[10px] text-muted-foreground">
                  {model.model}
                  {model.hasApiKey && model.hint ? ` · ••••${model.hint}` : ''}
                </span>
              </button>
              <div
                className="flex shrink-0 items-center gap-0.5 pt-0.5"
                onClick={(event) => event.stopPropagation()}
                onKeyDown={(event) => event.stopPropagation()}
              >
                <Switch
                  checked={enabled}
                  onCheckedChange={(checked) =>
                    void handleToggleEnabled(model, checked)
                  }
                  disabled={disabled || updateMutation.isPending}
                  aria-label={t('Enabled')}
                  className="scale-90"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7"
                  disabled={disabled}
                  onClick={() => {
                    setOpen(false)
                    onEditModel?.(model)
                  }}
                  aria-label={t('Update')}
                >
                  <Pencil className="h-3.5 w-3.5" />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7 text-muted-foreground hover:text-foreground"
                  disabled={disabled || deletingId === model.$id}
                  onClick={() => void handleDelete(model.$id)}
                  aria-label={t('Delete')}
                >
                  {deletingId === model.$id ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Trash2 className="h-3.5 w-3.5" />
                  )}
                </Button>
              </div>
            </div>
          )
        })}

        <div className="my-1 border-t border-border" />
        <button
          type="button"
          className="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-start text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          disabled={disabled}
          onClick={() => {
            setOpen(false)
            onAddModel?.()
          }}
        >
          <Plus className="h-3.5 w-3.5 shrink-0" />
          {t('Add model')}
        </button>
      </PopoverContent>
    </Popover>
  )
}
