import { useState, useEffect, useLayoutEffect, useRef } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { Zap, Clock } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Pagination } from '@/components/global/shared/Pagination'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  useProjectFunction,
  useFunctionExecutions,
} from '@/lib/react-query/hooks'
import { ExecutionDetailsDrawer } from './ExecutionDetailsDrawer'
import type { Models } from '@appwrite.io/console'
import { Route } from '@/routes/_public/projects.$projectId.functions.$functionId.executions'

const EXECUTIONS_PER_PAGE = 25

function formatDuration(ms: number): string {
  if (ms < 1000) return `${Math.round(ms)}ms`
  const seconds = ms / 1000
  if (seconds < 60) return `${Number(seconds.toFixed(2))}s`
  const minutes = Math.floor(seconds / 60)
  const secs = Number((seconds % 60).toFixed(2))
  return `${minutes}m ${secs}s`
}

export function getExecutionStatusBadge(status: string) {
  const statusMap: Record<
    string,
    {
      label: string
      variant: 'completed' | 'processing' | 'failed' | 'pending' | 'outline'
    }
  > = {
    completed: { label: 'Completed', variant: 'completed' },
    processing: { label: 'Processing', variant: 'processing' },
    failed: { label: 'Failed', variant: 'failed' },
    waiting: { label: 'Waiting', variant: 'pending' },
  }
  return statusMap[status] || { label: status, variant: 'outline' }
}

function getTriggerBadge(trigger: string) {
  const triggerMap: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
  > = {
    http: { label: 'HTTP', variant: 'default' },
    event: { label: 'Event', variant: 'secondary' },
    schedule: { label: 'Schedule', variant: 'outline' },
    manual: { label: 'Manual', variant: 'outline' },
  }
  return (
    triggerMap[trigger?.toLowerCase()] || {
      label: trigger || 'Unknown',
      variant: 'outline',
    }
  )
}

export function getStatusCodeBadge(statusCode: number) {
  if (statusCode >= 200 && statusCode < 300) {
    return { variant: 'success' as const }
  }
  if (statusCode >= 300 && statusCode < 400) {
    return { variant: 'warning' as const }
  }
  if (statusCode >= 400 && statusCode < 500) {
    return { variant: 'error' as const }
  }
  return { variant: 'error' as const }
}

export function FunctionExecutions() {
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = Route.useSearch()
  const urlPage = search.page || 1 // 1-indexed from URL
  const urlExecutionId = search.executionId

  // Initialize displayed page from URL (0-indexed)
  const [displayedPage, setDisplayedPage] = useState(urlPage - 1)
  const [requestedPage, setRequestedPage] = useState(urlPage - 1)
  const [pageSize, setPageSize] = useState(EXECUTIONS_PER_PAGE)
  const [selectedExecutionId, setSelectedExecutionId] = useState<string | null>(
    urlExecutionId || null,
  )
  const scrollContainerRef = useRef<HTMLDivElement>(null)

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  // Sync requested page with URL when it changes externally (e.g., browser back/forward)
  useEffect(() => {
    const newRequestedPage = urlPage - 1
    if (newRequestedPage !== requestedPage) {
      setRequestedPage(newRequestedPage)
    }
  }, [urlPage, requestedPage])

  // Fetch data for the requested page (this will fetch in background)
  const {
    executions: requestedExecutions,
    total,
    isLoading: executionsLoading,
    isFetching: executionsFetching,
  } = useFunctionExecutions(projectId, functionId, requestedPage, pageSize)

  // Fetch data for the displayed page (this is what we show)
  const { executions: displayedExecutions } = useFunctionExecutions(
    projectId,
    functionId,
    displayedPage,
    pageSize,
  )

  // Update displayed page only when requested page data is ready (not fetching)
  // This keeps the current page visible until the next page data is fully loaded
  useEffect(() => {
    if (
      !executionsFetching &&
      requestedPage !== displayedPage &&
      !executionsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [executionsFetching, executionsLoading, requestedPage, displayedPage])

  // Use displayed executions for rendering (stays on current page until new data is ready)
  const executions = displayedExecutions

  // Scroll to top when page changes and data is ready
  useLayoutEffect(() => {
    if (executions.length > 0 && displayedPage !== undefined) {
      // The ref is on the scrollable container itself, so scroll it directly
      const container = scrollContainerRef.current
      if (container) {
        container.scrollTop = 0
      }
    }
  }, [displayedPage, executions.length])
  const selectedExecution =
    executions.find((e) => e.$id === selectedExecutionId) || null

  const handlePageChange = (page: number) => {
    // Update URL with new page (1-indexed)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: page === 1 ? undefined : page, // Remove page param if it's page 1
      }),
      replace: true, // Replace history to avoid cluttering back button
    })
    // requestedPage will be updated via the useEffect that syncs with URL
  }

  const handlePageSizeChange = (size: number) => {
    setPageSize(size)
    // Reset to page 1 when changing page size
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: undefined, // Remove page param to go to page 1
      }),
      replace: true,
    })
    setRequestedPage(0)
    setDisplayedPage(0)
  }

  // Sync selectedExecutionId with URL parameter
  useEffect(() => {
    if (urlExecutionId && urlExecutionId !== selectedExecutionId) {
      setSelectedExecutionId(urlExecutionId)
    } else if (!urlExecutionId && selectedExecutionId) {
      // Only clear if URL doesn't have executionId (user closed drawer)
      // Don't clear if we're just initializing
    }
  }, [urlExecutionId])

  const handleExecutionClick = (executionId: string) => {
    setSelectedExecutionId(executionId)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        executionId,
      }),
      replace: true,
    })
  }

  const handleNavigate = (executionId: string) => {
    setSelectedExecutionId(executionId)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        executionId,
      }),
      replace: true,
    })
  }

  // Only show full loading state on initial load when there's no data
  if ((funcLoading || executionsLoading) && executions.length === 0) {
    return (
      <div className="flex-1">
        <div className="flex h-full items-center justify-center py-16">
          <div className="text-center">
            <p className="text-[13px] text-muted-foreground">
              Loading executions...
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div ref={scrollContainerRef} className="flex-1">
      {executions.length > 0 ? (
        <>
          <Table className="border-b border-border">
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead className="w-[200px] pl-6 sm:pl-8">
                  Execution ID
                </TableHead>
                <TableHead className="w-[200px]">Status</TableHead>
                <TableHead className="w-[150px]">Trigger</TableHead>
                <TableHead className="w-[120px]">Status Code</TableHead>
                <TableHead className="w-[120px]">Method</TableHead>
                <TableHead className="w-[280px]">Path</TableHead>
                <TableHead className="w-[150px]">Duration</TableHead>
                <TableHead className="w-[140px]">Created</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {executions.map((execution, index) => {
                const statusBadge = getExecutionStatusBadge(execution.status)
                const triggerBadge = getTriggerBadge(execution.trigger)
                const statusCodeBadge = execution.responseStatusCode
                  ? getStatusCodeBadge(execution.responseStatusCode)
                  : null

                return (
                  <TableRow
                    key={execution.$id}
                    className={cn(
                      'cursor-pointer',
                      index === executions.length - 1 &&
                        'border-b border-border',
                    )}
                    onClick={() => handleExecutionClick(execution.$id)}
                  >
                    <TableCell className="pl-6 sm:pl-8">
                      <CopyableId id={execution.$id} size="sm" />
                    </TableCell>
                    <TableCell>
                      <Badge variant={statusBadge.variant}>
                        {statusBadge.label}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Badge variant={triggerBadge.variant}>
                        {triggerBadge.label}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {execution.responseStatusCode ? (
                        <Badge variant={statusCodeBadge?.variant || 'outline'}>
                          {execution.responseStatusCode}
                        </Badge>
                      ) : (
                        <span className="text-[13px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <span className="font-mono text-[13px] text-muted-foreground">
                        {execution.requestMethod || 'N/A'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <p className="truncate text-[12px] text-muted-foreground">
                        {execution.requestPath ? (
                          <code className="text-[12px]">
                            {execution.requestPath}
                          </code>
                        ) : (
                          'N/A'
                        )}
                      </p>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Clock className="h-4 w-4 text-muted-foreground" />
                        <span className="text-[13px] text-muted-foreground">
                          {execution.duration
                            ? formatDuration(execution.duration * 1000)
                            : 'N/A'}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <DateTooltip
                        date={execution.$createdAt}
                        className="text-[12px] text-muted-foreground"
                      />
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
          <div className="px-4 py-3 sm:px-6">
            <Pagination
              currentPage={displayedPage + 1}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              itemLabel="executions"
            />
          </div>
        </>
      ) : (
        <div className="flex h-full items-center justify-center py-16">
          <div className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <Zap className="h-5 w-5 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              No executions yet
            </p>
            <p className="text-[13px] text-muted-foreground">
              Executions will appear here when your function runs.
            </p>
          </div>
        </div>
      )}

      <ExecutionDetailsDrawer
        open={selectedExecutionId !== null}
        onOpenChange={(open) => {
          if (!open) {
            setSelectedExecutionId(null)
            navigate({
              to: location.pathname,
              search: (prev) => ({
                ...prev,
                executionId: undefined,
              }),
              replace: true,
            })
          }
        }}
        execution={selectedExecution}
        executions={executions}
        func={func}
        onNavigate={handleNavigate}
      />
    </div>
  )
}
