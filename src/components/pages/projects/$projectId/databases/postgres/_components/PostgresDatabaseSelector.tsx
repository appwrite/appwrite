import { useEffect, useMemo, useState } from 'react'
import { ChevronDown, Loader2 } from 'lucide-react'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
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
import { dedicatedDatabasesQueryOptions } from '@/lib/react-query/hooks'
import { isPostgresEngine } from '@/lib/react-query/hooks/postgres-databases'
import { cn } from '@/lib/utils'

type PostgresDatabaseSelectorProps = {
  projectId: string
  value: string
  selectedName?: string
  onSelect: (databaseId: string) => void
  placeholder?: string
  triggerClassName?: string
}

export function PostgresDatabaseSelector({
  projectId,
  value,
  selectedName,
  onSelect,
  placeholder = 'Select database',
  triggerClassName,
}: PostgresDatabaseSelectorProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data, isFetching } = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const postgresDatabases = useMemo(
    () => (data?.databases ?? []).filter((db) => isPostgresEngine(db.engine)),
    [data?.databases],
  )

  const filteredDatabases = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return postgresDatabases
    return postgresDatabases.filter(
      (db) =>
        db.name.toLowerCase().includes(query) ||
        db.$id.toLowerCase().includes(query),
    )
  }, [postgresDatabases, search])

  const selectedDatabase = value
    ? postgresDatabases.find((db) => db.$id === value)
    : undefined

  const displayValue =
    selectedName || selectedDatabase?.name || placeholder

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          className={cn(
            'h-8 min-w-0 w-full justify-between gap-1.5 text-[13px] font-normal',
            !value && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 items-center gap-1.5">
            <DatabaseTypeIcon engine={selectedDatabase?.engine ?? 'postgres'} />
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
            {filteredDatabases.length === 0 && (
              <CommandEmpty>
                {isFetching ? '' : 'No PostgreSQL databases found'}
              </CommandEmpty>
            )}
            <CommandGroup>
              {filteredDatabases.map((db) => (
                <button
                  key={db.$id}
                  type="button"
                  onClick={() => {
                    onSelect(db.$id)
                    setOpen(false)
                  }}
                  className={cn(
                    'flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-left text-[13px] outline-none transition-colors hover:bg-accent hover:text-accent-foreground',
                    db.$id === value && 'bg-accent/50',
                  )}
                >
                  <DatabaseTypeIcon engine={db.engine} />
                  <span className="truncate">{db.name}</span>
                </button>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
