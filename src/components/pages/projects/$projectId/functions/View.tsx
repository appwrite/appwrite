import { useState, useEffect, useMemo, useRef } from 'react'
import {
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  Link,
} from '@tanstack/react-router'
import { useQueryClient, useQuery } from '@tanstack/react-query'
import { Play, FileCode } from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ResourceCard, RESOURCE_CARD_GRID_CLASSNAME } from '../shared/ResourceCard'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import {
  useProjectFunctions,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  fetchProjectFunctions,
  FUNCTIONS_DEFAULT_SORT_BY,
  FUNCTIONS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  parseSort,
  encodeSort,
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  urlFromRouterLocation,
  MIN_SEARCH_LENGTH,
  functionsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { canCreateFunction } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { FunctionContextMenu } from './_components/FunctionContextMenu'
import {
  FunctionsListTable,
  getActiveDeploymentCreatedAt,
  functionHasInProgressDeployment,
} from './_components/FunctionsListTable'
import { DeploymentResourceStatusBadges, resourceHasVisibleStatus } from '../shared/DeploymentResourceStatusBadges'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { useT } from '@/lib/i18n/translate'

function getFunctionsServiceTabs(projectId: string): Tab[] {
  return [
    {
      id: 'functions',
      label: 'Functions',
      to: '/projects/$projectId/functions/',
      params: { projectId },
    },
    {
      id: 'templates',
      label: 'Templates',
      to: '/projects/$projectId/functions/templates',
      params: { projectId },
    },
  ]
}

type FunctionsListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

function formatRuntimeLabel(runtime: string) {
  if (!runtime) return 'Unknown runtime'
  return runtime.split('-').join(' ')
}

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  useQueryClient()
  const search = useSearch({ strict: false })

  const isFunctionsIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/functions`
  const defaultFunctionsSort = {
    sortBy: FUNCTIONS_DEFAULT_SORT_BY,
    sortOrder: FUNCTIONS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
  }
  const functionsListParams = useMemo(() => {
    if (!isFunctionsIndex || typeof search !== 'object') return null
    const url = urlFromRouterLocation(location, window.location.origin)
    const parsed =
      parseSort(search.sort as string | undefined) ??
      getSort(url) ??
      defaultFunctionsSort
    // Prefer router search state (updated by navigate()) over URL so page size change takes effect even if URL lags
    const pageFromSearch =
      search.page != null
        ? typeof search.page === 'number'
          ? search.page
          : Number(search.page)
        : undefined
    const limitFromSearch =
      search.limit != null
        ? typeof search.limit === 'number'
          ? search.limit
          : Number(search.limit)
        : undefined
    const page =
      Number.isInteger(pageFromSearch) && (pageFromSearch ?? 0) >= 1
        ? pageFromSearch!
        : getPage(url, 1)
    const limit =
      Number.isInteger(limitFromSearch) && (limitFromSearch ?? 0) >= 1
        ? limitFromSearch!
        : getLimit(url, GRID_DEFAULT_PAGE_SIZE)
    return {
      search: getSearch(url) ?? (search.search as string | undefined),
      page,
      limit,
      filterMap: queryParamToMap(
        getQueryParam(url) ?? (search.query as string | undefined) ?? null,
      ),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [isFunctionsIndex, search, location.pathname, location.search, projectId])

  const urlPage = functionsListParams?.page ?? 1
  const urlLimit = functionsListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = functionsListParams?.search
  const urlSortBy = functionsListParams?.sortBy ?? FUNCTIONS_DEFAULT_SORT_BY
  const urlSortOrder =
    functionsListParams?.sortOrder ?? FUNCTIONS_DEFAULT_SORT_ORDER
  const filterMap = functionsListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  const [searchInput, setSearchInput] = useState<string>('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedSortBy, setDisplayedSortBy] = useState(
    FUNCTIONS_DEFAULT_SORT_BY,
  )
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    FUNCTIONS_DEFAULT_SORT_ORDER,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const { viewMode, setViewMode } = useServiceListViewMode('functions')

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isFunctionsIndex) return
    setRequestedPage((prev) => (prev === urlPage ? prev : urlPage))
  }, [isFunctionsIndex, urlPage, urlLimit])

  useEffect(() => {
    if (!isFunctionsIndex || !functionsListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [
    isFunctionsIndex,
    functionsListParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  useEffect(() => {
    if (!isFunctionsIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigateToFunctionsList({
        search: trimmed || undefined,
        query: filterQueryString || undefined,
        page: 1,
        limit: urlLimit,
        sort:
          urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
          urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
            ? encodeSort(urlSortBy, urlSortOrder)
            : undefined,
      })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [
    searchInput,
    projectId,
    navigate,
    urlSearch,
    urlLimit,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
    isFunctionsIndex,
  ])

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total,
    isLoading: functionsLoading,
    isFetching: functionsFetching,
    isFetched: functionsFetched,
    error,
  } = useProjectFunctions(
    projectId,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )

  const {
    functions,
    total: displayedTotal,
    isLoading: displayedLoading,
    refetch: refetchDisplayedFunctions,
  } = useProjectFunctions(
    projectId,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
    displayedSortBy,
    displayedSortOrder,
  )

  useEffect(() => {
    if (
      !isFunctionsIndex ||
      functionsFetching ||
      functionsLoading ||
      !functionsFetched
    )
      return
    const match =
      urlPage === displayedPage &&
      (urlSearch ?? '') === (displayedSearch ?? '') &&
      filterQueryString === displayedFilterQueryString &&
      urlSortBy === displayedSortBy &&
      urlSortOrder === displayedSortOrder
    if (!match) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
    }
  }, [
    isFunctionsIndex,
    functionsFetching,
    functionsLoading,
    functionsFetched,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
    displayedPage,
    displayedSearch,
    displayedSortBy,
    displayedSortOrder,
    displayedFilterQueryString,
  ])

  const hasInProgressDeployment = useMemo(
    () => functions.some((func) => functionHasInProgressDeployment(func)),
    [functions],
  )

  useEffect(() => {
    if (!hasInProgressDeployment) return
    const interval = setInterval(() => {
      void refetchDisplayedFunctions()
    }, 5000)
    return () => clearInterval(interval)
  }, [hasInProgressDeployment, refetchDisplayedFunctions])

  const handleFunctionsSortChange = (
    sortBy: string,
    sortOrder: 'asc' | 'desc',
  ) => {
    navigateToFunctionsList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        sortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
        sortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
          ? encodeSort(sortBy, sortOrder)
          : undefined,
    })
  }

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(filterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(compactKey, queryStr)
    navigateToFunctionsList({
      search: urlSearch ?? undefined,
      query: mapToQueryParam(next) || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
        urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(compactKey)
    navigateToFunctionsList({
      search: urlSearch ?? undefined,
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
        urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const clearAllFilters = () => {
    navigateToFunctionsList({
      search: urlSearch ?? undefined,
      query: undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
        urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
    setFiltersOpen(false)
  }

  const navigateToFunctionsList = (params: FunctionsListSearch) => {
    const hasQueryKey = 'query' in params
    const hasSearchKey = 'search' in params
    const hasSortKey = 'sort' in params
    navigate({
      to: '/projects/$projectId/functions',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: hasSearchKey ? params.search : (urlSearch ?? undefined),
          query: hasQueryKey ? params.query : filterQueryString || undefined,
          page: params.page ?? 1,
          limit: params.limit ?? urlLimit,
          sort: hasSortKey
            ? params.sort
            : urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
                urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        if (params.page === 1) delete next.page
        if (hasQueryKey && params.query === undefined) delete next.query
        if (
          hasSearchKey &&
          (params.search === undefined || params.search === '')
        )
          delete next.search
        if (hasSortKey && params.sort === undefined) delete next.sort
        return next
      },
      replace: true,
    })
  }

  const showLoading = displayedLoading && functions.length === 0

  const { data: totalFunctionsData } = useQuery({
    queryKey: [
      'functions',
      'project',
      projectId,
      0,
      urlLimit,
      undefined,
      undefined,
      FUNCTIONS_DEFAULT_SORT_BY,
      FUNCTIONS_DEFAULT_SORT_ORDER,
    ],
    queryFn: () =>
      fetchProjectFunctions(
        projectId!,
        0,
        urlLimit,
        undefined,
        undefined,
        FUNCTIONS_DEFAULT_SORT_BY,
        FUNCTIONS_DEFAULT_SORT_ORDER,
      ),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    refetchOnMount: false,
  })

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { showFunctionsLocalEditor } = useDebugOverrides()
  const { access } = useOrganizationScopes(project?.teamId)

  const localEditorBeforeCreateButtons = showFunctionsLocalEditor ? (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="outline"
            size="sm"
            className="h-9 w-9 gap-0 p-0 text-[13px] @[640px]:w-auto @[640px]:gap-1.5 @[640px]:px-3"
            asChild
          >
            <Link
              to="/projects/$projectId/functions/editor"
              params={{ projectId: projectId as string }}
              aria-label={t('Local editor')}
            >
              <FileCode className="h-4 w-4 shrink-0" />
              <span className="hidden @[640px]:inline">{t('Local editor')}</span>
            </Link>
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{t('Edit code locally and prepare gzip for deployment')}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : undefined

  // Total count of all functions (without search) - for limit checking
  const totalFunctionsCount = totalFunctionsData?.total || 0

  // Check if create button should be disabled (plan limit or missing write scope)
  const noCreatePermission = !canCreateFunction(access, features)
  const functionsLimit = organizationPlan?.functions ?? 0
  const isCreateDisabled =
    noCreatePermission ||
    (functionsLimit > 0 && totalFunctionsCount >= functionsLimit)

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
        navigate({
          to: '/projects/$projectId/functions/create',
          params: { projectId: projectId! },
        })
      } else if (to === 'cover') {
        // Redirect to function creation page
        // TODO: Implement function creation modal/page
        toast.info(t('Function creation coming soon'))
      }
    }
  }, [location.search, navigate, projectId, t])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    // URL is updated by the debounced effect so we don't fetch on every keystroke
  }

  const handleCreateFunction = () => {
    navigate({
      to: '/projects/$projectId/functions/create',
      params: { projectId: projectId as string },
    })
  }

  const functionsTabs: Tab[] = useMemo(
    () =>
      getFunctionsServiceTabs(projectId as string).map((tab) => ({
        ...tab,
        label: t(tab.label),
      })),
    [projectId, t],
  )

  // Use displayed data for empty states so we don't flash "No results" before syncing
  const filtersMatch =
    (displayedSearch ?? '') === (urlSearch ?? '') &&
    displayedFilterQueryString === filterQueryString
  const hasFunctions = (displayedTotal ?? 0) > 0
  const hasFilters = (urlSearch && urlSearch.length > 0) || filterMap.size > 0
  const noSearchResults =
    hasFilters &&
    filtersMatch &&
    (displayedTotal ?? 0) === 0 &&
    !displayedLoading

  const viewToggle = (
    <ServiceListViewToggle viewMode={viewMode} onViewModeChange={setViewMode} />
  )

  if (error) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title={t('Functions')}
          tabs={functionsTabs}
          activeTab="functions"
          searchPlaceholder={t('Search functions...')}
          searchValue={searchInput}
          onSearchChange={handleSearchChange}
          createLabel={t('Create function')}
          onCreate={handleCreateFunction}
          createDisabled={isCreateDisabled}
          createDisabledTooltip={
            noCreatePermission
              ? t("You don't have permission to create functions.")
              : undefined
          }
          fullWidthBorder
          showFilters
          rightContent={viewToggle}
          filterTrigger={
            <FiltersPopover
              open={filtersOpen}
              onOpenChange={setFiltersOpen}
              columns={functionsFilterColumns}
              filterMap={filterMap}
              onRemoveFilter={removeFilter}
              onClearAll={clearAllFilters}
              onApplyFilter={applyFilter}
              resourceLabel="functions"
              filterScope="functions"
              onApplyQuery={(queryParam, sortParam) =>
                navigateToFunctionsList({
                  search: urlSearch ?? undefined,
                  query: queryParam ?? undefined,
                  page: 1,
                  limit: urlLimit,
                  sort: sortParam ?? undefined,
                })
              }
              sortBy={urlSortBy}
              sortOrder={urlSortOrder}
              onSortChange={handleFunctionsSortChange}
              defaultSortParam={encodeSort(
                FUNCTIONS_DEFAULT_SORT_BY,
                FUNCTIONS_DEFAULT_SORT_ORDER,
              )}
              onReset={() => {
                navigate({
                  to: '/projects/$projectId/functions',
                  params: { projectId: projectId! },
                  search: { page: 1, limit: urlLimit },
                  replace: true,
                })
              }}
              teamId={project?.teamId}
            />
          }
          beforeCreateButtons={localEditorBeforeCreateButtons}
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {t('Failed to load functions. Please try again.')}
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Functions')}
        tabs={functionsTabs}
        activeTab="functions"
        searchPlaceholder={t('Search functions...')}
        searchValue={searchInput}
        onSearchChange={handleSearchChange}
        createLabel={t('Create function')}
        onCreate={handleCreateFunction}
        createDisabled={isCreateDisabled}
        createDisabledTooltip={
          noCreatePermission
            ? t("You don't have permission to create functions.")
            : undefined
        }
        fullWidthBorder
        beforeCreateButtons={localEditorBeforeCreateButtons}
        showFilters
        rightContent={viewToggle}
        filterTrigger={
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={functionsFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearAllFilters}
            onApplyFilter={applyFilter}
            resourceLabel="functions"
            filterScope="functions"
            onApplyQuery={(queryParam, sortParam) =>
              navigateToFunctionsList({
                search: urlSearch ?? undefined,
                query: queryParam ?? undefined,
                page: 1,
                limit: urlLimit,
                sort: sortParam ?? undefined,
              })
            }
            sortBy={urlSortBy}
            sortOrder={urlSortOrder}
            onSortChange={handleFunctionsSortChange}
            defaultSortParam={encodeSort(
              FUNCTIONS_DEFAULT_SORT_BY,
              FUNCTIONS_DEFAULT_SORT_ORDER,
            )}
            onReset={() => {
              navigate({
                to: '/projects/$projectId/functions',
                params: { projectId: projectId! },
                search: { page: 1, limit: urlLimit },
                replace: true,
              })
            }}
            teamId={project?.teamId}
          />
        }
        contentAfterBorder={
          project &&
          organizationPlan !== undefined &&
          totalFunctionsData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalFunctionsCount}
              limit={functionsLimit}
              planName={resolveOrganizationPlanDisplayLabel({
                planName: organizationPlan?.name ?? null,
                planId: organizationPlan?.$id,
              })}
              resourceName="functions"
              orgId={project?.teamId}
              fullWidth={false}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading functions...')}
                </p>
              </div>
            ) : noSearchResults ? (
              <EmptyState
                icon={Play}
                isEmpty={false}
                hasFilters={hasFilters}
                variant="card"
              />
            ) : !hasFunctions ? (
              <EmptyState
                icon={Play}
                title={hasFilters ? undefined : t('No functions yet')}
                description={
                  hasFilters
                    ? undefined
                    : t(
                        'Create your first function to deploy and manage serverless functions',
                      )
                }
                isEmpty={true}
                hasFilters={hasFilters}
                variant="card"
              />
            ) : (
              <>
                {viewMode === 'list' ? (
                  <FunctionsListTable
                    projectId={projectId!}
                    functions={functions as Models.Function[]}
                  />
                ) : (
                  <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                    {functions.map((func) => {
                      const activeDeploymentCreatedAt =
                        getActiveDeploymentCreatedAt(func as Models.Function)

                      return (
                        <FunctionContextMenu
                          key={func.$id}
                          projectId={projectId!}
                          func={{ $id: func.$id, name: func.name }}
                        >
                          <Link
                            to="/projects/$projectId/functions/$functionId"
                            params={{
                              projectId: projectId!,
                              functionId: func.$id,
                            }}
                          >
                            <ResourceCard
                              title={func.name || t('Unnamed Function')}
                              subtitle={t(formatRuntimeLabel(func.runtime || ''))}
                              resourceId={func.$id}
                              customIcon={
                                <RuntimeIcon
                                  runtime={func.runtime || ''}
                                  size="md"
                                  className="h-5 w-5"
                                />
                              }
                              iconColor="bg-muted text-muted-foreground"
                              metadata={[
                                ...(resourceHasVisibleStatus(func as Models.Function)
                                  ? [
                                      {
                                        label: '',
                                        value: (
                                          <DeploymentResourceStatusBadges
                                            resource={func as Models.Function}
                                          />
                                        ),
                                      },
                                    ]
                                  : []),
                                {
                                  label: t('Deployed'),
                                  value: activeDeploymentCreatedAt ? (
                                    <DateTooltip
                                      date={activeDeploymentCreatedAt}
                                      live
                                      className="text-[12px] font-medium text-muted-foreground"
                                    />
                                  ) : (
                                    t('Never')
                                  ),
                                },
                              ]}
                            />
                          </Link>
                        </FunctionContextMenu>
                      )
                    })}
                  </div>
                )}

                <Pagination
                  currentPage={displayedPage}
                  totalItems={displayedTotal ?? total}
                  pageSize={urlLimit}
                  pageSizeOptions={[12, 18, 36, 72]}
                  onPageChange={(page) => {
                    setRequestedPage(page)
                    navigateToFunctionsList({
                      search: urlSearch ?? undefined,
                      query: filterQueryString || undefined,
                      page,
                      limit: urlLimit,
                      sort:
                        urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
                        urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
                          ? encodeSort(urlSortBy, urlSortOrder)
                          : undefined,
                    })
                  }}
                  onPageSizeChange={(size) => {
                    setRequestedPage(1)
                    setDisplayedPage(1)
                    navigateToFunctionsList({
                      search: urlSearch ?? undefined,
                      query: filterQueryString || undefined,
                      page: 1,
                      limit: size,
                      sort:
                        urlSortBy !== FUNCTIONS_DEFAULT_SORT_BY ||
                        urlSortOrder !== FUNCTIONS_DEFAULT_SORT_ORDER
                          ? encodeSort(urlSortBy, urlSortOrder)
                          : undefined,
                    })
                  }}
                  itemLabel="functions"
                />
              </>
            )}
        </>
      </div>
    </div>
  )
}
