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

// Mock activity data
interface MockActivity {
  $id: string
  userId: string
  userName: string
  userEmail: string
  action: ActionType
  resourceType: ResourceType
  resourceId: string
  resourceName: string
  description: string | null
  metadata: string | null
  ipAddress: string | null
  timestamp: string
}

// Generate mock activities
const generateMockActivities = (): MockActivity[] => {
  const users = [
    {
      id: '507f1f77bcf86cd7994390a0',
      name: 'Alex Morgan',
      email: 'alex@appwrite.io',
    },
    {
      id: '507f1f77bcf86cd7994390a1',
      name: 'Sarah Chen',
      email: 'sarah@example.com',
    },
    {
      id: '507f1f77bcf86cd7994390a2',
      name: 'Mike Johnson',
      email: 'mike@example.com',
    },
    {
      id: '507f1f77bcf86cd7994390a3',
      name: 'Emily Davis',
      email: 'emily@example.com',
    },
    {
      id: '507f1f77bcf86cd7994390a4',
      name: 'System',
      email: 'system@appwrite.io',
    },
  ]

  const activities: MockActivity[] = []
  const now = Date.now()

  // Generate activities over the past 30 days
  const activityTemplates = [
    {
      action: 'create' as ActionType,
      resourceType: 'document' as ResourceType,
      resourceName: 'Order #28751',
      description: 'New order created',
    },
    {
      action: 'upload' as ActionType,
      resourceType: 'file' as ResourceType,
      resourceName: 'product-hero.jpg',
      description: 'File uploaded to Product Images bucket',
    },
    {
      action: 'execute' as ActionType,
      resourceType: 'function' as ResourceType,
      resourceName: 'send-notification',
      description: 'Function executed successfully',
    },
    {
      action: 'update' as ActionType,
      resourceType: 'document' as ResourceType,
      resourceName: 'Product SKU-1234',
      description: 'Price updated from $29.99 to $24.99',
    },
    {
      action: 'login' as ActionType,
      resourceType: 'user' as ResourceType,
      resourceName: 'mike@example.com',
      description: 'User logged in from Chrome on macOS',
    },
    {
      action: 'delete' as ActionType,
      resourceType: 'file' as ResourceType,
      resourceName: 'old-banner.png',
      description: 'File deleted from Marketing bucket',
    },
    {
      action: 'create' as ActionType,
      resourceType: 'collection' as ResourceType,
      resourceName: 'reviews',
      description: 'New collection created in Production database',
    },
    {
      action: 'execute' as ActionType,
      resourceType: 'function' as ResourceType,
      resourceName: 'process-payment',
      description: 'Payment processed for order #28750',
    },
    {
      action: 'update' as ActionType,
      resourceType: 'user' as ResourceType,
      resourceName: 'john@example.com',
      description: 'User profile updated',
    },
    {
      action: 'create' as ActionType,
      resourceType: 'database' as ResourceType,
      resourceName: 'Analytics',
      description: 'New database created',
    },
    {
      action: 'logout' as ActionType,
      resourceType: 'user' as ResourceType,
      resourceName: 'sarah@example.com',
      description: 'User logged out',
    },
    {
      action: 'view' as ActionType,
      resourceType: 'document' as ResourceType,
      resourceName: 'User #12450',
      description: 'Document viewed',
    },
    {
      action: 'create' as ActionType,
      resourceType: 'bucket' as ResourceType,
      resourceName: 'Backups',
      description: 'New storage bucket created',
    },
    {
      action: 'update' as ActionType,
      resourceType: 'function' as ResourceType,
      resourceName: 'generate-report',
      description: 'Function code updated',
    },
    {
      action: 'delete' as ActionType,
      resourceType: 'document' as ResourceType,
      resourceName: 'Draft #892',
      description: 'Draft document deleted',
    },
  ]

  // Generate 100 activities
  for (let i = 0; i < 100; i++) {
    const template = activityTemplates[i % activityTemplates.length]
    const user = users[Math.floor(Math.random() * users.length)]
    const hoursAgo = Math.floor(Math.random() * 720) // Up to 30 days
    const timestamp = new Date(now - hoursAgo * 60 * 60 * 1000).toISOString()

    activities.push({
      $id: `507f1f77bcf86cd799439${String(200 + i).padStart(3, '0')}`,
      userId: user.id,
      userName: user.name,
      userEmail: user.email,
      action: template.action,
      resourceType: template.resourceType,
      resourceId: `res_${String(Math.floor(Math.random() * 10000)).padStart(5, '0')}`,
      resourceName: template.resourceName,
      description: template.description,
      metadata: null,
      ipAddress: `192.168.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`,
      timestamp,
    })
  }

  // Sort by timestamp descending
  return activities.sort(
    (a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime(),
  )
}

const mockActivities = generateMockActivities()

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
  plan?: PlanType
}

export function View({ plan = 'pro' }: ViewProps) {
  const [searchValue, setSearchValue] = useState('')
  const [actionFilter, setActionFilter] = useState<string>('')
  const [resourceTypeFilter, setResourceTypeFilter] = useState<string>('')
  const [showFilters, setShowFilters] = useState(false)

  // Pagination state
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(ACTIVITY_DEFAULT_PAGE_SIZE)

  // Calculate cutoff date based on plan
  const planLimit = PLAN_TIME_LIMITS[plan]
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const cutoffDate = new Date(Date.now() - planLimit.hours * 60 * 60 * 1000)

  // Filter activities
  const filteredActivities = useMemo(() => {
    return mockActivities.filter((activity) => {
      // Check if within plan time limit
      const activityDate = new Date(activity.timestamp)
      if (activityDate < cutoffDate) return false

      // Action filter
      if (actionFilter && activity.action !== actionFilter) return false

      // Resource type filter
      if (resourceTypeFilter && activity.resourceType !== resourceTypeFilter)
        return false

      // Search filter
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
  }, [actionFilter, resourceTypeFilter, searchValue, cutoffDate])

  // Paginated activities
  const paginatedActivities = useMemo(() => {
    const start = (currentPage - 1) * pageSize
    return filteredActivities.slice(start, start + pageSize)
  }, [filteredActivities, currentPage, pageSize])

  // Reset page when filters change
  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setCurrentPage(1)
  }

  const clearFilters = () => {
    setActionFilter('')
    setResourceTypeFilter('')
    setCurrentPage(1)
  }

  const hasActiveFilters = actionFilter || resourceTypeFilter

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Activity"
        searchPlaceholder="Search activities..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        showFilters
        onFilterClick={() => setShowFilters(!showFilters)}
        showRefresh
        onRefresh={() => {}}
        showExport
        onExport={() => {}}
        fullWidthBorder
        fullWidth
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
                    Your <span className="font-medium capitalize">{plan}</span>{' '}
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
              size="sm"
              className="h-7 shrink-0 text-[12px]"
              style={{ backgroundColor: '#f02e65' }}
            >
              Upgrade
            </Button>
          </div>
        )}

        {paginatedActivities.length > 0 ? (
          <>
            <Table className="border-b border-border">
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
                {paginatedActivities.map((activity, index) => (
                  <TableRow
                    key={activity.$id}
                    className={cn(
                      'cursor-pointer',
                      index === paginatedActivities.length - 1 &&
                        'border-b border-border',
                    )}
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
                        {activity.description || '—'}
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
            <div className="px-4 py-2 sm:px-6">
              <Pagination
                currentPage={currentPage}
                totalItems={filteredActivities.length}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setCurrentPage(1)
                }}
                itemLabel="activities"
                className="border-0 mt-0 py-2"
              />
            </div>
          </>
        ) : (
          <EmptyState
            icon={Activity}
            title="No activities found"
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
    </div>
  )
}
