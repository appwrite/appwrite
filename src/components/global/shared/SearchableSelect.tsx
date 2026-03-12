/**
 * Searchable dropdown (combobox) using Popover + Command.
 * Use for column, operator, and value droplists in filters and elsewhere.
 */

import { useState } from 'react'
import { ChevronDown } from 'lucide-react'
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
import { cn } from '@/lib/utils'

export interface SearchableSelectItem {
  value: string
  label: string
}

export interface SearchableSelectProps {
  value: string
  onValueChange: (value: string) => void
  items: SearchableSelectItem[]
  placeholder?: string
  searchPlaceholder?: string
  disabled?: boolean
  triggerClassName?: string
  contentClassName?: string
  emptyMessage?: string
  /** When true, trigger shows placeholder-style text when no value selected */
  showPlaceholderWhenEmpty?: boolean
}

export function SearchableSelect({
  value,
  onValueChange,
  items,
  placeholder = 'Select…',
  searchPlaceholder = 'Search…',
  disabled = false,
  triggerClassName,
  contentClassName,
  emptyMessage = 'No results',
  showPlaceholderWhenEmpty = true,
}: SearchableSelectProps) {
  const [open, setOpen] = useState(false)
  const selectedLabel = items.find((i) => i.value === value)?.label ?? ''
  const displayText =
    value && selectedLabel
      ? selectedLabel
      : showPlaceholderWhenEmpty
        ? placeholder
        : ''

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            'h-9 w-full justify-between gap-2 text-[13px] font-normal',
            !value && showPlaceholderWhenEmpty && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="truncate">{displayText || placeholder}</span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'z-[200] w-[var(--radix-popover-trigger-width)] p-0',
          contentClassName,
        )}
        align="start"
      >
        <Command>
          <CommandInput
            placeholder={searchPlaceholder}
            className="h-9 text-[13px]"
          />
          <CommandList className="max-h-[240px]">
            <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
              {emptyMessage}
            </CommandEmpty>
            <CommandGroup>
              {items.map((item) => (
                <CommandItem
                  key={item.value}
                  value={item.label}
                  className="text-[13px]"
                  onSelect={() => {
                    onValueChange(item.value)
                    setOpen(false)
                  }}
                >
                  {item.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
