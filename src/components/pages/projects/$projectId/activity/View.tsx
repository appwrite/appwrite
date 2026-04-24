import { useState, useMemo, useCallback, useEffect } from 'react'
import { getRouteApi } from '@tanstack/react-router'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
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
  X,
  AlertCircle,
} from '@/lib/icons'
import type { Models } from '@appwrite.io/console'
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { ACTIVITY_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  useProjectActivities,
  useProjectActivity,
} from '@/lib/react-query/hooks/activities'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { PlanType } from '@/server/functions/activities'
import { ActivityLogDrawer } from '@/components/pages/projects/$projectId/activity/ActivityLogDrawer'
import {
  hasHumanEmail,
  userTypeBadge,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import { UserTypeAvatar } from '@/components/pages/projects/$projectId/activity/UserTypeAvatar'

const activityRouteApi = getRouteApi('/_public/projects/$projectId/activity')

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
  delete: 'bg-muted text-muted-foreground',
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
  document: <FileText className="h-3.5 w-3.5" />,
  collection: <Folder className="h-3.5 w-3.5" />,
  database: <Database className="h-3.5 w-3.5" />,
  file: <FileText className="h-3.5 w-3.5" />,
  bucket: <Folder className="h-3.5 w-3.5" />,
  function: <Zap className="h-3.5 w-3.5" />,
  user: <Users className="h-3.5 w-3.5" />,
  team: <Users className="h-3.5 w-3.5" />,
  project: <Server className="h-3.5 w-3.5" />,
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
  pro: { label: '7 days', hours: 7 * 24 },
  custom: { label: '30 days', hours: 30 * 24 },
}

// Filter options
const actionOptions = [
  { value: 'create', label: 'Created' },
  { value: 'update', label: 'Updated' },
  { value: 'delete', label: 'Deleted' },
  { value: 'execute', label: 'Executed' },
  { value: 'upload', label: 'Uploaded' },
  { value: 'login', label: 'Logged in' },
  { value: 'logout', label: 'Logged out' },
  { value: 'view', label: 'Viewed' },
]

const resourceTypeOptions = [
  { value: 'document', label: 'Document' },
  { value: 'collection', label: 'Collection' },
  { value: 'database', label: 'Database' },
  { value: 'file', label: 'File' },
  { value: 'bucket', label: 'Bucket' },
  { value: 'function', label: 'Function' },
  { value: 'user', label: 'User' },
  { value: 'team', label: 'Team' },
  { value: 'project', label: 'Project' },
]

interface ViewProps {
  projectId: string
  plan?: PlanType
}

export function View({ projectId, plan = 'pro' }: ViewProps) {
  const navigate = activityRouteApi.useNavigate()
  const { event: eventIdFromUrl } = activityRouteApi.useSearch()

  const [searchValue, setSearchValue] = useState('')
  const [actionFilter, setActionFilter] = useState<string>('')
  const [resourceTypeFilter, setResourceTypeFilter] = useState<string>('')
  const [showFilters, setShowFilters] = useState(false)
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

  // Plan-based retention window. We pass this to the API as a `since`
  // filter so the server only returns events within the plan's window.
  const planLimit = PLAN_TIME_LIMITS[plan]
  const since = useMemo(
    () => new Date(Date.now() - planLimit.hours * 60 * 60 * 1000).toISOString(),
    [planLimit.hours],
  )

  const {
    events,
    hasMore,
    isLoading,
    isFetching,
    refetch,
  } = useProjectActivities({
    projectId,
    limit: pageSize,
    cursorAfter: listCursor.cursorAfter,
    cursorBefore: listCursor.cursorBefore,
    resourceType: resourceTypeFilter || undefined,
    since,
  })

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

  // Convert API events to the display shape the table expects.
  const displayedActivities = useMemo(
    () => events.map(toDisplayActivity),
    [events],
  )

  // Action filter and free-text search are applied client-side to the current
  // page (the API doesn't expose a single "action" filter — `event` strings
  // are highly granular and don't map 1:1 to UI buckets).
  const filteredActivities = useMemo(() => {
    return displayedActivities.filter((activity) => {
      if (actionFilter && activity.action !== actionFilter) return false

      if (searchValue) {
        const search = searchValue.toLowerCase()
        return (
          activity.resourceName.toLowerCase().includes(search) ||
          activity.userName.toLowerCase().includes(search) ||
          activity.userEmail.toLowerCase().includes(search) ||
          (activity.description?.toLowerCase().includes(search) ?? false)
        )
      }

      return true
    })
  }, [displayedActivities, actionFilter, searchValue])

  const filteredIds = useMemo(
    () => new Set(filteredActivities.map((a) => a.$id)),
    [filteredActivities],
  )

  const filteredRawEvents = useMemo(
    () => events.filter((e) => filteredIds.has(e.$id)),
    [events, filteredIds],
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

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
  }

  const clearFilters = () => {
    setActionFilter('')
    setResourceTypeFilter('')
    resetListPosition()
  }

  const hasActiveFilters = Boolean(actionFilter || resourceTypeFilter)

  return (
    <div className="flex flex-col h-full min-h-0">
      <div className="sticky top-0 z-20 bg-background shrink-0">
        <ServiceHeader
          title="Activity"
          searchPlaceholder="Search activities..."
          searchValue={searchValue}
          onSearchChange={handleSearchChange}
          showFilters
          onFilterClick={() => setShowFilters(!showFilters)}
          showRefresh
          onRefresh={() => {
            void refetch()
          }}
          isRefreshing={isFetching}
          showExport
          onExport={() => {}}
          fullWidthBorder
          fullWidth
          showToolbarBottomBorder
          rightContent={
            <div className="flex items-center gap-2">
              {/* Plan indicator */}
              <div className="flex items-center gap-1.5 rounded-md border border-border bg-muted/30 px-2.5 py-1.5">
                <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                <span className="text-[12px] text-muted-foreground">
                  Last {planLimit.label}
                </span>
              </div>
            </div>
          }
          contentAfterBorder={
            <div className="border-b border-border bg-blue-500/5">
              <div className="px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-blue-500/30 bg-transparent"
                >
                  <Clock className="h-4 w-4 text-blue-500 shrink-0" />
                  <div>
                    <AlertTitle className="text-[13px] font-medium text-blue-600 dark:text-blue-400">
                      Activity retention period
                    </AlertTitle>
                    <AlertDescription className="text-[12px] text-blue-600/80 dark:text-blue-400/80 !block mt-1">
                      Your{' '}
                      <span className="font-medium capitalize">{plan}</span>{' '}
                      plan supports{' '}
                      <span className="font-medium">{planLimit.label}</span> of
                      activity retention.
                      {(plan === 'free' || plan === 'pro') && (
                        <>
                          {' '}
                          <a
                            href="#"
                            className="font-medium underline hover:no-underline"
                            onClick={(e) => {
                              e.preventDefault()
                            }}
                          >
                            Upgrade
                          </a>{' '}
                          or{' '}
                          <a
                            href="#"
                            className="font-medium underline hover:no-underline"
                            onClick={(e) => {
                              e.preventDefault()
                            }}
                          >
                            contact sales
                          </a>{' '}
                          for higher retention.
                        </>
                      )}
                    </AlertDescription>
                  </div>
                </Alert>
              </div>
            </div>
          }
        />

        {/* Filters Panel */}
        {showFilters && (
          <div className="border-b border-border">
            <div className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
              <span className="text-[12px] font-medium text-muted-foreground">
                Filter by:
              </span>

              {/* Action Filter */}
              <Select
                value={actionFilter}
                onValueChange={(value) => {
                  setActionFilter(value)
                  resetListPosition()
                }}
              >
                <SelectTrigger className="h-8 w-[140px] text-[12px]">
                  <SelectValue placeholder="Action" />
                </SelectTrigger>
                <SelectContent>
                  {actionOptions.map((option) => (
                    <SelectItem
                      key={option.value}
                      value={option.value}
                      className="text-[12px]"
                    >
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {/* Resource Type Filter */}
              <Select
                value={resourceTypeFilter}
                onValueChange={(value) => {
                  setResourceTypeFilter(value)
                  resetListPosition()
                }}
              >
                <SelectTrigger className="h-8 w-[140px] text-[12px]">
                  <SelectValue placeholder="Resource type" />
                </SelectTrigger>
                <SelectContent>
                  {resourceTypeOptions.map((option) => (
                    <SelectItem
                      key={option.value}
                      value={option.value}
                      className="text-[12px]"
                    >
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {/* Clear Filters */}
              {hasActiveFilters && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={clearFilters}
                  className="h-8 gap-1.5 text-[12px] text-muted-foreground hover:text-foreground"
                >
                  <X className="h-3.5 w-3.5" />
                  Clear filters
                </Button>
              )}
            </div>
          </div>
        )}
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
                for 7 days of history, or Scale/Enterprise for 30 days.
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

        {filteredActivities.length > 0 ? (
          <>
            <div className="min-h-0 flex-1 overflow-auto">
              <Table withScrollContainer={false}>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px] pl-6 sm:pl-8 shadow-[inset_0_-1px_0_var(--border)]">
                      Event
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[min(22rem,32vw)] min-w-[12rem] shadow-[inset_0_-1px_0_var(--border)]">
                      Resource
                    </TableHead>
                    <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px] shadow-[inset_0_-1px_0_var(--border)]">
                      User
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
                <TableBody>
                  {filteredRawEvents.map((rawEvent) => {
                    const activity = toDisplayActivity(rawEvent)
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
                        <div className="flex min-w-0 flex-col gap-1.5">
                          <div className="flex items-center gap-2">
                            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                              {resourceIcons[activity.resourceType]}
                            </div>
                            <p className="text-[11px] capitalize text-muted-foreground">
                              {activity.resourceType}
                            </p>
                          </div>
                          <p className="break-words text-[13px] font-medium leading-snug text-foreground">
                            {activity.resourceName}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          <UserTypeAvatar
                            userType={activity.userType}
                            userName={activity.userName}
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
                pageSizeOptions={[10, 25, 50, 100]}
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
        ) : isLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              Loading activities...
            </p>
          </div>
        ) : (
          <EmptyState
            icon={Activity}
            title={
              hasActiveFilters || searchValue ? undefined : 'No activities yet'
            }
            description={
              hasActiveFilters || searchValue
                ? undefined
                : 'Activity will appear here as you use your project'
            }
            isEmpty={!hasActiveFilters && !searchValue}
            hasFilters={hasActiveFilters || !!searchValue}
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
