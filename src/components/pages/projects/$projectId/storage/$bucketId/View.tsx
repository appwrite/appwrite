import { useState, useMemo, useEffect, useCallback, useRef } from 'react'
import { useLocation, Link, useSearch } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  FileText,
  Image,
  Film,
  Music,
  Archive,
  File,
  List,
  LayoutGrid,
  ArrowLeft,
  AlertCircle,
} from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useBucket,
  useBucketFiles,
  Dependencies,
  FILES_DEFAULT_SORT_BY,
  FILES_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
import { useNavigate, useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useProject, useOrganizationScopes } from '@/lib/react-query/hooks'
import { canShowBucketSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { UploadFile } from '../_components/UploadFile'
import { BucketSettings } from '../_components/BucketSettings'
import { BucketSecurity } from '../_components/BucketSecurity'
import { useUploadQueue } from '@/lib/upload-queue/use-upload-queue'
import type { Models } from '@appwrite.io/console'
import { FileContextMenu } from '../_components/FileContextMenu'
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
  MIN_SEARCH_LENGTH,
  filesFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'

function getFileIcon(type: string) {
  if (type.startsWith('image/')) return Image
  if (type.startsWith('video/')) return Film
  if (type.startsWith('audio/')) return Music
  if (type.includes('pdf') || type.includes('document')) return FileText
  if (type.includes('zip') || type.includes('archive')) return Archive
  return File
}

function getFileIconColor() {
  // Use muted colors per UI guidelines
  return 'bg-muted text-muted-foreground'
}

export function View() {
  const { projectId, bucketId } = useParams({
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
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecuritySettings = canShowBucketSecuritySettings(access, features)

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const bucketIndex = pathParts.findIndex(
      (part, idx) => part === 'storage' && pathParts[idx + 1] === bucketId,
    )

    if (bucketIndex >= 0 && pathParts[bucketIndex + 2]) {
      const tabFromPath = pathParts[bucketIndex + 2]
      if (tabFromPath === 'settings') {
        return 'settings'
      }
      if (tabFromPath === 'security') {
        return 'security'
      }
    }

    return 'files'
  }, [location.pathname, bucketId])

  const isFilesIndex =
    activeTab === 'files' &&
    location.pathname.replace(/\/$/, '') ===
      `/projects/${projectId}/storage/${bucketId}`

  const defaultFilesSort = {
    sortBy: FILES_DEFAULT_SORT_BY,
    sortOrder: FILES_DEFAULT_SORT_ORDER as 'asc' | 'desc',
  }
  const filesListParams = useMemo(() => {
    if (!isFilesIndex || typeof search !== 'object') return null
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    const parsed = parseSort(search.sort) ?? getSort(url) ?? defaultFilesSort
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
      search: getSearch(url) ?? search.search,
      page,
      limit,
      filterMap: queryParamToMap(getQueryParam(url) ?? search.query ?? null),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [
    isFilesIndex,
    search?.search,
    search?.query,
    search?.page,
    search?.limit,
    search?.sort,
    location.pathname,
    location.search,
    projectId,
    bucketId,
  ])

  const urlPage = filesListParams?.page ?? 1
  const urlLimit = filesListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = filesListParams?.search
  const urlSortBy = filesListParams?.sortBy ?? FILES_DEFAULT_SORT_BY
  const urlSortOrder = filesListParams?.sortOrder ?? FILES_DEFAULT_SORT_ORDER
  const filterMap = filesListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

  const queryClient = useQueryClient()
  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('list')
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedSortBy, setDisplayedSortBy] = useState(FILES_DEFAULT_SORT_BY)
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    FILES_DEFAULT_SORT_ORDER,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [uploadFileDialogOpen, setUploadFileDialogOpen] = useState(false)
  const [selectedFiles, setSelectedFiles] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  useEffect(() => {
    if (search?.create !== 'file' || uploadFileDialogOpen) return
    setUploadFileDialogOpen(true)
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
  }, [search?.create, uploadFileDialogOpen, navigate, location.pathname])

  const [filtersOpen, setFiltersOpen] = useState(false)
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  // Refetch files list when an upload completes (list uses refetchOnMount: false)
  const refetchFiles = useCallback(() => {
    if (projectId && bucketId) {
      void queryClient.refetchQueries({
        queryKey: ['files', 'project', projectId, 'bucket', bucketId],
      })
    }
  }, [queryClient, projectId, bucketId])

  // Background upload queue
  const { queueUpload } = useUploadQueue(projectId, bucketId, {
    onUploadComplete: refetchFiles,
  })

  // Fetch bucket data
  const { data: bucket } = useBucket(projectId, bucketId)

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isFilesIndex || !filesListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedSortBy(urlSortBy)
      setDisplayedSortOrder(urlSortOrder)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [
    isFilesIndex,
    filesListParams,
    urlPage,
    urlSearch,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  useEffect(() => {
    if (activeTab !== 'files') return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigate({
        to: '/projects/$projectId/storage/$bucketId/',
        params: { projectId: projectId!, bucketId: bucketId! },
        search: (prev: Record<string, unknown>) => ({
          ...prev,
          ...buildListSearchParams({
            search: trimmed || undefined,
            query: filterQueryString || undefined,
            page: 1,
            limit: urlLimit,
            sort:
              urlSortBy !== FILES_DEFAULT_SORT_BY ||
              urlSortOrder !== FILES_DEFAULT_SORT_ORDER
                ? encodeSort(urlSortBy, urlSortOrder)
                : undefined,
          }),
        }),
        replace: true,
      })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [
    activeTab,
    searchInput,
    projectId,
    bucketId,
    navigate,
    urlSearch,
    urlLimit,
    urlSortBy,
    urlSortOrder,
    filterQueryString,
  ])

  const {
    data: requestedFilesData,
    isLoading: filesLoading,
    isFetching: filesFetching,
    isFetched: filesFetched,
  } = useBucketFiles(
    projectId,
    bucketId,
    urlPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    undefined,
    filterQueries,
    urlSortBy,
    urlSortOrder,
  )

  const { data: displayedFilesData, isLoading: displayedFilesLoading } =
    useBucketFiles(
      projectId,
      bucketId,
      displayedPage - 1,
      urlLimit,
      displayedSearch ?? undefined,
      undefined,
      displayedFilterQueries,
      displayedSortBy,
      displayedSortOrder,
    )

  useEffect(() => {
    if (!isFilesIndex || filesFetching || filesLoading || !filesFetched) return
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
    isFilesIndex,
    filesFetching,
    filesLoading,
    filesFetched,
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

  const files = (displayedFilesData?.files || []) as Models.File[]
  const filesTotal = displayedFilesData?.total ?? requestedFilesData?.total ?? 0
  const showFilesLoading =
    displayedFilesLoading && (displayedFilesData?.files?.length ?? 0) === 0

  const tabs: Tab[] = useMemo(() => {
    const base: Tab[] = [
      {
        id: 'files',
        label: 'Files',
        to: '/projects/$projectId/storage/$bucketId/',
        params: {
          projectId: projectId as string,
          bucketId: bucketId as string,
        },
      },
      ...(showSecuritySettings
        ? [
            {
              id: 'security' as const,
              label: 'Security',
              to: '/projects/$projectId/storage/$bucketId/security',
              params: {
                projectId: projectId as string,
                bucketId: bucketId as string,
              },
            },
            {
              id: 'settings' as const,
              label: 'Settings',
              to: '/projects/$projectId/storage/$bucketId/settings',
              params: {
                projectId: projectId as string,
                bucketId: bucketId as string,
              },
            },
          ]
        : []),
    ]
    return base
  }, [projectId, bucketId, showSecuritySettings])

  // Redirect from security/settings when user lacks permission
  useEffect(() => {
    if (showSecuritySettings || !projectId || !bucketId) return
    if (activeTab === 'security' || activeTab === 'settings') {
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId, bucketId },
        replace: true,
      })
    }
  }, [showSecuritySettings, activeTab, projectId, bucketId, navigate])

  // Handle file upload - queues in background
  const handleFileUpload = async (data: {
    fileId?: string
    files: File[]
    permissions?: string[]
  }) => {
    try {
      await Promise.all(
        data.files.map((file) =>
          queueUpload(
            file,
            data.files.length === 1 ? data.fileId : undefined,
            data.permissions,
          ),
        ),
      )
      // No toast - progress bar shows upload status
      setUploadFileDialogOpen(false)
      // Files list will automatically reload when upload completes (handled by GlobalUploadProgress)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const isFilePending = (file: Models.File) => {
    return file.chunksTotal > 0 && file.chunksUploaded < file.chunksTotal
  }

  useEffect(() => {
    setSelectedFiles(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, bucketId, urlSearch, filterMap.size])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setSelectedFiles(new Set())
  }

  const handleFilesSortChange = (sortBy: string, sortOrder: 'asc' | 'desc') => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: filterQueryString || undefined,
          page: 1,
          limit: urlLimit,
          sort:
            sortBy !== FILES_DEFAULT_SORT_BY ||
            sortOrder !== FILES_DEFAULT_SORT_ORDER
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
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: mapToQueryParam(newMap),
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== FILES_DEFAULT_SORT_BY ||
            urlSortOrder !== FILES_DEFAULT_SORT_ORDER
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
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
            page: 1,
            limit: urlLimit,
            sort:
              urlSortBy !== FILES_DEFAULT_SORT_BY ||
              urlSortOrder !== FILES_DEFAULT_SORT_ORDER
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
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== FILES_DEFAULT_SORT_BY ||
            urlSortOrder !== FILES_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        }),
      }),
      replace: true,
    })
    setFiltersOpen(false)
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (fileIds: string[]) => {
      if (!projectId || !bucketId) {
        throw new Error('Project ID and Bucket ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all files in parallel
      await Promise.all(
        fileIds.map((fileId) =>
          projectSdk.storage.deleteFile({ bucketId, fileId }),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch files list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.FILES,
      })
      toast.success(
        `Successfully deleted ${selectedFiles.size} file${selectedFiles.size > 1 ? 's' : ''}`,
      )
      setSelectedFiles(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete files')
    },
  })

  const handleBulkDelete = () => {
    if (selectedFiles.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedFiles.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedFiles))
  }

  const toggleFile = (fileId: string) => {
    const newSelected = new Set(selectedFiles)
    if (newSelected.has(fileId)) {
      newSelected.delete(fileId)
    } else {
      newSelected.add(fileId)
    }
    setSelectedFiles(newSelected)
  }

  const toggleAllFiles = () => {
    const nonPendingFiles = files.filter((f) => !isFilePending(f))
    if (selectedFiles.size === nonPendingFiles.length) {
      setSelectedFiles(new Set())
    } else {
      setSelectedFiles(new Set(nonPendingFiles.map((f) => f.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setSelectedFiles(new Set())
    navigate({
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.search = urlSearch ?? undefined
        next.query = filterQueryString || undefined
        next.page = page
        next.limit = urlLimit
        if (!next.search) delete next.search
        if (page === 1) delete next.page
        if (next.limit === GRID_DEFAULT_PAGE_SIZE) delete next.limit
        return next
      },
      replace: true,
    })
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setSelectedFiles(new Set())
    navigate({
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.search = urlSearch ?? undefined
        next.query = filterQueryString || undefined
        delete next.page
        next.limit = newPageSize
        if (!next.search) delete next.search
        return next
      },
      replace: true,
    })
  }

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/storage',
      params: { projectId: projectId as string },
    })
  }

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
        title={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{bucket?.name || 'Bucket'}</span>
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'files' ? 'Search files...' : undefined
        }
        searchValue={activeTab === 'files' ? searchInput : ''}
        onSearchChange={activeTab === 'files' ? handleSearchChange : undefined}
        createLabel={activeTab === 'files' ? 'Create file' : undefined}
        onCreate={
          activeTab === 'files'
            ? () => setUploadFileDialogOpen(true)
            : undefined
        }
        showFilters={activeTab === 'files'}
        filterTrigger={
          activeTab === 'files' ? (
            <FiltersPopover
              open={filtersOpen}
              onOpenChange={setFiltersOpen}
              columns={filesFilterColumns}
              filterMap={filterMap}
              onRemoveFilter={removeFilter}
              onClearAll={clearAllFilters}
              onApplyFilter={applyFilter}
              resourceLabel="files"
              filterScope="storage.files"
              onApplyQuery={(queryParam, sortParam) => {
                navigate({
                  to: '/projects/$projectId/storage/$bucketId/',
                  params: { projectId: projectId!, bucketId: bucketId! },
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
              onSortChange={handleFilesSortChange}
              defaultSortParam={encodeSort(
                FILES_DEFAULT_SORT_BY,
                FILES_DEFAULT_SORT_ORDER,
              )}
              onReset={() => {
                navigate({
                  to: '/projects/$projectId/storage/$bucketId/',
                  params: { projectId: projectId!, bucketId: bucketId! },
                  search: { page: 1, limit: urlLimit },
                  replace: true,
                })
              }}
              teamId={project?.teamId}
            />
          ) : undefined
        }
        fullWidthBorder
        rightContent={activeTab === 'files' ? <ViewToggle /> : undefined}
        contentAfterBorder={
          bucket && !bucket.enabled ? (
            <div className="border-b border-border bg-amber-500/5">
              <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-amber-500/30 bg-transparent"
                >
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    Bucket is disabled
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                    <span className="inline">
                      This bucket is disabled and not accessible to end users
                      through the API. Console actions remain available.{' '}
                      <Link
                        to="/projects/$projectId/storage/$bucketId/settings"
                        params={{ projectId: projectId!, bucketId: bucketId! }}
                        className="font-medium underline hover:no-underline inline"
                      >
                        Enable it in the Settings tab
                      </Link>{' '}
                      to make it available to end users.
                    </span>
                  </AlertDescription>
                </Alert>
              </div>
            </div>
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1">
        {activeTab === 'files' && (
          <div className="px-4 pb-4 sm:px-6">
            <>
              {showFilesLoading ? (
                <div className="rounded-lg border border-border bg-card py-12 text-center">
                  <p className="text-[13px] text-muted-foreground">
                    Loading files...
                  </p>
                </div>
              ) : viewMode === 'list' ? (
                files.length > 0 ? (
                  <>
                    <div className="rounded-lg border border-border bg-card overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="w-[40px] px-4">
                              <Checkbox
                                checked={
                                  files.filter((f) => !isFilePending(f))
                                    .length > 0 &&
                                  files
                                    .filter((f) => !isFilePending(f))
                                    .every((f) => selectedFiles.has(f.$id))
                                }
                                onCheckedChange={toggleAllFiles}
                              />
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[60px]">
                              Preview
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              File
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              Type
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                              Size
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                              Created
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {files.map((file) => {
                            const FileIcon = getFileIcon(file.mimeType)
                            const iconColorClass = getFileIconColor(
                              file.mimeType,
                            )
                            const pending = isFilePending(file)
                            const fileLinkParams = {
                              projectId: projectId!,
                              bucketId: bucketId!,
                              fileId: file.$id,
                            }
                            return (
                              <FileContextMenu
                                key={file.$id}
                                projectId={projectId!}
                                bucketId={bucketId!}
                                file={{
                                  id: file.$id,
                                  name: file.name,
                                  pending,
                                }}
                              >
                                <TableRow
                                  className={cn(
                                    pending
                                      ? ''
                                      : 'cursor-pointer transition-colors border-b border-border/50',
                                    !pending && 'hover:bg-muted/30',
                                    selectedFiles.has(file.$id) &&
                                      'bg-sky-100 dark:bg-sky-950',
                                  )}
                                  onClick={(e) => {
                                    if (pending) return
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
                                      to: '/projects/$projectId/storage/$bucketId/files/$fileId',
                                      params: fileLinkParams,
                                    })
                                  }}
                                >
                                  <TableCell
                                    onClick={(e) => e.stopPropagation()}
                                    className="px-4 py-3"
                                  >
                                    {!pending && (
                                      <Checkbox
                                        checked={selectedFiles.has(file.$id)}
                                        onCheckedChange={() =>
                                          toggleFile(file.$id)
                                        }
                                      />
                                    )}
                                  </TableCell>
                                  <TableCell className="px-4 py-3">
                                    {pending ? (
                                      <>
                                        {file.mimeType?.startsWith('image/') &&
                                        projectId &&
                                        bucketId ? (
                                          <img
                                            src={
                                              sdk
                                                .forProject(projectId)
                                                .storage.getFilePreview({
                                                  bucketId,
                                                  fileId: file.$id,
                                                  width: 80,
                                                }) + '&mode=admin'
                                            }
                                            alt={file.name}
                                            className="h-10 w-10 rounded-md object-cover border border-border"
                                          />
                                        ) : (
                                          <div
                                            className={cn(
                                              'flex h-10 w-10 items-center justify-center rounded-md',
                                              iconColorClass,
                                            )}
                                          >
                                            <FileIcon className="h-5 w-5" />
                                          </div>
                                        )}
                                      </>
                                    ) : (
                                      <Link
                                        to="/projects/$projectId/storage/$bucketId/files/$fileId"
                                        params={fileLinkParams}
                                        className="block"
                                      >
                                        {file.mimeType?.startsWith('image/') &&
                                        projectId &&
                                        bucketId ? (
                                          <img
                                            src={
                                              sdk
                                                .forProject(projectId)
                                                .storage.getFilePreview({
                                                  bucketId,
                                                  fileId: file.$id,
                                                  width: 80,
                                                }) + '&mode=admin'
                                            }
                                            alt={file.name}
                                            className="h-10 w-10 rounded-md object-cover border border-border"
                                          />
                                        ) : (
                                          <div
                                            className={cn(
                                              'flex h-10 w-10 items-center justify-center rounded-md',
                                              iconColorClass,
                                            )}
                                          >
                                            <FileIcon className="h-5 w-5" />
                                          </div>
                                        )}
                                      </Link>
                                    )}
                                  </TableCell>
                                  <TableCell className="px-4 py-3">
                                    {pending ? (
                                      <div className="flex items-center gap-3 min-w-0">
                                        <div className="flex-1 min-w-0">
                                          <p className="truncate text-[13px] font-medium text-foreground">
                                            {file.name}
                                          </p>
                                          <div className="mt-0.5">
                                            <CopyableId
                                              id={file.$id}
                                              size="xs"
                                            />
                                          </div>
                                        </div>
                                        <Badge
                                          variant="secondary"
                                          className="text-[11px] font-medium border px-2 py-0.5 shrink-0"
                                        >
                                          Pending
                                        </Badge>
                                      </div>
                                    ) : (
                                      <Link
                                        to="/projects/$projectId/storage/$bucketId/files/$fileId"
                                        params={fileLinkParams}
                                        className="block group"
                                      >
                                        <div className="flex items-center gap-3 min-w-0">
                                          <div className="flex-1 min-w-0">
                                            <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                              {file.name}
                                            </p>
                                            <div className="mt-0.5">
                                              <CopyableId
                                                id={file.$id}
                                                size="xs"
                                              />
                                            </div>
                                          </div>
                                        </div>
                                      </Link>
                                    )}
                                  </TableCell>
                                  <TableCell className="px-4 py-3">
                                    {pending ? (
                                      <span className="text-[12px] text-muted-foreground font-mono">
                                        {file.mimeType || '-'}
                                      </span>
                                    ) : (
                                      <Link
                                        to="/projects/$projectId/storage/$bucketId/files/$fileId"
                                        params={fileLinkParams}
                                        className="block"
                                      >
                                        <span className="text-[12px] text-muted-foreground font-mono">
                                          {file.mimeType || '-'}
                                        </span>
                                      </Link>
                                    )}
                                  </TableCell>
                                  <TableCell className="px-4 py-3">
                                    {pending ? (
                                      <span className="text-[12px] text-muted-foreground font-mono text-right block">
                                        {formatBytes(file.sizeOriginal)}
                                      </span>
                                    ) : (
                                      <Link
                                        to="/projects/$projectId/storage/$bucketId/files/$fileId"
                                        params={fileLinkParams}
                                        className="block text-right"
                                      >
                                        <span className="text-[12px] text-muted-foreground font-mono">
                                          {formatBytes(file.sizeOriginal)}
                                        </span>
                                      </Link>
                                    )}
                                  </TableCell>
                                  <TableCell className="px-4 py-3">
                                    {pending ? (
                                      <DateTooltip
                                        date={new Date(file.$createdAt)}
                                        className="text-[12px] text-muted-foreground font-mono text-right block"
                                      />
                                    ) : (
                                      <Link
                                        to="/projects/$projectId/storage/$bucketId/files/$fileId"
                                        params={fileLinkParams}
                                        className="block text-right"
                                      >
                                        <DateTooltip
                                          date={new Date(file.$createdAt)}
                                          className="text-[12px] text-muted-foreground font-mono"
                                        />
                                      </Link>
                                    )}
                                  </TableCell>
                                </TableRow>
                              </FileContextMenu>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                    <Pagination
                      currentPage={displayedPage}
                      totalItems={filesTotal}
                      pageSize={urlLimit}
                      pageSizeOptions={[12, 18, 36, 72]}
                      onPageChange={handlePageChange}
                      onPageSizeChange={handlePageSizeChange}
                      itemLabel="files"
                    />
                  </>
                ) : (
                  <EmptyState
                    icon={File}
                    title={
                      urlSearch || filterMap.size > 0
                        ? undefined
                        : 'No files found'
                    }
                    description={
                      urlSearch || filterMap.size > 0
                        ? undefined
                        : 'Upload your first file to this bucket'
                    }
                    isEmpty={!urlSearch && filterMap.size === 0}
                    hasFilters={!!urlSearch || filterMap.size > 0}
                    variant="card"
                  />
                )
              ) : (
                <div>
                  {files.length > 0 ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                      {files.map((file) => {
                        const FileIcon = getFileIcon(file.mimeType)
                        const iconColorClass = getFileIconColor(file.mimeType)
                        const pending = isFilePending(file)
                        return (
                          <FileContextMenu
                            key={file.$id}
                            projectId={projectId!}
                            bucketId={bucketId!}
                            file={{ id: file.$id, name: file.name, pending }}
                          >
                            <div
                              className={cn(
                                'group cursor-pointer overflow-hidden rounded-lg border border-border bg-card transition-all hover:border-primary/30',
                                pending && 'opacity-75',
                              )}
                              onClick={() => {
                                if (!pending) {
                                  navigate({
                                    to: '/projects/$projectId/storage/$bucketId/files/$fileId',
                                    params: {
                                      projectId: projectId!,
                                      bucketId: bucketId!,
                                      fileId: file.$id,
                                    },
                                  })
                                }
                              }}
                            >
                              {/* Preview */}
                              {!pending &&
                              file.mimeType?.startsWith('image/') &&
                              projectId &&
                              bucketId ? (
                                <div className="h-32 w-full overflow-hidden border-b border-border">
                                  <img
                                    src={
                                      sdk
                                        .forProject(projectId)
                                        .storage.getFilePreview({
                                          bucketId,
                                          fileId: file.$id,
                                          width: 400,
                                        }) + '&mode=admin'
                                    }
                                    alt={file.name}
                                    className="h-full w-full object-cover"
                                  />
                                </div>
                              ) : (
                                <div
                                  className={cn(
                                    'flex h-32 items-center justify-center border-b border-border',
                                    iconColorClass,
                                  )}
                                >
                                  <FileIcon className="h-12 w-12" />
                                </div>
                              )}
                              {/* File info */}
                              <div className="p-3">
                                <div className="flex items-start justify-between gap-2">
                                  <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] font-medium text-foreground">
                                      {file.name}
                                    </p>
                                    <p className="text-[12px] text-muted-foreground">
                                      {file.mimeType}
                                    </p>
                                  </div>
                                </div>
                                <div className="mt-2 flex items-center gap-3 text-[12px] text-muted-foreground">
                                  <span>{formatBytes(file.sizeOriginal)}</span>
                                  {pending && (
                                    <>
                                      <span>•</span>
                                      <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                      >
                                        Pending
                                      </Badge>
                                    </>
                                  )}
                                </div>
                              </div>
                            </div>
                          </FileContextMenu>
                        )
                      })}
                    </div>
                  ) : (
                    <div>
                      <EmptyState
                        icon={File}
                        title={
                          urlSearch || filterMap.size > 0
                            ? undefined
                            : 'No files found'
                        }
                        description={
                          urlSearch || filterMap.size > 0
                            ? undefined
                            : 'Upload your first file to this bucket'
                        }
                        isEmpty={!urlSearch && filterMap.size === 0}
                        hasFilters={!!urlSearch || filterMap.size > 0}
                        variant="card"
                      />
                      {(urlSearch || filterMap.size > 0) && (
                        <div className="mt-4 text-center">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              setSearchInput('')
                              navigate({
                                to: '/projects/$projectId/storage/$bucketId/',
                                params: {
                                  projectId: projectId!,
                                  bucketId: bucketId!,
                                },
                                search: (prev: Record<string, unknown>) => {
                                  const next = { ...prev } as Record<
                                    string,
                                    unknown
                                  >
                                  next.limit = urlLimit
                                  delete next.search
                                  delete next.query
                                  delete next.page
                                  return next
                                },
                                replace: true,
                              })
                            }}
                          >
                            Clear search and filters
                          </Button>
                        </div>
                      )}
                    </div>
                  )}
                  {files.length > 0 && (
                    <Pagination
                      currentPage={displayedPage}
                      totalItems={filesTotal}
                      pageSize={urlLimit}
                      pageSizeOptions={[12, 18, 36, 72]}
                      onPageChange={handlePageChange}
                      onPageSizeChange={handlePageSizeChange}
                      itemLabel="files"
                    />
                  )}
                </div>
              )}

              {/* Bulk Delete Action Bar - shown for both list and grid when files selected */}
              {selectedFiles.size > 0 && (
                <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                  <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                    <Badge variant="secondary" className="h-6 px-2.5">
                      {selectedFiles.size} file
                      {selectedFiles.size > 1 ? 's' : ''} selected
                    </Badge>
                    <div className="flex items-center gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setSelectedFiles(new Set())}
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
              <Dialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
              >
                <DialogContent className="sm:max-w-md p-0">
                  <DialogHeader className="px-6 pt-6 text-left">
                    <DialogTitle>Delete Files</DialogTitle>
                    <DialogDescription className="text-[13px] mt-2">
                      Are you sure you want to delete {selectedFiles.size} file
                      {selectedFiles.size > 1 ? 's' : ''}? This action cannot be
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
            </>
          </div>
        )}

        {activeTab === 'security' && <BucketSecurity />}

        {activeTab === 'settings' && <BucketSettings />}
      </div>

      <UploadFile
        open={uploadFileDialogOpen}
        onOpenChange={setUploadFileDialogOpen}
        onUpload={handleFileUpload}
        bucket={bucket}
        isLoading={false}
      />
    </div>
  )
}
