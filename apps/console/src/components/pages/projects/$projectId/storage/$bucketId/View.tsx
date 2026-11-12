import {
  useState,
  useMemo,
  useEffect,
  useLayoutEffect,
  useCallback,
  useRef,
  type CSSProperties,
  type DragEvent,
  type MouseEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react'
import { flushSync } from 'react-dom'
import { useLocation, Link, useSearch } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  applyColumnResizeRailPosition,
  horizontalResizeDeltaPx,
  horizontalSplitHandleStyle,
  isRtlElement,
  setBodyResizeDragActive,
} from '@/lib/layout/horizontal-resize'
import {
  AlertCircle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Calendar,
  File as FileIcon,
  FileText,
  Fingerprint,
  Upload,
} from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useBucket,
  useBucketFiles,
  Dependencies,
  FILES_DEFAULT_SORT_BY,
  FILES_DEFAULT_SORT_ORDER,
  fileQueryOptions,
  removeCachedFile,
  getBucketFromProjectCaches,
  getConsoleAccountFromCache,
  fetchConsoleAccount,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  useStorageFilesTablePaneWidth,
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
import {
  FileContextMenu,
  FileRowActionsMenu,
} from '../_components/FileContextMenu'
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
  urlFromRouterLocation,
  searchParamsFromRouterLocation,
  MIN_SEARCH_LENGTH,
  filesFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { FileInspectorPanel } from '../_components/FileInspectorPanel'
import { FileInspectorDrawer } from '../_components/FileInspectorDrawer'
import {
  clampSplitFirstPaneWidthPx,
  fitSplitFirstPaneWidthOnContainerResize,
} from '@/lib/resizable-layout'
import {
  STORAGE_FILES_PREVIEW_PANE_MIN_PX,
  STORAGE_FILES_TABLE_PREVIEW_SPLIT_MIN_VIEWPORT_PX,
  STORAGE_FILES_TABLE_EDGE_COL_PX,
  STORAGE_FILES_TABLE_PANE_MAX_PX,
  STORAGE_FILES_TABLE_PANE_MIN_PX,
  STORAGE_FILES_SPLIT_PANE_BG_CLASS,
  defaultStorageFilesTablePaneWidthPx,
  storageFilesSplitGridStyle,
  STORAGE_SPREADSHEET_BODY_CELL_BORDER,
  STORAGE_SPREADSHEET_BODY_STICKY_EDGE_BG_CLASS,
  STORAGE_SPREADSHEET_TABLE_LAYER_CLASS,
  STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
  STORAGE_SPREADSHEET_HEADER_STICKY_ACTIONS_SHADOW,
  STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SHADOW,
  STORAGE_SPREADSHEET_STICKY_END_EDGE_SHADOW,
  STORAGE_SPREADSHEET_STICKY_START_EDGE_SHADOW,
  STORAGE_SPREADSHEET_STICKY_THEAD_CLASS,
  STORAGE_FILES_TABLE_HEADER_TH_CLASS,
  STORAGE_FILES_LIST_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS,
} from '../_components/files-documents-layout'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS,
  STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
  STORAGE_FILES_LIST_RESIZABLE_COLUMN_WIDTH_KEYS,
  clampStorageFilesListDataColumnWidthPx,
  getStorageFilesListColumnWidthsFromPrefs,
  mergeStorageFilesListColumnWidthsIntoPrefs,
  mergeStorageFilesListColumnWidthsWithDefaults,
  hasStorageFilesTablePaneWidthPref,
  parseStorageFilesTablePaneWidthPx,
  type StorageFilesListColumnWidthKey,
  type StorageFilesListResizableColumnWidthKey,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'
import { SPREADSHEET_STICKY_BODY_Z } from '@/lib/layout/spreadsheet-sticky'

const STORAGE_FILES_STACKED_COL_STYLES: Record<
  StorageFilesListColumnWidthKey,
  CSSProperties
> = {
  $id: { width: '180px' },
  name: { minWidth: '160px' },
  mimeType: { minWidth: '140px' },
  sizeOriginal: { width: '120px' },
  $createdAt: { width: '180px' },
  $updatedAt: { width: '180px' },
}

const STORAGE_FILES_LIST_COLUMN_RESIZE_LAYOUT_KEY =
  STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS.join('\u0001')

function dataTransferHasFileList(dataTransfer: DataTransfer | null): boolean {
  if (!dataTransfer?.types?.length) return false
  return Array.from(dataTransfer.types).includes('Files')
}

export function View() {
  const t = useT()
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
    filePanel?: 'overview' | 'permissions' | 'tokens' | 'security'
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
    const url = urlFromRouterLocation(location, window.location.origin)
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

  const inspectorFileId = useMemo(() => {
    if (typeof search?.file === 'string' && search.file.trim().length > 0) {
      return search.file.trim()
    }
    if (typeof window === 'undefined') return undefined
    const file = searchParamsFromRouterLocation(location).get('file')
    return file?.trim() || undefined
  }, [search?.file, location.search])

  const wideEnoughForTablePreviewSplit = useMediaMinWidth(
    STORAGE_FILES_TABLE_PREVIEW_SPLIT_MIN_VIEWPORT_PX,
  )
  const useInlineFilePreviewPane = true
  const isFilesStackedLayout =
    useInlineFilePreviewPane && !wideEnoughForTablePreviewSplit
  const splitFilesTable =
    useInlineFilePreviewPane && !isFilesStackedLayout

  /** Opens the bottom drawer immediately on row tap before `?file=` search syncs. */
  const [stackedDrawerFileId, setStackedDrawerFileId] = useState<
    string | undefined
  >()
  const effectiveInspectorFileId = isFilesStackedLayout
    ? inspectorFileId ?? stackedDrawerFileId
    : inspectorFileId

  useEffect(() => {
    if (inspectorFileId) setStackedDrawerFileId(undefined)
  }, [inspectorFileId])

  const prevInspectorFileIdRef = useRef<string | undefined>(undefined)
  useEffect(() => {
    const prev = prevInspectorFileIdRef.current
    prevInspectorFileIdRef.current = inspectorFileId
    if (prev && !inspectorFileId) {
      setStackedDrawerFileId(undefined)
    }
  }, [inspectorFileId])

  const queryClient = useQueryClient()
  const { account } = useAuth()
  const { persistTablePaneWidthPx } = useStorageFilesTablePaneWidth(account)

  const prefetchInspectorFileData = useCallback(
    (targetFileId: string) => {
      if (!projectId || !bucketId || !targetFileId) return
      void queryClient.prefetchQuery(
        fileQueryOptions(projectId, bucketId, targetFileId),
      )
    },
    [projectId, bucketId, queryClient],
  )
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
  const [uploadPrefillFiles, setUploadPrefillFiles] = useState<File[] | null>(
    null,
  )
  const clearUploadPrefill = useCallback(() => setUploadPrefillFiles(null), [])
  const [filesSectionFileDragActive, setFilesSectionFileDragActive] =
    useState(false)
  const [selectedFiles, setSelectedFiles] = useState<Set<string>>(new Set())
  const fileSelectionAnchorRef = useRef<string | null>(null)
  const stackedRowTouchActivateRef = useRef(false)
  const [fileMultiSelectModifierActive, setFileMultiSelectModifierActive] =
    useState(false)
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

  const lastDisplayBucketRef = useRef<Models.Bucket | null>(null)
  useLayoutEffect(() => {
    if (
      lastDisplayBucketRef.current &&
      bucketId &&
      lastDisplayBucketRef.current.$id !== bucketId
    ) {
      lastDisplayBucketRef.current = null
    }
  }, [bucketId])

  useEffect(() => {
    if (bucket && bucketId && bucket.$id === bucketId) {
      lastDisplayBucketRef.current = bucket
    }
  }, [bucket, bucketId])

  const displayBucket =
    bucket ??
    (lastDisplayBucketRef.current?.$id === bucketId
      ? lastDisplayBucketRef.current
      : null) ??
    getBucketFromProjectCaches(queryClient, projectId, bucketId) ??
    undefined

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
        label: t('Files'),
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
              label: t('Security'),
              to: '/projects/$projectId/storage/$bucketId/security',
              params: {
                projectId: projectId as string,
                bucketId: bucketId as string,
              },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
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
  }, [projectId, bucketId, showSecuritySettings, t])

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

  const handleFilesShellDragOverCapture = (e: DragEvent) => {
    if (!dataTransferHasFileList(e.dataTransfer)) return
    e.preventDefault()
    e.stopPropagation()
    e.dataTransfer.dropEffect = 'copy'
  }

  const handleFilesShellDragEnter = (e: DragEvent) => {
    if (!dataTransferHasFileList(e.dataTransfer)) return
    e.preventDefault()
    const root = e.currentTarget as HTMLElement
    const related = e.relatedTarget
    if (related instanceof Node && root.contains(related)) return
    setFilesSectionFileDragActive(true)
  }

  const handleFilesShellDragLeave = (e: DragEvent) => {
    if (!dataTransferHasFileList(e.dataTransfer)) return
    const root = e.currentTarget as HTMLElement
    const related = e.relatedTarget
    if (related instanceof Node && root.contains(related)) return
    setFilesSectionFileDragActive(false)
  }

  const handleFilesShellDropCapture = (e: DragEvent) => {
    if (!dataTransferHasFileList(e.dataTransfer)) return
    e.preventDefault()
    e.stopPropagation()
    setFilesSectionFileDragActive(false)
    const dropped = Array.from(e.dataTransfer.files ?? [])
    if (dropped.length === 0) return
    setUploadPrefillFiles(dropped)
    setUploadFileDialogOpen(true)
  }

  useEffect(() => {
    if (activeTab !== 'files') {
      setFilesSectionFileDragActive(false)
      return
    }
    const endDrag = () => setFilesSectionFileDragActive(false)
    window.addEventListener('dragend', endDrag)
    return () => window.removeEventListener('dragend', endDrag)
  }, [activeTab])

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
    onSuccess: async (_data, fileIds) => {
      for (const id of fileIds) {
        removeCachedFile(queryClient, projectId!, bucketId!, id)
      }
      // Refetch files list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.FILES,
      })
      toast.success(
        selectedFiles.size > 1
          ? `${t('Successfully deleted')} ${selectedFiles.size} ${t('files')}`
          : `${t('Successfully deleted')} ${selectedFiles.size} ${t('file')}`,
      )
      setSelectedFiles(new Set())
      setDeleteDialogOpen(false)
      setStackedDrawerFileId(undefined)
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId: projectId!, bucketId: bucketId! },
        search: (prev: Record<string, unknown>) => {
          const next = { ...prev } as Record<string, unknown>
          delete next.file
          delete next.filePanel
          return next
        },
        replace: true,
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete files'))
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
    fileSelectionAnchorRef.current = fileId
    setSelectedFiles(newSelected)
  }

  useEffect(() => {
    const syncModifierActive = (event: KeyboardEvent) => {
      setFileMultiSelectModifierActive(
        event.shiftKey || event.ctrlKey || event.metaKey,
      )
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (
        event.key === 'Shift' ||
        event.key === 'Control' ||
        event.key === 'Meta'
      ) {
        setFileMultiSelectModifierActive(true)
      }
    }
    const onBlur = () => setFileMultiSelectModifierActive(false)
    window.addEventListener('keydown', onKeyDown)
    window.addEventListener('keyup', syncModifierActive)
    window.addEventListener('blur', onBlur)
    return () => {
      window.removeEventListener('keydown', onKeyDown)
      window.removeEventListener('keyup', syncModifierActive)
      window.removeEventListener('blur', onBlur)
    }
  }, [])

  const isFileMultiSelectModifierClick = (event: MouseEvent) =>
    event.shiftKey || event.ctrlKey || event.metaKey

  const preventFileRowTextSelectionOnPointer = (event: MouseEvent) => {
    if (isFileMultiSelectModifierClick(event)) {
      event.preventDefault()
    }
  }

  const selectableFiles = useMemo(
    () => files.filter((f) => !isFilePending(f)),
    [files],
  )

  const selectFilesWithShift = useCallback(
    (fileId: string) => {
      const anchorId =
        fileSelectionAnchorRef.current ??
        (selectedFiles.size > 0 ? Array.from(selectedFiles)[0] : fileId)
      const anchorIndex = selectableFiles.findIndex((f) => f.$id === anchorId)
      const clickIndex = selectableFiles.findIndex((f) => f.$id === fileId)
      if (anchorIndex === -1 || clickIndex === -1) {
        const next = new Set(selectedFiles)
        if (next.has(fileId)) next.delete(fileId)
        else next.add(fileId)
        fileSelectionAnchorRef.current = fileId
        setSelectedFiles(next)
        return
      }
      const start = Math.min(anchorIndex, clickIndex)
      const end = Math.max(anchorIndex, clickIndex)
      const next = new Set(selectedFiles)
      for (let i = start; i <= end; i++) {
        next.add(selectableFiles[i].$id)
      }
      fileSelectionAnchorRef.current = fileId
      setSelectedFiles(next)
    },
    [selectableFiles, selectedFiles],
  )

  const handleFileMultiSelectPointer = (fileId: string, event: MouseEvent) => {
    if (!isFileMultiSelectModifierClick(event)) return false
    event.preventDefault()
    if (event.shiftKey) {
      selectFilesWithShift(fileId)
    } else {
      toggleFile(fileId)
    }
    return true
  }

  const clearInspectorFile = useCallback(() => {
    setStackedDrawerFileId(undefined)
    if (!projectId || !bucketId) return
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev }
        delete next.file
        delete next.filePanel
        return next
      },
      replace: true,
    })
  }, [navigate, projectId, bucketId])

  const handleFileRowClick = (file: Models.File, event: MouseEvent) => {
    if (handleFileMultiSelectPointer(file.$id, event)) return
    fileSelectionAnchorRef.current = file.$id

    if (isFilesStackedLayout) {
      setStackedDrawerFileId(file.$id)
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: {
          projectId: projectId!,
          bucketId: bucketId!,
        },
        search: (prev: Record<string, unknown>) => {
          const next = { ...prev } as Record<string, unknown>
          next.file = file.$id
          return next
        },
        replace: true,
      })
      return
    }

    const isActive = inspectorFileId === file.$id
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: {
        projectId: projectId!,
        bucketId: bucketId!,
      },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        if (isActive) delete next.file
        else next.file = file.$id
        return next
      },
      replace: true,
    })
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

  const filesTablePanePrefs = account?.prefs as UserPrefs | undefined
  const hasFilesTablePaneWidthPref = hasStorageFilesTablePaneWidthPref(
    filesTablePanePrefs,
  )
  const hasFilesTablePaneWidthPrefRef = useRef(hasFilesTablePaneWidthPref)
  hasFilesTablePaneWidthPrefRef.current = hasFilesTablePaneWidthPref
  const [fileTablePaneWidthPx, setFileTablePaneWidthPx] = useState(() => {
    const parsed = parseStorageFilesTablePaneWidthPx(
      getConsoleAccountFromCache(queryClient)?.prefs as UserPrefs | undefined,
    )
    if (parsed !== null) return parsed
    return defaultStorageFilesTablePaneWidthPx(
      STORAGE_FILES_TABLE_PREVIEW_SPLIT_MIN_VIEWPORT_PX,
    )
  })
  const [isFilesSplitResizing, setIsFilesSplitResizing] = useState(false)
  const isFilesSplitResizingRef = useRef(false)
  const filesSplitContainerRef = useRef<HTMLDivElement>(null)
  const fileTablePaneWidthRef = useRef(fileTablePaneWidthPx)
  fileTablePaneWidthRef.current = fileTablePaneWidthPx

  const handleFilesSplitPointerDown = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>) => {
      e.preventDefault()
      setBodyResizeDragActive(true)
      isFilesSplitResizingRef.current = true
      setIsFilesSplitResizing(true)
      const btn = e.currentTarget
      btn.setPointerCapture(e.pointerId)
      const startX = e.clientX
      const startW = fileTablePaneWidthRef.current
      const splitEl = filesSplitContainerRef.current
      const onMove = (ev: globalThis.PointerEvent) => {
        if (!splitEl) return
        const next = clampSplitFirstPaneWidthPx(
          startW +
            horizontalResizeDeltaPx(
              startX,
              ev.clientX,
              isRtlElement(splitEl),
            ),
          splitEl.clientWidth,
          STORAGE_FILES_TABLE_PANE_MIN_PX,
          STORAGE_FILES_TABLE_PANE_MAX_PX,
          STORAGE_FILES_PREVIEW_PANE_MIN_PX,
        )
        setFileTablePaneWidthPx(next)
        fileTablePaneWidthRef.current = next
      }
      const onUp = () => {
        const finalWidth = fileTablePaneWidthRef.current
        persistTablePaneWidthPx(finalWidth)
        setBodyResizeDragActive(false)
        isFilesSplitResizingRef.current = false
        setIsFilesSplitResizing(false)
        try {
          btn.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [persistTablePaneWidthPx],
  )

  useLayoutEffect(() => {
    if (isFilesStackedLayout) return
    const el = filesSplitContainerRef.current
    if (!el) return
    const fitOnContainerResize = () => {
      if (isFilesSplitResizingRef.current) return
      const containerW = el.clientWidth
      if (containerW <= 0) return
      setFileTablePaneWidthPx((w) => {
        if (!hasFilesTablePaneWidthPrefRef.current) {
          return defaultStorageFilesTablePaneWidthPx(containerW)
        }
        return fitSplitFirstPaneWidthOnContainerResize(
          w,
          containerW,
          STORAGE_FILES_TABLE_PANE_MIN_PX,
          STORAGE_FILES_TABLE_PANE_MAX_PX,
          STORAGE_FILES_PREVIEW_PANE_MIN_PX,
        )
      })
    }
    const ro = new ResizeObserver(fitOnContainerResize)
    ro.observe(el)
    fitOnContainerResize()
    return () => ro.disconnect()
  }, [isFilesStackedLayout])

  const [fileListColumnWidths, setFileListColumnWidths] = useState<
    Record<StorageFilesListColumnWidthKey, number>
  >(() => {
    const cached = getConsoleAccountFromCache(queryClient)
    const raw = getStorageFilesListColumnWidthsFromPrefs(
      cached?.prefs as UserPrefs | undefined,
    )
    return mergeStorageFilesListColumnWidthsWithDefaults(raw)
  })

  const filesTableMinWidthPx = useMemo(() => {
    const dataWidth = splitFilesTable
      ? STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS.reduce(
          (sum, key) => sum + fileListColumnWidths[key],
          0,
        )
      : 180 + 160 + 140 + 120 + 180 + 180
    return STORAGE_FILES_TABLE_EDGE_COL_PX * 2 + dataWidth
  }, [fileListColumnWidths, splitFilesTable])

  const [resizingFileColumnKey, setResizingFileColumnKey] =
    useState<StorageFilesListResizableColumnWidthKey | null>(null)

  const fileListColumnWidthsRef = useRef(fileListColumnWidths)
  if (resizingFileColumnKey == null) {
    fileListColumnWidthsRef.current = fileListColumnWidths
  }

  const fileListColumnColRefs = useRef(
    new Map<StorageFilesListColumnWidthKey, HTMLTableColElement>(),
  )

  const setFileColumnColRef = useCallback(
    (key: StorageFilesListColumnWidthKey) =>
      (el: HTMLTableColElement | null) => {
        if (el) fileListColumnColRefs.current.set(key, el)
        else fileListColumnColRefs.current.delete(key)
      },
    [],
  )

  const filesTableScrollRef = useRef<HTMLDivElement>(null)
  const filesTableLayerRef = useRef<HTMLDivElement>(null)
  const fileColumnHeaderThRefs = useRef(
    new Map<StorageFilesListColumnWidthKey, HTMLTableCellElement>(),
  )
  const fileColumnRailRefs = useRef(
    new Map<StorageFilesListResizableColumnWidthKey, HTMLButtonElement>(),
  )

  const setFileColumnHeaderThRef = useCallback(
    (key: StorageFilesListColumnWidthKey) =>
      (node: HTMLTableCellElement | null) => {
        if (node) fileColumnHeaderThRefs.current.set(key, node)
        else fileColumnHeaderThRefs.current.delete(key)
      },
    [],
  )

  const repositionFileColumnRailsOnly = useCallback(() => {
    const layer = filesTableLayerRef.current
    if (!layer) return
    for (const col of STORAGE_FILES_LIST_RESIZABLE_COLUMN_WIDTH_KEYS) {
      const th = fileColumnHeaderThRefs.current.get(col)
      const rail = fileColumnRailRefs.current.get(col)
      if (!th || !rail) continue
      applyColumnResizeRailPosition(rail, layer, th)
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const acct =
          account ??
          (await fetchConsoleAccount())
        if (cancelled) return
        const raw = getStorageFilesListColumnWidthsFromPrefs(
          acct.prefs as UserPrefs | undefined,
        )
        const next = mergeStorageFilesListColumnWidthsWithDefaults(raw)
        setFileListColumnWidths((prev) => {
          let same = true
          for (const k of STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS) {
            if (prev[k] !== next[k]) {
              same = false
              break
            }
          }
          return same ? prev : next
        })
      } catch {
        if (!cancelled) {
          setFileListColumnWidths(
            mergeStorageFilesListColumnWidthsWithDefaults(undefined),
          )
        }
      }
    })()
    return () => {
      cancelled = true
    }
  }, [account])

  const getFileColumnWidthPx = useCallback(
    (key: StorageFilesListColumnWidthKey) => fileListColumnWidths[key],
    [fileListColumnWidths],
  )

  const applyDraggedFileColumnWidthPx = useCallback(
    (columnKey: StorageFilesListColumnWidthKey, widthPx: number) => {
      const next = clampStorageFilesListDataColumnWidthPx(widthPx)
      fileListColumnWidthsRef.current = {
        ...fileListColumnWidthsRef.current,
        [columnKey]: next,
      }
      const colEl = fileListColumnColRefs.current.get(columnKey)
      if (colEl) {
        colEl.style.width = `${next}px`
        colEl.style.minWidth = `${next}px`
      }
      const thEl = fileColumnHeaderThRefs.current.get(columnKey)
      if (thEl) {
        thEl.style.width = `${next}px`
        thEl.style.minWidth = `${STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX}px`
      }
      repositionFileColumnRailsOnly()
    },
    [repositionFileColumnRailsOnly],
  )

  const persistFileListColumnWidths = useCallback(
    async (widths: Record<StorageFilesListColumnWidthKey, number>) => {
      try {
        const acct = await fetchConsoleAccount()
        const prefs = mergeStorageFilesListColumnWidthsIntoPrefs(
          (acct.prefs || {}) as UserPrefs,
          widths as Record<string, number>,
        )
        const updatedAccount = await updateAccountPrefs(prefs)
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      } catch {
        /* preference save is best-effort */
      }
    },
    [queryClient],
  )

  useLayoutEffect(() => {
    if (!splitFilesTable) return
    const scroll = filesTableScrollRef.current
    const layer = filesTableLayerRef.current
    if (!scroll || !layer) return

    const measure = () => {
      repositionFileColumnRailsOnly()
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(scroll)
    ro.observe(layer)
    scroll.addEventListener('scroll', measure, { passive: true })
    window.addEventListener('resize', measure)
    return () => {
      ro.disconnect()
      scroll.removeEventListener('scroll', measure)
      window.removeEventListener('resize', measure)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- stable layout key; widths/scroll/resize drive rail positions
  }, [
    STORAGE_FILES_LIST_COLUMN_RESIZE_LAYOUT_KEY,
    splitFilesTable,
    fileListColumnWidths,
    files.length,
    repositionFileColumnRailsOnly,
  ])

  const handleFileColumnResizePointerDown = useCallback(
    (columnKey: StorageFilesListResizableColumnWidthKey) =>
      (e: ReactPointerEvent<HTMLButtonElement>) => {
        if (!splitFilesTable) return
        e.preventDefault()
        e.stopPropagation()
        setBodyResizeDragActive(true)
        const btn = e.currentTarget
        btn.setPointerCapture(e.pointerId)
        const startX = e.clientX
        const initialWidth = getFileColumnWidthPx(columnKey)
        flushSync(() => {
          setResizingFileColumnKey(columnKey)
        })
        applyDraggedFileColumnWidthPx(columnKey, initialWidth)
        const onMove = (ev: globalThis.PointerEvent) => {
          applyDraggedFileColumnWidthPx(
            columnKey,
            initialWidth + horizontalResizeDeltaPx(startX, ev.clientX),
          )
        }
        const onUp = () => {
          setBodyResizeDragActive(false)
          try {
            btn.releasePointerCapture(e.pointerId)
          } catch {
            /* already released */
          }
          window.removeEventListener('pointermove', onMove)
          window.removeEventListener('pointerup', onUp)
          window.removeEventListener('pointercancel', onUp)
          flushSync(() => {
            setResizingFileColumnKey(null)
            setFileListColumnWidths({ ...fileListColumnWidthsRef.current })
          })
          void persistFileListColumnWidths(fileListColumnWidthsRef.current)
        }
        window.addEventListener('pointermove', onMove)
        window.addEventListener('pointerup', onUp)
        window.addEventListener('pointercancel', onUp)
      },
    [
      splitFilesTable,
      applyDraggedFileColumnWidthPx,
      getFileColumnWidthPx,
      persistFileListColumnWidths,
    ],
  )

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
      <ServiceHeader
        title={
          displayBucket ? (
            <div className="flex min-w-0 items-center gap-2">
              <span className="truncate">{displayBucket.name}</span>
              <CopyableId id={displayBucket.$id} size="xs" className="shrink-0" />
            </div>
          ) : (
            t('Bucket')
          )
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'files' ? t('Search files...') : undefined
        }
        searchValue={activeTab === 'files' ? searchInput : ''}
        onSearchChange={activeTab === 'files' ? handleSearchChange : undefined}
        createLabel={activeTab === 'files' ? t('Create file') : undefined}
        createAnalyticsAction={
          activeTab === 'files' ? 'upload-file' : undefined
        }
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
          displayBucket && !displayBucket.enabled ? (
            <div className="border-b border-border bg-amber-500/5">
              <div className="w-full px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-amber-500/30 bg-transparent"
                >
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    {t('Bucket is disabled')}
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                    <span className="inline">
                      {t(
                        'This bucket is disabled and not accessible to end users through the API. Console actions remain available.',
                      )}{' '}
                      <Link
                        to="/projects/$projectId/storage/$bucketId/settings"
                        params={{ projectId: projectId!, bucketId: bucketId! }}
                        className="font-medium underline hover:no-underline inline"
                      >
                        {t('Enable it in the Settings tab')}
                      </Link>{' '}
                      {t('to make it available to end users.')}
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
          <div
            className={cn(
              'relative flex min-h-0 flex-1 flex-col overflow-hidden transition-colors duration-200 ease-out',
              filesSectionFileDragActive && 'bg-muted/25',
            )}
            onDragEnter={handleFilesShellDragEnter}
            onDragLeave={handleFilesShellDragLeave}
            onDragOverCapture={handleFilesShellDragOverCapture}
            onDropCapture={handleFilesShellDropCapture}
          >
            {filesSectionFileDragActive ? (
              <div
                className="shrink-0 border-b border-border bg-card/80 backdrop-blur-sm animate-in fade-in slide-in-from-top-2 duration-200 motion-reduce:animate-none"
                role="status"
              >
                <div className="flex items-start gap-3 px-4 py-3 sm:px-6">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50 text-muted-foreground">
                    <Upload className="h-4 w-4" />
                  </div>
                  <div className="min-w-0 flex-1 pt-0.5">
                    <p className="text-[13px] font-medium leading-snug text-foreground">
                      {t('Drop files to upload')}
                    </p>
                    <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                      {t('Review and upload in the dialog that opens next.')}
                    </p>
                  </div>
                </div>
              </div>
            ) : null}
            {showFilesLoading ? (
              <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6 py-12">
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading files…')}
                </p>
              </div>
            ) : files.length === 0 ? (
              <div className="relative flex min-h-0 min-w-0 flex-1 flex-col border-t border-border bg-muted/20">
                <div className="absolute inset-0 flex flex-col items-center justify-center overflow-y-auto p-6 sm:p-10">
                  <EmptyState
                    icon={FileText}
                    title={
                      urlSearch?.trim() || filterMap.size > 0
                        ? t('No files match your filters')
                        : t('No files yet')
                    }
                    description={
                      urlSearch?.trim() || filterMap.size > 0
                        ? t(
                            'Try adjusting or clearing filters. You can also drop files anywhere here to upload new ones.',
                          )
                        : t(
                            'Drag and drop files anywhere in this view, or use Create file in the header to upload your first file.',
                          )
                    }
                    isEmpty={!urlSearch?.trim() && filterMap.size === 0}
                    hasFilters={!!urlSearch?.trim() || filterMap.size > 0}
                    variant="default"
                    iconSize="xl"
                    className="w-full max-w-lg"
                  />
                </div>
              </div>
            ) : (
              <div className="flex min-h-0 flex-1 flex-col">
                <div
                  ref={filesSplitContainerRef}
                  className={cn(
                    'relative min-h-0 flex-1 overflow-hidden',
                    useInlineFilePreviewPane
                      ? isFilesStackedLayout
                        ? 'flex flex-col'
                        : 'grid'
                      : 'flex flex-row',
                  )}
                  style={
                    splitFilesTable
                      ? storageFilesSplitGridStyle(fileTablePaneWidthPx)
                      : undefined
                  }
                >
                  <div
                    ref={filesTableScrollRef}
                    className={cn(
                      'min-h-0 min-w-0 overflow-auto overscroll-contain',
                      STORAGE_FILES_SPLIT_PANE_BG_CLASS,
                      fileMultiSelectModifierActive && 'select-none',
                      splitFilesTable
                        ? cn(
                            'col-start-1 row-start-1 border-e border-border',
                            files.length === 0 && 'border-t border-border',
                          )
                        : cn(
                            'border-border',
                            isFilesStackedLayout
                              ? 'min-h-0 w-full flex-1'
                              : 'shrink-0 border-e',
                            files.length === 0 && 'border-t border-border',
                          ),
                    )}
                  >
                      <div
                        ref={splitFilesTable ? filesTableLayerRef : undefined}
                        className={STORAGE_SPREADSHEET_TABLE_LAYER_CLASS}
                        style={{ minWidth: filesTableMinWidthPx }}
                      >
                    <table
                      className={cn(
                        'w-full border-collapse',
                        splitFilesTable && 'table-fixed',
                      )}
                    >
                      <colgroup>
                        <col
                          style={
                            splitFilesTable
                              ? {
                                  width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                }
                              : { width: 40 }
                          }
                        />
                        {STORAGE_FILES_LIST_COLUMN_WIDTH_KEYS.map((key) => {
                          const isDragResize =
                            splitFilesTable && resizingFileColumnKey === key
                          return (
                            <col
                              key={key}
                              ref={
                                splitFilesTable
                                  ? setFileColumnColRef(key)
                                  : undefined
                              }
                              style={
                                splitFilesTable
                                  ? isDragResize
                                    ? undefined
                                    : {
                                        width: fileListColumnWidths[key],
                                        minWidth: fileListColumnWidths[key],
                                      }
                                  : STORAGE_FILES_STACKED_COL_STYLES[key]
                              }
                            />
                          )
                        })}
                        <col
                          style={
                            splitFilesTable
                              ? {
                                  width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                }
                              : {
                                  width: 40,
                                  minWidth: 40,
                                  maxWidth: 40,
                                }
                          }
                        />
                      </colgroup>
                      <thead className={STORAGE_SPREADSHEET_STICKY_THEAD_CLASS}>
                        <tr>
                          <th
                            className={cn(
                              'sticky start-0 z-40 w-10 bg-background px-2 text-center',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              splitFilesTable &&
                                'min-w-[40px] max-w-[40px] shrink-0 box-border',
                              STORAGE_SPREADSHEET_HEADER_STICKY_CHECKBOX_SHADOW,
                            )}
                            style={
                              splitFilesTable
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('$id')
                                : undefined
                            }
                            className={cn(
                              splitFilesTable ? 'min-w-0 px-3' : 'w-[180px] px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? {
                                    width: fileListColumnWidths.$id,
                                    minWidth:
                                      STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                  }
                                : undefined
                            }
                          >
                            <div className="flex h-full items-center gap-2">
                              <Fingerprint className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $id
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$id')}
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('name')
                                : undefined
                            }
                            className={cn(
                              'px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? resizingFileColumnKey === 'name'
                                  ? undefined
                                  : {
                                      width: fileListColumnWidths.name,
                                      minWidth:
                                        STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                    }
                                : undefined
                            }
                          >
                            <div className="flex h-full min-w-0 items-center gap-2 pe-1.5">
                              <FileIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                name
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('name')}
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('mimeType')
                                : undefined
                            }
                            className={cn(
                              'px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? resizingFileColumnKey === 'mimeType'
                                  ? undefined
                                  : {
                                      width: fileListColumnWidths.mimeType,
                                      minWidth:
                                        STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                    }
                                : undefined
                            }
                          >
                            <div className="flex h-full min-w-0 items-center gap-2 pe-1.5">
                              <span className="text-[12px] font-medium text-foreground">
                                mimeType
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('mimeType')}
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                              >
                                {urlSortBy === 'mimeType' ? (
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('sizeOriginal')
                                : undefined
                            }
                            className={cn(
                              'px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? resizingFileColumnKey === 'sizeOriginal'
                                  ? undefined
                                  : {
                                      width: fileListColumnWidths.sizeOriginal,
                                      minWidth:
                                        STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                    }
                                : undefined
                            }
                          >
                            <div className="flex h-full min-w-0 items-center gap-2 whitespace-nowrap pe-1.5">
                              <span className="text-[12px] font-medium text-foreground">
                                size
                              </span>
                              <button
                                type="button"
                                onClick={() =>
                                  handleFileColumnSort('sizeOriginal')
                                }
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('$createdAt')
                                : undefined
                            }
                            className={cn(
                              splitFilesTable ? 'min-w-0 px-3' : 'w-[180px] px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? resizingFileColumnKey === '$createdAt'
                                  ? undefined
                                  : {
                                      width: fileListColumnWidths.$createdAt,
                                      minWidth:
                                        STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                    }
                                : undefined
                            }
                          >
                            <div className="flex h-full items-center gap-2">
                              <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $createdAt
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$createdAt')}
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
                            ref={
                              splitFilesTable
                                ? setFileColumnHeaderThRef('$updatedAt')
                                : undefined
                            }
                            className={cn(
                              splitFilesTable ? 'min-w-0 px-3' : 'w-[180px] px-3',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              STORAGE_SPREADSHEET_HEADER_CELL_BORDER,
                            )}
                            style={
                              splitFilesTable
                                ? resizingFileColumnKey === '$updatedAt'
                                  ? undefined
                                  : {
                                      width: fileListColumnWidths.$updatedAt,
                                      minWidth:
                                        STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                    }
                                : undefined
                            }
                          >
                            <div className="flex h-full items-center gap-2">
                              <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <span className="text-[12px] font-medium text-foreground">
                                $updatedAt
                              </span>
                              <button
                                type="button"
                                onClick={() => handleFileColumnSort('$updatedAt')}
                                className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
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
                          <th
                            className={cn(
                              'sticky end-0 z-30 bg-background p-0',
                              STORAGE_FILES_TABLE_HEADER_TH_CLASS,
                              splitFilesTable && 'shrink-0 box-border',
                              STORAGE_SPREADSHEET_HEADER_STICKY_ACTIONS_SHADOW,
                            )}
                            style={{
                              width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                              minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                              maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                            }}
                          />
                        </tr>
                      </thead>
                      <tbody>
                        {files.map((file) => {
                          const pending = isFilePending(file)
                          const isPreviewRow =
                            !pending && effectiveInspectorFileId === file.$id
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
                                    ? 'cursor-default hover:bg-transparent'
                                    : 'cursor-pointer',
                                  !pending &&
                                    (isPreviewRow
                                      ? 'bg-muted/25 ring-1 ring-inset ring-border/20 hover:bg-muted/35'
                                      : selectedFiles.has(file.$id)
                                        ? 'bg-muted'
                                        : 'hover:bg-muted/50'),
                                )}
                                onMouseDown={preventFileRowTextSelectionOnPointer}
                                onMouseEnter={() => {
                                  if (pending) return
                                  prefetchInspectorFileData(file.$id)
                                }}
                                onClick={(e) => {
                                  if (pending) return
                                  if (
                                    isFilesStackedLayout &&
                                    stackedRowTouchActivateRef.current
                                  ) {
                                    stackedRowTouchActivateRef.current = false
                                    return
                                  }
                                  const target = e.target as HTMLElement
                                  if (
                                    target.closest('button') ||
                                    target.closest('[role="checkbox"]')
                                  ) {
                                    return
                                  }
                                  handleFileRowClick(file, e)
                                }}
                                onPointerUp={
                                  isFilesStackedLayout
                                    ? (e) => {
                                        if (pending || e.pointerType !== 'touch')
                                          return
                                        const target = e.target as HTMLElement
                                        if (
                                          target.closest('button') ||
                                          target.closest('[role="checkbox"]')
                                        ) {
                                          return
                                        }
                                        stackedRowTouchActivateRef.current = true
                                        handleFileRowClick(
                                          file,
                                          e as unknown as MouseEvent,
                                        )
                                      }
                                    : undefined
                                }
                              >
                                <td
                                  className={cn(
                                    'sticky start-0 w-10 border-b border-border px-2 py-1.5 text-center',
                                    SPREADSHEET_STICKY_BODY_Z,
                                    splitFilesTable &&
                                      'min-w-[40px] max-w-[40px] shrink-0 box-border',
                                    STORAGE_SPREADSHEET_STICKY_START_EDGE_SHADOW,
                                    STORAGE_SPREADSHEET_BODY_STICKY_EDGE_BG_CLASS,
                                  )}
                                  style={
                                    splitFilesTable
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
                                        onClick={(e) => {
                                          e.stopPropagation()
                                          if (e.shiftKey) {
                                            e.preventDefault()
                                            selectFilesWithShift(file.$id)
                                            return
                                          }
                                          toggleFile(file.$id)
                                        }}
                                      />
                                    </div>
                                  ) : null}
                                </td>
                                <td
                                  className={cn(
                                    splitFilesTable
                                      ? 'min-w-0 px-3 py-1.5'
                                      : 'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="$id"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.$id,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  {!pending ? (
                                    <CopyableId id={file.$id} size="xs" />
                                  ) : (
                                    <span className="text-[12px] text-muted-foreground">
                                      -
                                    </span>
                                  )}
                                </td>
                                <td
                                  className={cn(
                                    'max-w-0 px-3 py-1.5',
                                    splitFilesTable && 'min-w-0',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="name"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.name,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  <div className="flex min-w-0 items-center gap-2">
                                    <span className="min-w-0 flex-1 truncate text-[12px] text-foreground">
                                      {file.name}
                                    </span>
                                    {pending ? (
                                      <Badge
                                        variant="secondary"
                                        className="shrink-0 text-[10px] font-medium"
                                      >
                                        {t('Pending')}
                                      </Badge>
                                    ) : null}
                                  </div>
                                </td>
                                <td
                                  className={cn(
                                    'px-3 py-1.5',
                                    splitFilesTable && 'min-w-0',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="mimeType"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.mimeType,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  <span className="block truncate font-mono text-[12px] text-muted-foreground">
                                    {file.mimeType || '-'}
                                  </span>
                                </td>
                                <td
                                  className={cn(
                                    splitFilesTable
                                      ? 'min-w-0 shrink-0 whitespace-nowrap px-3 py-1.5 text-end tabular-nums'
                                      : 'w-[120px] min-w-[120px] shrink-0 whitespace-nowrap px-3 py-1.5 text-end tabular-nums',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="sizeOriginal"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.sizeOriginal,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  <span className="inline-block font-mono text-[12px] text-muted-foreground">
                                    {formatBytes(file.sizeOriginal)}
                                  </span>
                                </td>
                                <td
                                  className={cn(
                                    splitFilesTable
                                      ? 'min-w-0 px-3 py-1.5'
                                      : 'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="$createdAt"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.$createdAt,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  {file.$createdAt ? (
                                    <DateTooltip
                                      date={new Date(file.$createdAt)}
                                      className="text-[12px] text-muted-foreground"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-foreground/60">
                                      {t('N/A')}
                                    </span>
                                  )}
                                </td>
                                <td
                                  className={cn(
                                    splitFilesTable
                                      ? 'min-w-0 px-3 py-1.5'
                                      : 'w-[180px] px-3 py-1.5',
                                    STORAGE_SPREADSHEET_BODY_CELL_BORDER,
                                    pending && 'opacity-70',
                                  )}
                                  data-column="$updatedAt"
                                  style={
                                    splitFilesTable
                                      ? {
                                          width: fileListColumnWidths.$updatedAt,
                                          minWidth:
                                            STORAGE_FILES_LIST_DATA_COLUMN_MIN_WIDTH_PX,
                                        }
                                      : undefined
                                  }
                                >
                                  {file.$updatedAt ? (
                                    <DateTooltip
                                      date={new Date(file.$updatedAt)}
                                      className="text-[12px] text-muted-foreground"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-foreground/60">
                                      {t('N/A')}
                                    </span>
                                  )}
                                </td>
                                <td
                                  className={cn(
                                    'sticky end-0 border-b border-border p-0',
                                    SPREADSHEET_STICKY_BODY_Z,
                                    splitFilesTable && 'shrink-0 box-border',
                                    STORAGE_SPREADSHEET_STICKY_END_EDGE_SHADOW,
                                    STORAGE_SPREADSHEET_BODY_STICKY_EDGE_BG_CLASS,
                                  )}
                                  style={{
                                    width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                    minWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                    maxWidth: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                  }}
                                >
                                  <div
                                    className="flex h-full items-center justify-center py-1.5"
                                    style={{
                                      width: STORAGE_FILES_TABLE_EDGE_COL_PX,
                                    }}
                                  >
                                    <FileRowActionsMenu
                                      projectId={projectId!}
                                      bucketId={bucketId!}
                                      file={{
                                        id: file.$id,
                                        name: file.name,
                                        pending,
                                      }}
                                    />
                                  </div>
                                </td>
                              </tr>
                            </FileContextMenu>
                          )
                        })}
                      </tbody>
                    </table>
                    {splitFilesTable
                      ? STORAGE_FILES_LIST_RESIZABLE_COLUMN_WIDTH_KEYS.map((col) => (
                          <button
                            key={`col-resize-rail-${col}`}
                            ref={(node) => {
                              if (node) {
                                fileColumnRailRefs.current.set(col, node)
                              } else {
                                fileColumnRailRefs.current.delete(col)
                              }
                            }}
                            type="button"
                            aria-label={`${t('Resize')} ${col} ${t('column width')}`}
                            aria-orientation="vertical"
                            role="separator"
                            tabIndex={0}
                            onPointerDown={handleFileColumnResizePointerDown(
                              col,
                            )}
                            className={cn(
                              STORAGE_FILES_LIST_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS,
                              resizingFileColumnKey === col &&
                                'before:opacity-100',
                              'focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                            )}
                          />
                        ))
                      : null}
                      </div>
                    </div>
                  <div
                    className={cn(
                      'h-[54px] shrink-0 border-t border-border bg-background',
                      splitFilesTable
                        ? 'col-start-1 row-start-2 border-e'
                        : 'w-full',
                    )}
                  >
                    <div className="@container flex h-full items-center px-4">
                      <div className="flex-1 min-w-0">
                        <Pagination
                          currentPage={displayedPage}
                          totalItems={filesTotal}
                          pageSize={urlLimit}
                          pageSizeOptions={[10, 25, 50, 100]}
                          onPageChange={handlePageChange}
                          onPageSizeChange={handlePageSizeChange}
                          itemLabel={t('files')}
                          className="h-full min-h-0 border-0 mt-0 py-0"
                        />
                      </div>
                    </div>
                  </div>
                  {!isFilesStackedLayout ? (
                    <div
                      className={cn(
                        'flex min-h-0 min-w-0 flex-col overflow-hidden',
                        STORAGE_FILES_SPLIT_PANE_BG_CLASS,
                        splitFilesTable
                          ? 'col-start-2 row-span-2 row-start-1'
                          : 'min-w-0 flex-1',
                      )}
                    >
                      <FileInspectorPanel
                        projectId={projectId!}
                        bucketId={bucketId!}
                        fileId={inspectorFileId}
                        panelTab={
                          search?.filePanel === 'overview' ||
                          search?.filePanel === 'permissions' ||
                          search?.filePanel === 'tokens' ||
                          search?.filePanel === 'security'
                            ? search.filePanel
                            : undefined
                        }
                      />
                    </div>
                  ) : null}
                  {splitFilesTable ? (
                    <button
                      type="button"
                      aria-label={t('Resize file table and preview')}
                      aria-orientation="vertical"
                      role="separator"
                      tabIndex={0}
                      style={horizontalSplitHandleStyle(fileTablePaneWidthPx)}
                      onKeyDown={(e) => {
                        const splitEl = filesSplitContainerRef.current
                        if (!splitEl) return
                        const step = 24
                        if (e.key === 'ArrowLeft') {
                          e.preventDefault()
                          setFileTablePaneWidthPx((w) => {
                            const next = clampSplitFirstPaneWidthPx(
                              w - step,
                              splitEl.clientWidth,
                              STORAGE_FILES_TABLE_PANE_MIN_PX,
                              STORAGE_FILES_TABLE_PANE_MAX_PX,
                              STORAGE_FILES_PREVIEW_PANE_MIN_PX,
                            )
                            fileTablePaneWidthRef.current = next
                            persistTablePaneWidthPx(next)
                            return next
                          })
                        } else if (e.key === 'ArrowRight') {
                          e.preventDefault()
                          setFileTablePaneWidthPx((w) => {
                            const next = clampSplitFirstPaneWidthPx(
                              w + step,
                              splitEl.clientWidth,
                              STORAGE_FILES_TABLE_PANE_MIN_PX,
                              STORAGE_FILES_TABLE_PANE_MAX_PX,
                              STORAGE_FILES_PREVIEW_PANE_MIN_PX,
                            )
                            fileTablePaneWidthRef.current = next
                            persistTablePaneWidthPx(next)
                            return next
                          })
                        }
                      }}
                      className={cn(
                        'absolute top-0 bottom-0 z-30 w-1.5 cursor-col-resize border-0 bg-transparent p-0 outline-none transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
                        isFilesSplitResizing &&
                          'bg-primary/30 dark:bg-sidebar-accent/70',
                        'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                      )}
                      onPointerDown={handleFilesSplitPointerDown}
                    />
                  ) : null}
                </div>

                {selectedFiles.size > 0 && (
                  <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:px-0 sm:w-auto sm:max-w-none">
                    <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 sm:min-w-[400px] sm:gap-3 sm:px-6">
                      <Badge variant="secondary" className="h-6 px-2.5">
                        {selectedFiles.size}{' '}
                        {selectedFiles.size > 1
                          ? t('files selected')
                          : t('file selected')}
                      </Badge>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setSelectedFiles(new Set())}
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

                <Dialog
                  open={deleteDialogOpen}
                  onOpenChange={setDeleteDialogOpen}
                >
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-start">
                      <DialogTitle>{t('Delete Files')}</DialogTitle>
                      <DialogDescription className="mt-2 text-[13px]">
                        {t('Are you sure you want to delete')}{' '}
                        {selectedFiles.size}{' '}
                        {selectedFiles.size > 1 ? t('files') : t('file')}?{' '}
                        {t('This action cannot be undone.')}
                      </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
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
            )}
            {isFilesStackedLayout ? (
              <FileInspectorDrawer
                open={!!effectiveInspectorFileId}
                onOpenChange={(open) => {
                  if (!open) clearInspectorFile()
                }}
                projectId={projectId!}
                bucketId={bucketId!}
                fileId={effectiveInspectorFileId}
                panelTab={
                  search?.filePanel === 'overview' ||
                  search?.filePanel === 'permissions' ||
                  search?.filePanel === 'tokens' ||
                  search?.filePanel === 'security'
                    ? search.filePanel
                    : undefined
                }
              />
            ) : null}
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
        bucket={displayBucket}
        isLoading={false}
        prefillFiles={uploadPrefillFiles}
        onPrefillConsumed={clearUploadPrefill}
      />
    </div>
  )
}
