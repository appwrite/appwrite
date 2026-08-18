/**
 * Shared resource search popover used by detail title switchers and form
 * selectors (users, functions, sites, …).
 */

import {
  useState,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  type ReactElement,
} from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { LucideIcon } from 'lucide-react'
import {
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

const MODAL_PORTAL_HOST_SELECTOR =
  '[data-slot="dialog-content"], [data-slot="sheet-content"], [data-slot="alert-dialog-content"]'

export type ResourceSearchKind =
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

export type ResourceSearchListItem = {
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

function ResourceSearchListItemIcon({
  kind,
  item,
  fallbackIcon: FallbackIcon,
}: {
  kind: ResourceSearchKind
  item: ResourceSearchListItem
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

const SEARCH_PLACEHOLDERS: Record<ResourceSearchKind, string> = {
  function: 'Search functions by name or ID...',
  site: 'Search sites by name or ID...',
  user: 'Search users by name, email, or ID...',
  team: 'Search teams by name or ID...',
  bucket: 'Search buckets by name or ID...',
  database: 'Search databases by name or ID...',
  table: 'Search tables by name or ID...',
  topic: 'Search topics by name or ID...',
  provider: 'Search providers by name or ID...',
  domain: 'Search domains by name or ID...',
}

const RESOURCE_ICONS: Record<ResourceSearchKind, LucideIcon> = {
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

function ResourceSearchListSkeleton({
  rows = 5,
  kind,
}: {
  rows?: number
  kind?: ResourceSearchKind
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

function useResourceSearchList(
  kind: ResourceSearchKind,
  {
    projectId,
    organizationId,
    databaseId,
    open,
    prefetch,
    debouncedSearch,
  }: {
    projectId?: string | null
    organizationId?: string | null
    databaseId?: string | null
    open: boolean
    prefetch?: boolean
    debouncedSearch: string
  },
) {
  const search = debouncedSearch || undefined
  const enabled = open || !!prefetch

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
        return { items: [] as ResourceSearchListItem[], isFetching: false }
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

export interface ResourceSearchPopoverProps {
  kind: ResourceSearchKind
  projectId?: string | null
  organizationId?: string | null
  databaseId?: string | null
  /** Currently selected resource id (highlighted in the list). */
  selectedId?: string
  onSelect: (resourceId: string, item: ResourceSearchListItem) => void
  /** Hide these ids from the list (e.g. existing team members). */
  excludeIds?: ReadonlySet<string> | readonly string[]
  /** Pin these items to the top when missing from the current search page. */
  pinnedItems?: ResourceSearchListItem[]
  disabled?: boolean
  contentClassName?: string
  align?: 'start' | 'center' | 'end'
  /** Trigger element; rendered with PopoverTrigger asChild. */
  trigger: ReactElement
  /** Optional wrapper class. Defaults to `contents` so title triggers stay inline. */
  className?: string
  emptyMessage?: string
  /**
   * Warm the first page of results while the popover is closed (e.g. when
   * mounted inside an open dialog). Avoids a cold fetch when the picker opens.
   */
  prefetch?: boolean
}

function resolveModalPortalHost(from: Element | null | undefined): HTMLElement | null {
  if (!from) return null
  return from.closest(MODAL_PORTAL_HOST_SELECTOR) as HTMLElement | null
}

export function ResourceSearchPopover({
  kind,
  projectId,
  organizationId,
  databaseId,
  selectedId,
  onSelect,
  excludeIds,
  pinnedItems,
  disabled = false,
  contentClassName,
  align = 'start',
  trigger,
  className,
  emptyMessage = 'No results found',
  prefetch = false,
}: ResourceSearchPopoverProps) {
  const t = useT()
  const rootRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(
    null,
  )

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 150)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) {
      setSearch('')
      setDebouncedSearch('')
    }
  }, [open])

  // Keep host in sync if the tree remounts while open.
  useLayoutEffect(() => {
    if (!open) return
    const host =
      resolveModalPortalHost(rootRef.current) ??
      resolveModalPortalHost(
        document.activeElement instanceof Element
          ? document.activeElement
          : null,
      )
    setPortalContainer((prev) => (prev === host ? prev : host))
  }, [open])

  const excluded = useMemo(() => {
    if (!excludeIds) return null
    return excludeIds instanceof Set ? excludeIds : new Set(excludeIds)
  }, [excludeIds])

  const { items: fetchedItems, isFetching } = useResourceSearchList(kind, {
    projectId,
    organizationId,
    databaseId,
    open,
    prefetch,
    debouncedSearch,
  })

  const items = useMemo(() => {
    const list = fetchedItems.filter((item) => !excluded?.has(item.id))
    const extras = (pinnedItems ?? []).filter(
      (item) =>
        !excluded?.has(item.id) && !list.some((entry) => entry.id === item.id),
    )
    return extras.length > 0 ? [...extras, ...list] : list
  }, [fetchedItems, excluded, pinnedItems])

  const Icon = RESOURCE_ICONS[kind]
  const showListSkeleton = isFetching && items.length === 0

  return (
    <div ref={rootRef} className={className ?? 'contents'}>
      <Popover
        open={open}
        modal={!portalContainer}
        onOpenChange={(nextOpen) => {
          if (disabled && nextOpen) return
          if (nextOpen) {
            const host =
              resolveModalPortalHost(rootRef.current) ??
              resolveModalPortalHost(
                document.activeElement instanceof Element
                  ? document.activeElement
                  : null,
              )
            setPortalContainer(host)
          } else {
            setPortalContainer(null)
          }
          setOpen(nextOpen)
        }}
      >
        <PopoverTrigger asChild disabled={disabled}>
          {trigger}
        </PopoverTrigger>
        <PopoverContent
          container={portalContainer}
          className={cn('w-[min(100vw-2rem,320px)] p-0', contentClassName)}
          align={align}
          onWheelCapture={(event) => {
            event.stopPropagation()
          }}
          onCloseAutoFocus={(event) => {
            // Keep focus in the dialog form field instead of jumping to body.
            if (portalContainer) event.preventDefault()
          }}
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
            <CommandList className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain">
              {showListSkeleton ? (
                <ResourceSearchListSkeleton kind={kind} />
              ) : (
                <>
                  <CommandEmpty>{t(emptyMessage)}</CommandEmpty>
                  <CommandGroup>
                    {items.map((item) => (
                      <CommandItem
                        key={item.id}
                        value={`${item.id} ${item.label}`}
                        onSelect={() => {
                          if (item.id !== selectedId) onSelect(item.id, item)
                          setOpen(false)
                        }}
                        className={cn(
                          'gap-2',
                          item.id === selectedId && 'bg-accent/50',
                        )}
                      >
                        <ResourceSearchListItemIcon
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
    </div>
  )
}

/** @deprecated Prefer ResourceSearchKind; kept for existing title-switcher imports. */
export type ResourceTitleKind = ResourceSearchKind
