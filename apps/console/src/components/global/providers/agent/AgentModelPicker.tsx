import { useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useInfiniteQuery, useQuery } from '@tanstack/react-query'
import {
  Check,
  ChevronDown,
  Cpu,
  Loader2,
  Settings2,
} from 'lucide-react'
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
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Skeleton } from '@/components/ui/skeleton'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { getAssistantModelIconPath } from '@/lib/assistant/model-providers'
import {
  ASSISTANT_MODELS_PICKER_PAGE_SIZE,
  assistantModelQueryOptions,
  assistantModelsInfiniteQueryOptions,
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
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const listScrollRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const {
    data,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
  } = useInfiniteQuery({
    ...assistantModelsInfiniteQueryOptions(
      ASSISTANT_MODELS_PICKER_PAGE_SIZE,
      open ? debouncedSearch || undefined : undefined,
      { enabled: !disabled && open },
    ),
    placeholderData: keepPreviousData,
  })

  const { data: selectedModelFromQuery } = useQuery({
    ...assistantModelQueryOptions(value || undefined, {
      enabled: !disabled && !!value,
    }),
  })

  const models = useMemo(
    () =>
      (data?.pages.flatMap((page) => page.models) ?? []).filter(
        (model) => model.enabled !== false,
      ),
    [data?.pages],
  )

  const selectedModel = useMemo(() => {
    if (!value) return undefined
    return (
      models.find((model) => model.$id === value) ?? selectedModelFromQuery
    )
  }, [models, selectedModelFromQuery, value])

  const isFormSize = size === 'form'
  const iconClassName = isFormSize ? 'h-4 w-4' : 'h-3.5 w-3.5'
  const itemClassName = isFormSize
    ? 'px-2.5 py-2 text-[13px]'
    : 'px-2 py-1.5 text-[12px]'

  const selectedLabel = useMemo(() => {
    if (!value) return t('Appwrite default')
    return selectedModel ? modelLabel(selectedModel) : t('Custom model')
  }, [selectedModel, t, value])

  const showDefaultOption = useMemo(() => {
    if (!debouncedSearch) return true
    return t('Appwrite default').toLowerCase().includes(debouncedSearch.toLowerCase())
  }, [debouncedSearch, t])

  const showListSkeleton = isFetching && models.length === 0 && !showDefaultOption

  useEffect(() => {
    const sentinel = sentinelRef.current
    const root = listScrollRef.current
    if (
      !sentinel ||
      !root ||
      !open ||
      !hasNextPage ||
      isFetchingNextPage ||
      !fetchNextPage
    ) {
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
          void fetchNextPage()
        }
      },
      { root, rootMargin: '120px', threshold: 0.1 },
    )

    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [fetchNextPage, hasNextPage, isFetchingNextPage, open, models.length])

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
          aria-expanded={open}
          role="combobox"
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
        className={cn(
          'p-0',
          isFormSize
            ? 'w-[var(--radix-popover-trigger-width)] min-w-[280px]'
            : 'w-[260px]',
        )}
        onWheelCapture={(event) => {
          event.stopPropagation()
        }}
      >
        <Command shouldFilter={false} className="overflow-hidden">
          <div className="relative">
            <CommandInput
              placeholder={t('Search models...')}
              value={search}
              onValueChange={setSearch}
              className={cn(
                isFormSize ? 'h-9 text-[13px]' : 'h-8 text-[12px]',
                isFetching && 'pe-8',
              )}
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList
            ref={listScrollRef}
            className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain"
          >
            {showListSkeleton ? (
              <div className="space-y-0.5 p-1" aria-hidden>
                {Array.from({ length: 5 }, (_, index) => (
                  <div
                    key={index}
                    className="flex items-center gap-2 rounded-sm px-2 py-1.5"
                  >
                    <Skeleton className="h-4 w-4 shrink-0 rounded-sm" />
                    <Skeleton
                      className="h-4 rounded-sm"
                      style={{ width: `${55 + (index % 3) * 12}%` }}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <>
                <CommandEmpty>{t('No results found')}</CommandEmpty>
                <CommandGroup>
                  {showDefaultOption ? (
                    <CommandItem
                      value={`default ${t('Appwrite default')}`}
                      onSelect={() => selectModel(DEFAULT_MODEL_ID)}
                      className={cn('gap-2', itemClassName, !value && 'bg-accent/50')}
                    >
                      <img
                        src="/icons/appwrite.svg"
                        alt=""
                        className={cn(
                          'shrink-0',
                          iconClassName,
                          PUBLIC_ICON_MUTED_CLASSES,
                        )}
                      />
                      <span className="min-w-0 flex-1 truncate">
                        {t('Appwrite default')}
                      </span>
                      {!value ? (
                        <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
                      ) : null}
                    </CommandItem>
                  ) : null}

                  {models.map((model) => {
                    const selected = value === model.$id
                    return (
                      <CommandItem
                        key={model.$id}
                        value={`${model.$id} ${modelLabel(model)}`}
                        onSelect={() => selectModel(model.$id)}
                        className={cn(
                          'gap-2',
                          itemClassName,
                          selected && 'bg-accent/50',
                        )}
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
                      </CommandItem>
                    )
                  })}

                  {hasNextPage ? (
                    <div
                      ref={sentinelRef}
                      className="h-px w-full shrink-0"
                      aria-hidden
                    />
                  ) : null}
                  {isFetchingNextPage ? (
                    <div className="flex items-center justify-center py-2">
                      <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    </div>
                  ) : null}
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>

        {onManageModels ? (
          <div className="shrink-0 border-t border-border p-1">
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
          </div>
        ) : null}
      </PopoverContent>
    </Popover>
  )
}
