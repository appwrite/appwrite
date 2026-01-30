import { useState, useEffect, useMemo } from 'react'
import {
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  Link,
} from '@tanstack/react-router'
import { useQueryClient, useQuery } from '@tanstack/react-query'
import { Plus, Clock, Zap, Play } from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ResourceCard } from '../shared/ResourceCard'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import {
  useProjectFunctions,
  Dependencies,
  useProject,
  useOrganizationPlan,
  fetchProjectFunctions,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { TemplatesView } from './Templates'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { formatCronExpression } from './CronScheduleEditor'

const FUNCTIONS_PER_PAGE = 25

/**
 * Get next scheduled execution time from cron expression
 * Returns null since cron-parser is not available in client-side code
 * TODO: Implement client-side cron parsing if needed
 */
function getNextScheduledExecution(func: Models.Function): string | null {
  if (!func.schedule) return null

  // cron-parser is not available in client-side code
  // Return null to avoid dependency resolution errors
  return null
}

/**
 * Extract runtime prefix from runtime string (e.g., "node-18.0" -> "node")
 */
function getRuntimePrefix(runtime: string): string {
  return runtime.split('-')[0] || 'unknown'
}

export function View() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const search = useSearch({ strict: false })

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const functionsIndex = pathParts.findIndex((part) => part === 'functions')

    if (functionsIndex >= 0) {
      // Check if there's a tab segment after 'functions'
      // pathParts structure: ['projects', 'projectId', 'functions', 'tab?']
      if (pathParts[functionsIndex + 1]) {
        const tabFromPath = pathParts[functionsIndex + 1]
        if (['templates'].includes(tabFromPath)) {
          return tabFromPath
        }
      }
    }

    // Default to functions for index route (/projects/:projectId/functions or /projects/:projectId/functions/)
    return 'functions'
  }, [location.pathname])

  const [searchValue, setSearchValue] = useState<string>('')
  const [requestedPage, setRequestedPage] = useState(0)
  const [displayedPage, setDisplayedPage] = useState(0)
  const [pageSize, setPageSize] = useState(FUNCTIONS_PER_PAGE)

  // Get search from URL params
  const urlSearch =
    typeof search === 'object' && 'search' in search
      ? (search.search as string)
      : undefined

  // Initialize search from URL
  useEffect(() => {
    if (urlSearch !== undefined) {
      setSearchValue(urlSearch)
    }
  }, [urlSearch])

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total,
    isLoading: functionsLoading,
    isFetching: functionsFetching,
    error,
  } = useProjectFunctions(
    projectId,
    requestedPage,
    pageSize,
    searchValue || undefined,
  )

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    functions,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useProjectFunctions(
    projectId,
    displayedPage,
    pageSize,
    searchValue || undefined,
  )

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !functionsFetching &&
      requestedPage !== displayedPage &&
      !functionsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [functionsFetching, functionsLoading, requestedPage, displayedPage])

  const showLoading = displayedLoading && functions.length === 0

  // Get total count from the first page query (no search) - already fetched in route loader
  // This is used for limit checking and doesn't change when searching
  const { data: totalFunctionsData } = useQuery({
    queryKey: ['functions', 'project', projectId, 0, pageSize, undefined],
    queryFn: () => fetchProjectFunctions(projectId!, 0, pageSize, undefined),
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
    refetchOnMount: false, // Data is fresh from route loader, no need to refetch
  })

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)

  // Total count of all functions (without search) - for limit checking
  const totalFunctionsCount = totalFunctionsData?.total || 0

  // Check if create button should be disabled
  const functionsLimit = organizationPlan?.functions ?? 0
  const isCreateDisabled =
    functionsLimit > 0 && totalFunctionsCount >= functionsLimit

  // Handle GitHub redirect
  useEffect(() => {
    const searchString =
      typeof location.search === 'string'
        ? location.search
        : new URLSearchParams(
            location.search as Record<string, string>,
          ).toString()
    const urlParams = new URLSearchParams(searchString)
    const from = urlParams.get('from')
    const to = urlParams.get('to')

    if (from === 'github') {
      if (to === 'template') {
        // Redirect to templates page
        navigate({
          to: '/projects/$projectId/functions/templates',
          params: { projectId: projectId! },
        })
      } else if (to === 'cover') {
        // Redirect to function creation page
        // TODO: Implement function creation modal/page
        toast.info('Function creation coming soon')
      }
    }
  }, [location.search, navigate, projectId])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(0)
    setDisplayedPage(0)
    // Update URL
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...prev,
        search: value || undefined,
      }),
      replace: true,
    })
  }

  const handleCreateFunction = () => {
    // TODO: Open create function dialog
    toast.info('Function creation coming soon')
  }

  const hasFunctions = total > 0
  const noSearchResults = searchValue && total === 0 && !functionsLoading

  // Update tabs with dynamic function count
  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'functions',
        label: 'Functions',
        to: '/projects/$projectId/functions/',
        params: { projectId: projectId as string },
      },
      {
        id: 'templates',
        label: 'Templates',
        to: '/projects/$projectId/functions/templates',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const getCreateLabel = () => {
    switch (activeTab) {
      case 'functions':
        return 'Create function'
      default:
        return undefined
    }
  }

  if (error) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title="Functions"
          tabs={tabs}
          activeTab={activeTab}
          searchPlaceholder={
            activeTab === 'functions' ? 'Search by name or ID' : undefined
          }
          searchValue={activeTab === 'functions' ? searchValue : undefined}
          onSearchChange={
            activeTab === 'functions' ? handleSearchChange : undefined
          }
          createLabel={getCreateLabel()}
          onCreate={
            activeTab === 'functions' ? handleCreateFunction : undefined
          }
          createDisabled={activeTab === 'functions' ? isCreateDisabled : false}
          fullWidthBorder
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-sm text-muted-foreground">
              Failed to load functions. Please try again.
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Functions"
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'functions' ? 'Search by name or ID' : undefined
        }
        searchValue={activeTab === 'functions' ? searchValue : undefined}
        onSearchChange={
          activeTab === 'functions' ? handleSearchChange : undefined
        }
        createLabel={getCreateLabel()}
        onCreate={activeTab === 'functions' ? handleCreateFunction : undefined}
        createDisabled={activeTab === 'functions' ? isCreateDisabled : false}
        fullWidthBorder
        contentAfterBorder={
          // Data is prefetched in route loader, only render if data exists
          // PlanLimitWarning handles its own visibility logic
          activeTab === 'functions' &&
          project &&
          organizationPlan !== undefined &&
          totalFunctionsData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalFunctionsCount}
              limit={functionsLimit}
              planName={organizationPlan?.name}
              resourceName="functions"
              orgId={project?.teamId}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {activeTab === 'templates' ? (
          <TemplatesView />
        ) : (
          <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-sm text-muted-foreground">
                  Loading functions...
                </p>
              </div>
            ) : noSearchResults ? (
              <EmptyState
                icon={Play}
                isEmpty={false}
                hasFilters={true}
                variant="card"
                iconSize="md"
              >
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <Play className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <p className="mb-1 text-[14px] font-medium text-foreground">
                    No results found
                  </p>
                  <p className="mb-4 text-[13px] text-muted-foreground">
                    Try adjusting your search or filters to see more results.
                  </p>
                  <Button
                    variant="outline"
                    onClick={() => {
                      setSearchValue('')
                      setRequestedPage(0)
                      setDisplayedPage(0)
                      navigate({
                        to: location.pathname,
                        search: (prev) => ({
                          ...prev,
                          search: undefined,
                        }),
                        replace: true,
                      })
                    }}
                  >
                    Clear search
                  </Button>
                </div>
              </EmptyState>
            ) : !hasFunctions ? (
              <EmptyState
                icon={Play}
                title="No functions yet"
                description="Deploy and manage serverless functions with Appwrite Functions"
                isEmpty={true}
                variant="card"
                iconSize="md"
              >
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <Play className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <p className="mb-1 text-[14px] font-medium text-foreground">
                    No functions yet
                  </p>
                  <p className="mb-4 text-[13px] text-muted-foreground">
                    Deploy and manage serverless functions with Appwrite Functions
                  </p>
                  <div className="flex items-center justify-center gap-2">
                    <Button variant="outline" asChild className="gap-1.5">
                      <a
                        href="https://appwrite.io/docs/functions"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        Documentation
                      </a>
                    </Button>
                    <Button onClick={handleCreateFunction} className="gap-1.5">
                      <Plus className="h-4 w-4" />
                      Create function
                    </Button>
                  </div>
                </div>
              </EmptyState>
            ) : (
              <>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {functions.map((func) => {
                    const runtimePrefix = getRuntimePrefix(
                      func.runtime || 'unknown',
                    )
                    const nextExecution = func.schedule
                      ? getNextScheduledExecution(func as Models.Function)
                      : null

                    return (
                      <Link
                        key={func.$id}
                        to="/projects/$projectId/functions/$functionId"
                        params={{ projectId: projectId!, functionId: func.$id }}
                      >
                        <ResourceCard
                          title={func.name || 'Unnamed Function'}
                          resourceId={func.$id}
                          customIcon={
                            <RuntimeIcon
                              runtime={func.runtime || ''}
                              size="md"
                              className="h-5 w-5"
                            />
                          }
                          iconColor="bg-muted text-muted-foreground"
                          status={func.enabled === false ? 'error' : undefined}
                          statusLabel={
                            func.enabled === false ? 'Disabled' : undefined
                          }
                          metadata={[
                            {
                              label: 'Runtime',
                              value: func.runtime || 'unknown',
                            },
                            ...(func.schedule
                              ? [
                                  {
                                    label: 'Schedule',
                                    value: formatCronExpression(func.schedule),
                                  },
                                ]
                              : []),
                            ...(nextExecution
                              ? [
                                  {
                                    label: 'Next execution',
                                    value: (
                                      <TooltipProvider>
                                        <Tooltip>
                                          <TooltipTrigger asChild>
                                            <span className="flex items-center gap-1">
                                              <Clock className="h-3 w-3" />
                                              {nextExecution}
                                            </span>
                                          </TooltipTrigger>
                                          <TooltipContent>
                                            <p>
                                              Next execution: {nextExecution}
                                            </p>
                                          </TooltipContent>
                                        </Tooltip>
                                      </TooltipProvider>
                                    ),
                                  },
                                ]
                              : []),
                          ]}
                        />
                      </Link>
                    )
                  })}
                </div>

                <Pagination
                  currentPage={displayedPage + 1}
                  totalItems={displayedTotal ?? total}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={(page) => setRequestedPage(page - 1)}
                  onPageSizeChange={(size) => {
                    setPageSize(size)
                    setRequestedPage(0)
                    setDisplayedPage(0)
                  }}
                  itemLabel="functions"
                />
              </>
            )}
          </>
        )}
      </div>
    </div>
  )
}
