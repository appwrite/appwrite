import { cn } from '@/lib/utils'

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
}

interface RequestItem {
  id: string
  method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'
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

export function TopRequests({ className, title }: TopRequestsProps) {
  // Find max count for progress bar scaling
  const maxCount = Math.max(...topRequests.map((r) => r.count))

  const displayTitle = title || 'Top requests'

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Header */}
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-[13px] font-medium text-foreground">
          {displayTitle}
        </h3>
        <button className="text-[12px] text-muted-foreground transition-colors hover:text-foreground">
          View all
        </button>
      </div>

      {/* Request list */}
      <div className="space-y-1">
        {topRequests.map((request) => (
          <div
            key={request.id}
            className="group relative flex items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-accent/50"
          >
            {/* Progress bar background */}
            <div
              className="absolute inset-y-0 left-0 rounded-md bg-accent/30 transition-all group-hover:bg-accent/50"
              style={{ width: `${(request.count / maxCount) * 100}%` }}
            />

            {/* Content */}
            <div className="relative flex flex-1 items-center gap-3">
              <span className="w-8 text-[11px] font-medium text-muted-foreground">
                {request.method}
              </span>
              <span
                className={cn(
                  'w-8 text-[12px] font-medium',
                  getStatusColor(request.statusCode),
                )}
              >
                {request.statusCode}
              </span>
              <span className="flex-1 truncate text-[12px] text-foreground/70">
                {request.path}
              </span>
              <span className="text-[12px] font-medium text-muted-foreground">
                {request.count}
              </span>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
