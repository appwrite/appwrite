/**
 * Table Selector
 *
 * A searchable dropdown for selecting a table within a database.
 * Limits results, uses debounced search, and keepPreviousData to avoid
 * layout shifts and loading flashes (follows ProjectSelector pattern).
 */

import { useState, useEffect, useMemo } from 'react'
import type { LucideIcon } from 'lucide-react'
import { ChevronDown, Table2, Loader2, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandList,
} from '@/components/ui/command'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { tablesQueryOptions } from '@/lib/react-query/hooks'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const DEFAULT_LIMIT = 15

export interface TableSelectorProps {
  projectId: string
  databaseId: string
  dbKind: DatabaseRouteKind
  value: string
  selectedName?: string
  onSelect: (tableId: string) => void
  onCreateClick: () => void
  placeholder?: string
  /** Shown when the database has no containers (e.g. "No tables", "No collections") */
  emptyLabel?: string
  /** Shown in the popover when search returns nothing */
  noResultsLabel?: string
  /** Tooltip on the create button when enabled (e.g. "Create table") */
  createTooltip?: string
  /** Icon for trigger and list rows; default Table2 */
  itemIcon?: LucideIcon
  limit?: number
  triggerClassName?: string
  /** When true, show a disabled droplist with emptyLabel and the create button */
  empty?: boolean
  /** When true, the create (plus) button is disabled with tooltip */
  createDisabled?: boolean
  createDisabledTooltip?: string
}

export function TableSelector({
  projectId,
  databaseId,
  dbKind,
  value,
  selectedName,
  onSelect,
  onCreateClick,
  placeholder = 'Select table',
  emptyLabel = 'No tables',
  noResultsLabel = 'No tables found',
  createTooltip = 'Create table',
  itemIcon: ItemIcon = Table2,
  limit = DEFAULT_LIMIT,
  triggerClassName,
  empty = false,
  createDisabled = false,
  createDisabledTooltip = "You don't have permission to create tables.",
}: TableSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data, isFetching } = useQuery({
    ...tablesQueryOptions(
      projectId,
      databaseId,
      dbKind,
      0,
      limit,
      debouncedSearch || undefined,
      'asc',
      'name',
    ),
    enabled: !!projectId && !!databaseId && open && !empty,
    placeholderData: keepPreviousData,
  })

  const tables = useMemo(() => data?.tables ?? [], [data?.tables])

  const displayValue = empty
    ? t(emptyLabel)
    : value && value !== '-'
      ? selectedName || tables.find((t) => t.$id === value)?.name || t(placeholder)
      : t(placeholder)

  return (
    <div className="flex min-w-0 flex-1 items-center gap-2">
      {empty ? (
        <Button
          type="button"
          variant="outline"
          disabled
          className={cn(
            'h-8 min-w-0 flex-1 justify-between gap-1.5 text-[13px] font-normal text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-1.5">
            <ItemIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span className="truncate">{displayValue}</span>
          </span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      ) : (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              className={cn(
                'h-8 min-w-0 flex-1 justify-between gap-1.5 text-[13px] font-normal',
                (!value || value === '-') && 'text-muted-foreground',
                triggerClassName,
              )}
            >
              <span className="flex min-w-0 items-center gap-1.5">
                <ItemIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="truncate">{displayValue}</span>
              </span>
              <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent
            className="min-w-[var(--radix-popover-trigger-width)] max-w-[320px] p-0"
            align="start"
          >
            <Command shouldFilter={false}>
              <div className="relative">
                <CommandInput
                  placeholder={t('Search...')}
                  value={search}
                  onValueChange={setSearch}
                  className={cn('h-9', isFetching && 'pe-8')}
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
              <CommandList className="min-h-[180px] max-h-[240px]">
                {tables.length === 0 && (
                  <CommandEmpty>
                    {isFetching ? t('Loading…') : t(noResultsLabel)}
                  </CommandEmpty>
                )}
                <CommandGroup>
                  {tables.map((table) => (
                    <button
                      key={table.$id}
                      type="button"
                      onClick={() => {
                        onSelect(table.$id)
                        setOpen(false)
                      }}
                      className="flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-start text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                      <ItemIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="truncate">{table.name}</span>
                    </button>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      )}
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8 shrink-0"
              onClick={onCreateClick}
              disabled={createDisabled}
              aria-label={
                createDisabled ? t(createDisabledTooltip) : t(createTooltip)
              }
            >
              <Plus className="h-4 w-4" />
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          {createDisabled ? t(createDisabledTooltip) : t(createTooltip)}
        </TooltipContent>
      </Tooltip>
    </div>
  )
}
