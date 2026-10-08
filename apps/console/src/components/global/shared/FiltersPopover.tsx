/**
 * Shared filter popover: trigger button (Filters + badge) and panel content.
 * Use with table-filters URL state; parent provides filterMap and navigate callbacks.
 */

import { Filter, X } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterMap,
} from '@/lib/table-filters'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { FiltersPopoverContent } from './FiltersPopoverContent'
import { ToolbarCountBadge } from './ToolbarCountBadge'
import {
  serviceHeaderFiltersButton,
  serviceHeaderFiltersLabel,
} from '@/components/pages/projects/$projectId/shared/service-header-container'

export interface FiltersPopoverProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  columns: FilterColumn[]
  filterMap: FilterMap
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  /** When `replaceKey` is set, that filter is removed before applying (single navigation). */
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  /** e.g. "buckets", "files" – used in description. */
  resourceLabel?: string
  /**
   * Unique scope for saved filter presets (e.g. "sites", "storage.buckets").
   * When set with onApplyQuery, shows saved filters list and "Save current".
   */
  filterScope?: string
  /** Apply a saved filter (query and optional sort). Signature: (queryParam?, sortParam?). */
  onApplyQuery?: (queryParam: string | undefined, sortParam?: string) => void
  /** Team/org ID for team-level saved filters (project.teamId or orgId). When set, users can save filters for the team. */
  teamId?: string | null
  /** When set, show "Order by" inside the popover (options come from columns); sort is saved with filter presets. */
  sortBy?: string
  sortOrder?: 'asc' | 'desc'
  onSortChange?: (sortBy: string, sortOrder: 'asc' | 'desc') => void
  /** Encoded default sort for matching (e.g. $createdAt_desc). Omit when no sort UI. */
  defaultSortParam?: string
  /** Called when user clicks Reset. Omit to hide the reset control. */
  onReset?: () => void
  triggerClassName?: string
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
  sortBy,
  sortOrder,
  onSortChange,
  defaultSortParam,
  onReset,
  triggerClassName,
}: FiltersPopoverProps) {
  const t = useT()
  const hasFilters = filterMap.size > 0
  return (
    // Split button while filters are active: [ Filters 2 | × ]. The clear
    // control sits beside the trigger (not inside it) because buttons can't
    // be nested.
    <div className="inline-flex shrink-0 items-stretch">
    <Popover open={open} onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(
            'border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground',
            serviceHeaderFiltersButton,
            hasFilters && 'rounded-e-none',
            triggerClassName,
          )}
        >
          <Filter className="h-3.5 w-3.5 shrink-0" />
          <span className={serviceHeaderFiltersLabel}>{t('Filters')}</span>
          {filterMap.size > 0 ? (
            <ToolbarCountBadge
              count={filterMap.size}
              placement="inline"
              inlineTone="emphasis"
            />
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="max-h-[calc(100dvh-4rem)] w-[380px] overflow-hidden rounded-xl border-border p-0 shadow-lg"
        align="start"
        side="bottom"
        sideOffset={8}
      >
        {open ? (
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
            sortBy={sortBy}
            sortOrder={sortOrder}
            onSortChange={onSortChange}
            defaultSortParam={defaultSortParam}
            onReset={onReset}
          />
        ) : null}
      </PopoverContent>
    </Popover>
    {hasFilters ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          onClearAll()
          onOpenChange(false)
        }}
        aria-label={t('Clear filters')}
        title={t('Clear filters')}
        className={cn(
          'h-auto w-9 shrink-0 rounded-s-none border-s-0 border-border bg-transparent p-0 text-muted-foreground hover:bg-accent hover:text-foreground',
        )}
      >
        <X className="h-3.5 w-3.5" />
      </Button>
    ) : null}
    </div>
  )
}
