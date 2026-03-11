import { useState, useEffect, useMemo, useRef } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { useProjectSite, useSiteLogs } from '@/lib/react-query/hooks'
import { LogsListView } from '@/components/global/shared/LogsListView'
import { Route } from '@/routes/_public/projects.$projectId.sites.$siteId.logs'
import { useRefreshOptional } from '@/components/global/shared/RefreshContext'
import { queryParamToMap } from '@/lib/table-filters'

const LOGS_PER_PAGE = 25

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
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

  // Initialize displayed page from URL (0-indexed)
  const [displayedPage, setDisplayedPage] = useState(urlPage - 1)
  const [requestedPage, setRequestedPage] = useState(urlPage - 1)
  const [pageSize, setPageSize] = useState(LOGS_PER_PAGE)
  const [selectedExecutionId, setSelectedExecutionId] = useState<string | null>(
    urlExecutionId || null,
  )

  const refreshContext = useRefreshOptional()

  const { isLoading: siteLoading } = useProjectSite(projectId, siteId)

  // Sync requested page with URL when it changes externally (e.g., browser back/forward)
  useEffect(() => {
    const newRequestedPage = urlPage - 1
    if (newRequestedPage !== requestedPage) {
      setRequestedPage(newRequestedPage)
    }
  }, [urlPage, requestedPage])

  // Fetch data for the requested page (this will fetch in background)
  const {
    total,
    isLoading: logsLoading,
    isFetching: logsFetching,
    refetch,
  } = useSiteLogs(projectId, siteId, requestedPage, pageSize, filterQueries)

  // Fetch data for the displayed page (this is what we show)
  const { logs: displayedLogs, total: displayedTotal } = useSiteLogs(
    projectId,
    siteId,
    displayedPage,
    pageSize,
    filterQueries,
  )

  // Update displayed page only when requested page data is ready (not fetching)
  // This keeps the current page visible until the next page data is fully loaded
  useEffect(() => {
    if (!logsFetching && requestedPage !== displayedPage && !logsLoading) {
      setDisplayedPage(requestedPage)
    }
  }, [logsFetching, logsLoading, requestedPage, displayedPage])

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastLogsRef = useRef<typeof displayedLogs>([])
  useEffect(() => {
    if (!logsFetching && displayedLogs.length > 0) {
      lastLogsRef.current = displayedLogs
    }
  }, [logsFetching, displayedLogs])
  const logs =
    logsFetching && lastLogsRef.current.length > 0
      ? lastLogsRef.current
      : displayedLogs

  // Register refetch function with the context for the layout's refresh button
  useEffect(() => {
    if (refreshContext) {
      refreshContext.registerRefreshHandler(async () => {
        await refetch()
      }, 'Logs')
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
        executions={logs}
        total={displayedTotal ?? total}
        isLoading={siteLoading || logsLoading}
        isFetching={logsFetching}
        currentPage={displayedPage + 1}
        pageSize={pageSize}
        onPageChange={handlePageChange}
        onPageSizeChange={handlePageSizeChange}
        selectedExecutionId={selectedExecutionId}
        onExecutionSelect={handleExecutionSelect}
        onExecutionDeselect={handleExecutionDeselect}
        func={null}
        emptyStateTitle={hasFilters ? undefined : 'No logs yet'}
        emptyStateDescription={
          hasFilters
            ? undefined
            : 'Logs will appear here when your site runs.'
        }
        hasFilters={hasFilters}
        itemLabel="logs"
      />
    </div>
  )
}
