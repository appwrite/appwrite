/**
 * Database Selector
 *
 * A searchable dropdown for selecting a database within a project.
 * Limits results, uses debounced search, and keepPreviousData to avoid
 * layout shifts and loading flashes (follows ProjectSelector pattern).
 */

import { useState, useEffect, useMemo } from 'react'
import { ChevronDown, Database, Loader2, Plus, Table2 } from 'lucide-react'
import { DatabaseTypeIcon } from './DatabaseTypeIcon'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { databasesQueryOptions } from '@/lib/react-query/hooks'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'

const DEFAULT_LIMIT = 15

export interface DatabaseSelectorProps {
  projectId: string
  value: string
  selectedName?: string
  onSelect: (databaseId: string) => void
  onCreateDatabaseClick: () => void
  onCreateTableClick: () => void
  placeholder?: string
  limit?: number
  triggerClassName?: string
  /** Label for the table/container create action (e.g. Create table vs Create collection) */
  createTableMenuLabel?: string
  /** When true, the create database menu item is disabled */
  createDatabaseDisabled?: boolean
  createDatabaseDisabledTooltip?: string
  /** When true, the create table menu item is disabled */
  createTableDisabled?: boolean
  createTableDisabledTooltip?: string
}

export function DatabaseSelector({
  projectId,
  value,
  selectedName,
  onSelect,
  onCreateDatabaseClick,
  onCreateTableClick,
  placeholder = 'Select database',
  limit = DEFAULT_LIMIT,
  triggerClassName,
  createTableMenuLabel = 'Create table',
  createDatabaseDisabled = false,
  createDatabaseDisabledTooltip = "You don't have permission to create databases.",
  createTableDisabled = false,
  createTableDisabledTooltip = "You don't have permission to create tables.",
}: DatabaseSelectorProps) {
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
    ...databasesQueryOptions(projectId, 0, limit, debouncedSearch || undefined),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const databases = useMemo(() => data?.databases ?? [], [data?.databases])

  const selectedDatabase = value
    ? databases.find((d) => d.$id === value)
    : undefined

  const displayValue =
    selectedName || selectedDatabase?.name || placeholder

  const bothCreateDisabled = createDatabaseDisabled && createTableDisabled
  const triggerDisabledTooltip =
    "You don't have permission to create databases or tables."

  return (
    <div className="flex min-w-0 flex-1 items-center gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            className={cn(
              'h-8 min-w-0 flex-1 justify-between gap-1.5 text-[13px] font-normal',
              !value && 'text-muted-foreground',
              triggerClassName,
            )}
          >
            <span className="flex min-w-0 items-center gap-1.5">
              <DatabaseTypeIcon apiType={selectedDatabase?.type} />
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
                placeholder="Search databases..."
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
              {databases.length === 0 && (
                <CommandEmpty>
                  {isFetching ? '' : 'No databases found'}
                </CommandEmpty>
              )}
              <CommandGroup>
                {databases.map((db) => (
                  <button
                    key={db.$id}
                    type="button"
                    onClick={() => {
                      onSelect(db.$id)
                      setOpen(false)
                    }}
                    className="flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-left text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground"
                  >
                    <DatabaseTypeIcon apiType={db.type} />
                    <span className="truncate">{db.name}</span>
                  </button>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {bothCreateDisabled ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">
              <Button
                variant="outline"
                size="icon"
                className="h-8 w-8 shrink-0"
                disabled
                aria-label={triggerDisabledTooltip}
              >
                <Plus className="h-4 w-4" />
              </Button>
            </span>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            {triggerDisabledTooltip}
          </TooltipContent>
        </Tooltip>
      ) : (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              className="h-8 w-8 shrink-0"
              aria-label="Create database or table"
              aria-haspopup="menu"
            >
              <Plus className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            <DropdownMenuItem
              disabled={createDatabaseDisabled}
              title={
                createDatabaseDisabled
                  ? createDatabaseDisabledTooltip
                  : undefined
              }
              className="gap-2 text-[13px]"
              onSelect={() => onCreateDatabaseClick()}
            >
              <Database className="h-4 w-4 shrink-0 text-muted-foreground" />
              Create database
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={createTableDisabled}
              title={
                createTableDisabled ? createTableDisabledTooltip : undefined
              }
              className="gap-2 text-[13px]"
              onSelect={() => onCreateTableClick()}
            >
              <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
              {createTableMenuLabel}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  )
}
