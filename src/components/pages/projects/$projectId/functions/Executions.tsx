import { useState, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import {
  useProjectFunction,
  useFunctionExecutions,
} from '@/lib/react-query/hooks'
import { LogsListView } from '@/components/global/shared/LogsListView'
import { Route } from '@/routes/_public/projects.$projectId.functions.$functionId.executions'
import { useRefreshOptional } from '@/components/global/shared/RefreshContext'

const EXECUTIONS_PER_PAGE = 25

// Export badge functions for use in ExecutionDetailsDrawer
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

  const refreshContext = useRefreshOptional()

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
    refetch,
  } = useFunctionExecutions(projectId, functionId, requestedPage, pageSize)

  // Fetch data for the displayed page (this is what we show)
  const {
    executions: displayedExecutions,
    total: displayedTotal,
  } = useFunctionExecutions(
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

  // Register refetch function with the context for the layout's refresh button
  useEffect(() => {
    if (refreshContext) {
      refreshContext.registerRefreshHandler(async () => {
        await refetch()
      }, 'Executions')
      return () => {
        refreshContext.unregisterRefreshHandler()
      }
    }
  }, [refreshContext, refetch])

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
      // Clear selection when URL doesn't have executionId (user closed drawer)
      setSelectedExecutionId(null)
    }
  }, [urlExecutionId, selectedExecutionId])

  const handleExecutionSelect = (executionId: string) => {
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

  const handleExecutionDeselect = () => {
    setSelectedExecutionId(null)
  }

  return (
    <LogsListView
      executions={executions}
      total={displayedTotal ?? total}
      isLoading={funcLoading || executionsLoading}
      currentPage={displayedPage + 1}
      pageSize={pageSize}
      onPageChange={handlePageChange}
      onPageSizeChange={handlePageSizeChange}
      selectedExecutionId={selectedExecutionId}
      onExecutionSelect={handleExecutionSelect}
      onExecutionDeselect={handleExecutionDeselect}
      func={func}
      emptyStateTitle="No executions yet"
      emptyStateDescription="Executions will appear here when your function runs."
      itemLabel="executions"
    />
  )
}
