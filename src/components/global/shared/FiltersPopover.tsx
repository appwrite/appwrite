/**
 * Shared filter popover: trigger button (Filters + badge) and panel content.
 * Use with table-filters URL state; parent provides filterMap and navigate callbacks.
 */

import { Filter } from 'lucide-react'
import type { CompactFilterKey, FilterColumn, FilterMap } from '@/lib/table-filters'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { FiltersPopoverContent } from './FiltersPopoverContent'

export interface FiltersPopoverProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  columns: FilterColumn[]
  filterMap: FilterMap
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  onApplyFilter: (key: CompactFilterKey, queryStr: string) => void
  /** e.g. "buckets", "files" – used in description. */
  resourceLabel?: string
}

export function FiltersPopover({
  open,
  onOpenChange,
  columns,
  filterMap,
  onRemoveFilter,
  onClearAll,
  onApplyFilter,
  resourceLabel = 'items',
}: FiltersPopoverProps) {
  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 gap-2 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
        >
          <Filter className="h-3.5 w-3.5" />
          Filters
          {filterMap.size > 0 && (
            <span className="ml-1 flex size-5 items-center justify-center rounded-full bg-primary/20 text-[11px] font-medium text-primary">
              {filterMap.size}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="z-[200] w-96 p-0"
        align="start"
        side="bottom"
        sideOffset={6}
      >
        <FiltersPopoverContent
          columns={columns}
          filterMap={filterMap}
          onRemoveFilter={onRemoveFilter}
          onClearAll={onClearAll}
          onApplyFilter={onApplyFilter}
          onClose={() => onOpenChange(false)}
          resourceLabel={resourceLabel}
        />
      </PopoverContent>
    </Popover>
  )
}
