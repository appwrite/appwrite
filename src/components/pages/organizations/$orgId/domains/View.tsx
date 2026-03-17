import { useState, useEffect, useMemo, useRef } from 'react'
import {
  Globe,
  MoreHorizontal,
  CheckCircle2,
  AlertCircle,
  Search,
  Plus,
  ShoppingCart,
} from 'lucide-react'
import {
  useOrganizationDomains,
  fetchOrganizationDomains,
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
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
  MIN_SEARCH_LENGTH,
  domainsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { ResourceCard } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
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
import { CreateDomainDialog } from './CreateDomain'
import { RetryVerification } from './RetryVerification'
import type { Models } from '@appwrite.io/console'
import {
  useCreateOrganizationDomain,
  useDeleteOrganizationDomain,
  useRetryDomainVerification,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

type DomainsListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

export function View() {
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false })
  const queryClient = useQueryClient()

  const isDomainsIndex =
    location.pathname.replace(/\/$/, '') === `/organizations/${orgId}/domains`
  const defaultDomainsSort = {
    sortBy: DOMAINS_DEFAULT_SORT_BY,
    sortOrder: DOMAINS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
  }
  const domainsListParams = useMemo(() => {
    if (!isDomainsIndex || typeof search !== 'object') return null
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    const parsed =
      parseSort(search.sort as string | undefined) ??
      getSort(url) ??
      defaultDomainsSort
    // Prefer router search state (updated by navigate()) over URL so page size change takes effect even if URL lags
    const pageFromSearch =
      search.page != null
        ? (typeof search.page === 'number'
            ? search.page
            : Number(search.page))
        : undefined
    const limitFromSearch =
      search.limit != null
        ? (typeof search.limit === 'number'
            ? search.limit
            : Number(search.limit))
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
  const [retryDialogOpen, setRetryDialogOpen] = useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [selectedDomain, setSelectedDomain] = useState<Models.Domain | null>(
    null,
  )

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

  const applyFilter = (compactKey: CompactFilterKey, queryStr: string) => {
    const next = new Map(filterMap)
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

  useEffect(() => {
    setSelectedDomains(new Set())
    setDeleteDialogOpen(false)
    setRetryDialogOpen(false)
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
        `Successfully deleted ${selectedDomains.size} domain${selectedDomains.size > 1 ? 's' : ''}`,
      )
      setSelectedDomains(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete domains')
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

  const toggleDomain = (domainId: string) => {
    const newSelected = new Set(selectedDomains)
    if (newSelected.has(domainId)) {
      newSelected.delete(domainId)
    } else {
      newSelected.add(domainId)
    }
    setSelectedDomains(newSelected)
  }

  const toggleAllDomains = () => {
    if (selectedDomains.size === paginatedDomains.length) {
      setSelectedDomains(new Set())
    } else {
      setSelectedDomains(new Set(paginatedDomains.map((d) => d.$id)))
    }
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

  const handleCreateDomain = (domain: string) => {
    createDomainMutation.mutate(domain, {
      onSuccess: (createdDomain) => {
        toast.success(`${createdDomain.domain} has been created`)
        setCreateDialogOpen(false)
        navigate({
          to: '/organizations/$orgId/domains/$domainId',
          params: { orgId: orgId!, domainId: createdDomain.$id },
        })
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Retry verification mutation
  const retryVerificationMutation = useRetryDomainVerification(orgId)

  const handleRetryVerification = (domainId: string) => {
    retryVerificationMutation.mutate(domainId, {
      onSuccess: (domain) => {
        const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
        if (isVerified) {
          toast.success('Domain verification successful')
        } else {
          toast.success('Nameservers updated. Please wait for DNS propagation.')
        }
        setRetryDialogOpen(false)
        setSelectedDomain(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error))
      },
    })
  }

  // Get verification status
  const getVerificationStatus = (domain: Models.Domain) => {
    const isVerified = domain.nameservers?.toLowerCase() === 'appwrite'
    if (isVerified) {
      return {
        status: 'verified' as const,
        icon: CheckCircle2,
        label: 'Verified',
        className: 'text-green-600 dark:text-green-500',
      }
    }
    return {
      status: 'unverified' as const,
      icon: AlertCircle,
      label: 'Unverified',
      className: 'text-yellow-600 dark:text-yellow-500',
    }
  }

  return (
    <div className="flex h-full flex-col">
      {/* Toolbar: Search + Filters + View Toggle (start) | Buy + Add (end) */}
      <div className="mb-4 flex items-center gap-3">
        <div className="flex items-center gap-2">
          <div className="relative w-64">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search domains..."
              value={searchInput}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="h-9 border-border bg-accent/50 pl-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
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
        <div className="ml-auto flex items-center gap-2">
          <Button
            variant="outline"
            asChild
            className="h-9 gap-1.5 text-[13px] font-medium"
          >
            <Link
              to="/organizations/$orgId/domains/buy"
              params={{ orgId: orgId! }}
            >
              <ShoppingCart className="h-4 w-4" />
              Buy domain
            </Link>
          </Button>
          <Button
            onClick={() => setCreateDialogOpen(true)}
            className="h-9 gap-1.5 text-[13px] font-medium text-white hover:opacity-90"
            style={{ backgroundColor: '#f02e65' }}
          >
            <Plus className="h-4 w-4" />
            Add domain
          </Button>
        </div>
      </div>

      <div className="flex-1">
        {showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              Loading domains...
            </p>
          </div>
        ) : paginatedDomains.length > 0 ? (
          <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {paginatedDomains.map((domain) => {
                const verification = getVerificationStatus(domain)
                return (
                  <Link
                    key={domain.$id}
                    to="/organizations/$orgId/domains/$domainId"
                    params={{ orgId, domainId: domain.$id }}
                  >
                    <ResourceCard
                      title={domain.domain}
                      resourceId={domain.$id}
                      icon={Globe}
                      iconColor="bg-muted text-muted-foreground"
                      status={
                        verification.status === 'verified'
                          ? 'success'
                          : 'warning'
                      }
                      statusLabel={verification.label}
                      metadata={[
                        {
                          label: 'Nameservers',
                          value: (
                            <span className="text-[11px] font-medium text-muted-foreground">
                              {domain.nameservers || '—'}
                            </span>
                          ),
                        },
                        {
                          label: 'Created',
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
                )
              })}
            </div>
            {!showLoading && paginatedDomains.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={paginationTotal}
                pageSize={urlLimit}
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="domains"
              />
            )}
          </>
        ) : (
          <EmptyState
            icon={Globe}
            title="No domains yet"
            description="Create your first domain to get started"
            isEmpty={!(urlSearch || filterMap.size > 0)}
            hasFilters={!!(urlSearch || filterMap.size > 0)}
            variant="card"
          />
        )}

        {/* Bulk Delete Action Bar */}
        {selectedDomains.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDomains.size} domain
                {selectedDomains.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedDomains(new Set())}
                  className="h-8 text-xs"
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  Delete
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>
                Delete Domain{selectedDomains.size > 1 ? 's' : ''}
              </DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedDomains.size} domain
                {selectedDomains.size > 1 ? 's' : ''}? This action cannot be
                undone.
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                Delete
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
        isLoading={createDomainMutation.isPending}
      />

      {/* Retry Verification Dialog */}
      {selectedDomain && (
        <RetryVerification
          open={retryDialogOpen}
          onOpenChange={setRetryDialogOpen}
          domain={selectedDomain}
          onRetry={handleRetryVerification}
          isLoading={retryVerificationMutation.isPending}
        />
      )}
    </div>
  )
}
