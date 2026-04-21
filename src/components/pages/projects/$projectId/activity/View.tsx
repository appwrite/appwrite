import { useState, useMemo } from 'react'
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
  Info,
} from '@/lib/icons'
import type { Models } from '@appwrite.io/console'
import { ServiceHeader } from '../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
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
import { useProjectActivities } from '@/lib/react-query/hooks/activities'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import type { PlanType } from '@/server/functions/activities'

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
  const [searchValue, setSearchValue] = useState('')
  const [actionFilter, setActionFilter] = useState<string>('')
  const [resourceTypeFilter, setResourceTypeFilter] = useState<string>('')
  const [showFilters, setShowFilters] = useState(false)

  // Pagination state (1-indexed in UI, 0-indexed for the API)
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(ACTIVITY_DEFAULT_PAGE_SIZE)

  // Plan-based retention window. We pass this to the API as a `since`
  // filter so the server only returns events within the plan's window.
  const planLimit = PLAN_TIME_LIMITS[plan]
  const since = useMemo(
    () => new Date(Date.now() - planLimit.hours * 60 * 60 * 1000).toISOString(),
    [planLimit.hours],
  )

  const {
    events,
    total,
    isLoading,
    isFetching,
    refetch,
  } = useProjectActivities({
    projectId,
    page: currentPage - 1,
    limit: pageSize,
    resourceType: resourceTypeFilter || undefined,
    since,
  })

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

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
  }

  const clearFilters = () => {
    setActionFilter('')
    setResourceTypeFilter('')
    setCurrentPage(1)
  }

  const hasActiveFilters = Boolean(actionFilter || resourceTypeFilter)

  return (
    <div className="flex flex-col">
      <div className="sticky top-0 z-20 bg-background">
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
                  setCurrentPage(1)
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
                  setCurrentPage(1)
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

      {/* Activity Table */}
      <div className="flex-1">
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
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px] pl-6 sm:pl-8">
                    Event
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[200px]">
                    Resource
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[180px]">
                    User
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[280px]">
                    Description
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[140px]">
                    Time
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px] pr-6 sm:pr-8"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredActivities.map((activity) => (
                  <TableRow
                    key={activity.$id}
                    className="cursor-pointer"
                    onClick={() => {}}
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
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                          {resourceIcons[activity.resourceType]}
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-medium text-foreground">
                            {activity.resourceName}
                          </p>
                          <p className="text-[11px] capitalize text-muted-foreground">
                            {activity.resourceType}
                          </p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-2.5">
                        <InitialsAvatar name={activity.userName} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-medium text-foreground">
                            {activity.userName}
                          </p>
                          <p className="truncate text-[11px] text-muted-foreground">
                            {activity.userEmail}
                          </p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <p className="truncate text-[12px] text-muted-foreground">
                        {activity.description || '-'}
                      </p>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip
                        date={activity.timestamp}
                        className="text-[12px] text-muted-foreground"
                      />
                    </TableCell>
                    <TableCell className="pr-6 sm:pr-8 py-3">
                      <Popover>
                        <PopoverTrigger asChild>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 w-7 p-0"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <Info className="h-4 w-4 text-muted-foreground" />
                          </Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-72">
                          <div className="space-y-3">
                            <div>
                              <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Activity ID
                              </p>
                              <CopyableId
                                id={activity.$id}
                                size="xs"
                                className="mt-1"
                              />
                            </div>
                            <div>
                              <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                Resource ID
                              </p>
                              <CopyableId
                                id={activity.resourceId}
                                size="xs"
                                className="mt-1"
                              />
                            </div>
                            <div>
                              <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                User ID
                              </p>
                              <CopyableId
                                id={activity.userId}
                                size="xs"
                                className="mt-1"
                              />
                            </div>
                            {activity.ipAddress && (
                              <div>
                                <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                                  IP Address
                                </p>
                                <p className="mt-1 text-[12px] text-foreground">
                                  {activity.ipAddress}
                                </p>
                              </div>
                            )}
                          </div>
                        </PopoverContent>
                      </Popover>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="sticky bottom-0 z-20 h-[54px] shrink-0 border-t border-border bg-background px-4 sm:px-6">
              <Pagination
                currentPage={currentPage}
                totalItems={total}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setCurrentPage(1)
                }}
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
            variant="card"
          />
        )}
      </div>
    </div>
  )
}
