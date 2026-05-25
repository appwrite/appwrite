import { cn, truncateMiddle } from '@/lib/utils'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
  overviewChartPanelBodyClass,
  overviewChartPanelHeaderClass,
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from './chart-panel'
import { OverviewChartPanelError } from './OverviewChartPanelError'
import { OverviewChartPanelSkeleton } from './OverviewChartPanelSkeleton'

/** Character cap for middle truncation; flex + overflow-hidden handle the rest. */
const PATH_DISPLAY_MAX = 32

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
  items?: RequestItem[]
  formatCount?: (value: number) => string
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

function getStatusColor(statusCode: number): string {
  if (statusCode >= 500) return 'text-red-500 dark:text-red-400'
  if (statusCode >= 400) return 'text-amber-500 dark:text-amber-400'
  if (statusCode >= 300) return 'text-blue-500 dark:text-blue-400'
  if (statusCode >= 200) return 'text-emerald-500 dark:text-emerald-400'
  return 'text-muted-foreground'
}

export function TopRequests({
  className,
  title,
  items,
  formatCount,
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
  const itemSlots = Array.from(
    { length: OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT },
    (_, index) => requestItems[index] ?? null,
  )
  const showEmptyOverlay = usesLiveItems && !isLoading && !isError && requestItems.length === 0

  return (
    <div className={cn('flex h-full min-w-0 flex-col', className)}>
      <div className={overviewChartPanelHeaderClass}>
        <h3 className="text-[13px] font-medium text-foreground">
          {displayTitle}
        </h3>
        <button className="shrink-0 text-[12px] text-muted-foreground transition-colors hover:text-foreground">
          View all
        </button>
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
                    className="absolute inset-y-0 left-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
                    style={{ width: `${(request.count / maxCount) * 100}%` }}
                  />

                  <div className="relative flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
                    <span className="w-9 shrink-0 text-[11px] font-medium text-muted-foreground">
                      {request.method}
                    </span>
                    <span
                      className={cn(
                        'w-9 shrink-0 text-center text-[12px] font-medium tabular-nums',
                        getStatusColor(request.statusCode),
                      )}
                    >
                      {request.statusCode || '—'}
                    </span>
                    <span
                      className="min-w-0 flex-1 overflow-hidden text-[12px] text-foreground/70"
                      title={request.path}
                    >
                      <span className="block overflow-hidden text-ellipsis whitespace-nowrap font-mono">
                        {truncateMiddle(request.path, PATH_DISPLAY_MAX)}
                      </span>
                    </span>
                    <span className="shrink-0 text-right text-[12px] font-medium tabular-nums text-muted-foreground">
                      {formatValue(request.count)}
                    </span>
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
