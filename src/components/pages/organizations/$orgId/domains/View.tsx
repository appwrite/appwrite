import { useState, useEffect, useMemo, useRef } from 'react'
import { Globe, Search, Plus, ShoppingCart, ArrowLeftRight } from 'lucide-react'
import {
  useOrganizationDomains,
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  domainRecordsQueryOptions,
  DNS_RECORDS_DEFAULT_SORT_BY,
  DNS_RECORDS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
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
  domainsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Link,
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { CreateDomainDialog } from './CreateDomain'
import { DomainContextMenu } from './_components/DomainContextMenu'
import { useOrganizationDomainsPlanLimit } from './_components/useOrganizationDomainsPlanLimit'
import {
  useCreateOrganizationDomain,
  useDeleteOrganizationDomain,
} from '@/lib/react-query/hooks'
import {
  GRID_DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'

type DomainsListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

export function View() {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false })
  const queryClient = useQueryClient()
  const [isCreateDomainSubmitting, setIsCreateDomainSubmitting] =
    useState(false)

  const isDomainsIndex =
    location.pathname.replace(/\/$/, '') === `/organizations/${orgId}/domains`
  const defaultDomainsSort = {
    sortBy: DOMAINS_DEFAULT_SORT_BY,
    sortOrder: DOMAINS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
  }
  const domainsListParams = useMemo(() => {
    if (!isDomainsIndex || typeof search !== 'object') return null
    const url = urlFromRouterLocation(location, window.location.origin)
    const parsed =
      parseSort(search.sort as string | undefined) ??
      getSort(url) ??
      defaultDomainsSort
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
  }, [isDomainsIndex, search, location.pathname, location.search, orgId])

  const urlPage = domainsListParams?.page ?? 1
  const urlLimit = domainsListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = domainsListParams?.search
  const urlSortBy = domainsListParams?.sortBy ?? DOMAINS_DEFAULT_SORT_BY
  const urlSortOrder =
    domainsListParams?.sortOrder ?? DOMAINS_DEFAULT_SORT_ORDER
  const filterMap = domainsListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedSortBy, setDisplayedSortBy] = useState(
    DOMAINS_DEFAULT_SORT_BY,
  )
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    DOMAINS_DEFAULT_SORT_ORDER,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [selectedDomains, setSelectedDomains] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isDomainsIndex) return
    setRequestedPage((p) => (p === urlPage ? p : urlPage))
  }, [isDomainsIndex, urlPage])

  useEffect(() => {
    if (!isDomainsIndex || !domainsListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [
    isDomainsIndex,
    domainsListParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  useEffect(() => {
    if (!isDomainsIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigateToDomainsList({
        search: trimmed || undefined,
        query: filterQueryString || undefined,
        page: 1,
        limit: urlLimit,
        sort:
          urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
          urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
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
    isDomainsIndex,
    navigate,
    orgId,
  ])

  const navigateToDomainsList = (params: DomainsListSearch) => {
    const hasQueryKey = 'query' in params
    const hasSearchKey = 'search' in params
    const hasSortKey = 'sort' in params
    navigate({
      to: '/organizations/$orgId/domains',
      params: { orgId: orgId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: hasSearchKey ? params.search : (urlSearch ?? undefined),
          query: hasQueryKey ? params.query : filterQueryString || undefined,
          page: params.page ?? 1,
          limit: params.limit ?? urlLimit,
          sort: hasSortKey
            ? params.sort
            : urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
                urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
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

  const handleDomainsSortChange = (
    sortBy: string,
    sortOrder: 'asc' | 'desc',
  ) => {
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        sortBy !== DOMAINS_DEFAULT_SORT_BY ||
        sortOrder !== DOMAINS_DEFAULT_SORT_ORDER
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
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: mapToQueryParam(next) || undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
        urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const removeFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(compactKey)
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
        urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const clearAllFilters = () => {
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: undefined,
      page: 1,
      limit: urlLimit,
      sort:
        urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
        urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
    setFiltersOpen(false)
  }

  const {
    total: domainsTotal,
    isLoading: domainsLoading,
    isFetching: domainsFetching,
    isFetched: domainsFetched,
  } = useOrganizationDomains(
    orgId,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )

  const {
    domains: apiDomains,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useOrganizationDomains(
    orgId,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
    displayedSortBy,
    displayedSortOrder,
  )

  useEffect(() => {
    if (!isDomainsIndex || domainsFetching || domainsLoading || !domainsFetched)
      return
    const match =
      requestedPage === displayedPage &&
      (urlSearch ?? '') === (displayedSearch ?? '') &&
      filterQueryString === displayedFilterQueryString &&
      urlSortBy === displayedSortBy &&
      urlSortOrder === displayedSortOrder
    if (!match) {
      setDisplayedPage(requestedPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
    }
  }, [
    isDomainsIndex,
    domainsFetching,
    domainsLoading,
    domainsFetched,
    requestedPage,
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

  const showLoading = displayedLoading && apiDomains.length === 0
  const paginationTotal = displayedTotal ?? domainsTotal

  // Paginated data
  const paginatedDomains = apiDomains
  const {
    limit: domainsLimit,
    isAtLimit: isDomainLimitReached,
  } = useOrganizationDomainsPlanLimit(orgId)
  const domainLimitTooltip = isDomainLimitReached
    ? `${t('Your current plan includes up to')} ${domainsLimit} ${t('domains')}.`
    : undefined

  useEffect(() => {
    setSelectedDomains(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, orgId, urlSearch])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setRequestedPage(1)
    setSelectedDomains(new Set())
    // URL is updated by the debounced effect so we don't fetch on every keystroke
  }

  // Delete domain mutation (for bulk delete)
  const deleteDomainMutation = useDeleteOrganizationDomain(orgId)

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (domainIds: string[]) => {
      if (!orgId) {
        throw new Error('Organization ID is required')
      }
      await Promise.all(
        domainIds.map((domainId) => deleteDomainMutation.mutateAsync(domainId)),
      )
    },
    onSuccess: async () => {
      // Refetch domains list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      toast.success(
        `${t('Successfully deleted')} ${selectedDomains.size} ${selectedDomains.size > 1 ? t('domains') : t('domain')}`,
      )
      setSelectedDomains(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete domains'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedDomains.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedDomains.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedDomains))
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedDomains(new Set())
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page,
      limit: urlLimit,
      sort:
        urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
        urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedDomains(new Set())
    navigateToDomainsList({
      search: urlSearch ?? undefined,
      query: filterQueryString || undefined,
      page: 1,
      limit: newPageSize,
      sort:
        urlSortBy !== DOMAINS_DEFAULT_SORT_BY ||
        urlSortOrder !== DOMAINS_DEFAULT_SORT_ORDER
          ? encodeSort(urlSortBy, urlSortOrder)
          : undefined,
    })
  }

  // Create domain mutation
  const createDomainMutation = useCreateOrganizationDomain(orgId)

  const handleCreateDomain = async (domain: string) => {
    if (!orgId) return
    if (isDomainLimitReached) {
      toast.error(
        `${t('Your current plan includes up to')} ${domainsLimit} ${t('domains')}.`,
      )
      return
    }
    setIsCreateDomainSubmitting(true)
    try {
      const createdDomain = await createDomainMutation.mutateAsync(domain)
      const domainId = createdDomain.$id
      queryClient.setQueryData(['domain', domainId], createdDomain)
      await queryClient.ensureQueryData(
        domainRecordsQueryOptions(
          domainId,
          0,
          ROWS_DEFAULT_PAGE_SIZE,
          undefined,
          DNS_RECORDS_DEFAULT_SORT_BY,
          DNS_RECORDS_DEFAULT_SORT_ORDER,
        ),
      )
      toast.success(`${createdDomain.domain} ${t('has been created')}`)
      await navigate({
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId: orgId!, domainId },
      })
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setIsCreateDomainSubmitting(false)
    }
  }

  return (
    <div className="flex h-full flex-col">
      {/* Toolbar: Search + Filters + View Toggle (start) | Buy + Add (end) */}
      <div className="mb-4 flex items-center gap-3">
        <div className="flex items-center gap-2">
          <div className="relative w-64">
            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t('Search domains...')}
              value={searchInput}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="h-9 border-border bg-accent/50 ps-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={domainsFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearAllFilters}
            onApplyFilter={applyFilter}
            resourceLabel="domains"
            filterScope="organizations.domains"
            onApplyQuery={(queryParam, sortParam) =>
              navigateToDomainsList({
                search: urlSearch ?? undefined,
                query: queryParam ?? undefined,
                page: 1,
                limit: urlLimit,
                sort: sortParam ?? undefined,
              })
            }
            sortBy={urlSortBy}
            sortOrder={urlSortOrder}
            onSortChange={handleDomainsSortChange}
            defaultSortParam={encodeSort(
              DOMAINS_DEFAULT_SORT_BY,
              DOMAINS_DEFAULT_SORT_ORDER,
            )}
            onReset={() => {
              navigate({
                to: '/organizations/$orgId/domains',
                params: { orgId: orgId! },
                search: { page: 1, limit: urlLimit },
                replace: true,
              })
            }}
            teamId={orgId}
          />
        </div>
        <div className="ms-auto flex items-center gap-2">
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">
                  <Button
                    variant="outline"
                    disabled={isDomainLimitReached}
                    onClick={() =>
                      navigate({
                        to: '/organizations/$orgId/domains/transfer-in',
                        params: { orgId: orgId! },
                      })
                    }
                    className="h-9 gap-1.5 text-[13px] font-medium"
                  >
                    <ArrowLeftRight className="h-4 w-4" />
                    {t('Transfer in')}
                  </Button>
                </span>
              </TooltipTrigger>
              {domainLimitTooltip ? (
                <TooltipContent className="max-w-xs text-xs">
                  {domainLimitTooltip}
                </TooltipContent>
              ) : null}
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">
                  <Button
                    variant="outline"
                    disabled={isDomainLimitReached}
                    onClick={() =>
                      navigate({
                        to: '/organizations/$orgId/domains/buy',
                        params: { orgId: orgId! },
                      })
                    }
                    className="h-9 gap-1.5 text-[13px] font-medium"
                    {...analyticsAttrs('buy-domain')}
                  >
                    <ShoppingCart className="h-4 w-4" />
                    {t('Buy domain')}
                  </Button>
                </span>
              </TooltipTrigger>
              {domainLimitTooltip ? (
                <TooltipContent className="max-w-xs text-xs">
                  {domainLimitTooltip}
                </TooltipContent>
              ) : null}
            </Tooltip>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="inline-flex">
                  <Button
                    variant="brandCta"
                    onClick={() => setCreateDialogOpen(true)}
                    disabled={isDomainLimitReached}
                    className="h-9 gap-1.5 text-[13px] font-medium"
                    {...analyticsAttrs('add-org-domain')}
                  >
                    <Plus className="h-4 w-4" />
                    {t('Add domain')}
                  </Button>
                </span>
              </TooltipTrigger>
              {domainLimitTooltip ? (
                <TooltipContent className="max-w-xs text-xs">
                  {domainLimitTooltip}
                </TooltipContent>
              ) : null}
            </Tooltip>
          </TooltipProvider>
        </div>
      </div>

      <div className="flex-1">
        {showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Loading domains...')}
            </p>
          </div>
        ) : paginatedDomains.length > 0 ? (
          <>
            <div className={RESOURCE_CARD_GRID_CLASSNAME}>
              {paginatedDomains.map((domain) => (
                  <DomainContextMenu
                    key={domain.$id}
                    orgId={orgId!}
                    domain={domain}
                  >
                    <Link
                      to="/organizations/$orgId/domains/$domainId"
                      params={{ orgId: orgId!, domainId: domain.$id }}
                    >
                      <ResourceCard
                        title={domain.domain}
                        resourceId={domain.$id}
                        metadata={[
                          {
                            label: t('Nameservers'),
                            value: (
                              <span className="text-[11px] font-medium text-muted-foreground">
                                {domain.nameservers || t('3rd party')}
                              </span>
                            ),
                          },
                          {
                            label: t('Created'),
                            value: (
                              <DateTooltip
                                date={domain.$createdAt}
                                className="text-[11px] font-medium text-muted-foreground"
                              />
                            ),
                          },
                        ]}
                      />
                    </Link>
                  </DomainContextMenu>
                ))}
            </div>
            {!showLoading && paginatedDomains.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={paginationTotal}
                pageSize={urlLimit}
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('domains')}
              />
            )}
          </>
        ) : (
          <EmptyState
            icon={Globe}
            title={t('No domains yet')}
            description={t('Create your first domain to get started')}
            isEmpty={!(urlSearch || filterMap.size > 0)}
            hasFilters={!!(urlSearch || filterMap.size > 0)}
            variant="card"
          />
        )}

        {/* Bulk Delete Action Bar */}
        {selectedDomains.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDomains.size}{' '}
                {selectedDomains.size > 1 ? t('domains') : t('domain')}{' '}
                {t('selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedDomains(new Set())}
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
              <DialogTitle>
                {selectedDomains.size > 1
                  ? t('Delete Domains')
                  : t('Delete Domain')}
              </DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {selectedDomains.size}{' '}
                {selectedDomains.size > 1 ? t('domains') : t('domain')}?{' '}
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

      {/* Create Domain Dialog */}
      <CreateDomainDialog
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onCreate={handleCreateDomain}
        isLoading={isCreateDomainSubmitting}
      />
    </div>
  )
}
