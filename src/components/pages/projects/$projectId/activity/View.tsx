import { useState, useMemo, useCallback, useEffect } from 'react'
import { getRouteApi, Link } from '@tanstack/react-router'
import { useIsFetching, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { cn, truncateMiddle } from '@/lib/utils'
import { formatIpForDisplay } from '@/lib/format-ip'
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
  Globe,
  ListChecks,
  Clock,
  AlertCircle,
} from '@/lib/icons'
import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { ServiceHeader } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
  activityEventQueryOptions,
  useCountryLookups,
  useProjectActivities,
  useProjectActivity,
  useProject,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import {
  inferActivityUiResourceTypeFromPath,
  parseActivityResourcePath,
  type ActivityUiResourceType,
} from '@/lib/activity-resource-path'
import {
  getActivitiesFilterColumns,
  buildFilterQueryString,
  mapToQueryParam,
  queryParamToMap,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { translate, useT } from '@/lib/i18n/translate'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  formatActivityLogRetentionLabel,
  getActivityLogRetentionDaysFromPlan,
  getActivityLogRetentionHoursFromPlan,
  getDefaultActivityDateRangeFromRetentionDays,
} from '@/lib/activity/activity-log-retention'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { ActivityLogDrawer } from '@/components/pages/projects/$projectId/activity/ActivityLogDrawer'
import { ActivityLogVolumeChart } from '@/components/pages/projects/$projectId/activity/_components/ActivityLogVolumeChart'
import { ActivityLogRowContextMenu } from '@/components/pages/projects/$projectId/activity/_components/ActivityLogRowContextMenu'
import {
  getActivityCountryCode,
  getActivityCountryDisplayName,
  hasHumanEmail,
  isMcpSdkActivity,
  userTypeBadge,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import type { CountryLookups } from '@/lib/locale/country-lookups'
import { UserTypeAvatar } from '@/components/pages/projects/$projectId/activity/UserTypeAvatar'
import { McpIcon } from '@/components/global/shared/McpIcon'

const activityRouteApi = getRouteApi('/_public/projects/$projectId/activity')

/** Skeleton row count caps page size so default 150 does not render hundreds of placeholders. */
const ACTIVITY_TABLE_SKELETON_ROWS_CAP = 24

/** Max characters for resource id/name in the table before middle ellipsis. */
const ACTIVITY_RESOURCE_DISPLAY_MAX = 56

function ActivityTableCountryCell({
  countryName,
}: {
  countryName: string | null
}) {
  const name = countryName?.trim() ?? ''

  return (
    <p
      className="truncate text-[13px] text-muted-foreground"
      title={name || undefined}
    >
      {name || '-'}
    </p>
  )
}

/** Column widths for activity log table - shared by `colgroup` and kept in sync with header labels. */
function ActivityLogsTableColGroup() {
  return (
    <colgroup>
      <col className="w-[14%]" />
      <col className="w-[12%]" />
      <col className="w-[10%]" />
      <col className="" />
      <col className="w-[12%]" />
      <col className="w-[10%]" />
      <col className="w-[10%]" />
    </colgroup>
  )
}

function ActivityLogsTableHead() {
  const t = useT()
  return (
    <TableHeader>
      <TableRow className="hover:bg-transparent border-b border-border">
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider ps-6 sm:ps-8 shadow-[inset_0_-1px_0_var(--border)]">
          {t('Event')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider shadow-[inset_0_-1px_0_var(--border)]">
          {t('Actor')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider shadow-[inset_0_-1px_0_var(--border)]">
          {t('Type')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider shadow-[inset_0_-1px_0_var(--border)]">
          {t('Resource')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider shadow-[inset_0_-1px_0_var(--border)]">
          {t('IP address')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider shadow-[inset_0_-1px_0_var(--border)]">
          {t('Country')}
        </TableHead>
        <TableHead className="sticky top-0 z-10 bg-background px-4 py-3 pe-6 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider sm:pe-8 shadow-[inset_0_-1px_0_var(--border)]">
          {t('Time')}
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
          <TableCell className="min-w-0 px-4 py-3 ps-6 sm:ps-8">
            <div className="flex min-w-0 items-center gap-2">
              <Skeleton className="h-7 w-7 shrink-0 rounded-md" />
              <Skeleton className="h-3.5 min-w-0 flex-1 max-w-full" />
            </div>
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <div className="flex min-w-0 items-center gap-2.5">
              <Skeleton className="h-8 w-8 shrink-0 rounded-full" />
              <div className="min-w-0 flex flex-1 flex-col gap-1">
                <Skeleton className="h-4 w-[7.5rem] max-w-full" />
                <Skeleton className="h-3 w-24 max-w-full" />
              </div>
            </div>
          </TableCell>
          <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
            <Skeleton className="h-5 w-20 rounded px-1.5" />
          </TableCell>
          <TableCell className="min-w-0 whitespace-normal px-4 py-3 align-top">
            <div className="flex min-w-0 items-center gap-2.5">
              <Skeleton className="h-7 w-7 shrink-0 rounded-md" />
              <div className="min-w-0 flex flex-1 flex-col gap-1">
                <Skeleton className="h-4 w-[11rem] max-w-full" />
                <Skeleton className="h-3 w-14 max-w-full" />
              </div>
            </div>
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-3.5 w-28 max-w-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3">
            <Skeleton className="h-3.5 w-20 max-w-full" />
          </TableCell>
          <TableCell className="min-w-0 px-4 py-3 pe-6 sm:pe-8">
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

/** Table skeleton for the initial activity list load only (not refresh). */
function ActivityLogsLoadingTable({ rowCount }: { rowCount: number }) {
  const t = useT()
  const rows = Math.min(rowCount, ACTIVITY_TABLE_SKELETON_ROWS_CAP)
  return (
    <>
      <div
        className="relative min-h-0 flex-1 overflow-auto"
        role="status"
        aria-live="polite"
        aria-busy="true"
        aria-label={t('Loading activities')}
      >
        <Table
          withScrollContainer={false}
          className="table-fixed w-full"
        >
          <ActivityLogsTableColGroup />
          <ActivityLogsTableHead />
          <TableBody>
            <ActivityLogsSkeletonRows rowCount={rows} />
          </TableBody>
        </Table>
      </div>
      <ActivityLogsPaginationSkeleton />
    </>
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

// Resource types and their icons (see {@link ActivityUiResourceType})
type ResourceType = ActivityUiResourceType

const resourceIcons: Record<ResourceType, React.ReactNode> = {
  document: <FileText className="h-4 w-4" />,
  collection: <Folder className="h-4 w-4" />,
  database: <Database className="h-4 w-4" />,
  file: <FileText className="h-4 w-4" />,
  bucket: <Folder className="h-4 w-4" />,
  function: <Zap className="h-4 w-4" />,
  user: <Users className="h-4 w-4" />,
  team: <Users className="h-4 w-4" />,
  site: <Globe className="h-4 w-4" />,
  rule: <ListChecks className="h-4 w-4" />,
  project: <Server className="h-4 w-4" />,
}

/**
 * Display-shaped activity row derived from `Models.ActivityEvent`.
 * The API returns raw audit events (e.g. `users.[ID].sessions.create`); we
 * normalize them into the action/resource-type buckets the UI is built around.
 */
interface DisplayActivity {
  $id: string
  actorId: string
  actorType: string
  actorName: string
  actorEmail: string
  action: ActionType
  resourceType: ResourceType
  resourceId: string
  resourceName: string
  description: string | null
  ipAddress: string | null
  countryCode: string | null
  countryName: string | null
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
  if (parts.includes('upsert')) return 'update'
  if (parts.includes('delete')) return 'delete'
  return 'view'
}

/**
 * When `resourceType` is missing or generic, infer the table bucket from the
 * audit `resource` path via {@link parseActivityResourcePath}.
 */
function resourcePathToResourceType(
  resource: string | null | undefined,
): ResourceType | null {
  const parsed = parseActivityResourcePath(resource)
  if (!parsed?.isRecognizedPattern) return null
  return inferActivityUiResourceTypeFromPath(parsed)
}

/** Maps the API `resourceType`/`event` to a UI resource-type bucket. */
function eventToResourceType(
  resourceType: string,
  event: string,
  resource?: string | null,
): ResourceType {
  const normalized = (resourceType ?? '').toLowerCase()
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
  if (normalized.startsWith('site') || eventParts.includes('sites'))
    return 'site'
  if (normalized === 'rule' || normalized === 'rules') return 'rule'
  if (event.startsWith('rule.')) return 'rule'
  if (normalized.startsWith('team') || eventParts.includes('teams')) return 'team'
  if (normalized.startsWith('user') || eventParts.includes('users')) return 'user'

  const fromPath = resourcePathToResourceType(resource)
  if (fromPath) return fromPath

  return 'project'
}

/**
 * Build a human-readable resource label from the raw event string when the
 * API doesn't provide a dedicated name (most audit events do not).
 * Example: `databases.[DB_ID].collections.[COL_ID].documents.[DOC_ID].create`
 *   → resourceType `documents`, label `[DOC_ID]`.
 */
function resourceLabelFromEvent(activity: Models.ActivityEvent): string {
  const pathLeaf = parseActivityResourcePath(activity.resource)?.leafId
  if (pathLeaf) return pathLeaf
  if (activity.resourceId) return activity.resourceId
  const segments = activity.event.split('.')
  // Last id-like segment (anything that isn't a known verb) tends to be the
  // resource id; fall back to the full event string.
  const verbs = new Set([
    'create',
    'update',
    'delete',
    'upsert',
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

function toDisplayActivity(
  event: Models.ActivityEvent,
  countryLookups: CountryLookups | null,
): DisplayActivity {
  return {
    $id: event.$id,
    actorId: event.actorId,
    actorType: event.actorType || '',
    actorName:
      event.actorName || event.actorEmail || translate('Unknown'),
    actorEmail: event.actorEmail || '',
    action: eventToActionType(event.event),
    resourceType: eventToResourceType(
      event.resourceType,
      event.event,
      event.resource,
    ),
    resourceId: event.resourceId || '',
    resourceName: resourceLabelFromEvent(event),
    description: event.event,
    ipAddress: event.ip?.trim() || null,
    countryCode: getActivityCountryCode(event),
    countryName: getActivityCountryDisplayName(event, countryLookups),
    timestamp: event.time,
    rawEvent: event.event,
  }
}

interface ViewProps {
  projectId: string
}

export function View({ projectId }: ViewProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = activityRouteApi.useNavigate()
  const { event: eventIdFromUrl, query: queryFromSearch } =
    activityRouteApi.useSearch()

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { showActivityChart } = useDebugOverrides()
  const { lookups: countryLookups, countries } = useCountryLookups()
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

  const activityLogRetentionDays = useMemo(
    () => getActivityLogRetentionDaysFromPlan(organizationPlan),
    [organizationPlan],
  )
  const activityLogRetentionHours = useMemo(
    () => getActivityLogRetentionHoursFromPlan(organizationPlan),
    [organizationPlan],
  )
  const activityLogRetentionLabel = useMemo(
    () => formatActivityLogRetentionLabel(activityLogRetentionDays),
    [activityLogRetentionDays],
  )
  const planCanonical = useMemo(
    () => getPlanNameFromTier(organizationPlan?.$id),
    [organizationPlan?.$id],
  )
  const isFreePlan = planCanonical === 'free'

  /** Default window when no URL `time` filter: plan retention, aligned to picker presets. */
  const defaultActivityDateRange = useMemo(
    () => getDefaultActivityDateRangeFromRetentionDays(activityLogRetentionDays),
    [activityLogRetentionDays],
  )

  const filterMap = useMemo(
    () => queryParamToMap(queryFromSearch ?? null),
    [queryFromSearch],
  )

  const activityFilterColumns = useMemo(() => {
    const countryElements = countries
      .map((country) => ({
        value: country.code.toLowerCase(),
        label: country.name,
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
    return getActivitiesFilterColumns(countryElements)
  }, [countries])

  const { events, hasMore, isLoading, refetch } = useProjectActivities({
    projectId,
    limit: pageSize,
    cursorAfter: listCursor.cursorAfter,
    cursorBefore: listCursor.cursorBefore,
    planRetentionHours: activityLogRetentionHours,
    filterQueryKey: queryFromSearch ?? null,
  })

  const activityListFetchingCount = useIsFetching({
    queryKey: ['activities', 'project', projectId],
  })
  /** ServiceHeader refresh control only; keep the list visible while refetching. */
  const activityListRefreshing = activityListFetchingCount > 0

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
      const normalizedKey =
        compactKey.c === 'country' &&
        compactKey.v != null &&
        compactKey.v !== ''
          ? {
              ...compactKey,
              v: Array.isArray(compactKey.v)
                ? compactKey.v.map((item) => String(item).toLowerCase())
                : String(compactKey.v).toLowerCase(),
            }
          : compactKey
      const normalizedQueryStr =
        normalizedKey.c === 'country'
          ? buildFilterQueryString(
              normalizedKey.o,
              normalizedKey.c,
              normalizedKey.v,
            )
          : queryStr
      const next = new Map(filterMap)
      if (replaceKey) next.delete(replaceKey)
      next.set(normalizedKey, normalizedQueryStr)
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
    toast.error(t('Activity log not found or unavailable.'))
    navigate({
      search: (prev) => ({ ...prev, event: undefined }),
      replace: true,
    })
  }, [eventIdFromUrl, singleEventError, navigate, t])

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

  const prefetchActivityEventOnHover = useCallback(
    (eventId: string) => {
      if (!projectId || !eventId) return
      void queryClient
        .prefetchQuery(activityEventQueryOptions(projectId, eventId))
        .catch(() => {})
    },
    [projectId, queryClient],
  )

  const openActivityDrawer = useCallback(
    (event: Models.ActivityEvent) => {
      const cached = queryClient.getQueryData<Models.ActivityEvent>([
        'activity',
        'project',
        projectId,
        event.$id,
      ])
      setSelectedEvent(cached ?? event)
      setDrawerOpen(true)
      navigate({
        search: (prev) => ({ ...prev, event: event.$id }),
        replace: true,
      })
    },
    [navigate, projectId, queryClient],
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
          title={t('Activity')}
          showFilters
          filterTrigger={
            <div className="flex shrink-0 items-center gap-2">
              <FiltersPopover
                open={filtersOpen}
                onOpenChange={setFiltersOpen}
                columns={activityFilterColumns}
                filterMap={filterMap}
                onRemoveFilter={removeFilter}
                onClearAll={clearAllFilters}
                onApplyFilter={applyFilter}
                resourceLabel={t('activities')}
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
                popoverContentAlign="start"
              />
            </div>
          }
          beforeRefreshButtons={
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className={cn(
                    'inline-flex max-w-[10.5rem] shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-start sm:max-w-[13rem]',
                    'border border-transparent text-muted-foreground',
                    'hover:border-border hover:bg-muted/50 hover:text-foreground',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                  )}
                >
                  <Clock className="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" />
                  <span className="min-w-0 truncate text-[11px] leading-tight">
                    <span className="text-muted-foreground">
                      {t('Retention')}{' '}
                    </span>
                    <span className="font-medium text-foreground">
                      {t(activityLogRetentionLabel)}
                    </span>
                  </span>
                </button>
              </TooltipTrigger>
              <TooltipContent
                side="bottom"
                align="end"
                className="max-w-sm text-[12px] leading-snug text-balance"
              >
                <p className="font-medium text-background">
                  {t('Activity retention')}
                </p>
                <p className="mt-1.5 text-background/85">
                  {t('Your plan includes')}{' '}
                  <span className="font-medium text-background">
                    {t(activityLogRetentionLabel)}
                  </span>{' '}
                  {t('of activity history.')}
                  {isFreePlan && (
                    <>
                      {' '}
                      {t('Upgrade or contact sales for longer retention.')}
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
          isRefreshing={activityListRefreshing}
          fullWidthBorder
          fullWidth
          showToolbarBottomBorder
        />
        {/* Plan upgrade notice for free tier */}
        {isFreePlan && (
          <div className="border-b border-border bg-amber-500/5">
            <div className="w-full px-4 py-3 sm:px-6">
              <Alert
                variant="default"
                className="border-amber-500/30 bg-transparent"
              >
                <AlertCircle className="h-4 w-4 text-amber-500" />
                <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                      {t('Limited activity history')}
                    </AlertTitle>
                    <AlertDescription className="col-start-2 block min-w-0 truncate whitespace-nowrap text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      {t('Your plan includes')}{' '}
                      <span className="font-medium">
                        {t(activityLogRetentionLabel)}
                      </span>{' '}
                      {t('of activity history. Upgrade for longer retention.')}
                    </AlertDescription>
                  </div>
                  {project?.teamId && (
                    <Button
                      asChild
                      size="sm"
                      className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                    >
                      <Link to="/upgrade" search={{ orgId: project.teamId }}>
                        {t('Upgrade')}
                      </Link>
                    </Button>
                  )}
                </div>
              </Alert>
            </div>
          </div>
        )}
      </div>

      {/* Activity table + pagination: flex column so only the table body scrolls
          (sticky thead needs its nearest scroll ancestor to be the table area,
          not a parent that also wraps the pagination bar). */}
      <div className="flex flex-1 min-h-0 flex-col">
        {showActivityChart && (
          <div className="shrink-0 pb-4">
            <ActivityLogVolumeChart
              rangeFrom={volumeChartRange.from}
              rangeTo={volumeChartRange.to}
              activeResourceTypeFilter={activeResourceTypeFilter}
              onLegendResourceTypeClick={handleLegendResourceTypeClick}
            />
          </div>
        )}

        {isLoading && events.length === 0 ? (
          <ActivityLogsLoadingTable rowCount={pageSize} />
        ) : events.length > 0 ? (
          <>
            <div className="min-h-0 flex-1 overflow-auto">
              <Table
                withScrollContainer={false}
                className="table-fixed w-full"
              >
                <ActivityLogsTableColGroup />
                <ActivityLogsTableHead />
                <TableBody>
                  {events.map((rawEvent) => {
                    const activity = toDisplayActivity(rawEvent, countryLookups)
                    const resourcePrimary =
                      activity.resourceId?.trim() ||
                      activity.resourceName ||
                      '-'
                    const resourceDisplay = truncateMiddle(
                      resourcePrimary,
                      ACTIVITY_RESOURCE_DISPLAY_MAX,
                    )
                    const resourceTitle =
                      resourcePrimary !== '-' &&
                      resourcePrimary.length > ACTIVITY_RESOURCE_DISPLAY_MAX
                        ? resourcePrimary
                        : undefined
                    return (
                      <ActivityLogRowContextMenu
                        key={activity.$id}
                        projectId={projectId}
                        event={rawEvent}
                        onOpenDetails={() => openActivityDrawer(rawEvent)}
                      >
                        <TableRow
                          role="button"
                          tabIndex={0}
                          data-state={
                            drawerOpen && selectedEvent?.$id === activity.$id
                              ? 'selected'
                              : undefined
                          }
                          aria-label={`${t('Open activity details')}: ${activity.rawEvent}`}
                          className={cn(
                            'cursor-pointer',
                            drawerOpen &&
                              selectedEvent?.$id === activity.$id &&
                              'bg-muted/60 hover:bg-muted/60',
                          )}
                          onClick={() => openActivityDrawer(rawEvent)}
                          onMouseEnter={() =>
                            prefetchActivityEventOnHover(rawEvent.$id)
                          }
                          onFocus={() =>
                            prefetchActivityEventOnHover(rawEvent.$id)
                          }
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                              e.preventDefault()
                              openActivityDrawer(rawEvent)
                            }
                          }}
                        >
                      <TableCell className="min-w-0 px-4 py-3 ps-6 sm:ps-8">
                        <div className="flex min-w-0 items-center gap-2">
                          <div
                            className={cn(
                              'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                              actionColors[activity.action],
                            )}
                            title={t(actionLabels[activity.action])}
                          >
                            {actionIcons[activity.action]}
                          </div>
                          <p
                            className="min-w-0 truncate font-mono text-[12px] text-muted-foreground"
                            title={activity.description ?? undefined}
                          >
                            {activity.description || '-'}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          <UserTypeAvatar
                            actorType={activity.actorType}
                            actorName={activity.actorName}
                            className="shadow-none"
                          />
                          <div className="min-w-0">
                            <div className="flex min-w-0 items-center gap-1.5">
                              <p className="truncate text-[13px] font-medium text-foreground">
                                {activity.actorName}
                              </p>
                              {isMcpSdkActivity(rawEvent) ? (
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <span
                                      className="inline-flex shrink-0 text-muted-foreground"
                                      aria-label={t('Via MCP')}
                                      onClick={(e) => e.stopPropagation()}
                                      onKeyDown={(e) => e.stopPropagation()}
                                    >
                                      <McpIcon className="h-3.5 w-3.5" />
                                    </span>
                                  </TooltipTrigger>
                                  <TooltipContent side="top">
                                    {t('Via MCP')}
                                  </TooltipContent>
                                </Tooltip>
                              ) : null}
                            </div>
                            <p
                              className={cn(
                                'truncate text-[11px] text-muted-foreground',
                                hasHumanEmail(activity.actorType)
                                  ? ''
                                  : 'font-mono',
                              )}
                            >
                              {hasHumanEmail(activity.actorType)
                                ? activity.actorEmail ||
                                  activity.actorId ||
                                  '-'
                                : activity.actorId || '-'}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="min-w-0 whitespace-nowrap px-4 py-3">
                        {(() => {
                          const badge = userTypeBadge(activity.actorType)
                          return (
                            <span
                              className={cn(
                                'inline-block rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider',
                                badge.tone,
                              )}
                            >
                              {t(badge.label)}
                            </span>
                          )
                        })()}
                      </TableCell>
                      <TableCell className="min-w-0 whitespace-normal px-4 py-3 align-top">
                        <div className="flex min-w-0 items-center gap-2.5">
                          <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                            {resourceIcons[activity.resourceType]}
                          </div>
                          <div className="min-w-0 flex flex-col gap-1">
                            <p
                              className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-[13px] font-medium leading-snug text-foreground"
                              title={resourceTitle}
                            >
                              {resourceDisplay}
                            </p>
                            <p className="text-[11px] capitalize text-muted-foreground">
                              {t(activity.resourceType)}
                            </p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        {activity.ipAddress ? (
                          <CopyableId
                            id={activity.ipAddress}
                            displayText={
                              formatIpForDisplay(activity.ipAddress, 32) ??
                              activity.ipAddress
                            }
                            size="md"
                            maxWidth={128}
                            className="max-w-full"
                          />
                        ) : (
                          <span className="font-mono text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3">
                        <ActivityTableCountryCell
                          countryName={activity.countryName}
                        />
                      </TableCell>
                      <TableCell className="min-w-0 px-4 py-3 pe-6 sm:pe-8">
                        <DateTooltip
                          date={activity.timestamp}
                          className="text-[12px] text-muted-foreground"
                        />
                      </TableCell>
                      </TableRow>
                    </ActivityLogRowContextMenu>
                  )
                  })}
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
                itemLabel={t('activities')}
                className="h-full min-h-0 border-0 mt-0 py-0"
              />
            </div>
          </>
        ) : (
          <EmptyState
            icon={Activity}
            title={
              filterMap.size > 0 ? undefined : t('No activities yet')
            }
            description={
              filterMap.size > 0
                ? undefined
                : t('Activity will appear here as you use your project')
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
                  selectedEvent.resource,
                ),
                resourceName: resourceLabelFromEvent(selectedEvent),
              }
            : null
        }
      />
    </div>
  )
}
