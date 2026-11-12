import { useState, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { useProjectSite, useSiteLogs } from '@/lib/react-query/hooks'
import { LogsListView } from '@/components/global/shared/LogsListView'
import { Route } from '@/routes/_public/projects.$projectId.sites.$siteId.logs'
import { useRefreshOptional } from '@/components/global/shared/RefreshContext'
import { useT } from '@/lib/i18n/translate'

const LOGS_PER_PAGE = 25

export function SiteLogsView() {
  const t = useT()
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

  const refreshContext = useRefreshOptional()

  useProjectSite(projectId, siteId)

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
    if (!logsFetching && requestedPage !== displayedPage && !logsLoading) {
      setDisplayedPage(requestedPage)
    }
  }, [logsFetching, logsLoading, requestedPage, displayedPage])

  // Use displayed logs for rendering (stays on current page until new data is ready)
  const logs = displayedLogs

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

  return (
    <LogsListView
      executions={logs}
      total={total}
      isLoading={logsLoading}
      currentPage={displayedPage + 1}
      pageSize={pageSize}
      onPageChange={handlePageChange}
      onPageSizeChange={handlePageSizeChange}
      selectedExecutionId={selectedExecutionId}
      onExecutionSelect={handleExecutionSelect}
      onExecutionDeselect={handleExecutionDeselect}
      func={null}
      projectId={projectId}
      resourceVariant="site"
      resourceId={siteId}
      emptyStateTitle={t('No executions yet')}
      emptyStateDescription={t(
        'Executions will appear here when your site runs.',
      )}
      itemLabel={t('logs')}
    />
  )
}
