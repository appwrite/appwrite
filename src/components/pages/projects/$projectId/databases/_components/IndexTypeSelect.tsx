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
  COLLECTION_INDEX_TYPES,
  getCollectionIndexTypeDefinition,
  getCollectionIndexTypeSearchValue,
  type CollectionIndexType,
} from '@/lib/databases/collection-index-types'
import { localizeTableIndexTypeLabel } from '@/lib/i18n/resource-status-labels'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type IndexTypeSelectProps = {
  id?: string
  value: CollectionIndexType
  onChange: (value: CollectionIndexType) => void
  disabled?: boolean
}

export function IndexTypeSelect({
  id,
  value,
  onChange,
  disabled = false,
}: IndexTypeSelectProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const selected = getCollectionIndexTypeDefinition(value)

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
          <span className="truncate">
            {localizeTableIndexTypeLabel(selected.id, t)}
          </span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0"
        align="start"
        onWheelCapture={(event) => {
          event.stopPropagation()
        }}
      >
        <Command>
          <CommandInput
            placeholder={t('Search index types...')}
            className="h-9 text-[13px]"
          />
          <CommandList className="max-h-[240px] overflow-y-auto overscroll-contain p-1">
            <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
              {t('No index types found')}
            </CommandEmpty>
            <CommandGroup>
              {COLLECTION_INDEX_TYPES.map((entry) => (
                <CommandItem
                  key={entry.id}
                  value={getCollectionIndexTypeSearchValue(entry)}
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
                    <p className="text-[13px] font-medium leading-none text-foreground">
                      {localizeTableIndexTypeLabel(entry.id, t)}
                    </p>
                    <p className="text-[12px] leading-relaxed text-muted-foreground">
                      {t(entry.description)}
                    </p>
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
