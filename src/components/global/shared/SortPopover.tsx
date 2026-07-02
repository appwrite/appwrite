/**
 * Sort popover: trigger button showing current sort and dropdown to pick field + direction.
 * Use with URL-backed sort state; parent provides sortBy/sortOrder and onSortChange.
 */

import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export interface SortOption {
  id: string
  label: string
}

export interface SortPopoverProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Available sort fields (e.g. name, email, $createdAt). */
  options: SortOption[]
  sortBy: string
  sortOrder: 'asc' | 'desc'
  onSortChange: (sortBy: string, sortOrder: 'asc' | 'desc') => void
  /** Optional label for the trigger, e.g. "Sort". */
  triggerLabel?: string
}

function SortIcon({ order }: { order: 'asc' | 'desc' }) {
  return order === 'asc' ? (
    <ArrowUp className="h-3.5 w-3.5 shrink-0" />
  ) : (
    <ArrowDown className="h-3.5 w-3.5 shrink-0" />
  )
}

export function SortPopover({
  open,
  onOpenChange,
  options,
  sortBy,
  sortOrder,
  onSortChange,
  triggerLabel = 'Sort',
}: SortPopoverProps) {
  const t = useT()
  const currentOption = options.find((o) => o.id === sortBy) ?? options[0]
  const currentLabel = currentOption?.label ?? sortBy

  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 shrink-0 gap-1.5 text-[13px]"
        >
          <ArrowUpDown className="h-3.5 w-3.5 shrink-0" />
          {t(triggerLabel)}
          <span className="text-muted-foreground">
            {t(currentLabel)}
            <SortIcon order={sortOrder} />
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[220px] rounded-xl border-border p-1 shadow-lg"
        align="start"
        side="bottom"
        sideOffset={8}
      >
        <div className="py-1">
          {options.map((opt) => (
            <button
              key={opt.id}
              type="button"
              onClick={() => {
                const nextOrder =
                  sortBy === opt.id && sortOrder === 'desc' ? 'asc' : 'desc'
                onSortChange(opt.id, nextOrder)
                onOpenChange(false)
              }}
              className={cn(
                'flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-start text-[13px] transition-colors hover:bg-muted/80',
                sortBy === opt.id && 'bg-muted/50',
              )}
            >
              <span>{t(opt.label)}</span>
              {sortBy === opt.id ? (
                <SortIcon order={sortOrder} />
              ) : (
                <ArrowUpDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              )}
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  )
}
