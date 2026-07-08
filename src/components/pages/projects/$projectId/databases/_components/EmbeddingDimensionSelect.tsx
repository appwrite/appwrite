import { useState } from 'react'
import { Check, ChevronDown } from 'lucide-react'
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
import {
  EMBEDDING_DIMENSION_CUSTOM,
  EMBEDDING_DIMENSION_PRESETS,
  getEmbeddingDimensionPreset,
  getEmbeddingDimensionPresetSearchValue,
  type EmbeddingDimensionPresetId,
} from '@/lib/databases/embedding-dimension-presets'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type EmbeddingDimensionSelectProps = {
  id?: string
  value: EmbeddingDimensionPresetId
  onChange: (value: EmbeddingDimensionPresetId) => void
  disabled?: boolean
}

export function EmbeddingDimensionSelect({
  id,
  value,
  onChange,
  disabled = false,
}: EmbeddingDimensionSelectProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const selectedPreset = getEmbeddingDimensionPreset(value)
  const isCustom = value === EMBEDDING_DIMENSION_CUSTOM
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className="h-9 w-full justify-between gap-2 text-[13px] font-normal"
        >
          {isCustom ? (
            <span className="truncate">{t('Custom dimension')}</span>
          ) : selectedPreset ? (
            <span className="flex min-w-0 items-center gap-1.5 truncate">
              <span className="truncate">{selectedPreset.label}</span>
              <span className="shrink-0 text-muted-foreground">
                ({selectedPreset.dimension})
              </span>
              <span className="shrink-0 text-[11px] text-muted-foreground">
                · {selectedPreset.vendor}
              </span>
            </span>
          ) : (
            <span className="truncate text-muted-foreground">
              {t('Select embedding model')}
            </span>
          )}
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="flex max-h-[min(360px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] flex-col overflow-hidden p-0"
        align="start"
        onWheelCapture={(event) => {
          event.stopPropagation()
        }}
      >
        <Command className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <CommandInput
            placeholder={t('Search embedding models...')}
            className="h-9 shrink-0 text-[13px]"
          />
          <CommandList className="max-h-none min-h-0 flex-1 overflow-y-auto overscroll-contain p-1">
            <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
              {t('No embedding models found')}
            </CommandEmpty>
            <CommandGroup>
              {EMBEDDING_DIMENSION_PRESETS.map((entry) => (
                <CommandItem
                  key={entry.id}
                  value={getEmbeddingDimensionPresetSearchValue(entry)}
                  className="items-start rounded-md px-2 py-2.5 aria-selected:bg-accent"
                  onSelect={() => {
                    onChange(entry.id)
                    setOpen(false)
                  }}
                >
                  <Check
                    className={cn(
                      'me-2 mt-0.5 h-4 w-4 shrink-0',
                      value === entry.id ? 'opacity-100' : 'opacity-0',
                    )}
                  />
                  <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                      <p className="text-[13px] font-medium leading-none text-foreground">
                        {entry.label}
                      </p>
                      <span className="text-[12px] leading-none text-muted-foreground">
                        ({entry.dimension})
                      </span>
                      <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium leading-none text-muted-foreground">
                        {entry.vendor}
                      </span>
                    </div>
                    <p className="text-pretty text-[12px] leading-relaxed text-muted-foreground">
                      {t(entry.description)}
                    </p>
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
          <div className="shrink-0 border-t border-border bg-popover p-1">
            <button
              type="button"
              className="relative flex w-full cursor-pointer items-start gap-2 rounded-sm px-2 py-2.5 text-start text-[13px] outline-hidden hover:bg-accent hover:text-accent-foreground"
              onClick={() => {
                onChange(EMBEDDING_DIMENSION_CUSTOM)
                setOpen(false)
              }}
            >
              <Check
                className={cn(
                  'me-0 mt-0.5 h-4 w-4 shrink-0',
                  isCustom ? 'opacity-100' : 'opacity-0',
                )}
              />
              <div className="min-w-0 flex-1 space-y-1">
                <p className="text-[13px] font-medium leading-none text-foreground">
                  {t('Custom dimension')}
                </p>
                <p className="text-pretty text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Enter a custom embedding dimension for models not listed above.',
                  )}
                </p>
              </div>
            </button>
          </div>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
