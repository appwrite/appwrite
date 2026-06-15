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
import {
  getSpecOptionById,
  mapDedicatedDatabaseSpecifications,
  type SpecOption,
} from '@/lib/database-specs'
import {
  dedicatedDatabasesQueryOptions,
  useDatabaseSpecifications,
} from '@/lib/react-query/hooks'
import { isPostgresEngine } from '@/lib/react-query/hooks/postgres-databases'
import { cn } from '@/lib/utils'

type PostgresDatabaseSelectorProps = {
  projectId: string
  value: string
  selectedName?: string
  selectedSpecification?: string | null
  onSelect: (databaseId: string) => void
  placeholder?: string
  triggerClassName?: string
}

function resolveDatabaseSpecSummary(
  specs: SpecOption[],
  specSlug: string | null | undefined,
): string | null {
  const slug = specSlug?.trim()
  if (!slug) return null

  const spec =
    specs.find((item) => item.id === slug) ?? getSpecOptionById(slug)
  if (!spec) return slug

  const { cpu, memory } = spec
  if (cpu === 'Shared' && memory === 'Shared') return 'Shared'
  if (cpu === '—' && memory === '—') return null
  if (cpu === '—') return memory !== '—' ? memory : null
  if (memory === '—') return cpu

  return `${cpu} · ${memory}`
}

function DatabaseSelectorNameWithSpec({
  name,
  specSummary,
  nameClassName,
}: {
  name: string
  specSummary?: string | null
  nameClassName?: string
}) {
  return (
    <span className="flex min-w-0 items-baseline gap-1">
      <span className={cn('truncate', nameClassName)}>{name}</span>
      {specSummary ? (
        <>
          <span className="shrink-0 text-muted-foreground/60">·</span>
          <span className="truncate text-[12px] text-muted-foreground">
            {specSummary}
          </span>
        </>
      ) : null}
    </span>
  )
}

export function PostgresDatabaseSelector({
  projectId,
  value,
  selectedName,
  selectedSpecification,
  onSelect,
  placeholder = 'Select database',
  triggerClassName,
}: PostgresDatabaseSelectorProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: specificationsData } = useDatabaseSpecifications(projectId)

  const specs = useMemo(
    () =>
      mapDedicatedDatabaseSpecifications(specificationsData?.specifications),
    [specificationsData?.specifications],
  )

  const { data, isFetching } = useQuery({
    ...dedicatedDatabasesQueryOptions(projectId),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const postgresDatabases = useMemo(
    () => (data?.databases ?? []).filter((db) => isPostgresEngine(db.engine)),
    [data?.databases],
  )

  const getSpecSummary = useMemo(
    () => (specSlug: string | null | undefined) =>
      resolveDatabaseSpecSummary(specs, specSlug),
    [specs],
  )

  const filteredDatabases = useMemo(() => {
    const query = search.trim().toLowerCase()
    if (!query) return postgresDatabases
    return postgresDatabases.filter((db) => {
      const specSummary = getSpecSummary(db.specification)?.toLowerCase() ?? ''
      const spec =
        specs.find((item) => item.id === db.specification) ??
        getSpecOptionById(db.specification ?? '')
      const specSearch = [
        specSummary,
        spec?.cpu.toLowerCase(),
        spec?.memory.toLowerCase(),
        spec?.label.toLowerCase(),
      ]
        .filter(Boolean)
        .join(' ')

      return (
        db.name.toLowerCase().includes(query) ||
        db.$id.toLowerCase().includes(query) ||
        specSearch.includes(query)
      )
    })
  }, [getSpecSummary, postgresDatabases, search, specs])

  const selectedDatabase = value
    ? postgresDatabases.find((db) => db.$id === value)
    : undefined

  const displayName =
    selectedName || selectedDatabase?.name || placeholder

  const selectedSpecSummary = getSpecSummary(
    selectedDatabase?.specification ?? selectedSpecification,
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          className={cn(
            'h-9 min-w-0 w-full justify-between gap-1.5 text-[13px] font-normal',
            !value && 'text-muted-foreground',
            triggerClassName,
          )}
        >
          <span className="flex min-w-0 flex-1 items-center gap-1.5">
            <DatabaseTypeIcon engine={selectedDatabase?.engine ?? 'postgres'} />
            <DatabaseSelectorNameWithSpec
              name={displayName}
              specSummary={selectedSpecSummary}
              nameClassName={value ? 'font-medium text-foreground' : undefined}
            />
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
              {filteredDatabases.map((db) => {
                const specSummary = getSpecSummary(db.specification)

                return (
                  <button
                    key={db.$id}
                    type="button"
                    onClick={() => {
                      onSelect(db.$id)
                      setOpen(false)
                    }}
                    className={cn(
                      'flex w-full cursor-pointer items-center gap-1.5 rounded-sm px-2 py-1.5 text-left outline-none transition-colors hover:bg-accent hover:text-accent-foreground',
                      db.$id === value && 'bg-accent/50',
                    )}
                  >
                    <DatabaseTypeIcon engine={db.engine} />
                    <DatabaseSelectorNameWithSpec
                      name={db.name}
                      specSummary={specSummary}
                      nameClassName="text-[13px] font-medium text-foreground"
                    />
                  </button>
                )
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
