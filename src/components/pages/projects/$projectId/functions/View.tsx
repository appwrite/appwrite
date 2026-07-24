import { useState, useEffect, useMemo, useRef } from 'react'
import {
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  Link,
} from '@tanstack/react-router'
import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query'
import { Play, FileCode, Clock } from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
} from '../shared/ResourceCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { cn } from '@/lib/utils'
import { FunctionExecutionsChartPreview } from './_components/FunctionExecutionsChartPreview'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import {
  useProjectFunctions,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  fetchProjectFunctions,
  Dependencies,
  FUNCTIONS_DEFAULT_SORT_BY,
  FUNCTIONS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
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
import { formatCronExpression } from './CronScheduleEditor'
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
  const queryClient = useQueryClient()
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
  const [selectedFunctions, setSelectedFunctions] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
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

  useEffect(() => {
    setSelectedFunctions(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, urlSearch, filterQueryString])

  const bulkDeleteMutation = useMutation({
    mutationFn: async (functionIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      await Promise.all(
        functionIds.map((functionId) =>
          projectSdk.functions.delete({ functionId }),
        ),
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: Dependencies.FUNCTIONS,
      })
      toast.success(
        selectedFunctions.size === 1
          ? t('Function deleted successfully')
          : `${t('Successfully deleted')} ${selectedFunctions.size} ${t('functions')}`,
      )
      setSelectedFunctions(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete functions'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedFunctions.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedFunctions.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedFunctions))
  }

  const toggleFunction = (functionId: string) => {
    const newSelected = new Set(selectedFunctions)
    if (newSelected.has(functionId)) {
      newSelected.delete(functionId)
    } else {
      newSelected.add(functionId)
    }
    setSelectedFunctions(newSelected)
  }

  const toggleAllFunctions = () => {
    if (selectedFunctions.size === functions.length) {
      setSelectedFunctions(new Set())
    } else {
      setSelectedFunctions(
        new Set(functions.map((func) => (func as Models.Function).$id)),
      )
    }
  }

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedFunctions(new Set())
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
          createAnalyticsAction="create-function"
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
        createAnalyticsAction="create-function"
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
                    selectedFunctionIds={selectedFunctions}
                    onToggleFunction={toggleFunction}
                    onToggleAll={toggleAllFunctions}
                  />
                ) : (
                  <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                    {functions.map((func) => {
                      const activeDeploymentCreatedAt =
                        getActiveDeploymentCreatedAt(func as Models.Function)
                      const showStatus = resourceHasVisibleStatus(
                        func as Models.Function,
                      )

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
                            className="block min-w-0"
                          >
                            <div
                              className={cn(
                                RESOURCE_CARD_PADDED_CLASSNAME,
                                RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                                'pb-0',
                              )}
                            >
                              <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                  <RuntimeIcon
                                    runtime={func.runtime || ''}
                                    size="md"
                                    className="h-5 w-5"
                                  />
                                </div>
                                <div className="min-w-0 flex-1 overflow-hidden">
                                  <h3 className="truncate text-[14px] font-medium text-foreground">
                                    {func.name || t('Unnamed Function')}
                                  </h3>
                                  <p className="mt-0.5 truncate text-[12px] text-muted-foreground whitespace-nowrap">
                                    {t(formatRuntimeLabel(func.runtime || ''))}
                                  </p>
                                  <div className="mt-1.5">
                                    <CopyableId
                                      id={func.$id}
                                      size="xs"
                                      maxWidth={120}
                                    />
                                  </div>
                                </div>
                              </div>

                              <FunctionExecutionsChartPreview
                                projectId={projectId!}
                                functionId={func.$id}
                                enabled={features.usageStats}
                              />

                              <div
                                className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}
                              >
                                <div className="flex min-w-0 flex-nowrap items-center gap-x-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                  {showStatus ? (
                                    <>
                                      <DeploymentResourceStatusBadges
                                        resource={func as Models.Function}
                                      />
                                      <span
                                        className="shrink-0 text-[10px] text-muted-foreground/40"
                                        aria-hidden
                                      >
                                        ·
                                      </span>
                                    </>
                                  ) : null}
                                  <div className="flex shrink-0 items-center gap-0.5">
                                    <span className="text-[12px] text-muted-foreground/70">
                                      {t('Deployed')}
                                    </span>
                                    <span className="text-[12px] font-medium text-muted-foreground">
                                      {activeDeploymentCreatedAt ? (
                                        <DateTooltip
                                          date={activeDeploymentCreatedAt}
                                          live
                                          className="text-[12px] font-medium text-muted-foreground"
                                        />
                                      ) : (
                                        t('Never')
                                      )}
                                    </span>
                                  </div>
                                  {func.schedule?.trim() ? (
                                    <div className="ms-auto flex min-w-0 shrink items-center gap-1">
                                      <Clock className="h-3 w-3 shrink-0 text-muted-foreground" />
                                      <span
                                        className="truncate text-[12px] font-medium text-muted-foreground"
                                        title={func.schedule}
                                      >
                                        {t(formatCronExpression(func.schedule))}
                                      </span>
                                    </div>
                                  ) : null}
                                </div>
                              </div>
                            </div>
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
                    setSelectedFunctions(new Set())
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
                    setSelectedFunctions(new Set())
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
                  itemLabel={t('functions')}
                />
              </>
            )}
        </>

        {selectedFunctions.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedFunctions.size}{' '}
                {selectedFunctions.size > 1 ? t('functions') : t('function')}{' '}
                {t('selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedFunctions(new Set())}
                  className="h-8 text-xs"
                >
                  {t('Cancel')}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  {t('Delete')}
                </Button>
              </div>
            </div>
          </div>
        )}

        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete functions')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {selectedFunctions.size}{' '}
                {selectedFunctions.size > 1 ? t('functions') : t('function')}?{' '}
                {t('This action cannot be undone.')}
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
