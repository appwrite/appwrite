/**
 * Table Selector
 *
 * A searchable dropdown for selecting a table within a database.
 * Limits results, uses debounced search, and keepPreviousData to avoid
 * layout shifts and loading flashes (follows ProjectSelector pattern).
 */

import { useState, useEffect, useMemo } from 'react'
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
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'

const DEFAULT_LIMIT = 15

export interface TableSelectorProps {
  projectId: string
  databaseId: string
  value: string
  selectedName?: string
  onSelect: (tableId: string) => void
  onCreateClick: () => void
  placeholder?: string
  limit?: number
  triggerClassName?: string
  /** When true, show "No tables" and disable the selector, only show create button */
  empty?: boolean
}

export function TableSelector({
  projectId,
  databaseId,
  value,
  selectedName,
  onSelect,
  onCreateClick,
  placeholder = 'Select table',
  limit = DEFAULT_LIMIT,
  triggerClassName,
  empty = false,
}: TableSelectorProps) {
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
      0,
      limit,
      debouncedSearch || undefined,
      'asc',
      'name',
    ),
    enabled: !!projectId && !!databaseId && open,
    placeholderData: keepPreviousData,
  })

  const tables = useMemo(() => data?.tables ?? [], [data?.tables])

  const displayValue =
    value && value !== '-'
      ? selectedName || tables.find((t) => t.$id === value)?.name || placeholder
      : placeholder

  if (empty) {
    return (
      <div className="flex min-w-0 flex-1 items-center gap-2">
        <span className="text-[13px] text-muted-foreground">No tables</span>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8 shrink-0"
              onClick={onCreateClick}
            >
              <Plus className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent side="bottom">Create table</TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <div className="flex min-w-0 flex-1 items-center gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            className={cn(
              'h-8 min-w-0 flex-1 justify-between gap-1.5 text-[13px] font-normal',
              (!value || value === '-') && 'text-muted-foreground',
              triggerClassName,
            )}
          >
            <span className="flex min-w-0 items-center gap-1.5">
              <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
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
                placeholder="Search tables..."
                value={search}
                onValueChange={setSearch}
                className={cn('h-9', isFetching && 'pr-8')}
              />
              <div
                className={cn(
                  'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                  isFetching ? 'opacity-100' : 'opacity-0',
                )}
                aria-hidden
              >
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            </div>
            <CommandList className="max-h-[240px]">
              {tables.length === 0 && (
                <CommandEmpty>
                  {isFetching ? '' : 'No tables found'}
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
                    className="flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-left text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground"
                  >
                    <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{table.name}</span>
                  </button>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="outline"
            size="icon"
            className="h-8 w-8 shrink-0"
            onClick={onCreateClick}
          >
            <Plus className="h-4 w-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">Create table</TooltipContent>
      </Tooltip>
    </div>
  )
}
