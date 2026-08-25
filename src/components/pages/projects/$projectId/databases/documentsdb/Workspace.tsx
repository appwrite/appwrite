// Database product workspace (see ../Workspace.tsx router).
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Plus,
  Layers,
  Settings,
  Table2,
  ChevronLeft,
  ChevronRight,
  AlertCircle,
  ArrowUpDown,
  Network,
  Download,
  Search,
  Activity,
} from 'lucide-react'

import {
  useState,
  useEffect,
  useRef,
  useCallback,
  useMemo,
  useLayoutEffect,
} from 'react'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectDatabase,
  useProjectTables,
  useProjectCollectionAttributes,
  useProjectCollectionIndexes,
  useProjectTable,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  createProjectDatabase,
  createProjectTable,
  tablesQueryOptions,
  tableQueryOptions,
  collectionAttributesQueryOptions,
  tableRowsQueryOptions,
  type TablesSortBy,
} from '@/lib/react-query/hooks'
import {
  ROWS_DEFAULT_PAGE_SIZE,
  TABLE_WORKSPACE_TABLES_LIST_LIMIT,
} from '@/lib/react-query/hooks/constants'
import { CreateDatabase } from '../CreateDatabase'
import { CreateTable } from '../CreateTable'
import { TableContextMenu } from '../_components/TableContextMenu'
import { DatabaseBackupsNavLink } from '../_components/DatabaseBackupsNavLink'
import { DatabaseSidebarComputeSpec } from '../_components/DatabaseSidebarComputeSpec'
import { DatabaseSidebarNavItem } from '../_components/DatabaseSidebarNavItem'
import {
  DATABASE_SIDEBAR_LIST_STRIP_CLASS,
  DATABASE_SIDEBAR_LIST_STRIP_ROW_CLASS,
  isSpreadsheetLikeTableTab,
} from '../_components/database-sidebar-chrome'
import { DatabaseSelector } from '../_components/DatabaseSelector'
import {
  DatabaseSectionSelector,
  type DatabaseSectionId,
} from '../_components/DatabaseSectionSelector'
import { navigateToDatabaseFromSwitcher } from '@/lib/databases/navigate-to-database-switcher'
import { TableSelector } from '../_components/TableSelector'
import {
  DatabaseMonitorHeaderActions,
  getDefaultMonitorDateRange,
} from '../_components/DatabaseMonitorHeaderActions'
import { DatabaseMonitorMobileNav } from '../_components/DatabaseMonitorMobileNav'
import type { DateRange } from 'react-day-picker'
import { ImportCsv } from '../_components/ImportCsv'
import { ExportCsv } from '../_components/ExportCsv'

import {
  canCreateDatabase,
  canShowTableSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import type { Models } from '@appwrite.io/console'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import {
  DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
  isDedicatedDatabaseProvisioning,
} from '@/lib/databases/dedicated-database-status'

function routeKindForDatabase(
  database:
    | { databaseType?: ApiDatabaseType | string; type?: ApiDatabaseType | string }
    | null
    | undefined,
): DatabaseRouteKind {
  return databaseRouteKindFromApiType(
    database?.databaseType ?? database?.type ?? ApiDatabaseType.Documentsdb,
  )
}
import { TableViewResizableLayout } from '../_components/TableViewResizableLayout'
import {
  useDatabaseRowOperationsAccess,
  useDatabaseTableOperationsAccess,
} from '../_components/DatabaseOperationsLockContext'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'

import { Button } from '@/components/ui/button'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Link,
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
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
  appendDocumentsDbCustomAttributeFilter,
  rowsFilterColumnsFromAttributes,
  tableIndexesFilterColumns,
  type TableIndexForFilters,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Input } from '@/components/ui/input'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  DocumentsRowCreateBridge,
  RowsSpreadsheet,
  TableSecurity,
  TableSettings,
} from './Spreadsheet'
import { IndexesSpreadsheet } from './IndexesSpreadsheet'
import { CollectionAttributesSpreadsheet } from '../_components/CollectionAttributesSpreadsheet'
import { DocumentsJsonSpreadsheet } from '../_components/DocumentsJsonSpreadsheet'
import { Overview } from './Overview'
import {
  DATABASE_TAB_LABELS,
  DATABASE_TAB_TO_OVERVIEW,
  type WorkspaceProps,
} from '../workspace-types'
import { useT } from '@/lib/i18n/translate'

const DB_KIND = 'documentsdb' as const satisfies DatabaseRouteKind
const sidebarTableListScrollTopByKey = new Map<string, number>()

export function Workspace({
  databaseId,
  tableId,
  activeTab,
  databaseTab,
  children,
}: WorkspaceProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as
    | Record<string, unknown>
    | undefined
  const isDatabaseLevelView = tableId === '-' || databaseTab != null
  const { features } = useConsoleProfile()
  const showDesktopTableSidebar = useMediaMinWidth(1024)

  // Sidebar tables list: search, pagination, order (API-backed)
  const [sidebarTablesSearch, setSidebarTablesSearch] = useState('')
  const [sidebarTablesRequestedPage, setSidebarTablesRequestedPage] =
    useState(1)
  const [sidebarTablesDisplayedPage, setSidebarTablesDisplayedPage] =
    useState(1)
  const sidebarTablesPageSize = ROWS_DEFAULT_PAGE_SIZE
  const [sidebarTablesOrder, setSidebarTablesOrder] = useState<'asc' | 'desc'>(
    'asc',
  )
  const [sidebarTablesSortBy, setSidebarTablesSortBy] =
    useState<TablesSortBy>('$createdAt')

  // Fetch database
  const { database, isLoading: databaseLoading } = useProjectDatabase(
    projectId,
    databaseId,
    DB_KIND,
  )
  const provisioning = isDedicatedDatabaseProvisioning(
    (database as { status?: string | null } | null)?.status,
  )
  const provisioningDisabledSections = provisioning
    ? {
        monitor: DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
        backups: DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
        settings: DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
      }
    : undefined

  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const ContainerListIcon =
    dbLabels.sdkListContainersMethod === 'listCollections' ? Layers : Table2
  const dbNav = useMemo(() => dbNavLink(DB_KIND), [])
  const tableNavParams = useMemo(
    () => ({
      projectId,
      dbKind: DB_KIND,
      databaseId,
      resourceId: tableId,
    }),
    [projectId, databaseId, tableId],
  )
  // Fetch tables for the database (first page; same limit as DB route loaders / Export Import)
  const { tables: dbTables, isLoading: tablesLoading } = useProjectTables(
    projectId,
    databaseId,
    DB_KIND,
    0,
    TABLE_WORKSPACE_TABLES_LIST_LIMIT,
  )

  // Requested page query (drives fetch when user changes page)
  const { isFetching: sidebarTablesFetching } = useProjectTables(
    projectId,
    databaseId,
    DB_KIND,
    sidebarTablesRequestedPage - 1,
    sidebarTablesPageSize,
    sidebarTablesSearch.trim() || undefined,
    sidebarTablesOrder,
    sidebarTablesSortBy,
  )

  // Displayed page query (what we show - stays until new page has loaded)
  const {
    tables: sidebarTables,
    total: sidebarTablesTotal,
    isLoading: sidebarTablesLoading,
  } = useProjectTables(
    projectId,
    databaseId,
    DB_KIND,
    sidebarTablesDisplayedPage - 1,
    sidebarTablesPageSize,
    sidebarTablesSearch.trim() || undefined,
    sidebarTablesOrder,
    sidebarTablesSortBy,
  )

  // Update displayed page only when requested page has finished loading
  useEffect(() => {
    if (
      !sidebarTablesFetching &&
      sidebarTablesRequestedPage !== sidebarTablesDisplayedPage
    ) {
      setSidebarTablesDisplayedPage(sidebarTablesRequestedPage)
    }
  }, [
    sidebarTablesFetching,
    sidebarTablesRequestedPage,
    sidebarTablesDisplayedPage,
  ])

  // Keep previous table list visible while search/filter is fetching (no loading flash)
  const lastSidebarTablesRef = useRef<typeof sidebarTables>([])
  if (sidebarTables.length > 0) {
    lastSidebarTablesRef.current = sidebarTables
  }
  const displayedSidebarTables =
    sidebarTablesLoading && sidebarTables.length === 0
      ? lastSidebarTablesRef.current
      : sidebarTables
  const selectedTable =
    tableId === '-' ? undefined : dbTables.find((c) => c.$id === tableId)
  // Only show loading if we don't have data yet (account for prefetched data)
  const isActuallyLoading =
    (databaseLoading && !database) || (tablesLoading && dbTables.length === 0)
  // Sidebar unmounts during loading / "not found" flashes on first table open.
  // Restore must re-run once the list is actually in the DOM.
  const sidebarScrollReady =
    !isActuallyLoading &&
    !!database &&
    (tableId === '-' || Boolean(selectedTable))
  const sidebarScrollContainerRef = useRef<HTMLDivElement | null>(null)
  const sidebarScrollKey = `${projectId}:${databaseId}:${sidebarTablesDisplayedPage}:${sidebarTablesSearch.trim()}:${sidebarTablesOrder}:${sidebarTablesSortBy}`
  const sidebarScrollKeyRef = useRef(sidebarScrollKey)
  sidebarScrollKeyRef.current = sidebarScrollKey
  const ignoreSidebarScrollSaveRef = useRef(false)

  const restoreSidebarScroll = useCallback((node: HTMLDivElement) => {
    const savedTop = sidebarTableListScrollTopByKey.get(
      sidebarScrollKeyRef.current,
    )
    if (typeof savedTop !== 'number') return
    ignoreSidebarScrollSaveRef.current = true
    node.scrollTop = savedTop
    requestAnimationFrame(() => {
      node.scrollTop = savedTop
      ignoreSidebarScrollSaveRef.current = false
    })
  }, [])

  // Callback ref restores scroll whenever the list remounts (route change,
  // layout swap, early-return flash) - useLayoutEffect alone misses that.
  const setSidebarScrollContainerRef = useCallback(
    (node: HTMLDivElement | null) => {
      sidebarScrollContainerRef.current = node
      if (node) restoreSidebarScroll(node)
    },
    [restoreSidebarScroll],
  )

  const persistSidebarScroll = useCallback(() => {
    const node = sidebarScrollContainerRef.current
    if (!node || ignoreSidebarScrollSaveRef.current) return
    const currentTop = node.scrollTop
    const savedTop = sidebarTableListScrollTopByKey.get(sidebarScrollKey)
    // Don't clobber a saved position with 0 before layout can scroll.
    if (
      currentTop === 0 &&
      typeof savedTop === 'number' &&
      savedTop > 0 &&
      node.scrollHeight <= node.clientHeight + 1
    ) {
      return
    }
    sidebarTableListScrollTopByKey.set(sidebarScrollKey, currentTop)
  }, [sidebarScrollKey])

  const handleSidebarListScroll = persistSidebarScroll

  useLayoutEffect(() => {
    if (!sidebarScrollReady) return
    const node = sidebarScrollContainerRef.current
    if (!node) return
    restoreSidebarScroll(node)

    // Re-apply if a later layout pass resets scrollTop (e.g. panel sync).
    const onResize = () => {
      const savedTop = sidebarTableListScrollTopByKey.get(sidebarScrollKey)
      if (
        typeof savedTop === 'number' &&
        savedTop > 0 &&
        node.scrollTop === 0
      ) {
        restoreSidebarScroll(node)
      }
    }
    const ro = new ResizeObserver(onResize)
    ro.observe(node)
    const t = window.setTimeout(onResize, 50)
    return () => {
      ro.disconnect()
      window.clearTimeout(t)
    }
  }, [
    sidebarScrollKey,
    displayedSidebarTables.length,
    sidebarScrollReady,
    tableId,
    restoreSidebarScroll,
  ])

  // Reset sidebar to page 1 when search, sort, or database changes
  useEffect(() => {
    setSidebarTablesRequestedPage(1)
    setSidebarTablesDisplayedPage(1)
  }, [sidebarTablesSearch, sidebarTablesOrder, sidebarTablesSortBy, databaseId])
  const rowsRefetchRef = useRef<(() => Promise<unknown>) | null>(null)
  const openCreateRowDrawerRef = useRef<(() => void) | null>(null)
  const openCreateIndexDialogRef = useRef<(() => void) | null>(null)
  const [isRefreshingRows, setIsRefreshingRows] = useState(false)
  const refreshStartTimeRef = useRef<number | null>(null)
  const minAnimationDuration = 1000 // 1 second for at least one full rotation
  const [hasRows, setHasRows] = useState(true) // Track if table has rows
  const [, setRowsTotal] = useState<number | undefined>(undefined) // Track total row count
  const [importCsvOpen, setImportCsvOpen] = useState(false)
  const [exportCsvOpen, setExportCsvOpen] = useState(false)
  const [createTableDialogOpen, setCreateTableDialogOpen] = useState(false)
  const [createDatabaseDialogOpen, setCreateDatabaseDialogOpen] =
    useState(false)
  const [monitorDateRange, setMonitorDateRange] = useState<DateRange>(() =>
    getDefaultMonitorDateRange(),
  )
  const [monitorChartTick, setMonitorChartTick] = useState(0)
  const queryClient = useQueryClient()

  const { project } = useProject(projectId)
  const useCreateDatabaseWizard = features.dedicatedDbsSupport
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showTableSecuritySettings = canShowTableSecuritySettings(
    access,
    features,
  )
  const tableWriteAccess = useDatabaseTableOperationsAccess()
  const rowWriteAccess = useDatabaseRowOperationsAccess()
  const noCreateTablePermission = !tableWriteAccess.canWrite
  // Settings / create-db visibility is RBAC only. Ops lock (e.g. failed status)
  // disables writes inside settings but must not hide the Settings link.
  const noCreateDbPermission = !canCreateDatabase(access, features)
  const noCreateRowPermission = !rowWriteAccess.canWrite
  const createPermissionTooltip =
    "You don't have permission to perform this action."

  // Redirect from table security/settings when user lacks permission
  useEffect(() => {
    if (
      !showTableSecuritySettings &&
      selectedTable &&
      (activeTab === 'security' || activeTab === 'settings')
    ) {
      navigate({
        ...dbNav.dataGrid(tableNavParams),
        replace: true,
      })
    }
  }, [
    showTableSecuritySettings,
    activeTab,
    selectedTable,
    dbNav,
    tableNavParams,
    navigate,
  ])

  // Columns tab and legacy "documents" tab URL are not used for Documents DB
  useEffect(() => {
    if (tableId === '-' || !selectedTable) return
    if (activeTab === 'columns' || activeTab === 'documents') {
      navigate({
        ...dbNav.dataGrid(tableNavParams),
        replace: true,
      })
    }
  }, [tableId, selectedTable, activeTab, navigate, dbNav, tableNavParams])

  useEffect(() => {
    setMonitorDateRange(getDefaultMonitorDateRange())
    setMonitorChartTick(0)
  }, [databaseId])

  // Reset rows total when switching tables
  useEffect(() => {
    setRowsTotal(undefined)
    setHasRows(true)
  }, [tableId])

  // Memoize the callback to prevent infinite loops
  const handleRowsCountChange = useCallback((count: number) => {
    setHasRows(count > 0)
    setRowsTotal(count)
  }, [])

  const handleBackToDatabases = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  const handleBackToDatabase = () => {
    if (tableId === '-') {
      navigate({ to: '/projects/$projectId/databases', params: { projectId } })
    } else {
      navigate({
        ...dbNav.dataGrid({
          projectId,
          dbKind: DB_KIND,
          databaseId,
          resourceId: '-',
        }),
      })
    }
  }

  const mobileSectionValue: DatabaseSectionId =
    databaseTab === 'visualizer' ||
    databaseTab === 'monitor' ||
    databaseTab === 'backups' ||
    databaseTab === 'export-import' ||
    databaseTab === 'settings'
      ? databaseTab
      : databaseTab === 'db-security'
        ? 'settings'
        : 'tables'

  const handleMobileSectionSelect = (sectionId: DatabaseSectionId) => {
    if (
      provisioning &&
      (sectionId === 'monitor' ||
        sectionId === 'backups' ||
        sectionId === 'settings')
    ) {
      return
    }
    if (sectionId === 'tables') {
      navigate({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: { projectId, dbKind: DB_KIND, databaseId },
      })
      return
    }
    if (sectionId === 'visualizer') {
      navigate({ ...dbNav.visualizer(tableNavParams) })
      return
    }
    if (sectionId === 'monitor') {
      navigate({ ...dbNav.monitor(tableNavParams) })
      return
    }
    if (sectionId === 'backups') {
      navigate({ ...dbNav.backups(tableNavParams) })
      return
    }
    if (sectionId === 'export-import') {
      navigate({ ...dbNav.exportImport(tableNavParams) })
      return
    }
    if (sectionId === 'settings') {
      navigate({ ...dbNav.dbSettings(tableNavParams) })
    }
  }

  // Create table mutation for workspace
  const createTableMutation = useMutation({
    mutationFn: (data: {
      tableId?: string
      name: string
      dimension?: number
    }) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return createProjectTable(projectId!, databaseId!, DB_KIND, data)
    },
    onSuccess: async (table) => {
      toast.success(`${table.name} ${t('has been created')}`)
      // Refetch tables and wait for it to complete before navigating
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      navigate({
        ...dbNav.dataGrid({
          projectId: projectId!,
          dbKind: DB_KIND,
          databaseId: databaseId!,
          resourceId: table.$id,
        }),
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to create table'))
    },
  })

  // Create database mutation for workspace (rows view sidebar)
  const createDatabaseMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(projectId!, data, ApiDatabaseType.Documentsdb),
    onSuccess: async (database) => {
      toast.success(`${database.name} ${t('has been created')}`)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      setCreateDatabaseDialogOpen(false)
      const nextKind = routeKindForDatabase(database)
      try {
        const tablesData = await queryClient.ensureQueryData(
          tablesQueryOptions(
            projectId,
            database.$id,
            nextKind,
            0,
            TABLE_WORKSPACE_TABLES_LIST_LIMIT,
            undefined,
          ),
        )
        const firstTable = (tablesData.tables || [])[0] as
          | { $id?: string }
          | undefined
        const nextNav = dbNavLink(nextKind)
        if (firstTable?.$id) {
          navigate({
            ...nextNav.dataGrid({
              projectId,
              dbKind: nextKind,
              databaseId: database.$id,
              resourceId: firstTable.$id,
            }),
          })
        } else {
          navigate({
            ...nextNav.dataGrid({
              projectId,
              dbKind: nextKind,
              databaseId: database.$id,
              resourceId: '-',
            }),
          })
        }
      } catch {
        navigate({
          ...dbNavLink(nextKind).dataGrid({
            projectId,
            dbKind: nextKind,
            databaseId: database.$id,
            resourceId: '-',
          }),
        })
      }
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to create database'))
    },
  })

  const effectiveTableId = tableId === '-' ? undefined : tableId
  const { columns: tableColumns } = useProjectCollectionAttributes(
    projectId,
    databaseId,
    DB_KIND,
    effectiveTableId,
  )
  const { indexes: tableIndexes } = useProjectCollectionIndexes(
    projectId,
    databaseId,
    DB_KIND,
    effectiveTableId,
  )
  const { table: tableDataForStatus } = useProjectTable(
    projectId,
    databaseId,
    DB_KIND,
    effectiveTableId,
  )

  const tableTabs: Tab[] = useMemo(() => {
    const grid = dbNav.dataGrid(tableNavParams)
    const all: Tab[] = [
      {
        id: 'rows',
        label: dbLabels.gridDataTabLabel,
        to: grid.to,
        params: grid.params,
      },
      {
        id: 'indexes',
        label: 'Indexes',
        ...dbNav.indexes(tableNavParams),
      },
      ...(showTableSecuritySettings
        ? [
            {
              id: 'security' as const,
              label: 'Security',
              ...dbNav.security(tableNavParams),
            },
            {
              id: 'settings' as const,
              label: 'Settings',
              ...dbNav.settings(tableNavParams),
            },
          ]
        : []),
    ]
    return all
  }, [
    dbNav,
    tableNavParams,
    showTableSecuritySettings,
    dbLabels.gridDataTabLabel,
  ])

  const ROWS_DEFAULT_SORT_BY = '$createdAt'
  const ROWS_DEFAULT_SORT_ORDER = 'desc' as const
  const isTableDataTab =
    (activeTab === 'rows' || activeTab === 'documents') && tableId !== '-'
  const rowsListParams = useMemo(() => {
    if (!isTableDataTab || typeof search !== 'object') return null
    const url = urlFromRouterLocation(location, window.location.origin)
    const defaultSort = {
      sortBy: ROWS_DEFAULT_SORT_BY,
      sortOrder: ROWS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
    }
    const parsed =
      parseSort(search?.sort as string | undefined) ??
      getSort(url) ??
      defaultSort
    // Prefer router search state (updated by navigate()) over URL so page size change takes effect even if URL lags
    const pageFromSearch =
      search?.page != null
        ? typeof search.page === 'number'
          ? search.page
          : Number(search.page)
        : undefined
    const limitFromSearch =
      search?.limit != null
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
      search: getSearch(url) ?? (search?.search as string | undefined),
      page,
      limit,
      filterMap: queryParamToMap(
        getQueryParam(url) ?? (search?.query as string | undefined) ?? null,
      ),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [isTableDataTab, search, location.pathname, location.search])

  const rowsUrlPage = rowsListParams?.page ?? 1
  const rowsUrlLimit = rowsListParams?.limit ?? ROWS_DEFAULT_PAGE_SIZE
  const rowsUrlSearch = rowsListParams?.search
  const rowsSortBy = rowsListParams?.sortBy ?? ROWS_DEFAULT_SORT_BY
  const rowsSortOrder = rowsListParams?.sortOrder ?? ROWS_DEFAULT_SORT_ORDER
  const rowsFilterMap = rowsListParams?.filterMap ?? new Map()
  const rowsFilterQueries =
    rowsFilterMap.size > 0 ? Array.from(rowsFilterMap.values()) : undefined
  const rowsFilterQueryString =
    rowsFilterMap.size > 0 ? mapToQueryParam(rowsFilterMap) : ''

  const rowsFilterColumns = useMemo(() => {
    const base = rowsFilterColumnsFromAttributes(
      tableColumns,
      tableIndexes as TableIndexForFilters[],
    )
    return appendDocumentsDbCustomAttributeFilter(base)
  }, [tableColumns, tableIndexes])

  const [rowsFiltersOpen, setRowsFiltersOpen] = useState(false)
  const [indexesFiltersOpen, setIndexesFiltersOpen] = useState(false)

  const navigateToRowsWithOpenCreate = useCallback(() => {
    navigate({
      ...dbNav.dataGrid(tableNavParams),
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: rowsUrlSearch ?? undefined,
          query: rowsFilterQueryString || undefined,
          page: rowsUrlPage,
          limit: rowsUrlLimit,
          sort:
            rowsSortBy !== ROWS_DEFAULT_SORT_BY ||
            rowsSortOrder !== ROWS_DEFAULT_SORT_ORDER
              ? encodeSort(rowsSortBy, rowsSortOrder)
              : undefined,
        })
        return { ...prev, ...built, openRowCreate: '1' }
      },
    })
  }, [
    navigate,
    dbNav,
    tableNavParams,
    rowsUrlSearch,
    rowsFilterQueryString,
    rowsUrlPage,
    rowsUrlLimit,
    rowsSortBy,
    rowsSortOrder,
  ])

  const navigateToRowsList = (params: {
    search?: string
    query?: string
    page?: number
    limit?: number
    sort?: string
  }) => {
    const hasQueryKey = 'query' in params
    const hasSortKey = 'sort' in params
    const listLink = dbNav.dataGrid(tableNavParams)

    navigate({
      ...listLink,
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: params.search ?? rowsUrlSearch ?? undefined,
          query: hasQueryKey
            ? params.query
            : rowsFilterQueryString || undefined,
          page: params.page ?? rowsUrlPage,
          limit: params.limit ?? rowsUrlLimit,
          sort: hasSortKey
            ? params.sort
            : rowsSortBy !== ROWS_DEFAULT_SORT_BY ||
                rowsSortOrder !== ROWS_DEFAULT_SORT_ORDER
              ? encodeSort(rowsSortBy, rowsSortOrder)
              : undefined,
        })
        const next = { ...prev, ...built }
        if (params.page === 1) delete next.page
        if (hasQueryKey && params.query === undefined) delete next.query
        if (hasSortKey && params.sort === undefined) delete next.sort
        return next
      },
      replace: true,
    })
  }

  const handleRowsSortChange = (sortBy: string, sortOrder: 'asc' | 'desc') => {
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: rowsFilterQueryString || undefined,
      page: 1,
      limit: rowsUrlLimit,
      sort:
        sortBy !== ROWS_DEFAULT_SORT_BY || sortOrder !== ROWS_DEFAULT_SORT_ORDER
          ? encodeSort(sortBy, sortOrder)
          : undefined,
    })
  }

  const rowsApplyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(rowsFilterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(compactKey, queryStr)
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: mapToQueryParam(next) || undefined,
      page: 1,
      limit: rowsUrlLimit,
      sort:
        rowsSortBy !== ROWS_DEFAULT_SORT_BY ||
        rowsSortOrder !== ROWS_DEFAULT_SORT_ORDER
          ? encodeSort(rowsSortBy, rowsSortOrder)
          : undefined,
    })
  }

  const rowsRemoveFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(rowsFilterMap)
    next.delete(compactKey)
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
      limit: rowsUrlLimit,
      sort:
        rowsSortBy !== ROWS_DEFAULT_SORT_BY ||
        rowsSortOrder !== ROWS_DEFAULT_SORT_ORDER
          ? encodeSort(rowsSortBy, rowsSortOrder)
          : undefined,
    })
  }

  const rowsClearAllFilters = () => {
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: undefined,
      page: 1,
      limit: rowsUrlLimit,
      sort:
        rowsSortBy !== ROWS_DEFAULT_SORT_BY ||
        rowsSortOrder !== ROWS_DEFAULT_SORT_ORDER
          ? encodeSort(rowsSortBy, rowsSortOrder)
          : undefined,
    })
    setRowsFiltersOpen(false)
  }

  const tableDetailFilterMap = useMemo(
    () => queryParamToMap((search?.query as string | undefined) ?? null),
    [search?.query],
  )

  const navigateTableDetailSearch = (updates: { query?: string }) => {
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          ...updates,
        }
        if ('query' in updates && updates.query === undefined) {
          delete next.query
        }
        return next
      },
      replace: true,
    })
  }

  const indexesApplyFilter = (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(tableDetailFilterMap)
    if (replaceKey) newMap.delete(replaceKey)
    newMap.set(key, queryStr)
    navigateTableDetailSearch({
      query: mapToQueryParam(newMap) || undefined,
    })
  }

  const indexesRemoveFilter = (key: CompactFilterKey) => {
    const newMap = new Map(tableDetailFilterMap)
    newMap.delete(key)
    navigateTableDetailSearch({
      query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
    })
  }

  const indexesClearAllFilters = () => {
    navigateTableDetailSearch({ query: undefined })
    setIndexesFiltersOpen(false)
  }

  const getCreateLabel = () => {
    switch (activeTab) {
      case 'rows':
        return dbLabels.createRecord
      case 'documents':
        return dbLabels.createRecord
      case 'columns':
        return undefined
      case 'indexes':
        return dbLabels.createIndex
      default:
        return undefined
    }
  }

  if (isActuallyLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  if (!database) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            {t('Database not found')}
          </p>
          <Button variant="link" onClick={handleBackToDatabases}>
            {t('Back to databases')}
          </Button>
        </div>
      </div>
    )
  }

  if (tableId !== '-' && !selectedTable) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            {dbLabels.containerNotFoundTitle}
          </p>
          <Button variant="link" onClick={handleBackToDatabase}>
            {t('Back to database')}
          </Button>
        </div>
      </div>
    )
  }

  const tableViewSidebar = (
    <div className="flex h-full min-h-0 min-w-0 flex-col">
      {/* Tables Sidebar - sticky sections: database selector, create table, scrollable list, bottom nav */}
      {/* 1. Sticky top: Database selector */}
      <div className="flex shrink-0 flex-col border-b border-border bg-background">
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <button
            onClick={handleBackToDatabases}
            className="flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label={t('Back to databases')}
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <span className="text-[13px] font-medium text-foreground">
            {t('Databases')}
          </span>
        </div>
        <div className="flex min-w-0 items-center gap-2 px-2 py-2">
          <DatabaseSelector
            projectId={projectId}
            value={databaseId}
            selectedName={database?.name}
            createTableMenuLabel={dbLabels.createContainer}
            createDatabaseDisabled={noCreateDbPermission}
            createDatabaseDisabledTooltip={createPermissionTooltip}
            createTableDisabled={noCreateTablePermission}
            createTableDisabledTooltip={createPermissionTooltip}
            onSelect={(newDatabaseId, meta) => {
              if (newDatabaseId === databaseId) return
              void navigateToDatabaseFromSwitcher({
                projectId,
                selection: {
                  id: newDatabaseId,
                  apiType: meta?.apiType,
                  engine: meta?.engine,
                  product: meta?.product,
                },
                navigate: (link) => {
                  navigate({
                    to: link.to,
                    params: link.params,
                  })
                },
                queryClient,
              })
            }}
            onCreateDatabaseClick={() =>
              useCreateDatabaseWizard
                ? navigate({
                    to: '/projects/$projectId/databases/create',
                    params: { projectId },
                  })
                : setCreateDatabaseDialogOpen(true)
            }
            onCreateTableClick={() => setCreateTableDialogOpen(true)}
          />
        </div>
      </div>

      {/* 2. Scrollable: search, create table, tables list, pagination */}
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="shrink-0 space-y-2 border-b border-border px-2 py-2">
          <div className="flex min-w-0 items-center gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="text"
                placeholder={dbLabels.searchContainersPlaceholder}
                value={sidebarTablesSearch}
                onChange={(e) => setSidebarTablesSearch(e.target.value)}
                className="h-8 ps-8 pe-2 text-[13px]"
              />
            </div>
            <DropdownMenu>
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 shrink-0"
                        aria-label={dbLabels.sortContainersAriaLabel}
                      >
                        <ArrowUpDown className="h-3.5 w-3.5" />
                      </Button>
                    </DropdownMenuTrigger>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="text-xs">
                    {t('Sort by attribute and direction')}
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
              <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {dbLabels.sortContainersMenu}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                  value={`${sidebarTablesSortBy}-${sidebarTablesOrder}`}
                  onValueChange={(value) => {
                    const [by, dir] = value.split('-')
                    if (
                      by &&
                      (dir === 'asc' || dir === 'desc') &&
                      (by === 'name' ||
                        by === '$createdAt' ||
                        by === '$updatedAt')
                    ) {
                      setSidebarTablesSortBy(by)
                      setSidebarTablesOrder(dir)
                      setSidebarTablesRequestedPage(1)
                      setSidebarTablesDisplayedPage(1)
                    }
                  }}
                >
                  <DropdownMenuRadioItem value="name-asc">
                    {t('Name (A → Z)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="name-desc">
                    {t('Name (Z → A)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-asc">
                    {t('Created (oldest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-desc">
                    {t('Created (newest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-asc">
                    {t('Updated (oldest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-desc">
                    {t('Updated (newest first)')}
                  </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
        <div className="shrink-0 px-2 py-2">
          {noCreateTablePermission ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="block w-full">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 w-full gap-2 ps-6 pe-6 text-[13px] font-medium"
                    onClick={() => setCreateTableDialogOpen(true)}
                    disabled
                  >
                    <Plus className="h-4 w-4" />
                    {dbLabels.createContainer}
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent side="right">
                {t(createPermissionTooltip)}
              </TooltipContent>
            </Tooltip>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-9 w-full gap-2 ps-6 pe-6 text-[13px] font-medium"
              onClick={() => setCreateTableDialogOpen(true)}
            >
              <Plus className="h-4 w-4" />
              {dbLabels.createContainer}
            </Button>
          )}
        </div>

        <div
          ref={setSidebarScrollContainerRef}
          onScroll={handleSidebarListScroll}
          onPointerDownCapture={persistSidebarScroll}
          className="min-h-0 flex-1 overflow-y-auto"
        >
          {displayedSidebarTables.length === 0 && sidebarTablesLoading ? (
            <div className="p-2 text-center text-[12px] text-muted-foreground">
              {t('Loading…')}
            </div>
          ) : (
            <div className="space-y-0.5 px-2.5 py-2.5">
              {displayedSidebarTables.map((table) => {
                const isTableSelected =
                  selectedTable?.$id === table.$id && !databaseTab
                return (
                  <TableContextMenu
                    key={table.$id}
                    projectId={projectId!}
                    databaseId={databaseId}
                    dbKind={DB_KIND}
                    table={table}
                    showSecuritySettings={showTableSecuritySettings}
                    onCreateSimilar={async (newTableId) => {
                      await queryClient.refetchQueries({
                        queryKey: ['tables', 'project', projectId, databaseId],
                      })
                      // Prefetch new table data before navigating to avoid layout shift / loading screen
                      await Promise.all([
                        queryClient.ensureQueryData(
                          tableQueryOptions(
                            projectId,
                            databaseId,
                            DB_KIND,
                            newTableId,
                          ),
                        ),
                        queryClient.ensureQueryData(
                          collectionAttributesQueryOptions(
                            projectId,
                            databaseId,
                            DB_KIND,
                            newTableId,
                          ),
                        ),
                        queryClient.ensureQueryData(
                          tableRowsQueryOptions(
            projectId,
            databaseId,
            newTableId,
            DB_KIND,
            0,
                            ROWS_DEFAULT_PAGE_SIZE,
                            undefined,
                            undefined,
                            undefined,
                            undefined,
                            undefined,
          ),
                        ),
                      ])
                      navigate({
                        ...dbNav.dataGrid({
                          projectId: projectId!,
                          dbKind: DB_KIND,
                          databaseId,
                          resourceId: newTableId,
                        }),
                      })
                    }}
                  >
                    <Link
                      {...dbNav.dataGrid({
                        projectId,
                        dbKind: DB_KIND,
                        databaseId,
                        resourceId: table.$id,
                      })}
                      onClick={handleSidebarListScroll}
                      className={cn(secondarySidebarNavLinkClassName(isTableSelected, 'transition-colors duration-150'), SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS)}
                    >
                      <ContainerListIcon className="h-3.5 w-3.5 shrink-0" />
                      <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
                        {table.name}
                      </span>
                      {table.enabled === false && (
                        <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                      )}
                    </Link>
                  </TableContextMenu>
                )
              })}
            </div>
          )}
        </div>
        <div className={DATABASE_SIDEBAR_LIST_STRIP_CLASS}>
          <div className={DATABASE_SIDEBAR_LIST_STRIP_ROW_CLASS}>
            <span className="shrink-0 tabular-nums">
              {sidebarTablesTotal === 0
                ? `0 ${dbLabels.containerPlural}`
                : `${(sidebarTablesDisplayedPage - 1) * sidebarTablesPageSize + 1}-${Math.min(sidebarTablesDisplayedPage * sidebarTablesPageSize, sidebarTablesTotal ?? 0)} ${t('of')} ${(sidebarTablesTotal ?? 0).toLocaleString()} ${dbLabels.containerPlural}`}
            </span>
            <div className="flex items-center gap-0.5">
              <Button
                variant="ghost"
                size="icon"
                className="h-6 w-6"
                onClick={() =>
                  setSidebarTablesRequestedPage((p) => Math.max(1, p - 1))
                }
                disabled={sidebarTablesDisplayedPage <= 1}
                aria-label={t('Previous page')}
              >
                <ChevronLeft className="h-3 w-3" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="h-6 w-6"
                onClick={() => setSidebarTablesRequestedPage((p) => p + 1)}
                disabled={
                  sidebarTablesDisplayedPage >=
                  Math.ceil((sidebarTablesTotal ?? 0) / sidebarTablesPageSize)
                }
                aria-label={t('Next page')}
              >
                <ChevronRight className="h-3 w-3" />
              </Button>
            </div>
          </div>
        </div>
      </div>

      {/* 4. Sticky bottom: Nav links (match main sidebar item size and spacing) */}
      <div className="flex shrink-0 flex-col border-t border-border bg-background px-2.5 pt-2 pb-2 has-[*[data-sidebar-spec]]:gap-2 has-[*[data-sidebar-spec]]:pb-0">
        <div className="space-y-0.5">
        <Link
          {...dbNav.visualizer(tableNavParams)}
          className={cn(
            secondarySidebarNavLinkClassName(databaseTab === 'visualizer'
              , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
          )}
        >
          <Network className="h-3.5 w-3.5 shrink-0" />
          <span>{t('Visualizer')}</span>
        </Link>
        {features.usageStats && (
          <DatabaseSidebarNavItem
            disabled={provisioning}
            disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'monitor'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
            {...dbNav.monitor(tableNavParams)}
          >
            <Activity className="h-3.5 w-3.5 shrink-0" />
            <span>{t('Monitor')}</span>
          </DatabaseSidebarNavItem>
        )}
        {features.databaseBackups && (
          <DatabaseBackupsNavLink
            projectId={projectId}
            databaseId={databaseId}
            disabled={provisioning}
            disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
            {...dbNav.backups(tableNavParams)}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'backups'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
            labelClassName="flex-1"
          />
        )}
        <Link
          {...dbNav.exportImport(tableNavParams)}
          className={cn(
            secondarySidebarNavLinkClassName(databaseTab === 'export-import'
              , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
          )}
        >
          <Download className="h-3.5 w-3.5 shrink-0" />
          <span>{t('Export / Import')}</span>
        </Link>
        {!noCreateDbPermission && (
          <DatabaseSidebarNavItem
            disabled={provisioning}
            disabledTooltip={DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'settings'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
            {...dbNav.dbSettings(tableNavParams)}
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span>{t('Settings')}</span>
          </DatabaseSidebarNavItem>
        )}
        </div>
        <DatabaseSidebarComputeSpec
          projectId={projectId}
          databaseId={databaseId}
          mode="product"
          dbKind={DB_KIND}
          variant="footer"
        />
      </div>
    </div>
  )

  const tableViewMain = (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <ServiceHeader
        title={
          isDatabaseLevelView ? (
            databaseTab ? (
              DATABASE_TAB_LABELS[databaseTab]
            ) : (
              dbLabels.containerPluralTitle
            )
          ) : (
            <div className="flex min-w-0 items-center gap-2">
              <span className="truncate">{selectedTable!.name}</span>
              <CopyableId
                id={selectedTable!.$id}
                size="xs"
                className="shrink-0"
              />
            </div>
          )
        }
        tabs={isDatabaseLevelView ? undefined : tableTabs}
        activeTab={isDatabaseLevelView ? undefined : activeTab}
        searchPlaceholder={undefined}
        searchValue={undefined}
        onSearchChange={undefined}
        createLabel={isDatabaseLevelView ? undefined : getCreateLabel()}
        createDisabled={
          !isDatabaseLevelView &&
          (activeTab === 'rows' || activeTab === 'documents'
            ? noCreateRowPermission
            : activeTab === 'indexes'
              ? noCreateTablePermission
              : false)
        }
        createDisabledTooltip={
          !isDatabaseLevelView
            ? activeTab === 'indexes' && noCreateTablePermission
              ? createPermissionTooltip
              : activeTab === 'rows' || activeTab === 'documents'
                ? noCreateRowPermission
                  ? createPermissionTooltip
                  : undefined
                : undefined
            : undefined
        }
        onCreate={
          isDatabaseLevelView
            ? undefined
            : () => {
                if (activeTab === 'rows' && openCreateRowDrawerRef.current) {
                  openCreateRowDrawerRef.current()
                } else if (activeTab === 'documents') {
                  navigateToRowsWithOpenCreate()
                } else if (
                  activeTab === 'indexes' &&
                  openCreateIndexDialogRef.current
                ) {
                  openCreateIndexDialogRef.current()
                }
              }
        }
        showFilters={
          !isDatabaseLevelView &&
          (activeTab === 'rows' ||
            activeTab === 'documents' ||
            activeTab === 'indexes')
        }
        filterTrigger={
          !isDatabaseLevelView &&
          (activeTab === 'rows' || activeTab === 'documents') ? (
            <FiltersPopover
              open={rowsFiltersOpen}
              onOpenChange={setRowsFiltersOpen}
              columns={rowsFilterColumns}
              filterMap={rowsFilterMap}
              onRemoveFilter={rowsRemoveFilter}
              onClearAll={rowsClearAllFilters}
              onApplyFilter={rowsApplyFilter}
              resourceLabel={dbLabels.recordPlural}
              filterScope={
                activeTab === 'rows'
                  ? `databases.documents.${databaseId}.${tableId}`
                  : `databases.rows.${databaseId}.${tableId}`
              }
              onApplyQuery={(queryParam, sortParam) =>
                navigateToRowsList({
                  search: rowsUrlSearch ?? undefined,
                  query: queryParam ?? undefined,
                  page: 1,
                  limit: rowsUrlLimit,
                  sort: sortParam ?? undefined,
                })
              }
              sortBy={rowsSortBy}
              sortOrder={rowsSortOrder}
              onSortChange={handleRowsSortChange}
              defaultSortParam={encodeSort(
                ROWS_DEFAULT_SORT_BY,
                ROWS_DEFAULT_SORT_ORDER,
              )}
              onReset={() => {
                navigate({
                  ...dbNav.dataGrid(tableNavParams),
                  search: { page: 1, limit: rowsUrlLimit },
                  replace: true,
                })
              }}
              teamId={project?.teamId}
            />
          ) : !isDatabaseLevelView && activeTab === 'indexes' ? (
            <FiltersPopover
              open={indexesFiltersOpen}
              onOpenChange={setIndexesFiltersOpen}
              columns={tableIndexesFilterColumns}
              filterMap={tableDetailFilterMap}
              onRemoveFilter={indexesRemoveFilter}
              onClearAll={indexesClearAllFilters}
              onApplyFilter={indexesApplyFilter}
              resourceLabel={t('indexes')}
              filterScope={`databases.indexes.${databaseId}.${tableId}`}
              onApplyQuery={(queryParam) =>
                navigateTableDetailSearch({
                  query: queryParam ?? undefined,
                })
              }
              teamId={project?.teamId}
            />
          ) : undefined
        }
        showRefresh={
          !isDatabaseLevelView &&
          (activeTab === 'rows' || activeTab === 'documents')
        }
        onRefresh={
          !isDatabaseLevelView &&
          (activeTab === 'rows' || activeTab === 'documents')
            ? async () => {
                if (rowsRefetchRef.current) {
                  refreshStartTimeRef.current = Date.now()
                  setIsRefreshingRows(true)
                  try {
                    await rowsRefetchRef.current()
                    const elapsed =
                      Date.now() - (refreshStartTimeRef.current || 0)
                    const remaining = Math.max(
                      0,
                      minAnimationDuration - elapsed,
                    )
                    await new Promise((resolve) =>
                      setTimeout(resolve, remaining),
                    )
                    toast.success(dbLabels.recordsRefreshedSuccess)
                  } catch {
                    toast.error(dbLabels.failedToRefreshRecords)
                  } finally {
                    setIsRefreshingRows(false)
                    refreshStartTimeRef.current = null
                  }
                }
              }
            : undefined
        }
        isRefreshing={isRefreshingRows}
        showImport={!isDatabaseLevelView && activeTab === 'rows'}
        onImport={
          !isDatabaseLevelView && activeTab === 'rows'
            ? () => setImportCsvOpen(true)
            : undefined
        }
        importTooltip="Import CSV"
        showExport={!isDatabaseLevelView && activeTab === 'rows'}
        onExport={
          !isDatabaseLevelView && activeTab === 'rows'
            ? () => setExportCsvOpen(true)
            : undefined
        }
        exportTooltip="Export CSV"
        exportDisabled={
          !isDatabaseLevelView && activeTab === 'rows' && !hasRows
        }
        beforeCreateButtons={undefined}
        collapsible={!isDatabaseLevelView && isSpreadsheetLikeTableTab(activeTab)}
        fullWidthBorder
        fullWidth={
          !isDatabaseLevelView ||
          databaseTab === 'visualizer' ||
          databaseTab === 'monitor'
        }
        titleRightContent={
          databaseTab === 'monitor' ? (
            <DatabaseMonitorHeaderActions
              dateRange={monitorDateRange}
              onDateRangeChange={(r) =>
                setMonitorDateRange(r ?? getDefaultMonitorDateRange())
              }
              onRefresh={() => setMonitorChartTick((n) => n + 1)}
            />
          ) : undefined
        }
        contentAfterBorder={
          <>
            {databaseTab === 'monitor' ? (
              <div className="border-b border-border px-4 py-3 sm:px-6 lg:hidden">
                <DatabaseMonitorMobileNav
                  projectId={projectId}
                  databaseId={databaseId}
                  dbKind={DB_KIND}
                />
              </div>
            ) : null}
            {/* Mobile DB + table selector - above toolbar, shows when sidebar is hidden */}
            <div className="flex flex-col gap-2 border-b border-border px-4 py-3 lg:hidden">
              <DatabaseSelector
                projectId={projectId}
                value={databaseId}
                selectedName={database?.name}
                createTableMenuLabel={dbLabels.createContainer}
                createDatabaseDisabled={noCreateDbPermission}
                createDatabaseDisabledTooltip={createPermissionTooltip}
                createTableDisabled={noCreateTablePermission}
                createTableDisabledTooltip={createPermissionTooltip}
                onSelect={(newDatabaseId, meta) => {
                  if (newDatabaseId === databaseId) return
                  void navigateToDatabaseFromSwitcher({
                    projectId,
                    selection: {
                      id: newDatabaseId,
                      apiType: meta?.apiType,
                      engine: meta?.engine,
                      product: meta?.product,
                    },
                    navigate: (link) => {
                      navigate({
                        to: link.to,
                        params: link.params,
                      })
                    },
                    queryClient,
                  })
                }}
                onCreateDatabaseClick={() =>
                  useCreateDatabaseWizard
                    ? navigate({
                        to: '/projects/$projectId/databases/create',
                        params: { projectId },
                      })
                    : setCreateDatabaseDialogOpen(true)
                }
                onCreateTableClick={() => setCreateTableDialogOpen(true)}
              />
              <DatabaseSectionSelector
                projectId={projectId}
                databaseId={databaseId}
                value={mobileSectionValue}
                tablesLabel={dbLabels.databaseOverviewTabLabel}
                tablesIcon={ContainerListIcon}
                showMonitor={features.usageStats}
                showBackups={features.databaseBackups}
                showSettings={!noCreateDbPermission}
                disabledSectionIds={provisioningDisabledSections}
                onSelect={handleMobileSectionSelect}
              />
              <TableSelector
                projectId={projectId}
                databaseId={databaseId}
                dbKind={DB_KIND}
                value={tableId}
                selectedName={selectedTable?.name}
                placeholder={dbLabels.selectContainerPlaceholder}
                emptyLabel={dbLabels.emptyContainersShortLabel}
                noResultsLabel={dbLabels.noContainerSearchResultsLabel}
                createTooltip={dbLabels.createContainer}
                itemIcon={ContainerListIcon}
                createDisabled={noCreateTablePermission}
                createDisabledTooltip={createPermissionTooltip}
                onSelect={(newTableId) => {
                  navigate({
                    ...dbNav.dataGrid({
                      projectId,
                      dbKind: DB_KIND,
                      databaseId,
                      resourceId: newTableId,
                    }),
                  })
                }}
                onCreateClick={() => setCreateTableDialogOpen(true)}
                empty={dbTables.length === 0}
              />
            </div>
            {database && (database as Models.Database).enabled === false ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="px-4 py-3 sm:px-6">
                  <Alert
                    variant="default"
                    className="border-amber-500/30 bg-transparent"
                  >
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                      {t('Database is disabled')}
                    </AlertTitle>
                    <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      <span className="inline">
                        {t('This database is disabled and not accessible to end users through the API. Console actions remain available.')}{' '}
                        <Link
                          to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
                          params={{
                            projectId,
                            dbKind: DB_KIND,
                            databaseId,
                          }}
                          className="font-medium underline hover:no-underline inline"
                        >
                          {t('Enable it in the Settings tab')}
                        </Link>{' '}
                        to make it available to end users.
                      </span>
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : tableDataForStatus && !tableDataForStatus.enabled ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="px-4 py-3 sm:px-6">
                  <Alert
                    variant="default"
                    className="border-amber-500/30 bg-transparent"
                  >
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                      {dbLabels.disabledContainerTitle}
                    </AlertTitle>
                    <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      <span className="inline">
                        {dbLabels.disabledContainerBodyPrefix}{' '}
                        <Link
                          {...dbNav.settings({
                            projectId,
                            dbKind: DB_KIND,
                            databaseId,
                            resourceId: tableId,
                          })}
                          className="font-medium underline hover:no-underline inline"
                        >
                          {t('Enable it in the Settings tab')}
                        </Link>{' '}
                        to access its data and functionality.
                      </span>
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : null}
          </>
        }
      />

      <div
        className={cn(
          'flex-1 min-h-0',
          databaseTab === 'settings' && children
            ? 'flex flex-col overflow-hidden'
            : activeTab === 'rows'
              ? 'flex min-h-0 flex-col overflow-hidden'
              : 'overflow-y-auto',
        )}
      >
        {isDatabaseLevelView ? (
          databaseTab === 'settings' && children ? (
            children
          ) : (
            <Overview
              databaseId={databaseId}
              activeTab={
                databaseTab ? DATABASE_TAB_TO_OVERVIEW[databaseTab] : 'tables'
              }
              contentOnly
              monitorEmbed={
                databaseTab === 'monitor'
                  ? {
                      dateRange: monitorDateRange,
                      chartTick: monitorChartTick,
                    }
                  : undefined
              }
            />
          )
        ) : (
          <>
            {activeTab === 'rows' && selectedTable ? (
              <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                <RowsSpreadsheet
                  key={selectedTable.$id}
                  table={selectedTable}
                  canWriteRows={!noCreateRowPermission}
                  canWriteTables={!noCreateTablePermission}
                  onRefetchReady={(refetchFn) => {
                    rowsRefetchRef.current = refetchFn
                  }}
                  onCreateRowReady={(openCreateDrawer) => {
                    openCreateRowDrawerRef.current = openCreateDrawer
                  }}
                  onRowsCountChange={handleRowsCountChange}
                  rowsUrlSearch={rowsUrlSearch}
                  rowsUrlPage={rowsUrlPage}
                  rowsUrlLimit={rowsUrlLimit}
                  rowsFilterQueries={rowsFilterQueries}
                  rowsFilterQueryString={rowsFilterQueryString}
                  rowsSortBy={rowsSortBy}
                  rowsSortOrder={rowsSortOrder}
                  onNavigateToRowsList={navigateToRowsList}
                />
              </div>
            ) : null}
            {selectedTable && activeTab === 'documents' && (
              <>
                <DocumentsJsonSpreadsheet
                  table={selectedTable}
                  canWriteRows={!noCreateRowPermission}
                  rowsUrlSearch={rowsUrlSearch}
                  rowsUrlPage={rowsUrlPage}
                  rowsUrlLimit={rowsUrlLimit}
                  rowsFilterQueries={rowsFilterQueries}
                  rowsFilterQueryString={rowsFilterQueryString}
                  rowsSortBy={rowsSortBy}
                  rowsSortOrder={rowsSortOrder}
                  onNavigateToList={navigateToRowsList}
                  onRefetchReady={(refetchFn) => {
                    rowsRefetchRef.current = refetchFn
                  }}
                  onRowsCountChange={handleRowsCountChange}
                />
                <DocumentsRowCreateBridge
                  table={selectedTable}
                  onCreateRowReady={(openFn) => {
                    openCreateRowDrawerRef.current = openFn
                  }}
                />
              </>
            )}
            {activeTab === 'columns' && selectedTable ? (
              <CollectionAttributesSpreadsheet table={selectedTable} />
            ) : null}
            {activeTab === 'indexes' && selectedTable ? (
              <IndexesSpreadsheet
                table={selectedTable}
                canWriteTables={!noCreateTablePermission}
                filterMap={tableDetailFilterMap}
                onCreateReady={(openDialog) => {
                  openCreateIndexDialogRef.current = openDialog
                }}
              />
            ) : null}
            {activeTab === 'security' && (
              <TableSecurity table={selectedTable!} />
            )}
            {activeTab === 'settings' && (
              <TableSettings table={selectedTable!} />
            )}
          </>
        )}
      </div>
    </div>
  )

  return (
    <div className="@container flex h-full min-h-0 min-w-0">
      {showDesktopTableSidebar ? (
        <TableViewResizableLayout sidebar={tableViewSidebar}>
          {tableViewMain}
        </TableViewResizableLayout>
      ) : (
        tableViewMain
      )}

      {/* Create Database Dialog */}
      <CreateDatabase
        open={createDatabaseDialogOpen}
        onOpenChange={setCreateDatabaseDialogOpen}
        onCreate={(data) => createDatabaseMutation.mutate(data)}
        isLoading={createDatabaseMutation.isPending}
        backupsEnabled={
          features.databaseBackups
            ? organizationPlan?.backupsEnabled
            : undefined
        }
        orgId={project?.teamId}
      />
      {/* Create Table Dialog */}
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
        variant="documents"
      />
      {/* Import CSV Dialog */}
      {tableId !== '-' && (
        <ImportCsv
          projectId={projectId}
          databaseId={databaseId}
          tableId={tableId}
          open={importCsvOpen}
          onOpenChange={setImportCsvOpen}
        />
      )}
      {/* Export CSV Dialog */}
      {tableId !== '-' && (
        <ExportCsv
          projectId={projectId}
          databaseId={databaseId}
          tableId={tableId}
          dbKind={DB_KIND}
          filterQueries={rowsFilterQueries}
          open={exportCsvOpen}
          onOpenChange={setExportCsvOpen}
        />
      )}
    </div>
  )
}
