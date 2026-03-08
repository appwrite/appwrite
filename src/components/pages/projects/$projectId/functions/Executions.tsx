import { useState, useEffect, useMemo, useRef } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import {
  useProjectFunction,
  useFunctionExecutions,
} from '@/lib/react-query/hooks'
import { LogsListView } from '@/components/global/shared/LogsListView'
import { Route } from '@/routes/_public/projects.$projectId.functions.$functionId.executions'
import { useRefreshOptional } from '@/components/global/shared/RefreshContext'
import { queryParamToMap } from '@/lib/table-filters'

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

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = Route.useSearch()
  const urlPage = search.page ?? 1
  const urlExecutionId = search.executionId
  const filterMap = useMemo(
    () => queryParamToMap(search.query ?? null),
    [search.query],
  )
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

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

  const {
    total,
    isLoading: executionsLoading,
    isFetching: executionsFetching,
    refetch,
  } = useFunctionExecutions(
    projectId,
    functionId,
    requestedPage,
    pageSize,
    filterQueries,
  )

  const { executions: displayedExecutions, total: displayedTotal } =
    useFunctionExecutions(
      projectId,
      functionId,
      displayedPage,
      pageSize,
      filterQueries,
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

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastExecutionsRef = useRef<typeof displayedExecutions>([])
  useEffect(() => {
    if (!executionsFetching && displayedExecutions.length > 0) {
      lastExecutionsRef.current = displayedExecutions
    }
  }, [executionsFetching, displayedExecutions])
  const executions =
    executionsFetching && lastExecutionsRef.current.length > 0
      ? lastExecutionsRef.current
      : displayedExecutions

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

  const hasFilters = filterMap.size > 0

  return (
    <div className="flex flex-1 flex-col">
      <LogsListView
        executions={executions}
        total={displayedTotal ?? total}
        isLoading={funcLoading || executionsLoading}
        isFetching={executionsFetching}
        currentPage={displayedPage + 1}
        pageSize={pageSize}
        onPageChange={handlePageChange}
        onPageSizeChange={handlePageSizeChange}
        selectedExecutionId={selectedExecutionId}
        onExecutionSelect={handleExecutionSelect}
        onExecutionDeselect={handleExecutionDeselect}
        func={func}
        emptyStateTitle={hasFilters ? undefined : 'No executions yet'}
        emptyStateDescription={
          hasFilters
            ? undefined
            : 'Executions will appear here when your function runs.'
        }
        hasFilters={hasFilters}
        itemLabel="executions"
      />
    </div>
  )
}
