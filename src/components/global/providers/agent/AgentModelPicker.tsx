import { useMemo, useState } from 'react'
import { Check, ChevronDown, Cpu, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { getAssistantModelIconPath } from '@/lib/assistant/model-providers'
import {
  useAssistantModels,
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

type AgentModelPickerProps = {
  value: string
  onChange: (modelId: string) => void
  disabled?: boolean
  className?: string
  /** `compact` for the composer footer; `form` matches standard form controls. */
  size?: 'compact' | 'form'
  onManageModels?: () => void
}

function modelLabel(model: AssistantModel): string {
  return model.name?.trim() || model.model?.trim() || model.$id
}

export function AgentModelPicker({
  value,
  onChange,
  disabled = false,
  className,
  size = 'compact',
  onManageModels,
}: AgentModelPickerProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { data: models = [], isLoading } = useAssistantModels({
    enabled: !disabled,
  })
  const isFormSize = size === 'form'
  const iconClassName = isFormSize ? 'h-4 w-4' : 'h-3.5 w-3.5'
  const itemClassName = isFormSize
    ? 'px-2.5 py-2 text-[13px]'
    : 'px-2 py-1.5 text-[12px]'

  const selectableModels = useMemo(
    () => models.filter((model) => model.enabled !== false),
    [models],
  )

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

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant={isFormSize ? 'outline' : 'ghost'}
          size="sm"
          disabled={disabled}
          className={cn(
            isFormSize
              ? 'h-9 w-full justify-start gap-1.5 px-3 text-[13px] font-normal text-foreground'
              : 'h-7 max-w-[180px] gap-1 px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground',
            className,
          )}
          aria-label={t('Model')}
          {...analyticsAttrs('agent-model-picker')}
        >
          {selectedModel ? (
            <ModelIcon
              providerId={selectedModel.provider}
              modelId={selectedModel.model}
              className={iconClassName}
            />
          ) : (
            <img
              src="/icons/appwrite.svg"
              alt=""
              className={cn(
                'shrink-0',
                iconClassName,
                PUBLIC_ICON_MUTED_CLASSES,
              )}
            />
          )}
          <span className="min-w-0 flex-1 truncate text-start">
            {selectedLabel}
          </span>
          <ChevronDown
            className={cn(
              'shrink-0 opacity-70',
              isFormSize ? 'h-4 w-4' : 'h-3 w-3',
            )}
          />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className={cn('p-1', isFormSize ? 'w-[var(--radix-popover-trigger-width)] min-w-[280px]' : 'w-[240px]')}
      >
        <button
          type="button"
          className={cn(
            'flex w-full cursor-pointer items-center gap-2 rounded-md text-start transition-colors hover:bg-accent',
            itemClassName,
            !value && 'bg-accent/60',
          )}
          onClick={() => selectModel(DEFAULT_MODEL_ID)}
        >
          <img
            src="/icons/appwrite.svg"
            alt=""
            className={cn('shrink-0', iconClassName, PUBLIC_ICON_MUTED_CLASSES)}
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

        {!isLoading && selectableModels.length === 0 ? (
          <p className="px-2 py-2 text-[11px] leading-snug text-muted-foreground">
            {t('No custom models')}
          </p>
        ) : null}

        {selectableModels.map((model) => {
          const selected = value === model.$id
          return (
            <button
              key={model.$id}
              type="button"
              className={cn(
                'flex w-full cursor-pointer items-center gap-2 rounded-md text-start transition-colors hover:bg-accent',
                itemClassName,
                selected && 'bg-accent/60',
              )}
              onClick={() => selectModel(model.$id)}
            >
              <ModelIcon
                providerId={model.provider}
                modelId={model.model}
                className={iconClassName}
              />
              <span className="min-w-0 flex-1 truncate">
                {modelLabel(model)}
              </span>
              {selected ? (
                <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
              ) : null}
            </button>
          )
        })}

        {onManageModels ? (
          <>
            <div className="my-1 border-t border-border" />
            <button
              type="button"
              className={cn(
                'flex w-full cursor-pointer items-center gap-1.5 rounded-md text-start text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                itemClassName,
              )}
              disabled={disabled}
              {...analyticsAttrs('agent-manage-models')}
              onClick={() => {
                setOpen(false)
                onManageModels()
              }}
            >
              <Settings2 className={cn('shrink-0', iconClassName)} />
              {t('Manage models')}
            </button>
          </>
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
