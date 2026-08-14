/**
 * Searchable ID selector for event builder.
 * Lets users choose specific database, table, bucket, file, row, function, site, team, user, topic, or provider instead of *.
 */
import { useState, useEffect, useMemo } from 'react'
import {
  ChevronDown,
  Database,
  Table2,
  FolderOpen,
  File,
  Loader2,
  Terminal,
  Users,
  User,
  MessageSquare,
  Mail,
  Rows3,
  Columns3,
  Hash,
  Globe,
} from 'lucide-react'
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
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  consoleDatabasesQueryOptions,
  tablesQueryOptions,
  tableRowsQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
} from '@/lib/react-query/hooks/databases'
import { databaseRouteKindFromApiType } from '@/lib/database-routes'
import { Query } from '@appwrite.io/console'
import {
  bucketsQueryOptions,
  bucketFilesQueryOptions,
} from '@/lib/react-query/hooks/storage'
import { functionsQueryOptions } from '@/lib/react-query/hooks/functions'
import { sitesQueryOptions } from '@/lib/react-query/hooks/sites'
import {
  teamsQueryOptions,
  usersQueryOptions,
} from '@/lib/react-query/hooks/users'
import {
  topicsQueryOptions,
  providersQueryOptions,
} from '@/lib/react-query/hooks/messaging'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type ResourceIdType =
  | 'database'
  | 'table'
  | 'bucket'
  | 'file'
  | 'row'
  | 'column'
  | 'index'
  | 'function'
  | 'site'
  | 'team'
  | 'user'
  | 'topic'
  | 'provider'

export interface EventResourceIdSelectorProps {
  projectId: string | null | undefined
  type: ResourceIdType
  databaseId?: string | null
  tableId?: string | null
  bucketId?: string | null
  value: string | '*' | undefined
  onSelect: (value: string | '*') => void
  placeholder?: string
  /** When false, omits the wildcard "All (*)" row (e.g. API explorer needs a concrete ID). */
  allowAllOption?: boolean
  triggerClassName?: string
  contentClassName?: string
}

const ICONS = {
  database: Database,
  table: Table2,
  bucket: FolderOpen,
  file: File,
  row: Rows3,
  column: Columns3,
  index: Hash,
  function: Terminal,
  site: Globe,
  team: Users,
  user: User,
  topic: MessageSquare,
  provider: Mail,
}

const SEARCH_PLACEHOLDERS: Record<ResourceIdType, string> = {
  database: 'Search databases by name or ID...',
  table: 'Search tables by name or ID...',
  bucket: 'Search buckets by name or ID...',
  file: 'Search files by name or ID...',
  row: 'Search rows by ID...',
  column: 'Search columns by key or ID...',
  index: 'Search indexes by key or ID...',
  function: 'Search functions by name or ID...',
  site: 'Search sites by name or ID...',
  team: 'Search teams by name or ID...',
  user: 'Search users by name, email, or ID...',
  topic: 'Search topics by name or ID...',
  provider: 'Search providers by name or ID...',
}

export function EventResourceIdSelector({
  projectId,
  type,
  databaseId,
  tableId,
  bucketId,
  value,
  onSelect,
  placeholder = 'All',
  allowAllOption = true,
  triggerClassName,
  contentClassName,
}: EventResourceIdSelectorProps) {
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

  const dbQuery = useQuery({
    ...consoleDatabasesQueryOptions(
      projectId,
      0,
      20,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && open && type === 'database',
    placeholderData: keepPreviousData,
  })

  // Resolve the selected database's product kind so table/row/column/index
  // lookups hit the right product API. Never guess; fetch via the
  // product-agnostic console list filtered by ID.
  const needsDbKind =
    !!databaseId &&
    (type === 'table' || type === 'row' || type === 'column' || type === 'index')
  const dbTypeQuery = useQuery({
    ...consoleDatabasesQueryOptions(projectId, 0, 1, undefined, [
      Query.equal('$id', [databaseId ?? '']),
    ]),
    enabled: !!projectId && !!databaseId && open && needsDbKind,
  })
  const dbKind = databaseRouteKindFromApiType(
    dbTypeQuery.data?.databases?.[0]?.type,
  )

  const tableQuery = useQuery({
    ...tablesQueryOptions(
      projectId,
      databaseId,
      dbKind,
      0,
      20,
      debouncedSearch || undefined,
    ),
    enabled:
      !!projectId &&
      !!databaseId &&
      open &&
      type === 'table' &&
      dbTypeQuery.isFetched,
    placeholderData: keepPreviousData,
  })

  const bucketQuery = useQuery({
    ...bucketsQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'bucket',
    placeholderData: keepPreviousData,
  })

  const fileQuery = useQuery({
    ...bucketFilesQueryOptions(
      projectId,
      bucketId,
      0,
      20,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && !!bucketId && open && type === 'file',
    placeholderData: keepPreviousData,
  })

  const rowQuery = useQuery({
    ...tableRowsQueryOptions(
            projectId,
            databaseId,
            tableId,
            dbKind,
            0,
      20,
      debouncedSearch || undefined,
      undefined,
      undefined,
      undefined,
      undefined,
          ),
    enabled:
      !!projectId &&
      !!databaseId &&
      !!tableId &&
      open &&
      type === 'row' &&
      dbTypeQuery.isFetched,
    placeholderData: keepPreviousData,
  })

  const columnQuery = useQuery({
    ...tableColumnsQueryOptions(projectId, databaseId, dbKind, tableId),
    enabled:
      !!projectId &&
      !!databaseId &&
      !!tableId &&
      open &&
      type === 'column' &&
      dbTypeQuery.isFetched,
    placeholderData: keepPreviousData,
  })

  const indexQuery = useQuery({
    ...tableIndexesQueryOptions(projectId, databaseId, dbKind, tableId),
    enabled:
      !!projectId &&
      !!databaseId &&
      !!tableId &&
      open &&
      type === 'index' &&
      dbTypeQuery.isFetched,
    placeholderData: keepPreviousData,
  })

  const functionQuery = useQuery({
    ...functionsQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'function',
    placeholderData: keepPreviousData,
  })

  const siteQuery = useQuery({
    ...sitesQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'site',
    placeholderData: keepPreviousData,
  })

  const teamQuery = useQuery({
    ...teamsQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'team',
    placeholderData: keepPreviousData,
  })

  const userQuery = useQuery({
    ...usersQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'user',
    placeholderData: keepPreviousData,
  })

  const topicQuery = useQuery({
    ...topicsQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'topic',
    placeholderData: keepPreviousData,
  })

  const providerQuery = useQuery({
    ...providersQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'provider',
    placeholderData: keepPreviousData,
  })

  const items = useMemo(() => {
    const baseItems =
      type === 'database'
        ? (dbQuery.data?.databases ?? [])
        : type === 'table'
          ? (tableQuery.data?.tables ?? [])
          : type === 'bucket'
            ? (bucketQuery.data?.buckets ?? [])
            : type === 'file'
              ? (fileQuery.data?.files ?? [])
              : type === 'row'
                ? (rowQuery.data?.rows ?? [])
                : type === 'column'
                  ? (columnQuery.data?.columns ?? [])
                  : type === 'index'
                    ? (indexQuery.data?.indexes ?? [])
                    : type === 'function'
                      ? (functionQuery.data?.functions ?? [])
                      : type === 'site'
                        ? (siteQuery.data?.sites ?? [])
                        : type === 'team'
                          ? (teamQuery.data?.teams ?? [])
                          : type === 'user'
                            ? (userQuery.data?.users ?? [])
                            : type === 'topic'
                              ? (topicQuery.data?.topics ?? [])
                              : type === 'provider'
                                ? (providerQuery.data?.providers ?? [])
                                : []

    if ((type === 'column' || type === 'index') && debouncedSearch.trim()) {
      const q = debouncedSearch.trim().toLowerCase()
      return baseItems.filter(
        (x: { $id?: string; key?: string; name?: string }) => {
          const id = x.$id ?? x.key ?? ''
          const name = x.name ?? ''
          return (
            String(id).toLowerCase().includes(q) ||
            String(name).toLowerCase().includes(q)
          )
        },
      )
    }
    return baseItems
  }, [
    type,
    debouncedSearch,
    dbQuery.data,
    tableQuery.data,
    bucketQuery.data,
    fileQuery.data,
    rowQuery.data,
    columnQuery.data,
    indexQuery.data,
    functionQuery.data,
    siteQuery.data,
    teamQuery.data,
    userQuery.data,
    topicQuery.data,
    providerQuery.data,
  ])

  const isFetching =
    type === 'database'
      ? dbQuery.isFetching
      : type === 'table'
        ? tableQuery.isFetching
        : type === 'bucket'
          ? bucketQuery.isFetching
          : type === 'file'
            ? fileQuery.isFetching
            : type === 'row'
              ? rowQuery.isFetching
              : type === 'column'
                ? columnQuery.isFetching
                : type === 'index'
                  ? indexQuery.isFetching
                  : type === 'function'
                    ? functionQuery.isFetching
                    : type === 'site'
                      ? siteQuery.isFetching
                      : type === 'team'
                        ? teamQuery.isFetching
                        : type === 'user'
                          ? userQuery.isFetching
                          : type === 'topic'
                            ? topicQuery.isFetching
                            : providerQuery.isFetching

  const getItemId = (x: {
    $id?: string
    key?: string
    name?: string
    email?: string
  }) => x.$id ?? x.key ?? ''
  const displayValue =
    !value || value === '*'
      ? t(placeholder)
      : (() => {
          const item = items.find(
            (x: {
              $id?: string
              key?: string
              name?: string
              email?: string
            }) => getItemId(x) === value,
          )
          return item?.name ?? item?.key ?? item?.email ?? value
        })()

  const Icon = ICONS[type]

  const needsBucket = type === 'file'
  const needsDbAndTable =
    type === 'row' || type === 'column' || type === 'index'
  if (
    !projectId ||
    (needsBucket && !bucketId) ||
    (needsDbAndTable && (!databaseId || !tableId))
  ) {
    return (
      <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[13px] text-muted-foreground">
        *
      </span>
    )
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[12px] font-medium leading-normal transition-colors',
            value && value !== '*'
              ? 'border-primary bg-primary/10 text-primary hover:bg-primary/20'
              : 'border-border bg-background hover:bg-muted',
            triggerClassName,
          )}
        >
          <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span className="min-w-0 truncate leading-normal">{displayValue}</span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent
        className={cn(
          'w-[var(--radix-popover-trigger-width)] min-w-[200px] max-w-[280px] p-0',
          contentClassName,
        )}
        align="start"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder={t(SEARCH_PLACEHOLDERS[type])}
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
          <CommandList className="min-h-[160px] max-h-[200px]">
            <CommandEmpty>
              {isFetching ? t('Loading...') : t('No results found')}
            </CommandEmpty>
            <CommandGroup>
              {allowAllOption ? (
                <CommandItem
                  value="__all__"
                  onSelect={() => {
                    onSelect('*')
                    setOpen(false)
                  }}
                >
                  <span className="text-muted-foreground">
                    {t('All')} (*)
                  </span>
                </CommandItem>
              ) : null}
              {items.map(
                (item: {
                  $id?: string
                  key?: string
                  name?: string
                  email?: string
                }) => {
                  const id = item.$id ?? item.key ?? ''
                  return (
                    <CommandItem
                      key={id}
                      value={`${id} ${item.name ?? ''} ${item.key ?? ''} ${item.email ?? ''}`}
                      onSelect={() => {
                        onSelect(id)
                        setOpen(false)
                      }}
                    >
                      <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="truncate">
                        {item.name ?? item.key ?? item.email ?? id}
                      </span>
                    </CommandItem>
                  )
                },
              )}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
