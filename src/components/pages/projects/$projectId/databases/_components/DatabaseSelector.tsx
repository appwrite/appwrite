/**
 * Database Selector
 *
 * A searchable dropdown for selecting a database within a project.
 * Limits results, uses debounced search, and keepPreviousData to avoid
 * layout shifts and loading flashes (follows ProjectSelector pattern).
 */

import { useState, useEffect, useMemo } from 'react'
import { ChevronDown, Database, Loader2, Plus } from 'lucide-react'
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
import { databasesQueryOptions } from '@/lib/react-query/hooks'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'

const DEFAULT_LIMIT = 15

export interface DatabaseSelectorProps {
  projectId: string
  value: string
  selectedName?: string
  onSelect: (databaseId: string) => void
  onCreateClick: () => void
  placeholder?: string
  limit?: number
  triggerClassName?: string
}

export function DatabaseSelector({
  projectId,
  value,
  selectedName,
  onSelect,
  onCreateClick,
  placeholder = 'Select database',
  limit = DEFAULT_LIMIT,
  triggerClassName,
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

  const displayValue = selectedName || (value ? databases.find((d) => d.$id === value)?.name : null) || placeholder

  return (
    <div className="flex min-w-0 flex-1 items-center gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            className={cn(
              'h-8 min-w-0 flex-1 justify-between gap-1.5 text-[13px] font-normal',
              !value && 'text-muted-foreground',
              triggerClassName,
            )}
          >
            <span className="flex min-w-0 items-center gap-1.5">
              <Database className="h-4 w-4 shrink-0 text-muted-foreground" />
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
                    <Database className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{db.name}</span>
                  </button>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      <Button
        variant="outline"
        size="icon"
        className="h-8 w-8 shrink-0"
        onClick={onCreateClick}
        title="Create database"
      >
        <Plus className="h-4 w-4" />
      </Button>
    </div>
  )
}
