import { useState, useMemo, useCallback, useEffect } from 'react'
import { getRouteApi } from '@tanstack/react-router'
import { useIsFetching } from '@tanstack/react-query'
import { toast } from 'sonner'
import { cn, truncateMiddle } from '@/lib/utils'
import {
  Activity,
  Plus,
  Pencil,
  Trash2,
  Zap,
  Upload,
  LogIn,
  LogOut,
  Eye,
  Database,
  Users,
  FileText,
  Folder,
  Server,
  Clock,
  AlertCircle,
} from '@/lib/icons'
import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { startOfDay, endOfDay, subDays, max } from 'date-fns'
import { ServiceHeader } from '../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { ACTIVITY_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  useProjectActivities,
  useProjectActivity,
  useProject,
} from '@/lib/react-query/hooks'
import {
  activitiesFilterColumns,
  buildFilterQueryString,
  mapToQueryParam,
  queryParamToMap,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { DateRangePicker } from '@/components/pages/projects/$projectId/analytics/DateRangePicker'

import type { PlanType } from '@/server/functions/activities'
import { ActivityLogDrawer } from '@/components/pages/projects/$projectId/activity/ActivityLogDrawer'
import { ActivityLogVolumeChart } from '@/components/pages/projects/$projectId/activity/_components/ActivityLogVolumeChart'
import {
  hasHumanEmail,
  userTypeBadge,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import { UserTypeAvatar } from '@/components/pages/projects/$projectId/activity/UserTypeAvatar'

const activityRouteApi = getRouteApi('/_public/projects/$projectId/activity')

/** Skeleton row count caps page size so default 150 does not render hundreds of placeholders. */
const ACTIVITY_TABLE_SKELETON_ROWS_CAP = 14

/** Max characters for resource id/name in the table before middle ellipsis. */
const ACTIVITY_RESOURCE_DISPLAY_MAX = 40

function ActivityLogsTableHead() {
  return (
    <TableHeader>
      <TableRow className="hover:bg-transparent border-b border-border">
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px] pl-6 sm:pl-8 shadow-[inset_0_-1px_0_var(--border)]">
          Event
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[min(22rem,32vw)] min-w-[12rem] shadow-[inset_0_-1px_0_var(--border)]">
          Resource
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px] shadow-[inset_0_-1px_0_var(--border)]">
          User / key
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px] shadow-[inset_0_-1px_0_var(--border)]">
          Type
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[280px] shadow-[inset_0_-1px_0_var(--border)]">
          Description
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 pr-6 sm:pr-8 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px] shadow-[inset_0_-1px_0_var(--border)]">
          Time
        </TableHead>
      </TableRow>
    </TableHeader>
  )
}

function ActivityLogsSkeletonRows({ rowCount }: { rowCount: number }) {
  return (
    <>
      {Array.from({ length: rowCount }, (_, i) => (
        <TableRow
          key={i}
          className="pointer-events-none hover:bg-transparent"
          aria-hidden
        >
          <TableCell className="pl-6 sm:pl-8 py-3">
            <div className="flex items-center gap-2.5">
              <Skeleton className="h-7 w-7 shrink-0 rounded-md" />
              <Skeleton className="h-4 w-[5.5rem]" />
            </div>
          </TableCell>
          <TableCell className="whitespace-normal px-4 py-3 align-top">
            <div className="flex min-w-0 items-center gap-2.5">
              <Skeleton className="h-7 w-7 shrink-0 rounded-md" />
              <div className="min-w-0 flex flex-1 flex-col gap-1">
                <Skeleton className="h-4 w-full max-w-[12rem]" />
                <Skeleton className="h-3 w-14" />
              </div>
            </div>
          </TableCell>
          <TableCell className="px-4 py-3">
            <div className="flex items-center gap-2.5">
              <Skeleton className="h-8 w-8 shrink-0 rounded-full" />
              <div className="min-w-0 flex flex-1 flex-col gap-1">
                <Skeleton className="h-4 w-[7.5rem] max-w-full" />
                <Skeleton className="h-3 w-24 max-w-full" />
              </div>
            </div>
          </TableCell>
          <TableCell className="px-4 py-3">
            <Skeleton className="h-5 w-14 rounded px-1.5" />
          </TableCell>
          <TableCell className="px-4 py-3">
            <Skeleton className="h-3.5 w-full max-w-[16rem]" />
          </TableCell>
          <TableCell className="px-4 py-3 pr-6 sm:pr-8">
            <Skeleton className="h-3.5 w-[6.5rem]" />
          </TableCell>
        </TableRow>
      ))}
    </>
  )
}

function ActivityLogsPaginationSkeleton() {
  return (
    <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
      <div className="@container flex h-full min-h-8 w-full items-center justify-between gap-2 py-3">
        <div className="flex min-w-0 items-center gap-3">
          <Skeleton className="hidden h-4 w-36 @[600px]:block" />
          <div className="hidden items-center gap-2 @[800px]:flex">
            <Skeleton className="h-4 w-8" />
            <Skeleton className="h-8 w-[72px] rounded-md" />
            <Skeleton className="h-4 w-14" />
          </div>
        </div>
        <Skeleton className="h-8 w-[200px] max-w-[45%] shrink-0 rounded-md" />
      </div>
    </div>
  )
}

// Action types and their visual representation
type ActionType =
  | 'create'
  | 'update'
  | 'delete'
  | 'execute'
  | 'upload'
  | 'login'
  | 'logout'
  | 'view'

const actionIcons: Record<ActionType, React.ReactNode> = {
  create: <Plus className="h-3.5 w-3.5" />,
  update: <Pencil className="h-3.5 w-3.5" />,
  delete: <Trash2 className="h-3.5 w-3.5" />,
  execute: <Zap className="h-3.5 w-3.5" />,
  upload: <Upload className="h-3.5 w-3.5" />,
  login: <LogIn className="h-3.5 w-3.5" />,
  logout: <LogOut className="h-3.5 w-3.5" />,
  view: <Eye className="h-3.5 w-3.5" />,
}

const actionColors: Record<ActionType, string> = {
  create: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  update: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  delete: 'bg-red-500/10 text-red-600 dark:text-red-400',
  execute: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  upload: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  login: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
  logout: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  view: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
}

const actionLabels: Record<ActionType, string> = {
  create: 'Created',
  update: 'Updated',
  delete: 'Deleted',
  execute: 'Executed',
  upload: 'Uploaded',
  login: 'Logged in',
  logout: 'Logged out',
  view: 'Viewed',
}

// Resource types and their icons
type ResourceType =
  | 'document'
  | 'collection'
  | 'database'
  | 'file'
  | 'bucket'
  | 'function'
  | 'user'
  | 'team'
  | 'project'

const resourceIcons: Record<ResourceType, React.ReactNode> = {
  document: <FileText className="h-4 w-4" />,
  collection: <Folder className="h-4 w-4" />,
  database: <Database className="h-4 w-4" />,
  file: <FileText className="h-4 w-4" />,
  bucket: <Folder className="h-4 w-4" />,
  function: <Zap className="h-4 w-4" />,
  user: <Users className="h-4 w-4" />,
  team: <Users className="h-4 w-4" />,
  project: <Server className="h-4 w-4" />,
}

/**
 * Display-shaped activity row derived from `Models.ActivityEvent`.
 * The API returns raw audit events (e.g. `users.[ID].sessions.create`); we
 * normalize them into the action/resource-type buckets the UI is built around.
 */
interface DisplayActivity {
  $id: string
  userId: string
  userType: string
  userName: string
  userEmail: string
  action: ActionType
  resourceType: ResourceType
  resourceId: string
  resourceName: string
  description: string | null
  ipAddress: string | null
  timestamp: string
  rawEvent: string
}

/** Maps an event string like `users.[ID].sessions.create` to a UI action bucket. */
function eventToActionType(event: string): ActionType {
  const parts = event.split('.')
  if (parts.includes('sessions')) {
    if (parts.includes('create')) return 'login'
    if (parts.includes('delete')) return 'logout'
  }
  if (parts.includes('executions') && parts.includes('create')) return 'execute'
  if (parts.includes('files') && parts.includes('create')) return 'upload'
  if (parts.includes('create')) return 'create'
  if (parts.includes('update')) return 'update'
  if (parts.includes('delete')) return 'delete'
  return 'view'
}

/** Maps the API `resourceType`/`event` to a UI resource-type bucket. */
function eventToResourceType(
  resourceType: string,
  event: string,
): ResourceType {
  const normalized = resourceType.toLowerCase()
  const eventParts = event.split('.')
  if (normalized.startsWith('document') || eventParts.includes('documents'))
    return 'document'
  if (normalized.startsWith('collection') || eventParts.includes('collections'))
    return 'collection'
  if (normalized.startsWith('database') || eventParts.includes('databases'))
    return 'database'
  if (normalized.startsWith('file') || eventParts.includes('files'))
    return 'file'
  if (normalized.startsWith('bucket') || eventParts.includes('buckets'))
    return 'bucket'
  if (normalized.startsWith('function') || eventParts.includes('functions'))
    return 'function'
  if (normalized.startsWith('team') || eventParts.includes('teams')) return 'team'
  if (normalized.startsWith('user') || eventParts.includes('users')) return 'user'
  return 'project'
}

/**
 * Build a human-readable resource label from the raw event string when the
 * API doesn't provide a dedicated name (most audit events do not).
 * Example: `databases.[DB_ID].collections.[COL_ID].documents.[DOC_ID].create`
 *   → resourceType `documents`, label `[DOC_ID]`.
 */
function resourceLabelFromEvent(activity: Models.ActivityEvent): string {
  if (activity.resourceId) return activity.resourceId
  const segments = activity.event.split('.')
  // Last id-like segment (anything that isn't a known verb) tends to be the
  // resource id; fall back to the full event string.
  const verbs = new Set([
    'create',
    'update',
    'delete',
    'read',
    'list',
    'createSession',
    'deleteSession',
  ])
  for (let i = segments.length - 1; i >= 0; i--) {
    const seg = segments[i]
    if (!verbs.has(seg) && seg && !/^[a-z]+$/.test(seg)) return seg
  }
  return activity.event
}

function toDisplayActivity(event: Models.ActivityEvent): DisplayActivity {
  return {
    $id: event.$id,
    userId: event.userId,
    userType: event.userType || '',
    userName: event.userName || event.userEmail || 'Unknown',
    userEmail: event.userEmail || '',
    action: eventToActionType(event.event),
    resourceType: eventToResourceType(event.resourceType, event.event),
    resourceId: event.resourceId || '',
    resourceName: resourceLabelFromEvent(event),
    description: event.event,
    ipAddress: event.ip || null,
    timestamp: event.time,
    rawEvent: event.event,
  }
}

// Plan time limits
const PLAN_TIME_LIMITS: Record<PlanType, { label: string; hours: number }> = {
  free: { label: '1 hour', hours: 1 },
  pro: { label: '30 days', hours: 30 * 24 },
  custom: { label: '30 days', hours: 30 * 24 },
}

interface ViewProps {
  projectId: string
  plan?: PlanType
}

export function View({ projectId, plan = 'pro' }: ViewProps) {
  const navigate = activityRouteApi.useNavigate()
  const { event: eventIdFromUrl, query: queryFromSearch } =
    activityRouteApi.useSearch()

  const { project } = useProject(projectId)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedEvent, setSelectedEvent] =
    useState<Models.ActivityEvent | null>(null)

  // Pagination: 1-based page for the UI; API uses Appwrite cursor pagination
  // (cursorAfter = last $id of previous page, cursorBefore = first $id of current page).
  const [currentPage, setCurrentPage] = useState(1)
  const [listCursor, setListCursor] = useState<{
    cursorAfter: string | null
    cursorBefore: string | null
  }>({ cursorAfter: null, cursorBefore: null })
  const [pageSize, setPageSize] = useState(ACTIVITY_DEFAULT_PAGE_SIZE)

  const resetListPosition = useCallback(() => {
    setCurrentPage(1)
    setListCursor({ cursorAfter: null, cursorBefore: null })
  }, [])

  const planLimit = PLAN_TIME_LIMITS[plan]
  const planSinceIso = useMemo(
    () => new Date(Date.now() - planLimit.hours * 60 * 60 * 1000).toISOString(),
    [planLimit.hours],
  )

  /** Default window when no URL `time` filter: last 30 days, floored by plan retention. */
  const defaultActivityDateRange = useMemo((): DateRange => {
    const thirtyDaysStart = startOfDay(subDays(new Date(), 29))
    const planFloor = new Date(planSinceIso)
    const from = max([thirtyDaysStart, startOfDay(planFloor)])
    return { from, to: endOfDay(new Date()) }
  }, [planSinceIso])

  const filterMap = useMemo(
    () => queryParamToMap(queryFromSearch ?? null),
    [queryFromSearch],
  )

  const {
    events,
    hasMore,
    isLoading,
    refetch,
  } = useProjectActivities({
    projectId,
    limit: pageSize,
    cursorAfter: listCursor.cursorAfter,
    cursorBefore: listCursor.cursorBefore,
    planSinceIso,
    filterQueryKey: queryFromSearch ?? null,
  })

  const activityListFetchingCount = useIsFetching({
    queryKey: ['activities', 'project', projectId],
  })

  useEffect(() => {
    resetListPosition()
  }, [queryFromSearch, resetListPosition])

  const dateRangeFromFilters = useMemo((): DateRange | undefined => {
    for (const [k] of filterMap) {
      if (k.c === 'time' && k.o === 'between' && k.v != null) {
        const raw = Array.isArray(k.v) ? k.v.join(',') : String(k.v)
        const parts = raw
          .split(',')
          .map((p) => p.trim())
          .filter(Boolean)
        if (parts.length >= 2) {
          const from = new Date(parts[0])
          const to = new Date(parts[1])
          if (!Number.isNaN(from.getTime()) && !Number.isNaN(to.getTime())) {
            return { from, to }
          }
        }
      }
    }
    return undefined
  }, [filterMap])

  const dateRangeForPicker = dateRangeFromFilters ?? defaultActivityDateRange

  const volumeChartRange = useMemo(() => {
    const from = dateRangeFromFilters?.from ?? defaultActivityDateRange.from
    const to = dateRangeFromFilters?.to ?? defaultActivityDateRange.to
    return { from, to }
  }, [dateRangeFromFilters, defaultActivityDateRange])

  const applyFilter = useCallback(
    (
      compactKey: CompactFilterKey,
      queryStr: string,
      replaceKey?: CompactFilterKey,
    ) => {
      const next = new Map(filterMap)
      if (replaceKey) next.delete(replaceKey)
      next.set(compactKey, queryStr)
      navigate({
        search: (prev) => ({
          ...prev,
          query: mapToQueryParam(next) || undefined,
        }),
        replace: true,
      })
    },
    [filterMap, navigate],
  )

  const removeFilter = useCallback(
    (compactKey: CompactFilterKey) => {
      const next = new Map(filterMap)
      next.delete(compactKey)
      navigate({
        search: (prev) => ({
          ...prev,
          query: next.size > 0 ? mapToQueryParam(next) : undefined,
        }),
        replace: true,
      })
    },
    [filterMap, navigate],
  )

  const clearAllFilters = useCallback(() => {
    navigate({
      search: (prev) => {
        const { event: ev } = prev
        return { ...(ev ? { event: ev } : {}) }
      },
      replace: true,
    })
    setFiltersOpen(false)
  }, [navigate])

  const handleDateRangeChange = useCallback(
    (range: DateRange | undefined) => {
      const next = new Map(filterMap)
      for (const key of [...next.keys()]) {
        if (key.c === 'time') next.delete(key)
      }
      if (range?.from && range?.to) {
        const v = `${range.from.toISOString()},${range.to.toISOString()}`
        const compactKey: CompactFilterKey = { c: 'time', o: 'between', v }
        next.set(
          compactKey,
          buildFilterQueryString('between', 'time', v),
        )
      }
      navigate({
        search: (prev) => ({
          ...prev,
          query: next.size > 0 ? mapToQueryParam(next) : undefined,
        }),
        replace: true,
      })
    },
    [filterMap, navigate],
  )

  const activeResourceTypeFilter = useMemo(() => {
    for (const [k] of filterMap) {
      if (
        k.c === 'resourceType' &&
        (k.o === 'equal' || k.o === 'is') &&
        k.v != null
      ) {
        return String(k.v)
      }
    }
    return null
  }, [filterMap])

  const handleLegendResourceTypeClick = useCallback(
    (resourceKey: string) => {
      const next = new Map(filterMap)
      for (const key of [...next.keys()]) {
        if (key.c === 'resourceType') next.delete(key)
      }

      let current: string | null = null
      for (const [k] of filterMap) {
        if (
          k.c === 'resourceType' &&
          (k.o === 'equal' || k.o === 'is') &&
          k.v != null
        ) {
          current = String(k.v)
          break
        }
      }

      if (current === resourceKey) {
        navigate({
          search: (prev) => ({
            ...prev,
            query: next.size > 0 ? mapToQueryParam(next) : undefined,
          }),
          replace: true,
        })
        return
      }

      const compactKey: CompactFilterKey = {
        c: 'resourceType',
        o: 'equal',
        v: resourceKey,
      }
      next.set(
        compactKey,
        buildFilterQueryString('equal', 'resourceType', resourceKey),
      )
      navigate({
        search: (prev) => ({
          ...prev,
          query: mapToQueryParam(next) || undefined,
        }),
        replace: true,
      })
    },
    [filterMap, navigate],
  )

  const eventOnCurrentList = useMemo(
    () =>
      eventIdFromUrl
        ? events.some((e) => e.$id === eventIdFromUrl)
        : false,
    [eventIdFromUrl, events],
  )

  const { event: fetchedEventById, error: singleEventError } =
    useProjectActivity(
      projectId,
      eventIdFromUrl && !eventOnCurrentList ? eventIdFromUrl : undefined,
    )

  // Deep link / share: `?event=<activity $id>` opens the drawer when the event
  // exists on the current page or loads via `getEvent`.
  useEffect(() => {
    if (!eventIdFromUrl) return

    const fromList = events.find((e) => e.$id === eventIdFromUrl)
    if (fromList) {
      setSelectedEvent(fromList)
      setDrawerOpen(true)
      return
    }

    if (fetchedEventById?.$id === eventIdFromUrl) {
      setSelectedEvent(fetchedEventById)
      setDrawerOpen(true)
    }
  }, [eventIdFromUrl, events, fetchedEventById])

  useEffect(() => {
    if (!eventIdFromUrl || !singleEventError) return
    toast.error('Activity log not found or unavailable.')
    navigate({
      search: (prev) => ({ ...prev, event: undefined }),
      replace: true,
    })
  }, [eventIdFromUrl, singleEventError, navigate])

  // Browser back/forward: closing `event` in the URL closes the drawer.
  useEffect(() => {
    if (eventIdFromUrl) return
    if (!drawerOpen) return
    setDrawerOpen(false)
    setSelectedEvent(null)
  }, [eventIdFromUrl, drawerOpen])

  const handlePageChange = useCallback(
    (nextPage: number) => {
      if (nextPage <= 1) {
        resetListPosition()
        return
      }
      if (nextPage === currentPage + 1) {
        const lastId = events.at(-1)?.$id
        if (!lastId) return
        setCurrentPage(nextPage)
        setListCursor({ cursorAfter: lastId, cursorBefore: null })
        return
      }
      if (nextPage === currentPage - 1) {
        const firstId = events[0]?.$id
        if (!firstId) return
        setCurrentPage(nextPage)
        setListCursor({ cursorAfter: null, cursorBefore: firstId })
      }
    },
    [currentPage, events, resetListPosition],
  )

  const openActivityDrawer = useCallback(
    (event: Models.ActivityEvent) => {
      setSelectedEvent(event)
      setDrawerOpen(true)
      navigate({
        search: (prev) => ({ ...prev, event: event.$id }),
        replace: true,
      })
    },
    [navigate],
  )

  const closeActivityDrawer = useCallback(() => {
    setDrawerOpen(false)
    setSelectedEvent(null)
    navigate({
      search: (prev) => ({ ...prev, event: undefined }),
      replace: true,
    })
  }, [navigate])

  return (
    <div className="flex flex-col h-full min-h-0">
      <div className="sticky top-0 z-20 bg-background shrink-0">
        <ServiceHeader
          title="Activity"
          showFilters
          filterTrigger={
            <div className="flex shrink-0 items-center gap-2">
              <FiltersPopover
                open={filtersOpen}
                onOpenChange={setFiltersOpen}
                columns={activitiesFilterColumns}
                filterMap={filterMap}
                onRemoveFilter={removeFilter}
                onClearAll={clearAllFilters}
                onApplyFilter={applyFilter}
                resourceLabel="activities"
                filterScope="activity"
                onApplyQuery={(queryParam) => {
                  navigate({
                    search: (prev) => ({
                      ...prev,
                      query: queryParam ?? undefined,
                    }),
                    replace: true,
                  })
                }}
                teamId={project?.teamId}
                onReset={() => {
                  navigate({
                    search: (prev) => {
                      const { event: ev } = prev
                      return { ...(ev ? { event: ev } : {}) }
                    },
                    replace: true,
                  })
                }}
              />
              <DateRangePicker
                dateRange={dateRangeForPicker}
                onDateRangeChange={handleDateRangeChange}
                className="h-9 min-w-[200px]"
              />
            </div>
          }
          beforeRefreshButtons={
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className={cn(
                    'inline-flex max-w-[10.5rem] shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-left sm:max-w-[13rem]',
                    'border border-transparent text-muted-foreground',
                    'hover:border-border hover:bg-muted/50 hover:text-foreground',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                  )}
                >
                  <Clock className="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" />
                  <span className="min-w-0 truncate text-[11px] leading-tight">
                    <span className="text-muted-foreground">Retention </span>
                    <span className="font-medium text-foreground">
                      {planLimit.label}
                    </span>
                  </span>
                </button>
              </TooltipTrigger>
              <TooltipContent
                side="bottom"
                align="end"
                className="max-w-sm text-[12px] leading-snug text-balance"
              >
                <p className="font-medium text-background">Activity retention</p>
                <p className="mt-1.5 text-background/85">
                  Your{' '}
                  <span className="font-medium capitalize text-background">
                    {plan}
                  </span>{' '}
                  plan supports{' '}
                  <span className="font-medium text-background">
                    {planLimit.label}
                  </span>{' '}
                  of activity history.
                  {plan === 'free' && (
                    <>
                      {' '}
                      Upgrade or contact sales for longer retention.
                    </>
                  )}
                </p>
              </TooltipContent>
            </Tooltip>
          }
          showRefresh
          onRefresh={() => {
            void refetch()
          }}
          isRefreshing={activityListFetchingCount > 0}
          fullWidthBorder
          fullWidth
          showToolbarBottomBorder
        />
      </div>

      {/* Activity table + pagination: flex column so only the table body scrolls
          (sticky thead needs its nearest scroll ancestor to be the table area,
          not a parent that also wraps the pagination bar). */}
      <div className="flex flex-1 min-h-0 flex-col">
        {/* Plan upgrade notice for free tier */}
        {plan === 'free' && (
          <div className="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div className="flex-1">
              <p className="text-[13px] font-medium text-amber-800 dark:text-amber-200">
                Limited activity history
              </p>
              <p className="mt-0.5 text-[12px] text-amber-700 dark:text-amber-300">
                Free plans only show the last hour of activity. Upgrade to Pro
                for 30 days of history, or Scale/Enterprise for longer retention.
              </p>
            </div>
            <Button
              variant="brandCta"
              size="sm"
              className="h-7 shrink-0 text-[12px]"
            >
              Upgrade
            </Button>
          </div>
        )}

        <div className="shrink-0 pb-4">
          <ActivityLogVolumeChart
            rangeFrom={volumeChartRange.from}
            rangeTo={volumeChartRange.to}
            activeResourceTypeFilter={activeResourceTypeFilter}
            onLegendResourceTypeClick={handleLegendResourceTypeClick}
          />
        </div>

        {events.length > 0 ? (
          <>
            <div className="min-h-0 flex-1 overflow-auto">
              <Table withScrollContainer={false}>
                <ActivityLogsTableHead />
                <TableBody>
                  {events.map((rawEvent) => {
                    const activity = toDisplayActivity(rawEvent)
                    const resourcePrimary =
                      activity.resourceId?.trim() ||
                      activity.resourceName ||
                      '—'
                    const resourceDisplay = truncateMiddle(
                      resourcePrimary,
                      ACTIVITY_RESOURCE_DISPLAY_MAX,
                    )
                    const resourceTitle =
                      resourcePrimary !== '—' &&
                      resourcePrimary.length > ACTIVITY_RESOURCE_DISPLAY_MAX
                        ? resourcePrimary
                        : undefined
                    return (
                    <TableRow
                      key={activity.$id}
                      role="button"
                      tabIndex={0}
                      data-state={
                        drawerOpen && selectedEvent?.$id === activity.$id
                          ? 'selected'
                          : undefined
                      }
                      aria-label={`Open activity details: ${activity.rawEvent}`}
                      className={cn(
                        'cursor-pointer',
                        drawerOpen &&
                          selectedEvent?.$id === activity.$id &&
                          'bg-muted/60 hover:bg-muted/60',
                      )}
                      onClick={() => openActivityDrawer(rawEvent)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault()
                          openActivityDrawer(rawEvent)
                        }
                      }}
                    >
                      <TableCell className="pl-6 sm:pl-8 py-3">
                        <div className="flex items-center gap-2.5">
                          <div
                            className={cn(
                              'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                              actionColors[activity.action],
                            )}
                          >
                            {actionIcons[activity.action]}
                          </div>
                          <span className="text-[13px] font-medium text-foreground">
                            {actionLabels[activity.action]}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell className="whitespace-normal px-4 py-3 align-top">
                        <div className="flex min-w-0 items-center gap-2.5">
                          <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                            {resourceIcons[activity.resourceType]}
                          </div>
                          <div className="min-w-0 flex flex-col gap-1">
                            <p
                              className="min-w-0 max-w-full overflow-hidden whitespace-nowrap text-[13px] font-medium leading-snug text-foreground"
                              title={resourceTitle}
                            >
                              {resourceDisplay}
                            </p>
                            <p className="text-[11px] capitalize text-muted-foreground">
                              {activity.resourceType}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          <UserTypeAvatar
                            userType={activity.userType}
                            userName={activity.userName}
                            className="shadow-none"
                          />
                          <div className="min-w-0">
                            <p className="truncate text-[13px] font-medium text-foreground">
                              {activity.userName}
                            </p>
                            <p
                              className={cn(
                                'truncate text-[11px] text-muted-foreground',
                                hasHumanEmail(activity.userType)
                                  ? ''
                                  : 'font-mono',
                              )}
                            >
                              {hasHumanEmail(activity.userType)
                                ? activity.userEmail ||
                                  activity.userId ||
                                  '—'
                                : activity.userId || '—'}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {(() => {
                          const badge = userTypeBadge(activity.userType)
                          return (
                            <span
                              className={cn(
                                'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider',
                                badge.tone,
                              )}
                            >
                              {badge.label}
                            </span>
                          )
                        })()}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <p className="truncate text-[12px] text-muted-foreground">
                          {activity.description || '-'}
                        </p>
                      </TableCell>
                      <TableCell className="px-4 py-3 pr-6 sm:pr-8">
                        <DateTooltip
                          date={activity.timestamp}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                    </TableRow>
                  )})}
                </TableBody>
              </Table>
            </div>
            <div className="h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
              <Pagination
                currentPage={currentPage}
                totalItems={0}
                totalKnown={false}
                hasNextPage={hasMore}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100, 150]}
                onPageChange={handlePageChange}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  resetListPosition()
                }}
                displayItemRange={
                  events.length === 0
                    ? { start: 0, end: 0 }
                    : {
                        start: (currentPage - 1) * pageSize + 1,
                        end: (currentPage - 1) * pageSize + events.length,
                      }
                }
                itemLabel="activities"
                className="h-full min-h-0 border-0 mt-0 py-0"
              />
            </div>
          </>
        ) : isLoading && events.length === 0 ? (
          <>
            <div
              className="min-h-0 flex-1 overflow-auto"
              role="status"
              aria-label="Loading activities"
            >
              <Table withScrollContainer={false}>
                <ActivityLogsTableHead />
                <TableBody>
                  <ActivityLogsSkeletonRows
                    rowCount={Math.min(
                      pageSize,
                      ACTIVITY_TABLE_SKELETON_ROWS_CAP,
                    )}
                  />
                </TableBody>
              </Table>
            </div>
            <ActivityLogsPaginationSkeleton />
          </>
        ) : (
          <EmptyState
            icon={Activity}
            title={
              filterMap.size > 0 ? undefined : 'No activities yet'
            }
            description={
              filterMap.size > 0
                ? undefined
                : 'Activity will appear here as you use your project'
            }
            isEmpty={filterMap.size === 0}
            hasFilters={filterMap.size > 0}
            variant="centered"
          />
        )}
      </div>

      <ActivityLogDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          if (open) {
            setDrawerOpen(true)
            return
          }
          closeActivityDrawer()
        }}
        event={selectedEvent}
        display={
          selectedEvent
            ? {
                action: eventToActionType(selectedEvent.event),
                resourceType: eventToResourceType(
                  selectedEvent.resourceType,
                  selectedEvent.event,
                ),
                resourceName: resourceLabelFromEvent(selectedEvent),
              }
            : null
        }
      />
    </div>
  )
}
