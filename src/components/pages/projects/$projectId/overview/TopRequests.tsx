import { cn, truncateMiddle } from '@/lib/utils'
import type { ReactNode } from 'react'
import { compactUsagePathIds } from '@/lib/usage/format-usage-path'
import {
  getComputeBreakdownResourceRoute,
  getComputeBreakdownResourceTypeLabel,
  resolveComputeBreakdownResource,
  type ComputeBreakdownResourceMap,
} from '@/lib/usage/resolve-compute-breakdown-resources'
import {
  getStorageBreakdownResourceRoute,
  resolveStorageBreakdownResource,
  type StorageBreakdownResourceMap,
} from '@/lib/usage/resolve-storage-breakdown-resources'
import { Link } from '@tanstack/react-router'
import { ChevronRight } from 'lucide-react'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
  overviewChartPanelBodyClass,
  overviewChartPanelHeaderClass,
  overviewChartPanelHeaderActionsClass,
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from './chart-panel'
import { OverviewChartPanelError } from './OverviewChartPanelError'
import { OverviewChartPanelSkeleton } from './OverviewChartPanelSkeleton'
import { GbHoursUnitInfo } from './GbHoursUnitInfo'
import { MetricValueWithUnit } from './MetricValueWithUnit'
import {
  TooltipProvider,
} from '@/components/ui/tooltip'
import type { OverviewStorageBreakdownType } from '@/lib/usage/storage-usage'

/** Character cap for middle truncation after ID compaction. */
const PATH_DISPLAY_MAX = 42

function formatBreakdownPath(path: string): string {
  return truncateMiddle(compactUsagePathIds(path), PATH_DISPLAY_MAX)
}

type MetricType =
  | 'bandwidth'
  | 'requests'
  | 'storage'
  | 'executions'
  | 'gbhours'

interface TopRequestsProps {
  className?: string
  title?: string
  metric?: MetricType
  /** Path-only endpoint rows vs resource ID rows. */
  breakdownVariant?: 'endpoint' | 'resource'
  projectId?: string
  resourceLookup?: ComputeBreakdownResourceMap
  storageLookup?: StorageBreakdownResourceMap
  storageBreakdownKind?: OverviewStorageBreakdownType
  headerAddon?: ReactNode
  itemCount?: number
  items?: RequestItem[]
  formatCount?: (value: number) => string
  showUnitInfo?: boolean
  isLoading?: boolean
  isError?: boolean
  onRetry?: () => void
  errorTitle?: string
  errorMessage?: string
}

interface RequestItem {
  id: string
  method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH' | string
  statusCode: number
  path: string
  count: number
}

// Mock data for top requests
const topRequests: RequestItem[] = [
  {
    id: '507f1f77bcf86cd799439400',
    method: 'GET',
    statusCode: 503,
    path: '/rest/v1',
    count: 240,
  },
  {
    id: '507f1f77bcf86cd799439401',
    method: 'GET',
    statusCode: 503,
    path: '/auth/v1/health',
    count: 90,
  },
  {
    id: '507f1f77bcf86cd799439402',
    method: 'GET',
    statusCode: 200,
    path: '/auth/v1/health',
    count: 88,
  },
  {
    id: '507f1f77bcf86cd799439403',
    method: 'GET',
    statusCode: 200,
    path: '/auth/v1/health',
    count: 88,
  },
  {
    id: '507f1f77bcf86cd799439404',
    method: 'GET',
    statusCode: 200,
    path: '/auth/v1/health',
    count: 88,
  },
  {
    id: '507f1f77bcf86cd799439405',
    method: 'GET',
    statusCode: 200,
    path: '/auth/v1/health',
    count: 88,
  },
  {
    id: '507f1f77bcf86cd799439406',
    method: 'GET',
    statusCode: 200,
    path: '/auth/v1/health',
    count: 88,
  },
]

export function TopRequests({
  className,
  title,
  metric,
  breakdownVariant = 'endpoint',
  projectId,
  resourceLookup,
  storageLookup,
  storageBreakdownKind = 'files',
  headerAddon,
  itemCount = OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
  items,
  formatCount,
  showUnitInfo = false,
  isLoading = false,
  isError = false,
  onRetry,
  errorTitle = OVERVIEW_BANDWIDTH_ERROR.title,
  errorMessage = OVERVIEW_BANDWIDTH_ERROR.message,
}: TopRequestsProps) {
  const usesLiveItems = items !== undefined
  const requestItems = usesLiveItems ? items : topRequests
  const maxCount = Math.max(...requestItems.map((r) => r.count), 1)
  const displayTitle = title || 'Top requests'
  const formatValue = formatCount ?? ((value: number) => value.toLocaleString())
  const isResourceBreakdown = breakdownVariant === 'resource'
  const useStorageBucketLookup =
    isResourceBreakdown &&
    metric === 'storage' &&
    storageBreakdownKind === 'files'
  const useComputeResourceLookup =
    isResourceBreakdown &&
    projectId &&
    (metric !== 'storage' || storageBreakdownKind !== 'files')
  const itemSlots = Array.from(
    { length: itemCount },
    (_, index) => requestItems[index] ?? null,
  )
  const showEmptyOverlay = usesLiveItems && !isLoading && !isError && requestItems.length === 0

  return (
    <div className={cn('flex h-full min-w-0 flex-col', className)}>
      <div
        className={cn(
          overviewChartPanelHeaderClass,
          headerAddon && 'mb-3 min-h-0 flex-col items-stretch gap-2',
        )}
      >
        <div className="flex min-w-0 items-center gap-1.5">
          <h3
            className={cn(
              'min-w-0 text-[13px] font-medium text-foreground',
              headerAddon && 'truncate',
            )}
          >
            {displayTitle}
          </h3>
          {showUnitInfo ? (
            <TooltipProvider delayDuration={0}>
              <GbHoursUnitInfo />
            </TooltipProvider>
          ) : null}
        </div>
        {headerAddon ? (
          <div className="w-full min-w-0">{headerAddon}</div>
        ) : (
          <div className={overviewChartPanelHeaderActionsClass}>
            <button className="shrink-0 text-[12px] text-muted-foreground transition-colors hover:text-foreground">
              View all
            </button>
          </div>
        )}
      </div>

      <div className={overviewChartPanelBodyClass}>
        {isError ? (
          <OverviewChartPanelError
            title={errorTitle}
            message={errorMessage}
            onRetry={onRetry}
          />
        ) : isLoading ? (
          <OverviewChartPanelSkeleton variant="list" embedded />
        ) : (
          <div className={overviewTopBreakdownListClass}>
            {showEmptyOverlay && (
              <div className="pointer-events-none absolute inset-0 flex items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
                No data for this date range
              </div>
            )}
            {itemSlots.map((request, index) =>
              request ? (
                <div
                  key={request.id}
                  className={cn(
                    overviewTopBreakdownRowClass,
                    'group relative overflow-hidden transition-colors hover:bg-accent/50',
                  )}
                >
                  <div
                    className="absolute inset-y-0 start-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                    style={{ width: `${(request.count / maxCount) * 100}%` }}
                  />

                  <div className="relative flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
                    {(() => {
                      const resourceKey = request.path || request.id
                      const storageResource =
                        useStorageBucketLookup
                          ? resolveStorageBreakdownResource(
                              resourceKey,
                              storageLookup,
                            )
                          : undefined
                      const computeResource = useComputeResourceLookup
                        ? resolveComputeBreakdownResource(
                            resourceKey,
                            resourceLookup,
                          )
                        : undefined
                      const fallbackLabel = formatBreakdownPath(request.path)

                      if (storageResource && projectId) {
                        const route = getStorageBreakdownResourceRoute(
                          projectId,
                          storageResource,
                        )
                        return (
                          <Link
                            to={route.to}
                            params={route.params}
                            className="min-w-0 flex-1 truncate text-[12px] font-medium text-foreground transition-colors hover:text-primary"
                            title={storageResource.name}
                          >
                            {storageResource.name}
                          </Link>
                        )
                      }

                      if (computeResource && projectId) {
                        const route = getComputeBreakdownResourceRoute(
                          projectId,
                          computeResource,
                        )
                        const typeLabel = getComputeBreakdownResourceTypeLabel(
                          computeResource.type,
                        )
                        return (
                          <div className="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
                            <span className="shrink-0 text-[11px] text-muted-foreground">
                              {typeLabel}
                            </span>
                            <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground/70" />
                            <Link
                              to={route.to}
                              params={route.params}
                              className="min-w-0 truncate text-[12px] font-medium text-foreground transition-colors hover:text-primary"
                              title={`${typeLabel} / ${computeResource.name}`}
                            >
                              {computeResource.name}
                            </Link>
                          </div>
                        )
                      }

                      return (
                        <span
                          className="min-w-0 flex-1 truncate font-mono text-[12px] text-foreground/70"
                          title={request.path}
                        >
                          {fallbackLabel}
                        </span>
                      )
                    })()}
                    <MetricValueWithUnit
                      value={formatValue(request.count)}
                      className="shrink-0 text-[12px] font-medium tabular-nums text-muted-foreground"
                      unitClassName="text-muted-foreground/80"
                    />
                  </div>
                </div>
              ) : (
                <div
                  key={`empty-slot-${index}`}
                  className={overviewTopBreakdownRowClass}
                  aria-hidden
                />
              ),
            )}
          </div>
        )}
      </div>
    </div>
  )
}
