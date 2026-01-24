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
  useProjectSite,
  useSiteLogs,
} from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { Route } from '@/routes/_public/projects.$projectId.sites.$siteId.logs'
import { ExecutionDetailsDrawer } from '../functions/ExecutionDetailsDrawer'

const LOGS_PER_PAGE = 25

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
  return statusMap[status] || { label: status || 'Completed', variant: 'completed' }
}

function getTriggerBadge(trigger: string) {
  const triggerMap: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
  > = {
    http: { label: 'HTTP', variant: 'default' },
    schedule: { label: 'Schedule', variant: 'secondary' },
    event: { label: 'Event', variant: 'outline' },
  }
  return (
    triggerMap[trigger?.toLowerCase()] || {
      label: trigger || 'HTTP',
      variant: 'default',
    }
  )
}

function getStatusCodeBadge(statusCode: number) {
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

export function SiteLogsView() {
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = Route.useSearch()
  const urlPage = search.page || 1 // 1-indexed from URL
  const urlExecutionId = search.executionId

  // Initialize displayed page from URL (0-indexed)
  const [displayedPage, setDisplayedPage] = useState(urlPage - 1)
  const [requestedPage, setRequestedPage] = useState(urlPage - 1)
  const [pageSize, setPageSize] = useState(LOGS_PER_PAGE)
  const [selectedExecutionId, setSelectedExecutionId] = useState<string | null>(
    urlExecutionId || null,
  )
  const scrollContainerRef = useRef<HTMLDivElement>(null)

  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
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
    logs: requestedLogs,
    total,
    isLoading: logsLoading,
    isFetching: logsFetching,
  } = useSiteLogs(projectId, siteId, requestedPage, pageSize)

  // Fetch data for the displayed page (this is what we show)
  const { logs: displayedLogs } = useSiteLogs(
    projectId,
    siteId,
    displayedPage,
    pageSize,
  )

  // Update displayed page only when requested page data is ready (not fetching)
  // This keeps the current page visible until the next page data is fully loaded
  useEffect(() => {
    if (
      !logsFetching &&
      requestedPage !== displayedPage &&
      !logsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [logsFetching, logsLoading, requestedPage, displayedPage])

  // Use displayed logs for rendering (stays on current page until new data is ready)
  const logs = displayedLogs

  // Scroll to top when page changes and data is ready
  useLayoutEffect(() => {
    if (logs.length > 0 && displayedPage !== undefined) {
      // The ref is on the scrollable container itself, so scroll it directly
      const container = scrollContainerRef.current
      if (container) {
        container.scrollTop = 0
      }
    }
  }, [displayedPage, logs.length])

  const selectedExecution =
    logs.find((l) => l.$id === selectedExecutionId) || null

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
  }, [urlExecutionId, selectedExecutionId])

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
  if ((siteLoading || logsLoading) && logs.length === 0) {
    return (
      <div className="flex-1">
        <div className="flex h-full items-center justify-center py-16">
          <div className="text-center">
            <p className="text-[13px] text-muted-foreground">
              Loading logs...
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div ref={scrollContainerRef} className="flex-1">
      {logs.length > 0 ? (
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
              {logs.map((execution, index) => {
                const executionData = execution as Models.Execution
                const statusBadge = getExecutionStatusBadge(executionData.status || 'completed')
                const triggerBadge = getTriggerBadge(executionData.trigger || 'http')
                const statusCodeBadge = executionData.responseStatusCode
                  ? getStatusCodeBadge(executionData.responseStatusCode)
                  : null

                return (
                  <TableRow
                    key={executionData.$id}
                    className={cn(
                      'cursor-pointer',
                      index === logs.length - 1 &&
                        'border-b border-border',
                    )}
                    onClick={() => handleExecutionClick(executionData.$id)}
                  >
                    <TableCell className="pl-6 sm:pl-8">
                      <CopyableId id={executionData.$id} size="sm" />
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
                      {executionData.responseStatusCode ? (
                        <Badge variant={statusCodeBadge?.variant || 'outline'}>
                          {executionData.responseStatusCode}
                        </Badge>
                      ) : (
                        <span className="text-[13px] text-muted-foreground">
                          N/A
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <span className="font-mono text-[13px] text-muted-foreground">
                        {executionData.requestMethod || 'N/A'}
                      </span>
                    </TableCell>
                    <TableCell>
                      <p className="truncate text-[12px] text-muted-foreground">
                        {executionData.requestPath ? (
                          <code className="text-[12px]">
                            {executionData.requestPath}
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
                          {executionData.duration
                            ? formatDuration(executionData.duration * 1000)
                            : 'N/A'}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <DateTooltip
                        date={executionData.$createdAt}
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
              itemLabel="logs"
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
              Executions will appear here when your site runs.
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
        execution={selectedExecution as Models.Execution | null}
        executions={logs as Models.Execution[]}
        func={null}
        onNavigate={handleNavigate}
      />
    </div>
  )
}
