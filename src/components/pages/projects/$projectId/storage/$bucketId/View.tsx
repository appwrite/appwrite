import {
  useState,
  useMemo,
  useEffect,
  useCallback,
  useRef,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { useLocation, Link, useSearch } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  AlertCircle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Calendar,
  File as FileIcon,
  FileText,
  Fingerprint,
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
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
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
import { FileInspectorPanel } from '../_components/FileInspectorPanel'
import {
  readStoredStorageFilesTablePaneWidthPx,
  STORAGE_FILES_PREVIEW_PANE_MIN_PX,
  STORAGE_FILES_TABLE_EDGE_COL_PX,
  STORAGE_FILES_TABLE_PANE_MIN_PX,
  STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY,
  STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
  STORAGE_SPREADSHEET_BODY_CELL_BORDER,
  STORAGE_SPREADSHEET_BODY_CELL_BORDER_LAST,
  STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
  STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP_LAST,
  STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SPLIT_TOP,
  STORAGE_SPREADSHEET_STICKY_THEAD_CLASS,
} from '../_components/files-documents-layout'
import { useIsMobile } from '@/hooks/use-mobile'

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
    file?: string
    filePanel?: 'overview' | 'security'
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
        : getLimit(url, ROWS_DEFAULT_PAGE_SIZE)
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
  const urlLimit = filesListParams?.limit ?? ROWS_DEFAULT_PAGE_SIZE
  const urlSearch = filesListParams?.search
  const urlSortBy = filesListParams?.sortBy ?? FILES_DEFAULT_SORT_BY
  const urlSortOrder = filesListParams?.sortOrder ?? FILES_DEFAULT_SORT_ORDER
  const filterMap = filesListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined

  const inspectorFileId =
    typeof search?.file === 'string' && search.file.trim().length > 0
      ? search.file
      : undefined

  const queryClient = useQueryClient()
  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
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
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId: projectId!, bucketId: bucketId! },
        search: (prev: Record<string, unknown>) => {
          const built = buildListSearchParams({
            search: trimmed || undefined,
            query: filterQueryString || undefined,
            page: 1,
            limit: urlLimit,
            sort:
              urlSortBy !== FILES_DEFAULT_SORT_BY ||
              urlSortOrder !== FILES_DEFAULT_SORT_ORDER
                ? encodeSort(urlSortBy, urlSortOrder)
                : undefined,
          })
          const next = { ...prev, ...built }
          delete next.file
          if (!trimmed) delete next.search
          if (!filterQueryString) delete next.query
          return next
        },
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

  const files = (
    (displayedFilesData as { files?: Models.File[] } | undefined)?.files ??
    (requestedFilesData as { files?: Models.File[] } | undefined)?.files ??
    []
  ) as Models.File[]
  const filesTotal =
    displayedFilesData?.total ?? requestedFilesData?.total ?? 0
  const showFilesLoading =
    displayedFilesLoading &&
    ((displayedFilesData as { files?: Models.File[] } | undefined)?.files
      ?.length ??
      (requestedFilesData as { files?: Models.File[] } | undefined)?.files
        ?.length ??
      0) === 0

  const tabs: Tab[] = useMemo(() => {
    const base: Tab[] = [
      {
        id: 'files',
        label: 'Files',
        to: '/projects/$projectId/storage/$bucketId',
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
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: urlSearch,
          query: filterQueryString || undefined,
          page: 1,
          limit: urlLimit,
          sort:
            sortBy !== FILES_DEFAULT_SORT_BY ||
            sortOrder !== FILES_DEFAULT_SORT_ORDER
              ? encodeSort(sortBy, sortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        if (!(urlSearch ?? '').trim()) delete next.search
        if (!filterQueryString) delete next.query
        delete next.file
        return next
      },
      replace: true,
    })
  }

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(filterMap)
    if (replaceKey) newMap.delete(replaceKey)
    newMap.set(compactKey, queryStr)
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: urlSearch,
          query: mapToQueryParam(newMap),
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== FILES_DEFAULT_SORT_BY ||
            urlSortOrder !== FILES_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        if (!(urlSearch ?? '').trim()) delete next.search
        delete next.file
        return next
      },
      replace: true,
    })
  }

  const removeFilter = (key: CompactFilterKey) => {
    const newMap = new Map(filterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: urlSearch,
          query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== FILES_DEFAULT_SORT_BY ||
            urlSortOrder !== FILES_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        if (!(urlSearch ?? '').trim()) delete next.search
        if (newMap.size === 0) delete next.query
        delete next.file
        return next
      },
      replace: true,
    })
  }

  const clearAllFilters = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: urlSearch,
          page: 1,
          limit: urlLimit,
          sort:
            urlSortBy !== FILES_DEFAULT_SORT_BY ||
            urlSortOrder !== FILES_DEFAULT_SORT_ORDER
              ? encodeSort(urlSortBy, urlSortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        delete next.query
        delete next.file
        return next
      },
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
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId: projectId!, bucketId: bucketId! },
        search: (prev: Record<string, unknown>) => {
          const next = { ...prev } as Record<string, unknown>
          delete next.file
          return next
        },
        replace: true,
      })
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
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.search = urlSearch ?? undefined
        next.query = filterQueryString || undefined
        next.page = page
        next.limit = urlLimit
        if (!next.search) delete next.search
        if (!filterQueryString) delete next.query
        if (page === 1) delete next.page
        if (next.limit === ROWS_DEFAULT_PAGE_SIZE) delete next.limit
        delete next.file
        return next
      },
      replace: true,
    })
  }

  const handleFileColumnSort = (column: string) => {
    const nextOrder =
      urlSortBy === column
        ? urlSortOrder === 'asc'
          ? 'desc'
          : 'asc'
        : 'asc'
    handleFilesSortChange(column, nextOrder)
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setSelectedFiles(new Set())
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId!, bucketId: bucketId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.search = urlSearch ?? undefined
        next.query = filterQueryString || undefined
        delete next.page
        if (newPageSize === ROWS_DEFAULT_PAGE_SIZE) {
          delete next.limit
        } else {
          next.limit = newPageSize
        }
        if (!next.search) delete next.search
        if (!filterQueryString) delete next.query
        delete next.file
        return next
      },
      replace: true,
    })
  }

  const isMobileViewport = useIsMobile()
  const useInlineFilePreviewPane = true
  const isFilesStackedLayout = useInlineFilePreviewPane && isMobileViewport
  const [fileTablePaneWidthPx, setFileTablePaneWidthPx] = useState(
    readStoredStorageFilesTablePaneWidthPx,
  )
  const [isFilesSplitResizing, setIsFilesSplitResizing] = useState(false)
  const filesSplitContainerRef = useRef<HTMLDivElement>(null)
  const fileTablePaneWidthRef = useRef(fileTablePaneWidthPx)
  fileTablePaneWidthRef.current = fileTablePaneWidthPx

  const handleFilesSplitPointerDown = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>) => {
      e.preventDefault()
      setIsFilesSplitResizing(true)
      const btn = e.currentTarget
      btn.setPointerCapture(e.pointerId)
      const startX = e.clientX
      const startW = fileTablePaneWidthRef.current
      const splitEl = filesSplitContainerRef.current
      const onMove = (ev: globalThis.PointerEvent) => {
        if (!splitEl) return
        const maxTable = Math.max(
          STORAGE_FILES_TABLE_PANE_MIN_PX,
          splitEl.clientWidth - STORAGE_FILES_PREVIEW_PANE_MIN_PX,
        )
        const delta = ev.clientX - startX
        const next = Math.min(
          maxTable,
          Math.max(STORAGE_FILES_TABLE_PANE_MIN_PX, startW + delta),
        )
        setFileTablePaneWidthPx(next)
        fileTablePaneWidthRef.current = next
      }
      const onUp = () => {
        setIsFilesSplitResizing(false)
        try {
          btn.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
        localStorage.setItem(
          STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY,
          String(fileTablePaneWidthRef.current),
        )
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [],
  )

  useEffect(() => {
    if (!isFilesSplitResizing) return
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    return () => {
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isFilesSplitResizing])

  useEffect(() => {
    if (isFilesStackedLayout) return
    const el = filesSplitContainerRef.current
    if (!el) return
    const clamp = () => {
      const maxTable = Math.max(
        STORAGE_FILES_TABLE_PANE_MIN_PX,
        el.clientWidth - STORAGE_FILES_PREVIEW_PANE_MIN_PX,
      )
      setFileTablePaneWidthPx((w) => Math.min(w, maxTable))
    }
    const ro = new ResizeObserver(clamp)
    ro.observe(el)
    clamp()
    return () => ro.disconnect()
  }, [isFilesStackedLayout])

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
      <ServiceHeader
        title={
          bucket ? (
            <div className="flex min-w-0 items-center gap-2">
              <span className="truncate">{bucket.name}</span>
              <CopyableId id={bucket.$id} size="xs" className="shrink-0" />
            </div>
          ) : (
            'Bucket'
          )
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
                  to: '/projects/$projectId/storage/$bucketId',
                  params: { projectId: projectId!, bucketId: bucketId! },
                  search: (prev: Record<string, unknown>) => {
                    const built = buildListSearchParams({
                      search: urlSearch,
                      query: queryParam ?? undefined,
                      page: 1,
                      limit: urlLimit,
                      sort: sortParam ?? undefined,
                    })
                    const next = { ...prev, ...built }
                    if (!queryParam?.trim()) delete next.query
                    delete next.file
                    return next
                  },
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
                  to: '/projects/$projectId/storage/$bucketId',
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
        fullWidth
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

      <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        {activeTab === 'files' && (
          <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
            {showFilesLoading ? (
              <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6 py-12">
                <p className="text-[13px] text-muted-foreground">Loading files…</p>
              </div>
            ) : files.length === 0 ? (
              <div className="flex min-h-0 flex-1 flex-col items-center justify-center border-t border-border px-6 py-12">
                <EmptyState
                  icon={FileText}
                  title={
                    urlSearch?.trim() || filterMap.size > 0
                      ? 'No files match your filters'
                      : 'No files yet'
                  }
                  description={
                    urlSearch?.trim() || filterMap.size > 0
                      ? 'Try adjusting or clearing filters.'
                      : 'Use Create file in the header to upload your first file.'
                  }
                  isEmpty={!urlSearch?.trim() && filterMap.size === 0}
                  hasFilters={!!urlSearch?.trim() || filterMap.size > 0}
                  variant="centered"
                  iconSize="md"
                />
              </div>
            ) : (
              <>
                <div
                  ref={filesSplitContainerRef}
                  className={cn(
                    'flex min-h-0 flex-1 overflow-hidden border-t border-border',
                    useInlineFilePreviewPane
                      ? isFilesStackedLayout
                        ? 'flex-col'
                        : 'flex-row'
                      : 'flex-row',
                    useInlineFilePreviewPane && 'relative',
                  )}
                >
                  <div
                    style={
                      useInlineFilePreviewPane && !isFilesStackedLayout
                        ? { width: fileTablePaneWidthPx }
                        : undefined
                    }
                    className={cn(
                      'flex min-h-0 min-w-0 flex-col',
                      useInlineFilePreviewPane
                        ? cn(
                            'shrink-0 border-border bg-background',
                            isFilesStackedLayout
                              ? 'max-h-[min(42dvh,320px)] w-full border-b border-border'
                              : 'border-r border-border',
                          )
                        : 'min-w-0 flex-1',
                    )}
                  >
                    <div className="min-h-0 flex-1 overflow-auto overscroll-contain">
                    <table
                      className={cn(
                        'w-full border-collapse',
                        useInlineFilePreviewPane &&
                          !isFilesStackedLayout &&
                          'table-fixed',
                      )}
                    >
                      <colgroup>
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? {
                                  width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                }
                              : { width: 40 }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { minWidth: 180 }
                              : { width: '180px' }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { minWidth: 160 }
                              : { minWidth: '160px' }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { minWidth: 140 }
                              : { minWidth: '140px' }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { width: 100, minWidth: 100 }
                              : { width: '100px' }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { width: 180, minWidth: 180 }
                              : { width: '180px' }
                          }
                        />
                        <col
                          style={
                            useInlineFilePreviewPane && !isFilesStackedLayout
                              ? { width: 180, minWidth: 180 }
                              : { width: '180px' }
                          }
                        />
                      </colgroup>
                      <thead className={STORAGE_SPREADSHEET_STICKY_THEAD_CLASS}>
                        <tr className={STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS}>
                          <th
                            className={cn(
                              'sticky left-0 z-40 bg-background px-2 py-0 align-middle text-center',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              useInlineFilePreviewPane &&
                                !isFilesStackedLayout &&
                                'min-w-[40px] max-w-[40px] shrink-0 box-border',
                              STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SPLIT_TOP,
                            )}
                            style={
                              useInlineFilePreviewPane && !isFilesStackedLayout
                                ? {
                                    width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                    minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                    maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  }
                                : undefined
                            }
                          >
                            <div className="flex h-full items-center justify-center">
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
                            </div>
                          </th>
                          <th
                            className={cn(
                              'w-[180px] px-3 py-0 align-middle',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
                            )}
                          >
                            <div className="flex h-full items-center gap-2">
                              <Fingerprint className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $id
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$id')}
                                className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === '$id' ? (
                                  urlSortOrder === 'asc' ? (
                                    <ArrowUp className="h-3 w-3 shrink-0 text-foreground" />
                                  ) : (
                                    <ArrowDown className="h-3 w-3 shrink-0 text-foreground" />
                                  )
                                ) : (
                                  <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                              </button>
                            </div>
                          </th>
                          <th
                            className={cn(
                              'min-w-[160px] px-3 py-0 align-middle',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
                            )}
                          >
                            <div className="flex h-full items-center gap-2">
                              <FileIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                name
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('name')}
                                className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === 'name' ? (
                                  urlSortOrder === 'asc' ? (
                                    <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                                  ) : (
                                    <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                                  )
                                ) : (
                                  <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                              </button>
                            </div>
                          </th>
                          <th
                            className={cn(
                              'min-w-[140px] px-3 py-0 align-middle text-left text-[11px] font-medium uppercase tracking-wider text-muted-foreground',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
                            )}
                          >
                            MIME type
                          </th>
                          <th
                            className={cn(
                              'w-[100px] px-3 py-0 align-middle',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
                            )}
                          >
                            <div className="flex h-full items-center gap-2">
                              <span className="text-[12px] font-medium text-foreground">
                                size
                              </span>
                              <button
                                type="button"
                                onClick={() =>
                                  handleFileColumnSort('sizeOriginal')
                                }
                                className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === 'sizeOriginal' ? (
                                  urlSortOrder === 'asc' ? (
                                    <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                                  ) : (
                                    <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                                  )
                                ) : (
                                  <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                              </button>
                            </div>
                          </th>
                          <th
                            className={cn(
                              'w-[180px] px-3 py-0 align-middle',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP,
                            )}
                          >
                            <div className="flex h-full items-center gap-2">
                              <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $createdAt
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$createdAt')}
                                className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === '$createdAt' ? (
                                  urlSortOrder === 'asc' ? (
                                    <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                                  ) : (
                                    <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                                  )
                                ) : (
                                  <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                              </button>
                            </div>
                          </th>
                          <th
                            className={cn(
                              'w-[180px] px-3 py-0 align-middle',
                              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER_SPLIT_TOP_LAST,
                            )}
                          >
                            <div className="flex h-full items-center gap-2">
                              <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $updatedAt
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$updatedAt')}
                                className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === '$updatedAt' ? (
                                  urlSortOrder === 'asc' ? (
                                    <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                                  ) : (
                                    <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                                  )
                                ) : (
                                  <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                              </button>
                            </div>
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {files.map((file) => {
                          const pending = isFilePending(file)
                          const isPreviewRow =
                            !pending && inspectorFileId === file.$id
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
                              <tr
                                className={cn(
                                  'group transition-colors',
                                  pending
                                    ? 'cursor-default opacity-70 hover:bg-transparent'
                                    : 'cursor-pointer',
                                  pending
                                    ? null
                                    : isPreviewRow
                                      ? 'bg-muted/25 ring-1 ring-inset ring-border/20 hover:bg-muted/35'
                                      : selectedFiles.has(file.$id)
                                        ? 'bg-muted'
                                        : 'hover:bg-muted/50',
                                )}
                                onClick={(e) => {
                                  if (pending) return
                                  const target = e.target as HTMLElement
                                  if (
                                    target.closest('button') ||
                                    target.closest('[role="checkbox"]')
                                  ) {
                                    return
                                  }
                                  const isActive =
                                    inspectorFileId === file.$id
                                  navigate({
                                    to: '/projects/$projectId/storage/$bucketId',
                                    params: {
                                      projectId: projectId!,
                                      bucketId: bucketId!,
                                    },
                                    search: (
                                      prev: Record<string, unknown>,
                                    ) => {
                                      const next = {
                                        ...prev,
                                      } as Record<string, unknown>
                                      if (isActive) delete next.file
                                      else next.file = file.$id
                                      return next
                                    },
                                    replace: true,
                                  })
                                }}
                              >
                                <td
                                  className={cn(
                                    'sticky left-0 w-10 border-b border-border px-2 py-1.5 text-center',
                                    useInlineFilePreviewPane &&
                                      !isFilesStackedLayout &&
                                      'min-w-[40px] max-w-[40px] shrink-0 box-border',
                                    'shadow-[inset_-1px_0_0_0_var(--border)]',
                                    !isPreviewRow
                                      ? 'bg-background'
                                      : 'bg-muted/25 group-hover:bg-muted/35',
                                    !isPreviewRow &&
                                      selectedFiles.has(file.$id) &&
                                      'bg-muted',
                                  )}
                                  style={
                                    useInlineFilePreviewPane &&
                                    !isFilesStackedLayout
                                      ? {
                                          width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                          minWidth:
                                            STORAGE_FILES_TABLE_EDGE_COL_PX,
                                          maxWidth:
                                            STORAGE_FILES_TABLE_EDGE_COL_PX,
                                        }
                                      : undefined
                                  }
                                  onClick={(e) => e.stopPropagation()}
                                >
                                  {!pending ? (
                                    <div className="flex justify-center">
                                      <Checkbox
                                        checked={selectedFiles.has(file.$id)}
                                        onCheckedChange={() =>
                                          toggleFile(file.$id)
                                        }
                                        onClick={(e) => e.stopPropagation()}
                                      />
                                    </div>
                                  ) : null}
                                </td>
                                <td
                                  className={cn(
                                    'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                  )}
                                >
                                  {!pending ? (
                                    <CopyableId id={file.$id} size="xs" />
                                  ) : (
                                    <span className="text-[12px] text-muted-foreground">
                                      —
                                    </span>
                                  )}
                                </td>
                                <td
                                  className={cn(
                                    'max-w-0 px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                  )}
                                >
                                  <div className="flex min-w-0 items-center gap-2">
                                    <span className="min-w-0 flex-1 truncate text-[12px] font-medium text-foreground">
                                      {file.name}
                                    </span>
                                    {pending ? (
                                      <Badge
                                        variant="secondary"
                                        className="shrink-0 text-[10px] font-medium"
                                      >
                                        Pending
                                      </Badge>
                                    ) : null}
                                  </div>
                                </td>
                                <td
                                  className={cn(
                                    'px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                  )}
                                >
                                  <span className="block truncate font-mono text-[12px] text-muted-foreground">
                                    {file.mimeType || '—'}
                                  </span>
                                </td>
                                <td
                                  className={cn(
                                    'px-3 py-1.5 text-right',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                  )}
                                >
                                  <span className="font-mono text-[12px] text-muted-foreground">
                                    {formatBytes(file.sizeOriginal)}
                                  </span>
                                </td>
                                <td
                                  className={cn(
                                    'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                  )}
                                >
                                  {file.$createdAt ? (
                                    <DateTooltip
                                      date={new Date(file.$createdAt)}
                                      className="text-[12px] text-muted-foreground"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-foreground/60">
                                      N/A
                                    </span>
                                  )}
                                </td>
                                <td
                                  className={cn(
                                    'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER_LAST,
                                  )}
                                >
                                  {file.$updatedAt ? (
                                    <DateTooltip
                                      date={new Date(file.$updatedAt)}
                                      className="text-[12px] text-muted-foreground"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-foreground/60">
                                      N/A
                                    </span>
                                  )}
                                </td>
                              </tr>
                            </FileContextMenu>
                          )
                        })}
                      </tbody>
                    </table>
                    </div>
                    <div className="shrink-0 border-t border-border bg-background px-3 py-2">
                      <Pagination
                        currentPage={displayedPage}
                        totalItems={filesTotal}
                        pageSize={urlLimit}
                        pageSizeOptions={[10, 25, 50, 100]}
                        onPageChange={handlePageChange}
                        onPageSizeChange={handlePageSizeChange}
                        itemLabel="files"
                        className="mt-0 min-h-0 border-0 py-0"
                      />
                    </div>
                  </div>
                  <div
                    className={cn(
                      'flex min-h-0 min-w-0 flex-1 flex-col bg-muted/10',
                      isFilesStackedLayout
                        ? 'min-h-[min(46dvh,360px)]'
                        : 'border-l border-border',
                    )}
                    style={
                      !isFilesStackedLayout
                        ? { minWidth: STORAGE_FILES_PREVIEW_PANE_MIN_PX }
                        : undefined
                    }
                  >
                    <FileInspectorPanel
                      projectId={projectId!}
                      bucketId={bucketId!}
                      fileId={inspectorFileId}
                      panelTab={
                        search?.filePanel === 'overview' ||
                        search?.filePanel === 'security'
                          ? search.filePanel
                          : undefined
                      }
                    />
                  </div>
                  {useInlineFilePreviewPane && !isFilesStackedLayout ? (
                    <button
                      type="button"
                      aria-label="Resize file table and preview"
                      aria-orientation="vertical"
                      role="separator"
                      tabIndex={0}
                      style={{ left: fileTablePaneWidthPx }}
                      onKeyDown={(e) => {
                        const splitEl = filesSplitContainerRef.current
                        if (!splitEl) return
                        const maxTable = Math.max(
                          STORAGE_FILES_TABLE_PANE_MIN_PX,
                          splitEl.clientWidth -
                            STORAGE_FILES_PREVIEW_PANE_MIN_PX,
                        )
                        const step = 24
                        if (e.key === 'ArrowLeft') {
                          e.preventDefault()
                          setFileTablePaneWidthPx((w) => {
                            const next = Math.max(
                              STORAGE_FILES_TABLE_PANE_MIN_PX,
                              w - step,
                            )
                            fileTablePaneWidthRef.current = next
                            localStorage.setItem(
                              STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY,
                              String(next),
                            )
                            return next
                          })
                        } else if (e.key === 'ArrowRight') {
                          e.preventDefault()
                          setFileTablePaneWidthPx((w) => {
                            const next = Math.min(maxTable, w + step)
                            fileTablePaneWidthRef.current = next
                            localStorage.setItem(
                              STORAGE_FILES_TABLE_PANE_WIDTH_STORAGE_KEY,
                              String(next),
                            )
                            return next
                          })
                        }
                      }}
                      className={cn(
                        'absolute top-0 bottom-0 z-30 w-1.5 -translate-x-1/2 cursor-col-resize border-0 bg-transparent p-0 outline-none transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
                        isFilesSplitResizing &&
                          'bg-primary/30 dark:bg-sidebar-accent/70',
                        'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                      )}
                      onPointerDown={handleFilesSplitPointerDown}
                    />
                  ) : null}
                </div>

                {selectedFiles.size > 0 && (
                  <div className="fixed bottom-4 left-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:px-0 sm:w-auto sm:max-w-none">
                    <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 sm:min-w-[400px] sm:gap-3 sm:px-6">
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

                <Dialog
                  open={deleteDialogOpen}
                  onOpenChange={setDeleteDialogOpen}
                >
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-left">
                      <DialogTitle>Delete Files</DialogTitle>
                      <DialogDescription className="mt-2 text-[13px]">
                        Are you sure you want to delete {selectedFiles.size} file
                        {selectedFiles.size > 1 ? 's' : ''}? This action cannot be
                        undone.
                      </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
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
            )}
          </div>
        )}

        {(activeTab === 'security' || activeTab === 'settings') && (
          <div className="flex w-full flex-1 min-h-0 flex-col overflow-y-auto">
            {activeTab === 'security' && <BucketSecurity />}
            {activeTab === 'settings' && <BucketSettings />}
          </div>
        )}
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
