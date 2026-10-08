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
import { useT } from '@/lib/i18n/translate'

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
    scheduled: { label: 'Scheduled', variant: 'pending' },
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
  const t = useT()
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
  const filterQueries = useMemo(
    () => (filterMap.size > 0 ? Array.from(filterMap.values()) : undefined),
    [filterMap],
  )

  const [pageSize, setPageSize] = useState(EXECUTIONS_PER_PAGE)
  const [selectedExecutionId, setSelectedExecutionId] = useState<string | null>(
    urlExecutionId || null,
  )

  const refreshContext = useRefreshOptional()

  const { data: func } = useProjectFunction(projectId, functionId)

  const {
    executions,
    total,
    isLoading: executionsLoading,
    isFetching: executionsFetching,
    refetch,
  } = useFunctionExecutions(
    projectId,
    functionId,
    urlPage - 1,
    pageSize,
    filterQueries,
  )

  const refetchRef = useRef(refetch)
  refetchRef.current = refetch

  // Register refetch function with the context for the layout's refresh button
  useEffect(() => {
    if (refreshContext) {
      refreshContext.registerRefreshHandler(async () => {
        await refetchRef.current()
      }, 'Executions')
      return () => {
        refreshContext.unregisterRefreshHandler()
      }
    }
  }, [refreshContext])

  const handlePageChange = (page: number) => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: page === 1 ? undefined : page,
      }),
      replace: true,
    })
  }

  const handlePageSizeChange = (size: number) => {
    setPageSize(size)
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        page: undefined,
      }),
      replace: true,
    })
  }

  // Sync selectedExecutionId with URL parameter
  useEffect(() => {
    if (urlExecutionId && urlExecutionId !== selectedExecutionId) {
      setSelectedExecutionId(urlExecutionId)
    } else if (!urlExecutionId && selectedExecutionId) {
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
    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
      <LogsListView
        executions={executions}
        total={total}
        isLoading={executionsLoading}
        isFetching={executionsFetching}
        currentPage={urlPage}
        pageSize={pageSize}
        onPageChange={handlePageChange}
        onPageSizeChange={handlePageSizeChange}
        selectedExecutionId={selectedExecutionId}
        onExecutionSelect={handleExecutionSelect}
        onExecutionDeselect={handleExecutionDeselect}
        func={func}
        projectId={projectId}
        resourceVariant="function"
        resourceId={functionId}
        emptyStateTitle={hasFilters ? undefined : t('No executions yet')}
        emptyStateDescription={
          hasFilters
            ? undefined
            : t('Executions will appear here when your function runs.')
        }
        hasFilters={hasFilters}
        itemLabel="executions"
      />
    </div>
  )
}
