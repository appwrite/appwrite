import { useState, useEffect, useRef, useMemo } from 'react'
import { cn } from '@/lib/utils'
import { FolderOpen, List, LayoutGrid, Lock, Folder } from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useProjectBuckets,
  Dependencies,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  fetchProjectBuckets,
  BUCKETS_DEFAULT_SORT_BY,
  BUCKETS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { canCreateBucket } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ResourceCard } from '../shared/ResourceCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
  Link,
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateBucket } from './_components/CreateBucket'
import type { Models } from '@appwrite.io/console'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { BucketContextMenu } from './_components/BucketContextMenu'
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
  bucketsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'

export function View() {
  const { projectId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as {
    create?: string
    search?: string
    query?: string
    page?: number
    limit?: number
    sort?: string
  }
  const queryClient = useQueryClient()

  const isStorageIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/storage`
  const bucketListParams = useMemo(() => {
    if (!isStorageIndex || typeof search !== 'object') return null
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    const defaultSort = {
      sortBy: BUCKETS_DEFAULT_SORT_BY,
      sortOrder: BUCKETS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
    }
    const parsed = parseSort(search.sort) ?? getSort(url) ?? defaultSort
    return {
      search: getSearch(url) ?? search.search,
      page: getPage(url, 1),
      limit: getLimit(url, DEFAULT_PAGE_SIZE),
      filterMap: queryParamToMap(getQueryParam(url) ?? search.query ?? null),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [
    isStorageIndex,
    search?.search,
    search?.query,
    search?.page,
    search?.limit,
    search?.sort,
    location.pathname,
    location.search,
  ])

  const urlPage = bucketListParams?.page ?? 1
  const urlLimit = bucketListParams?.limit ?? DEFAULT_PAGE_SIZE
  const urlSearch = bucketListParams?.search
  const urlSortBy = bucketListParams?.sortBy ?? BUCKETS_DEFAULT_SORT_BY
  const urlSortOrder = bucketListParams?.sortOrder ?? BUCKETS_DEFAULT_SORT_ORDER
  const filterMap = bucketListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedSortBy, setDisplayedSortBy] = useState(
    BUCKETS_DEFAULT_SORT_BY,
  )
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    BUCKETS_DEFAULT_SORT_ORDER,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [createBucketDialogOpen, setCreateBucketDialogOpen] = useState(false)
  const [selectedBuckets, setSelectedBuckets] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const [filtersOpen, setFiltersOpen] = useState(false)
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  // Open create bucket dialog when ?create=bucket (e.g. from header plus button)
  useEffect(() => {
    if (search?.create === 'bucket' && !createBucketDialogOpen) {
      setCreateBucketDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createBucketDialogOpen, navigate, location.pathname])

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isStorageIndex || !bucketListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [
    isStorageIndex,
    bucketListParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  useEffect(() => {
    if (!isStorageIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigate({
        to: '/projects/$projectId/storage/',
        params: { projectId: projectId! },
        search: (prev: Record<string, unknown>) => {
          const next = {
            ...prev,
            ...buildListSearchParams({
              search: trimmed || undefined,
              query: filterQueryString || undefined,
              page: 1,
              limit: urlLimit,
              sort:
                urlSortBy !== BUCKETS_DEFAULT_SORT_BY ||
                urlSortOrder !== BUCKETS_DEFAULT_SORT_ORDER
                  ? encodeSort(urlSortBy, urlSortOrder)
                  : undefined,
            }),
          }
          if (!trimmed) delete next.search
          return next
        },
        replace: true,
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
    isStorageIndex,
  ])

  // Fetch data for the requested page (URL page)
  const {
    total: bucketsTotal,
    isLoading: bucketsLoading,
    isFetching: bucketsFetching,
    isFetched: bucketsFetched,
  } = useProjectBuckets(
    projectId,
    urlPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )

  const {
    buckets: apiBuckets,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useProjectBuckets(
    projectId,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
    displayedSortBy,
    displayedSortOrder,
  )

  const showLoading = displayedLoading && apiBuckets.length === 0

  useEffect(() => {
    if (!isStorageIndex || bucketsFetching || bucketsLoading || !bucketsFetched)
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
    isStorageIndex,
    bucketsFetching,
    bucketsLoading,
    bucketsFetched,
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

  // Get total count from the first page query (no search/filters) - for limit checking
  const { data: totalBucketsData } = useQuery({
    queryKey: ['buckets', 'project', projectId, 0, DEFAULT_PAGE_SIZE, ''],
    queryFn: () => fetchProjectBuckets(projectId!, 0, DEFAULT_PAGE_SIZE, ''),
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
    refetchOnMount: false, // Data is fresh from route loader, no need to refetch
  })

  // Paginated data - buckets are already paginated by the API
  const paginatedBuckets = apiBuckets

  // Get project to get teamId for organization plan
  // Data is guaranteed to be available from route loader (fetchQuery blocks navigation)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  // Get organization plan to check limits
  // Data is guaranteed to be available from route loader if project has teamId
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)

  // Total count of all buckets (without search) - for limit checking
  // Use the total from the first page query (already cached from route loader)
  const totalBucketsCount = totalBucketsData?.total || 0

  // Check if create button should be disabled
  const bucketsLimit = organizationPlan?.buckets ?? 0
  const noCreatePermission = !canCreateBucket(access, features)
  const isCreateDisabled =
    noCreatePermission ||
    (bucketsLimit > 0 && totalBucketsCount >= bucketsLimit)

  useEffect(() => {
    setSelectedBuckets(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, urlSearch, filterMap.size])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setSelectedBuckets(new Set())
  }

  const handleBucketsSortChange = (
    sortBy: string,
    sortOrder: 'asc' | 'desc',
  ) => {
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: filterQueryString || undefined,
          page: 1,
          limit: urlLimit,
          sort:
            sortBy !== BUCKETS_DEFAULT_SORT_BY ||
            sortOrder !== BUCKETS_DEFAULT_SORT_ORDER
              ? encodeSort(sortBy, sortOrder)
              : undefined,
        }),
      }),
      replace: true,
    })
  }

  const applyFilter = (compactKey: CompactFilterKey, queryStr: string) => {
    const newMap = new Map(filterMap)
    newMap.set(compactKey, queryStr)
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: mapToQueryParam(newMap),
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== BUCKETS_DEFAULT_SORT_BY ||
            urlSortOrder !== BUCKETS_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        }),
      }),
      replace: true,
    })
  }

  const removeFilter = (key: CompactFilterKey) => {
    const newMap = new Map(filterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
            page: 1,
            limit: urlLimit,
            sort:
              urlSortBy !== BUCKETS_DEFAULT_SORT_BY ||
              urlSortOrder !== BUCKETS_DEFAULT_SORT_ORDER
                ? encodeSort(urlSortBy, urlSortOrder)
                : undefined,
          }),
        }
        if (newMap.size === 0) delete next.query
        return next
      },
      replace: true,
    })
  }

  const clearAllFilters = () => {
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            page: 1,
            limit: urlLimit,
            sort:
              urlSortBy !== BUCKETS_DEFAULT_SORT_BY ||
              urlSortOrder !== BUCKETS_DEFAULT_SORT_ORDER
                ? encodeSort(urlSortBy, urlSortOrder)
                : undefined,
          }),
        }
        delete next.query
        return next
      },
      replace: true,
    })
    setFiltersOpen(false)
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (bucketIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all buckets in parallel
      await Promise.all(
        bucketIds.map((bucketId) =>
          projectSdk.storage.deleteBucket({ bucketId }),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch buckets list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.BUCKETS,
      })
      toast.success(
        `Successfully deleted ${selectedBuckets.size} bucket${selectedBuckets.size > 1 ? 's' : ''}`,
      )
      setSelectedBuckets(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete buckets')
    },
  })

  const handleBulkDelete = () => {
    if (selectedBuckets.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedBuckets.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedBuckets))
  }

  const toggleBucket = (bucketId: string) => {
    const newSelected = new Set(selectedBuckets)
    if (newSelected.has(bucketId)) {
      newSelected.delete(bucketId)
    } else {
      newSelected.add(bucketId)
    }
    setSelectedBuckets(newSelected)
  }

  const toggleAllBuckets = () => {
    if (selectedBuckets.size === paginatedBuckets.length) {
      setSelectedBuckets(new Set())
    } else {
      setSelectedBuckets(
        new Set(paginatedBuckets.map((b) => (b as Models.Bucket).$id)),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setSelectedBuckets(new Set())
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: filterQueryString || undefined,
          page,
          limit: urlLimit,
        }),
      }),
      replace: true,
    })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setSelectedBuckets(new Set())
    navigate({
      to: '/projects/$projectId/storage/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: filterQueryString || undefined,
            page: 1,
            limit: newPageSize,
          }),
        }
        delete next.page
        return next
      },
      replace: true,
    })
  }

  // Create bucket mutation
  const createBucketMutation = useMutation({
    mutationFn: async (data: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const bucketId = data.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId,
        name: data.name,
      })
    },
    onSuccess: (bucket) => {
      toast.success(`${bucket.name} has been created`)
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateBucketDialogOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId/',
        params: { projectId: projectId!, bucketId: bucket.$id },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const ViewToggle = () => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('list')}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'grid' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Storage"
        searchPlaceholder="Search buckets..."
        searchValue={searchInput}
        onSearchChange={handleSearchChange}
        createLabel="Create bucket"
        onCreate={() => setCreateBucketDialogOpen(true)}
        createDisabled={isCreateDisabled}
        createDisabledTooltip={
          noCreatePermission
            ? "You don't have permission to create buckets."
            : undefined
        }
        showFilters={true}
        filterTrigger={
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={bucketsFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearAllFilters}
            onApplyFilter={applyFilter}
            resourceLabel="buckets"
            filterScope="storage.buckets"
            onApplyQuery={(queryParam, sortParam) => {
              navigate({
                to: '/projects/$projectId/storage/',
                params: { projectId: projectId! },
                search: (prev: Record<string, unknown>) => ({
                  ...prev,
                  ...buildListSearchParams({
                    search: urlSearch,
                    query: queryParam ?? undefined,
                    page: 1,
                    limit: urlLimit,
                    sort: sortParam ?? undefined,
                  }),
                }),
                replace: true,
              })
            }}
            sortBy={urlSortBy}
            sortOrder={urlSortOrder}
            onSortChange={handleBucketsSortChange}
            defaultSortParam={encodeSort(
              BUCKETS_DEFAULT_SORT_BY,
              BUCKETS_DEFAULT_SORT_ORDER,
            )}
            onReset={() => {
              navigate({
                to: '/projects/$projectId/storage/',
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
          // Data is guaranteed to be available from route loader (fetchQuery blocks navigation)
          // Only render if we have project and buckets data (project might be null if auth fails)
          // organizationPlan might be null/undefined if no plan exists, which is fine - PlanLimitWarning handles it
          project && totalBucketsData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalBucketsCount}
              limit={bucketsLimit}
              planName={organizationPlan?.name}
              resourceName="buckets"
              orgId={project?.teamId}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'list' ? (
          showLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                Loading buckets...
              </p>
            </div>
          ) : paginatedBuckets.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedBuckets.length > 0 &&
                            selectedBuckets.size === paginatedBuckets.length
                          }
                          onCheckedChange={toggleAllBuckets}
                        />
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Bucket
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                        Status
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Created
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Updated
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedBuckets.map((bucket) => {
                      const bucketData = bucket as Models.Bucket
                      const isDisabled = !bucketData.enabled
                      return (
                        <BucketContextMenu
                          key={bucketData.$id}
                          projectId={projectId!}
                          bucket={{ id: bucketData.$id, name: bucketData.name }}
                        >
                          <TableRow
                            className={cn(
                              'cursor-pointer transition-colors border-b border-border/50',
                              selectedBuckets.has(bucketData.$id)
                                ? 'bg-sky-100 dark:bg-sky-950'
                                : 'hover:bg-muted/30',
                            )}
                            onClick={(e) => {
                              // Don't navigate if clicking on checkbox, link, or their containers
                              const target = e.target as HTMLElement
                              if (
                                target.closest('button') ||
                                target.closest('[role="checkbox"]') ||
                                target.closest('a')
                              ) {
                                return
                              }
                              navigate({
                                to: '/projects/$projectId/storage/$bucketId/',
                                params: {
                                  projectId: projectId!,
                                  bucketId: bucketData.$id,
                                },
                              })
                            }}
                          >
                            <TableCell
                              onClick={(e) => e.stopPropagation()}
                              className="px-4 py-3"
                            >
                              <Checkbox
                                checked={selectedBuckets.has(bucketData.$id)}
                                onCheckedChange={() =>
                                  toggleBucket(bucketData.$id)
                                }
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/storage/$bucketId/"
                                params={{
                                  projectId: projectId!,
                                  bucketId: bucketData.$id,
                                }}
                                className="block group"
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <div className="flex-1 min-w-0">
                                    <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                      {bucketData.name}
                                    </p>
                                    <div className="mt-0.5">
                                      <CopyableId
                                        id={bucketData.$id}
                                        size="xs"
                                      />
                                    </div>
                                  </div>
                                </div>
                              </Link>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <div className="flex items-center justify-center">
                                {isDisabled ? (
                                  <Badge
                                    variant="error"
                                    className="text-[11px] font-medium border px-2 py-0.5"
                                  >
                                    Disabled
                                  </Badge>
                                ) : (
                                  <Badge
                                    variant="success"
                                    className="text-[11px] font-medium border px-2 py-0.5"
                                  >
                                    Enabled
                                  </Badge>
                                )}
                              </div>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <Link
                                to="/projects/$projectId/storage/$bucketId/"
                                params={{
                                  projectId: projectId!,
                                  bucketId: bucketData.$id,
                                }}
                                className="block text-right"
                              >
                                {bucketData.$createdAt ? (
                                  <DateTooltip
                                    date={bucketData.$createdAt}
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
                                to="/projects/$projectId/storage/$bucketId/"
                                params={{
                                  projectId: projectId!,
                                  bucketId: bucketData.$id,
                                }}
                                className="block text-right"
                              >
                                {bucketData.$updatedAt ? (
                                  <DateTooltip
                                    date={bucketData.$updatedAt}
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
                        </BucketContextMenu>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? bucketsTotal}
                pageSize={urlLimit}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="buckets"
              />
            </>
          ) : (
            <EmptyState
              icon={FolderOpen}
              title={
                urlSearch || filterMap.size > 0 ? undefined : 'No buckets yet'
              }
              description={
                urlSearch || filterMap.size > 0
                  ? undefined
                  : 'Create your first bucket to start storing files'
              }
              isEmpty={!urlSearch && filterMap.size === 0}
              hasFilters={!!urlSearch || filterMap.size > 0}
              variant="card"
            />
          )
        ) : (
          <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  Loading buckets...
                </p>
              </div>
            ) : paginatedBuckets.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {paginatedBuckets.map((bucket) => {
                  const bucketData = bucket as Models.Bucket
                  const isDisabled = !bucketData.enabled
                  return (
                    <BucketContextMenu
                      key={bucketData.$id}
                      projectId={projectId!}
                      bucket={{ id: bucketData.$id, name: bucketData.name }}
                    >
                      <Link
                        to="/projects/$projectId/storage/$bucketId/"
                        params={{ projectId, bucketId: bucketData.$id }}
                      >
                        <ResourceCard
                          title={bucketData.name}
                          resourceId={bucketData.$id}
                          icon={Folder}
                          iconColor="bg-muted text-muted-foreground"
                          status={isDisabled ? 'error' : undefined}
                          statusLabel={isDisabled ? 'Disabled' : undefined}
                          metadata={[
                            ...(bucketData.compression &&
                            bucketData.compression !== 'none'
                              ? [
                                  {
                                    label: 'Compression',
                                    value:
                                      bucketData.compression === 'gzip'
                                        ? 'Gzip'
                                        : bucketData.compression === 'zstd'
                                          ? 'Zstd'
                                          : bucketData.compression,
                                  },
                                ]
                              : []),
                            ...(bucketData.maximumFileSize &&
                            bucketData.maximumFileSize > 0
                              ? [
                                  {
                                    label: 'Max size',
                                    value: formatBytes(
                                      bucketData.maximumFileSize,
                                    ),
                                  },
                                ]
                              : []),
                            ...(bucketData.allowedFileExtensions &&
                            bucketData.allowedFileExtensions.length > 0
                              ? [
                                  {
                                    label: 'Extensions',
                                    value: `${bucketData.allowedFileExtensions.length} ${bucketData.allowedFileExtensions.length === 1 ? 'type' : 'types'}`,
                                  },
                                ]
                              : []),
                            {
                              label: 'Encrypted',
                              value: bucketData.encryption ? (
                                <Lock className="h-3.5 w-3.5 text-muted-foreground" />
                              ) : null,
                            },
                          ].filter(
                            (item) =>
                              item.value !== null && item.value !== undefined,
                          )}
                        />
                      </Link>
                    </BucketContextMenu>
                  )
                })}
              </div>
            ) : (
              <EmptyState
                icon={Folder}
                title={
                  urlSearch || filterMap.size > 0 ? undefined : 'No buckets yet'
                }
                description={
                  urlSearch || filterMap.size > 0
                    ? undefined
                    : 'Create your first bucket to start storing files'
                }
                isEmpty={!urlSearch && filterMap.size === 0}
                hasFilters={!!urlSearch || filterMap.size > 0}
                variant="card"
              />
            )}
            {!showLoading && paginatedBuckets.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? bucketsTotal}
                pageSize={urlLimit}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="buckets"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedBuckets.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedBuckets.size} bucket
                {selectedBuckets.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedBuckets(new Set())}
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
              <DialogTitle>Delete Buckets</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedBuckets.size} bucket
                {selectedBuckets.size > 1 ? 's' : ''}? This action cannot be
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

      <CreateBucket
        open={createBucketDialogOpen}
        onOpenChange={setCreateBucketDialogOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
      />
    </div>
  )
}
