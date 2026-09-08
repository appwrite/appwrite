import { useState, useEffect, useMemo, useRef, Fragment } from 'react'
import {
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  Link,
} from '@tanstack/react-router'
import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query'
import { useTheme } from 'next-themes'
import { Globe } from 'lucide-react'
import {
  RESOURCE_CARD_GRID_4_COL_CLASSNAME,
  RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
} from '../shared/ResourceCard'
import { ServiceHeader } from '../shared/ServiceHeader'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useProjectSites,
  Dependencies,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  fetchProjectSites,
  SITES_DEFAULT_SORT_BY,
  SITES_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { canCreateSite } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { sdk, getSiteScreenshotFilePreviewUrl } from '@/lib/appwrite/sdk'
import { useAvifSupport } from '@/lib/avif-support'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { ImageFormat, type Models } from '@appwrite.io/console'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { SiteContextMenu } from './_components/SiteContextMenu'
import {
  SITE_SCREENSHOTS_BUCKET_ID,
  SITE_SCREENSHOT_CARD_WIDTH,
  SITE_SCREENSHOT_CARD_HEIGHT,
  SITE_SCREENSHOT_THUMB_WIDTH,
  SITE_SCREENSHOT_THUMB_HEIGHT,
} from '@/lib/sites/screenshot-preview-sizes'
import { SitesListPreviewCell } from './_components/SitesListPreviewCell'
import {
  DeploymentResourceStatusBadges,
  getActiveDeploymentCreatedAt,
  resourceHasInProgressDeployment,
  resourceHasVisibleStatus,
} from '../shared/DeploymentResourceStatusBadges'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
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
  sitesFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { useT } from '@/lib/i18n/translate'

type SitesListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}
function formatRuntimeImageLabel(runtime: string | undefined) {
  if (!runtime?.trim()) return undefined
  return runtime.split('-').join(' ')
}

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false })
  const queryClient = useQueryClient()
  const { theme, resolvedTheme } = useTheme()

  const isSitesIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/sites`
  const defaultSitesSort = {
    sortBy: SITES_DEFAULT_SORT_BY,
    sortOrder: SITES_DEFAULT_SORT_ORDER as 'asc' | 'desc',
  }
  const sitesListParams = useMemo(() => {
    if (!isSitesIndex || typeof search !== 'object') return null
    const url = urlFromRouterLocation(location, window.location.origin)
    const parsed =
      parseSort(search.sort as string | undefined) ??
      getSort(url) ??
      defaultSitesSort
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
  }, [isSitesIndex, search, location.pathname, location.search, projectId])

  const urlPage = sitesListParams?.page ?? 1
  const urlLimit = sitesListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = sitesListParams?.search
  const urlSortBy = sitesListParams?.sortBy ?? SITES_DEFAULT_SORT_BY
  const urlSortOrder = sitesListParams?.sortOrder ?? SITES_DEFAULT_SORT_ORDER
  const filterMap = sitesListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const { viewMode, setViewMode } = useServiceListViewMode('sites')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedSortBy, setDisplayedSortBy] = useState(SITES_DEFAULT_SORT_BY)
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    SITES_DEFAULT_SORT_ORDER,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [selectedSites, setSelectedSites] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [loadedScreenshots, setLoadedScreenshots] = useState<Set<string>>(
    new Set(),
  )
  const avifSupported = useAvifSupport()

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isSitesIndex) return
    setRequestedPage((p) => (p === urlPage ? p : urlPage))
  }, [isSitesIndex, urlPage])

  useEffect(() => {
    if (!isSitesIndex || !sitesListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [
    isSitesIndex,
    sitesListParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  useEffect(() => {
    if (!isSitesIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      // Only update URL (and trigger API) when cleared or at least MIN_SEARCH_LENGTH chars
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigateToSitesList({
        search: trimmed || undefined,
        query: filterQueryString || undefined,
        page: 1,
        limit: urlLimit,
        sort:
          urlSortBy !== SITES_DEFAULT_SORT_BY ||
          urlSortOrder !== SITES_DEFAULT_SORT_ORDER
            ? encodeSort(urlSortBy, urlSortOrder)
            : undefined,
      })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [
    searchInput,
    urlSearch,
    urlLimit,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
    isSitesIndex,
    navigate,
    projectId,
  ])

  const navigateToSitesList = (params: SitesListSearch) => {
    const hasQueryKey = 'query' in params
    const hasSearchKey = 'search' in params
    const hasSortKey = 'sort' in params
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: hasSearchKey ? params.search : (urlSearch ?? undefined),
          query: hasQueryKey ? params.query : filterQueryString || undefined,
          page: params.page ?? 1,
          limit: params.limit ?? urlLimit,
          sort: hasSortKey
            ? params.sort
            : urlSortBy !== SITES_DEFAULT_SORT_BY ||
                urlSortOrder !== SITES_DEFAULT_SORT_ORDER
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

  const handleSitesSortChange = (sortBy: string, sortOrder: 'asc' | 'desc') => {
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        sortBy !== SITES_DEFAULT_SORT_BY ||
        sortOrder !== SITES_DEFAULT_SORT_ORDER
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
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: mapToQueryParam(next) || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== SITES_DEFAULT_SORT_BY ||
        urlSortOrder !== SITES_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(compactKey)
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== SITES_DEFAULT_SORT_BY ||
        urlSortOrder !== SITES_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const clearAllFilters = () => {
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== SITES_DEFAULT_SORT_BY ||
        urlSortOrder !== SITES_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
    setFiltersOpen(false)
  }

  // Get theme for screenshot selection
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true // Default to dark during SSR
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  // Reset loaded screenshots when theme changes
  useEffect(() => {
    setLoadedScreenshots(new Set())
  }, [isDark])

  // Helper to get screenshot URL at display-appropriate size (theme matches app)
  const getScreenshotUrl = (
    site: Models.Site,
    size: 'thumb' | 'card',
  ) => {
    const screenshotId = isDark
      ? (site as unknown).deploymentScreenshotDark
      : (site as unknown).deploymentScreenshotLight
    if (!screenshotId || !projectId) return null
    const width =
      size === 'thumb' ? SITE_SCREENSHOT_THUMB_WIDTH : SITE_SCREENSHOT_CARD_WIDTH
    const height =
      size === 'thumb'
        ? SITE_SCREENSHOT_THUMB_HEIGHT
        : SITE_SCREENSHOT_CARD_HEIGHT
    return getSiteScreenshotFilePreviewUrl(projectId, {
      bucketId: SITE_SCREENSHOTS_BUCKET_ID,
      fileId: screenshotId,
      width,
      height,
      output: avifSupported ? ImageFormat.Avif : undefined,
    })
  }

  const {
    total: sitesTotal,
    isLoading: sitesLoading,
    isFetching: sitesFetching,
    isFetched: sitesFetched,
  } = useProjectSites(
    projectId,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )

  const {
    sites: apiSites,
    total: displayedTotal,
    isLoading: displayedLoading,
    refetch: refetchDisplayedSites,
  } = useProjectSites(
    projectId,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
    displayedSortBy,
    displayedSortOrder,
  )

  useEffect(() => {
    if (!isSitesIndex || sitesFetching || sitesLoading || !sitesFetched) return
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
    isSitesIndex,
    sitesFetching,
    sitesLoading,
    sitesFetched,
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

  // Only show full loading when we have no data to display (initial load)
  const showLoading = displayedLoading && apiSites.length === 0

  const { data: totalSitesData } = useQuery({
    queryKey: [
      'sites',
      'project',
      projectId,
      0,
      urlLimit,
      undefined,
      undefined,
      SITES_DEFAULT_SORT_BY,
      SITES_DEFAULT_SORT_ORDER,
    ],
    queryFn: () =>
      fetchProjectSites(
        projectId!,
        0,
        urlLimit,
        undefined,
        undefined,
        SITES_DEFAULT_SORT_BY,
        SITES_DEFAULT_SORT_ORDER,
      ),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    refetchOnMount: false,
  })

  const paginatedSites = apiSites

  const hasInProgressDeployment = useMemo(
    () =>
      paginatedSites.some((site) =>
        resourceHasInProgressDeployment(site as Models.Site),
      ),
    [paginatedSites],
  )

  useEffect(() => {
    if (!hasInProgressDeployment) return
    const interval = setInterval(() => {
      void refetchDisplayedSites()
    }, 5000)
    return () => clearInterval(interval)
  }, [hasInProgressDeployment, refetchDisplayedSites])

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  // Total count of all sites (without search) - for limit checking
  const totalSitesCount = totalSitesData?.total || 0

  // Check if create button should be disabled (plan limit or missing write scope)
  const noCreatePermission = !canCreateSite(access, features)
  const sitesLimit = organizationPlan?.sites ?? 0
  const isCreateDisabled =
    noCreatePermission || (sitesLimit > 0 && totalSitesCount >= sitesLimit)

  useEffect(() => {
    setSelectedSites(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, urlSearch])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedSites(new Set())
    // URL is updated by the debounced effect so we don't fetch on every keystroke
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (siteIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all sites in parallel
      await Promise.all(
        siteIds.map((siteId) => projectSdk.sites.delete({ siteId })),
      )
    },
    onSuccess: async () => {
      // Refetch sites list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.SITES,
      })
      toast.success(
        selectedSites.size === 1
          ? t('Site deleted successfully')
          : `${t('Successfully deleted')} ${selectedSites.size} ${t('sites')}`,
      )
      setSelectedSites(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete sites'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedSites.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedSites.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedSites))
  }

  const toggleSite = (siteId: string) => {
    const newSelected = new Set(selectedSites)
    if (newSelected.has(siteId)) {
      newSelected.delete(siteId)
    } else {
      newSelected.add(siteId)
    }
    setSelectedSites(newSelected)
  }

  const toggleAllSites = () => {
    if (selectedSites.size === paginatedSites.length) {
      setSelectedSites(new Set())
    } else {
      setSelectedSites(
        new Set(paginatedSites.map((s) => (s as Models.Site).$id)),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedSites(new Set())
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page,
      limit: urlLimit,
      sort:
        urlSortBy !== SITES_DEFAULT_SORT_BY ||
        urlSortOrder !== SITES_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedSites(new Set())
    navigateToSitesList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page: 1,
      limit: newPageSize,
      sort:
        urlSortBy !== SITES_DEFAULT_SORT_BY ||
        urlSortOrder !== SITES_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const ViewToggle = () => (
    <ServiceListViewToggle viewMode={viewMode} onViewModeChange={setViewMode} />
  )

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Sites')}
        searchPlaceholder={t('Search sites...')}
        searchValue={searchInput}
        onSearchChange={handleSearchChange}
        createLabel={t('Create site')}
        createAnalyticsAction="create-site"
        onCreate={() => {
          navigate({
            to: '/projects/$projectId/sites/create',
            params: { projectId: projectId! },
          })
        }}
        createDisabled={isCreateDisabled}
        createDisabledTooltip={
          noCreatePermission
            ? t("You don't have permission to create sites.")
            : undefined
        }
        showFilters={true}
        filterTrigger={
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={sitesFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearAllFilters}
            onApplyFilter={applyFilter}
            resourceLabel={t('sites')}
            filterScope="sites"
            onApplyQuery={(queryParam, sortParam) =>
              navigateToSitesList({
                search: urlSearch ?? undefined,
                query: queryParam ?? undefined,
                page: 1,
                limit: urlLimit,
                sort: sortParam ?? undefined,
              })
            }
            sortBy={urlSortBy}
            sortOrder={urlSortOrder}
            onSortChange={handleSitesSortChange}
            defaultSortParam={encodeSort(
              SITES_DEFAULT_SORT_BY,
              SITES_DEFAULT_SORT_ORDER,
            )}
            onReset={() => {
              navigate({
                to: '/projects/$projectId/sites',
                params: { projectId: projectId! },
                search: { page: 1, limit: urlLimit },
                replace: true,
              })
            }}
            teamId={project?.teamId}
          />
        }
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          project && totalSitesData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalSitesCount}
              limit={sitesLimit}
              planName={resolveOrganizationPlanDisplayLabel({
                planName: organizationPlan?.name ?? null,
                planId: organizationPlan?.$id,
              })}
              resourceName={t('sites')}
              orgId={project?.teamId}
              fullWidth={false}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'list' ? (
          showLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                {t('Loading sites...')}
              </p>
            </div>
          ) : paginatedSites.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedSites.length > 0 &&
                            selectedSites.size === paginatedSites.length
                          }
                          onCheckedChange={toggleAllSites}
                        />
                      </TableHead>
                      <TableHead className="w-[120px] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Preview')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Site')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Status')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Last deployed')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                        {t('Created')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                        {t('Updated')}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedSites.map((site) => {
                      const siteData = site as Models.Site
                      if (!siteData?.$id) return null
                      const runtimeImageLabel = formatRuntimeImageLabel(
                        siteData.buildRuntime,
                      )
                      return (
                        <SiteContextMenu
                          key={siteData.$id}
                          projectId={projectId!}
                          site={{ $id: siteData.$id, name: siteData.name }}
                        >
                          <TableRow
                            className={cn(
                              'cursor-pointer transition-colors border-b border-border/50',
                              selectedSites.has(siteData.$id)
                                ? 'bg-muted'
                                : 'hover:bg-muted/30',
                            )}
                            onClick={(e: React.MouseEvent) => {
                              const target = e.target as HTMLElement
                              if (
                                target.closest('button') ||
                                target.closest('[role="checkbox"]') ||
                                target.closest('a')
                              ) {
                                return
                              }
                              navigate({
                                to: '/projects/$projectId/sites/$siteId/',
                                params: {
                                  projectId: projectId!,
                                  siteId: siteData.$id,
                                },
                              })
                            }}
                          >
                            <TableCell
                              onClick={(e) => e.stopPropagation()}
                              className="px-4 py-3"
                            >
                              <Checkbox
                                checked={selectedSites.has(siteData.$id)}
                                onCheckedChange={() => toggleSite(siteData.$id)}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <SitesListPreviewCell
                                site={siteData}
                                screenshotUrl={getScreenshotUrl(siteData, 'thumb')}
                                screenshotKey={`${siteData.$id}-${isDark ? 'dark' : 'light'}`}
                                isLoaded={loadedScreenshots.has(
                                  `${siteData.$id}-${isDark ? 'dark' : 'light'}`,
                                )}
                                onLoad={() => {
                                  setLoadedScreenshots((prev) =>
                                    new Set(prev).add(
                                      `${siteData.$id}-${isDark ? 'dark' : 'light'}`,
                                    ),
                                  )
                                }}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/sites/$siteId/"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteData.$id,
                                }}
                                className="block group"
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <FrameworkIcon
                                      framework={
                                        (siteData as unknown).buildFramework ||
                                        (siteData as unknown)
                                          .buildFrameworkId ||
                                        (siteData as unknown).framework
                                      }
                                      size="sm"
                                    />
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                                      {siteData.name || t('Unnamed Site')}
                                    </p>
                                    {runtimeImageLabel ? (
                                      <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                                        {runtimeImageLabel}
                                      </p>
                                    ) : null}
                                  </div>
                                </div>
                              </Link>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <DeploymentResourceStatusBadges resource={siteData} />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/sites/$siteId/"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteData.$id,
                                }}
                                className="block"
                              >
                                {(() => {
                                  const activeDeploymentCreatedAt =
                                    getActiveDeploymentCreatedAt(siteData)
                                  return activeDeploymentCreatedAt ? (
                                    <DateTooltip
                                      date={activeDeploymentCreatedAt}
                                      live
                                      className="text-[12px] text-muted-foreground"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-muted-foreground/50">
                                      {t('Never')}
                                    </span>
                                  )
                                })()}
                              </Link>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/sites/$siteId/"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteData.$id,
                                }}
                                className="block text-end"
                              >
                                {siteData.$createdAt ? (
                                  <DateTooltip
                                    date={siteData.$createdAt}
                                    className="text-[12px] text-muted-foreground font-mono"
                                  />
                                ) : (
                                  <span className="text-[12px] text-muted-foreground/50 italic">
                                    N/A
                                  </span>
                                )}
                              </Link>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/sites/$siteId/"
                                params={{
                                  projectId: projectId!,
                                  siteId: siteData.$id,
                                }}
                                className="block text-end"
                              >
                                {siteData.$updatedAt ? (
                                  <DateTooltip
                                    date={siteData.$updatedAt}
                                    className="text-[12px] text-muted-foreground font-mono"
                                  />
                                ) : (
                                  <span className="text-[12px] text-muted-foreground/50 italic">
                                    N/A
                                  </span>
                                )}
                              </Link>
                            </TableCell>
                          </TableRow>
                        </SiteContextMenu>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? sitesTotal}
                pageSize={urlLimit}
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('sites')}
              />
            </>
          ) : (
            <EmptyState
              icon={Globe}
              title={t('No sites yet')}
              description={t('Create your first site to get started')}
              isEmpty={
                !(urlSearch && urlSearch.length > 0) && filterMap.size === 0
              }
              hasFilters={
                (urlSearch && urlSearch.length > 0) || filterMap.size > 0
              }
              variant="card"
            />
          )
        ) : (
          <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading sites...')}
                </p>
              </div>
            ) : paginatedSites.length > 0 ? (
              <div className={RESOURCE_CARD_GRID_4_COL_CLASSNAME}>
                {paginatedSites.map((site) => {
                  const siteData = site as Models.Site
                  if (!siteData?.$id) return null
                  const screenshotUrl = getScreenshotUrl(siteData, 'card')
                  const activeDeploymentCreatedAt =
                    getActiveDeploymentCreatedAt(siteData)
                  const runtimeImageLabel = formatRuntimeImageLabel(
                    siteData.buildRuntime,
                  )
                  const siteFramework =
                    (siteData as unknown).buildFramework ||
                    (siteData as unknown).buildFrameworkId ||
                    (siteData as unknown).framework
                  const frameworkPreviewBadge = (
                    <div className="absolute bottom-1.5 start-1.5 z-10">
                      <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/50 bg-background/95 shadow-sm backdrop-blur-sm">
                        <FrameworkIcon framework={siteFramework} size="sm" />
                      </div>
                    </div>
                  )
                  const metadataItems = [
                    ...(resourceHasVisibleStatus(siteData)
                      ? [
                          {
                            label: '',
                            value: (
                              <DeploymentResourceStatusBadges
                                resource={siteData}
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
                  ]
                  return (
                    <SiteContextMenu
                      key={siteData.$id}
                      projectId={projectId!}
                      site={{ $id: siteData.$id, name: siteData.name }}
                    >
                      <Link
                        to="/projects/$projectId/sites/$siteId/"
                        params={{ projectId, siteId: siteData.$id }}
                        className="block min-w-0 group"
                      >
                        <div
                          className={cn(
                            RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
                            'h-auto',
                          )}
                        >
                          {/* Preview Image */}
                          <div className="p-1.5 pb-0">
                            <div className="relative aspect-video w-full overflow-hidden rounded-md border border-border/60 bg-muted">
                              {screenshotUrl ? (
                                <>
                                  {(() => {
                                    const screenshotKey = `${siteData.$id}-${isDark ? 'dark' : 'light'}`
                                    const isLoaded =
                                      loadedScreenshots.has(screenshotKey)
                                    return (
                                      <>
                                        {!isLoaded ? (
                                          <div
                                            className="absolute inset-0 animate-pulse bg-muted"
                                            aria-hidden
                                          />
                                        ) : null}
                                        <img
                                          src={screenshotUrl}
                                          alt={`${siteData.name || t('Site')} ${t('preview')}`}
                                          onLoad={() => {
                                            setLoadedScreenshots((prev) =>
                                              new Set(prev).add(screenshotKey),
                                            )
                                          }}
                                          className={cn(
                                            'relative h-full w-full rounded-md object-cover object-top transition-opacity duration-500',
                                            isLoaded
                                              ? 'opacity-100'
                                              : 'opacity-0',
                                          )}
                                        />
                                      </>
                                    )
                                  })()}
                                </>
                              ) : (
                                <>
                                  <div className="absolute inset-0 bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20" />
                                  <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(0,0,0,0.02),transparent_70%)] dark:bg-[radial-gradient(circle_at_50%_50%,rgba(255,255,255,0.02),transparent_70%)]" />
                                  <p className="relative flex h-full items-center justify-center text-[12px] font-medium text-muted-foreground/60">
                                    {t('Preview not available')}
                                  </p>
                                </>
                              )}
                              {frameworkPreviewBadge}
                            </div>
                          </div>
                          {/* Card Content */}
                          <div className="px-4 pt-4 pb-0">
                            <div className="min-w-0">
                              <h3 className="truncate text-[14px] font-medium text-foreground">
                                {siteData.name || t('Unnamed Site')}
                              </h3>
                              {runtimeImageLabel ? (
                                <p
                                  className="mt-0.5 text-[12px] leading-snug text-muted-foreground"
                                  title={runtimeImageLabel}
                                >
                                  {runtimeImageLabel}
                                </p>
                              ) : null}
                            </div>
                            <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
                              <div className="flex min-w-0 flex-nowrap items-center gap-x-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                {metadataItems.map((item, index) => (
                                  <Fragment key={index}>
                                    {index > 0 && item.align !== 'right' ? (
                                      <span
                                        className="shrink-0 text-[10px] text-muted-foreground/40"
                                        aria-hidden
                                      >
                                        ·
                                      </span>
                                    ) : null}
                                    <div
                                      className={cn(
                                        'flex shrink-0 items-center gap-0.5',
                                        item.align === 'right' && 'ms-auto',
                                      )}
                                    >
                                      {item.label ? (
                                        <span className="text-[12px] text-muted-foreground/70">
                                          {item.label}
                                        </span>
                                      ) : null}
                                      <span className="text-[12px] font-medium text-muted-foreground">
                                        {item.value}
                                      </span>
                                    </div>
                                  </Fragment>
                                ))}
                              </div>
                            </div>
                          </div>
                        </div>
                      </Link>
                    </SiteContextMenu>
                  )
                })}
              </div>
            ) : (
              <EmptyState
                icon={Globe}
                title={t('No sites yet')}
                description={t('Create your first site to get started')}
                isEmpty={!(urlSearch || filterMap.size > 0)}
                hasFilters={!!(urlSearch || filterMap.size > 0)}
                variant="card"
              />
            )}
            {!showLoading && paginatedSites.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? sitesTotal}
                pageSize={urlLimit}
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('sites')}
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedSites.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedSites.size}{' '}
                {selectedSites.size > 1 ? t('sites') : t('site')}{' '}
                {t('selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedSites(new Set())}
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

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete Sites')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {selectedSites.size}{' '}
                {selectedSites.size > 1 ? t('sites') : t('site')}?{' '}
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
