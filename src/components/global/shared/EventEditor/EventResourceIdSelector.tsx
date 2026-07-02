/**
 * Searchable ID selector for event builder.
 * Lets users choose specific database, table, bucket, file, row, function, team, user, topic, or provider instead of *.
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
  databasesQueryOptions,
  tablesQueryOptions,
  tableRowsQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
} from '@/lib/react-query/hooks/databases'
import {
  bucketsQueryOptions,
  bucketFilesQueryOptions,
} from '@/lib/react-query/hooks/storage'
import { functionsQueryOptions } from '@/lib/react-query/hooks/functions'
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
  team: Users,
  user: User,
  topic: MessageSquare,
  provider: Mail,
}

const SEARCH_PLACEHOLDERS: Record<ResourceIdType, string> = {
  database: 'Search databases...',
    table: 'Search',
  bucket: 'Search buckets...',
  file: 'Search files...',
  row: 'Search rows...',
  column: 'Search columns...',
  index: 'Search indexes...',
  function: 'Search functions...',
  team: 'Search teams...',
  user: 'Search users...',
  topic: 'Search topics...',
  provider: 'Search providers...',
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
    ...databasesQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'database',
    placeholderData: keepPreviousData,
  })

  const tableQuery = useQuery({
    ...tablesQueryOptions(
      projectId,
      databaseId,
      0,
      20,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && !!databaseId && open && type === 'table',
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
      0,
      20,
      debouncedSearch || undefined,
    ),
    enabled: !!projectId && !!databaseId && !!tableId && open && type === 'row',
    placeholderData: keepPreviousData,
  })

  const columnQuery = useQuery({
    ...tableColumnsQueryOptions(projectId, databaseId, tableId),
    enabled:
      !!projectId && !!databaseId && !!tableId && open && type === 'column',
    placeholderData: keepPreviousData,
  })

  const indexQuery = useQuery({
    ...tableIndexesQueryOptions(projectId, databaseId, tableId),
    enabled:
      !!projectId && !!databaseId && !!tableId && open && type === 'index',
    placeholderData: keepPreviousData,
  })

  const functionQuery = useQuery({
    ...functionsQueryOptions(projectId, 0, 20, debouncedSearch || undefined),
    enabled: !!projectId && open && type === 'function',
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
          <CommandList className="max-h-[200px]">
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
