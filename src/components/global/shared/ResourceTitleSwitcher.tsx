import { useState, useEffect, useMemo, useCallback } from 'react'
import { useLocation, useNavigate, Link } from '@tanstack/react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { LucideIcon } from 'lucide-react'
import {
  ArrowLeft,
  ChevronDown,
  Database,
  FolderOpen,
  Globe,
  Loader2,
  Mail,
  MessageSquare,
  Terminal,
  User,
  Users,
} from 'lucide-react'
import { cn } from '@/lib/utils'
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
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { Skeleton } from '@/components/ui/skeleton'
import {
  bucketsQueryOptions,
  consoleDatabasesQueryOptions,
  functionsQueryOptions,
  organizationDomainsQueryOptions,
  providersQueryOptions,
  sitesQueryOptions,
  tablesQueryOptions,
  teamsQueryOptions,
  topicsQueryOptions,
  usersQueryOptions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const PICK_LIMIT = 25

export type ResourceTitleKind =
  | 'function'
  | 'site'
  | 'user'
  | 'team'
  | 'bucket'
  | 'database'
  | 'table'
  | 'topic'
  | 'provider'
  | 'domain'

type ResourceListItem = {
  id: string
  label: string
  runtime?: string
  framework?: string
  initialsName?: string
}

function getSiteFramework(site: {
  buildFramework?: string
  buildFrameworkId?: string
  framework?: string
}): string | undefined {
  return site.buildFramework || site.buildFrameworkId || site.framework
}

function ResourceTitleListItemIcon({
  kind,
  item,
  fallbackIcon: FallbackIcon,
}: {
  kind: ResourceTitleKind
  item: ResourceListItem
  fallbackIcon: LucideIcon
}) {
  if (kind === 'function') {
    return (
      <RuntimeIcon
        runtime={item.runtime ?? ''}
        size="sm"
        className="h-4 w-4 shrink-0 text-muted-foreground"
      />
    )
  }

  if (kind === 'site') {
    return (
      <FrameworkIcon
        framework={item.framework}
        size="sm"
        className="h-4 w-4 shrink-0"
      />
    )
  }

  if (kind === 'user' || kind === 'team') {
    return (
      <InitialsAvatar
        name={item.initialsName || item.label}
        size="xs"
        className="shrink-0"
      />
    )
  }

  return (
    <FallbackIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
  )
}

const SEARCH_PLACEHOLDERS: Record<ResourceTitleKind, string> = {
  function: 'Search functions...',
  site: 'Search sites...',
  user: 'Search users...',
  team: 'Search teams...',
  bucket: 'Search buckets...',
  database: 'Search databases...',
  table: 'Search tables...',
  topic: 'Search topics...',
  provider: 'Search providers...',
  domain: 'Search domains...',
}

const RESOURCE_ICONS: Record<ResourceTitleKind, LucideIcon> = {
  function: Terminal,
  site: Globe,
  user: User,
  team: Users,
  bucket: FolderOpen,
  database: Database,
  table: Database,
  topic: MessageSquare,
  provider: Mail,
  domain: Globe,
}

/** Buckets and tables/collections already have sidebar selectors - no title switcher. */
export const RESOURCE_TITLE_SWITCHER_DISABLED_KINDS = [
  'bucket',
  'table',
] as const satisfies readonly ResourceTitleKind[]

export function isResourceTitleSwitchable(kind: ResourceTitleKind): boolean {
  return !RESOURCE_TITLE_SWITCHER_DISABLED_KINDS.includes(
    kind as (typeof RESOURCE_TITLE_SWITCHER_DISABLED_KINDS)[number],
  )
}

function ResourceTitleListSkeleton({
  rows = 5,
  kind,
}: {
  rows?: number
  kind?: ResourceTitleKind
}) {
  const useInitialsShape = kind === 'user' || kind === 'team'

  return (
    <div className="space-y-0.5 p-1" aria-hidden>
      {Array.from({ length: rows }, (_, index) => (
        <div
          key={index}
          className="flex items-center gap-2 rounded-sm px-2 py-1.5"
        >
          <Skeleton
            className={cn(
              'h-4 w-4 shrink-0',
              useInitialsShape ? 'rounded-full' : 'rounded-sm',
            )}
          />
          <Skeleton
            className="h-4 rounded-sm"
            style={{ width: `${55 + (index % 3) * 12}%` }}
          />
        </div>
      ))}
    </div>
  )
}

export function replaceResourceIdInPath(
  pathname: string,
  currentResourceId: string,
  newResourceId: string,
): string {
  const needle = `/${currentResourceId}`
  const idx = pathname.indexOf(needle)
  if (idx === -1) return pathname
  return (
    pathname.slice(0, idx) +
    `/${newResourceId}` +
    pathname.slice(idx + needle.length)
  )
}

export function useSwitchResourceInPlace() {
  const navigate = useNavigate()
  const location = useLocation()

  return useCallback(
    (currentResourceId: string, newResourceId: string) => {
      if (!currentResourceId || currentResourceId === newResourceId) return
      const newPath = replaceResourceIdInPath(
        location.pathname,
        currentResourceId,
        newResourceId,
      )
      navigate({
        to: newPath,
        search: location.search as Record<string, unknown>,
      })
    },
    [navigate, location.pathname, location.search],
  )
}

function useResourceTitleList(
  kind: ResourceTitleKind,
  {
    projectId,
    organizationId,
    databaseId,
    open,
    debouncedSearch,
  }: {
    projectId?: string | null
    organizationId?: string | null
    databaseId?: string | null
    open: boolean
    debouncedSearch: string
  },
) {
  const search = debouncedSearch || undefined
  const enabled = open

  const functionQuery = useQuery({
    ...functionsQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'function' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const siteQuery = useQuery({
    ...sitesQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'site' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const userQuery = useQuery({
    ...usersQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'user' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const teamQuery = useQuery({
    ...teamsQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'team' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const bucketQuery = useQuery({
    ...bucketsQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'bucket' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const databaseQuery = useQuery({
    ...consoleDatabasesQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'database' && !!projectId,
    placeholderData: keepPreviousData,
  })
  // 'table' is in RESOURCE_TITLE_SWITCHER_DISABLED_KINDS (tables already have a
  // sidebar selector), so this query never actually enables; 'tablesdb' is a
  // placeholder dbKind for the (dead) product API call shape.
  const tableQuery = useQuery({
    ...tablesQueryOptions(
      projectId,
      databaseId,
      'tablesdb',
      0,
      PICK_LIMIT,
      search,
    ),
    enabled: enabled && kind === 'table' && !!projectId && !!databaseId,
    placeholderData: keepPreviousData,
  })
  const topicQuery = useQuery({
    ...topicsQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'topic' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const providerQuery = useQuery({
    ...providersQueryOptions(projectId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'provider' && !!projectId,
    placeholderData: keepPreviousData,
  })
  const domainQuery = useQuery({
    ...organizationDomainsQueryOptions(organizationId, 0, PICK_LIMIT, search),
    enabled: enabled && kind === 'domain' && !!organizationId,
    placeholderData: keepPreviousData,
  })

  return useMemo(() => {
    switch (kind) {
      case 'function':
        return {
          items: (functionQuery.data?.functions ?? []).map((item) => ({
            id: item.$id,
            label: item.name || 'Unnamed function',
            runtime: item.runtime,
          })),
          isFetching: functionQuery.isFetching,
        }
      case 'site':
        return {
          items: (siteQuery.data?.sites ?? []).map((item) => ({
            id: item.$id,
            label: item.name || 'Unnamed site',
            framework: getSiteFramework(
              item as {
                buildFramework?: string
                buildFrameworkId?: string
                framework?: string
              },
            ),
          })),
          isFetching: siteQuery.isFetching,
        }
      case 'user':
        return {
          items: (userQuery.data?.users ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.email || item.phone || item.$id,
            initialsName: item.name || item.email || item.phone || undefined,
          })),
          isFetching: userQuery.isFetching,
        }
      case 'team':
        return {
          items: (teamQuery.data?.teams ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
            initialsName: item.name || undefined,
          })),
          isFetching: teamQuery.isFetching,
        }
      case 'bucket':
        return {
          items: (bucketQuery.data?.buckets ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
          })),
          isFetching: bucketQuery.isFetching,
        }
      case 'database':
        return {
          items: (databaseQuery.data?.databases ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
          })),
          isFetching: databaseQuery.isFetching,
        }
      case 'table':
        return {
          items: (tableQuery.data?.tables ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
          })),
          isFetching: tableQuery.isFetching,
        }
      case 'topic':
        return {
          items: (topicQuery.data?.topics ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
          })),
          isFetching: topicQuery.isFetching,
        }
      case 'provider':
        return {
          items: (providerQuery.data?.providers ?? []).map((item) => ({
            id: item.$id,
            label: item.name || item.$id,
          })),
          isFetching: providerQuery.isFetching,
        }
      case 'domain':
        return {
          items: (domainQuery.data?.domains ?? []).map((item) => ({
            id: item.$id,
            label: item.domain || item.$id,
          })),
          isFetching: domainQuery.isFetching,
        }
      default:
        return { items: [] as ResourceListItem[], isFetching: false }
    }
  }, [
    kind,
    functionQuery.data,
    functionQuery.isFetching,
    siteQuery.data,
    siteQuery.isFetching,
    userQuery.data,
    userQuery.isFetching,
    teamQuery.data,
    teamQuery.isFetching,
    bucketQuery.data,
    bucketQuery.isFetching,
    databaseQuery.data,
    databaseQuery.isFetching,
    tableQuery.data,
    tableQuery.isFetching,
    topicQuery.data,
    topicQuery.isFetching,
    providerQuery.data,
    providerQuery.isFetching,
    domainQuery.data,
    domainQuery.isFetching,
  ])
}

export interface ResourceTitleSwitcherProps {
  kind: ResourceTitleKind
  label: string
  resourceId: string
  projectId?: string | null
  organizationId?: string | null
  databaseId?: string | null
  onSelect: (newResourceId: string) => void
  disabled?: boolean
  className?: string
}

export function ResourceTitleSwitcher(props: ResourceTitleSwitcherProps) {
  if (!isResourceTitleSwitchable(props.kind)) {
    return (
      <span className={cn('truncate', props.className)}>{props.label}</span>
    )
  }
  return <ResourceTitleSwitcherPopover {...props} />
}

function ResourceTitleSwitcherPopover({
  kind,
  label,
  resourceId,
  projectId,
  organizationId,
  databaseId,
  onSelect,
  disabled = false,
  className,
}: ResourceTitleSwitcherProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { items, isFetching } = useResourceTitleList(kind, {
    projectId,
    organizationId,
    databaseId,
    open,
    debouncedSearch,
  })

  const Icon = RESOURCE_ICONS[kind]
  const showListSkeleton = isFetching && items.length === 0

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className={cn(
            'inline-flex min-w-0 max-w-full items-center gap-1 rounded-md px-1 py-0.5 text-start text-[17px] font-semibold text-foreground transition-colors',
            'hover:bg-accent/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            disabled && 'pointer-events-none opacity-50',
            className,
          )}
          aria-label={`Switch ${kind}`}
        >
          <span className="truncate">{label}</span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[min(100vw-2rem,320px)] p-0"
        align="start"
      >
        <Command shouldFilter={false}>
          <div className="relative">
            <CommandInput
              placeholder={t(SEARCH_PLACEHOLDERS[kind])}
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
            {showListSkeleton ? (
              <ResourceTitleListSkeleton kind={kind} />
            ) : (
              <>
                <CommandEmpty>{t('No results found')}</CommandEmpty>
                <CommandGroup>
                  {items.map((item) => (
                    <CommandItem
                      key={item.id}
                      value={`${item.id} ${item.label}`}
                      onSelect={() => {
                        if (item.id !== resourceId) onSelect(item.id)
                        setOpen(false)
                      }}
                      className={cn(
                        'gap-2',
                        item.id === resourceId && 'bg-accent/50',
                      )}
                    >
                      <ResourceTitleListItemIcon
                        kind={kind}
                        item={item}
                        fallbackIcon={Icon}
                      />
                      <span className="truncate">{item.label}</span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}

export interface DetailResourceHeaderTitleProps {
  kind: ResourceTitleKind
  label: string
  resourceId: string
  projectId?: string | null
  organizationId?: string | null
  databaseId?: string | null
  onResourceSelect?: (newResourceId: string) => void
  back?: {
    to?: string
    params?: Record<string, string>
    onClick?: () => void
    'aria-label': string
  }
  showCopyableId?: boolean
}

export function DetailResourceHeaderTitle({
  kind,
  label,
  resourceId,
  projectId,
  organizationId,
  databaseId,
  onResourceSelect,
  back,
  showCopyableId = true,
}: DetailResourceHeaderTitleProps) {
  const switchResource = useSwitchResourceInPlace()
  const handleSelect =
    onResourceSelect ??
    ((newResourceId: string) => switchResource(resourceId, newResourceId))

  return (
    <div className="flex min-w-0 items-center gap-2">
      {back ? (
        back.to ? (
          <Button
            variant="ghost"
            size="sm"
            asChild
            className="h-7 w-7 p-0"
            aria-label={back['aria-label']}
          >
            <Link to={back.to} params={back.params}>
              <ArrowLeft className="h-4 w-4" />
            </Link>
          </Button>
        ) : (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 w-7 p-0"
            onClick={back.onClick}
            aria-label={back['aria-label']}
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
        )
      ) : null}
      <ResourceTitleSwitcher
        kind={kind}
        label={label}
        resourceId={resourceId}
        projectId={projectId}
        organizationId={organizationId}
        databaseId={databaseId}
        onSelect={handleSelect}
      />
      {showCopyableId && resourceId ? (
        <CopyableId id={resourceId} size="xs" className="shrink-0" />
      ) : null}
    </div>
  )
}
