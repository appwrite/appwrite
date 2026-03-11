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
  /**
   * Unique scope for saved filter presets (e.g. "sites", "storage.buckets").
   * When set with onApplyQuery, shows saved filters list and "Save current".
   */
  filterScope?: string
  /** Apply a saved filter's query param (or undefined to clear). Called when user selects a saved filter. */
  onApplyQuery?: (queryParam: string | undefined) => void
  /** Team/org ID for team-level saved filters (project.teamId or orgId). When set, users can save filters for the team. */
  teamId?: string | null
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
  filterScope,
  onApplyQuery,
  teamId,
}: FiltersPopoverProps) {
  return (
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 gap-1.5 text-[13px]"
        >
          <Filter className="h-3.5 w-3.5 shrink-0" />
          Filters
          {filterMap.size > 0 && (
            <span className="flex size-5 items-center justify-center rounded-full bg-primary/15 text-[11px] font-semibold tabular-nums text-primary">
              {filterMap.size}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="z-[200] max-h-[calc(100dvh-4rem)] w-[380px] overflow-hidden rounded-xl border-border p-0 shadow-lg"
        align="start"
        side="bottom"
        sideOffset={8}
      >
        <FiltersPopoverContent
          columns={columns}
          filterMap={filterMap}
          onRemoveFilter={onRemoveFilter}
          onClearAll={onClearAll}
          onApplyFilter={onApplyFilter}
          onClose={() => onOpenChange(false)}
          resourceLabel={resourceLabel}
          filterScope={filterScope}
          onApplyQuery={onApplyQuery}
          teamId={teamId ?? undefined}
        />
      </PopoverContent>
    </Popover>
  )
}
