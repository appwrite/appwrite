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
  Lock,
  Table2,
  ChevronLeft,
  ChevronRight,
  AlertCircle,
  ArrowUpDown,
  BarChart3,
  Network,
  Download,
  Search,
  Activity,
} from 'lucide-react'

import { useState, useEffect, useRef, useCallback, useMemo } from 'react'

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
  databaseQueryOptions,
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
import { DatabaseSelector } from '../_components/DatabaseSelector'
import { TableSelector } from '../_components/TableSelector'
import {
  DatabaseMonitorHeaderActions,
  getDefaultMonitorDateRange,
} from '../_components/DatabaseMonitorHeaderActions'
import { DatabaseMonitorMobileNav } from '../_components/DatabaseMonitorMobileNav'
import type { DateRange } from 'react-day-picker'
import { ImportCsv } from '../_components/ImportCsv'
import { ExportCsv } from '../_components/ExportCsv'

import { useDebugMode } from '@/components/global/providers/DebugMode'
import {
  canCreateDatabase,
  canCreateRow,
  canShowTableSecuritySettings,
  canShowDatabaseSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import type { Models } from '@appwrite.io/console'
import { DatabaseType as ApiDatabaseType } from '@appwrite.io/console'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { IndexesSpreadsheet } from '../tablesdb/Spreadsheet'
import { CollectionAttributesSpreadsheet } from '../_components/CollectionAttributesSpreadsheet'
import { DocumentsJsonSpreadsheet } from '../_components/DocumentsJsonSpreadsheet'
import { TableViewResizableLayout } from '../_components/TableViewResizableLayout'
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
  rowsFilterColumnsFromAttributes,
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
import { Overview } from './Overview'
import {
  DATABASE_TAB_LABELS,
  DATABASE_TAB_TO_OVERVIEW,
  type WorkspaceProps,
} from '../workspace-types'

const DB_KIND = 'vectorsdb' as const satisfies DatabaseRouteKind
const SHOW_GRID_DEBUG_TOOLS = true

export function Workspace({
  databaseId,
  tableId,
  activeTab,
  databaseTab,
}: WorkspaceProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as
    | Record<string, unknown>
    | undefined
  const isDatabaseLevelView = tableId === '-' || databaseTab != null
  const { isDebugModeOpen } = useDebugMode()
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
  )

  const dbLabels = getDatabaseConsoleLabels(DB_KIND)
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
    0,
    TABLE_WORKSPACE_TABLES_LIST_LIMIT,
  )

  // Requested page query (drives fetch when user changes page)
  const { isFetching: sidebarTablesFetching } = useProjectTables(
    projectId,
    databaseId,
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

  // Reset sidebar to page 1 when search, sort, or database changes
  useEffect(() => {
    setSidebarTablesRequestedPage(1)
    setSidebarTablesDisplayedPage(1)
  }, [sidebarTablesSearch, sidebarTablesOrder, sidebarTablesSortBy, databaseId])

  const selectedTable =
    tableId === '-' ? undefined : dbTables.find((c) => c.$id === tableId)

  // Only show loading if we don't have data yet (account for prefetched data)
  const isActuallyLoading =
    (databaseLoading && !database) || (tablesLoading && dbTables.length === 0)
  const rowsRefetchRef = useRef<(() => Promise<unknown>) | null>(null)
  const openCreateRowDrawerRef = useRef<(() => void) | null>(null)
  const openCreateIndexDialogRef = useRef<(() => void) | null>(null)
  const [canCreateIndex, setCanCreateIndex] = useState(true)
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

  // Debug: create 50 random containers (only when debug mode is open and on tables list)
  const createFiftyTablesMutation = useMutation({
    mutationFn: async () => {
      const names = Array.from({ length: 50 }, (_, i) => `Table ${i + 1}`)
      for (const name of names) {
        await createProjectTable(projectId, databaseId, { name })
      }
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`Created 50 ${dbLabels.containerPlural}`)
    },
    onError: (error: Error) => {
      toast.error(
        error.message || `Failed to create ${dbLabels.containerPlural}`,
      )
    },
  })

  const { project } = useProject(projectId)
  const useCreateDatabaseWizard = features.dedicatedDbsSupport
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showTableSecuritySettings = canShowTableSecuritySettings(
    access,
    features,
  )
  const noCreateTablePermission = !canShowTableSecuritySettings(
    access,
    features,
  )
  const noCreateDbPermission = !canCreateDatabase(access, features)
  const noCreateRowPermission = !canCreateRow(access, features)
  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )
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

  // Columns tab is not available for Vectors DB
  useEffect(() => {
    if (tableId === '-' || !selectedTable) return
    if (activeTab === 'columns') {
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

  // Create table mutation for workspace
  const createTableMutation = useMutation({
    mutationFn: (data: {
      tableId?: string
      name: string
      dimension?: number
    }) => createProjectTable(projectId!, databaseId!, data),
    onSuccess: async (table) => {
      toast.success(`${table.name} has been created`)
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
      toast.error(getErrorMessage(error) || 'Failed to create table')
    },
  })

  // Create database mutation for workspace (rows view sidebar)
  const createDatabaseMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(projectId!, data, ApiDatabaseType.Vectorsdb),
    onSuccess: async (database) => {
      toast.success(`${database.name} has been created`)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      setCreateDatabaseDialogOpen(false)
      try {
        const tablesData = await queryClient.ensureQueryData(
          tablesQueryOptions(
            projectId,
            database.$id,
            0,
            TABLE_WORKSPACE_TABLES_LIST_LIMIT,
            undefined,
          ),
        )
        const firstTable = (tablesData.tables || [])[0] as
          | { $id?: string }
          | undefined
        const nextKind = databaseRouteKindFromApiType(
          (database as { databaseType?: ApiDatabaseType }).databaseType,
        )
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
        const nextKind = databaseRouteKindFromApiType(
          (database as { databaseType?: ApiDatabaseType }).databaseType,
        )
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
      toast.error(getErrorMessage(error) || 'Failed to create database')
    },
  })

  const effectiveTableId = tableId === '-' ? undefined : tableId
  const { columns: tableColumns } = useProjectCollectionAttributes(
    projectId,
    databaseId,
    effectiveTableId,
  )
  const { indexes: tableIndexes } = useProjectCollectionIndexes(
    projectId,
    databaseId,
    effectiveTableId,
  )
  const { table: tableDataForStatus } = useProjectTable(
    projectId,
    databaseId,
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
    return base
  }, [tableColumns, tableIndexes])

  const [rowsFiltersOpen, setRowsFiltersOpen] = useState(false)

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
    const listLink =
      activeTab === 'documents'
        ? dbNav.dataJson(tableNavParams)
        : dbNav.dataGrid(tableNavParams)

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
        <div className="text-muted-foreground">Loading...</div>
      </div>
    )
  }

  if (!database) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            Database not found
          </p>
          <Button variant="link" onClick={handleBackToDatabases}>
            Back to databases
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
            {dbLabels.containerSingularTitle} not found
          </p>
          <Button variant="link" onClick={handleBackToDatabase}>
            Back to database
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
            aria-label="Back to databases"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <span className="text-[13px] font-medium text-foreground">
            Databases
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
            onSelect={async (newDatabaseId) => {
              try {
                const newDb = await queryClient.ensureQueryData(
                  databaseQueryOptions(projectId, newDatabaseId),
                )
                const nextDbKind = databaseRouteKindFromApiType(
                  (newDb as { databaseType?: ApiDatabaseType }).databaseType,
                )
                // Same query/order as sidebar first page (useProjectTables)
                const tablesData = await queryClient.ensureQueryData(
                  tablesQueryOptions(
                    projectId,
                    newDatabaseId,
                    0,
                    ROWS_DEFAULT_PAGE_SIZE,
                    undefined,
                    'asc',
                    '$createdAt',
                  ),
                )
                const firstTable = (tablesData.tables || [])[0] as
                  | { $id?: string }
                  | undefined
                navigate({
                  ...dbNavLink(nextDbKind).dataGrid({
                    projectId,
                    dbKind: nextDbKind,
                    databaseId: newDatabaseId,
                    resourceId: firstTable?.$id ?? '-',
                  }),
                })
              } catch {
                let nextDbKind: DatabaseRouteKind = DB_KIND
                try {
                  const newDb = await queryClient.ensureQueryData(
                    databaseQueryOptions(projectId, newDatabaseId),
                  )
                  nextDbKind = databaseRouteKindFromApiType(
                    (newDb as { databaseType?: ApiDatabaseType }).databaseType,
                  )
                } catch {
                  // keep workspace db kind when type lookup fails
                }
                navigate({
                  ...dbNavLink(nextDbKind).dataGrid({
                    projectId,
                    dbKind: nextDbKind,
                    databaseId: newDatabaseId,
                    resourceId: '-',
                  }),
                })
              }
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
                    Sort by attribute and direction
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
                    Name (A → Z)
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="name-desc">
                    Name (Z → A)
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-asc">
                    Created (oldest first)
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-desc">
                    Created (newest first)
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-asc">
                    Updated (oldest first)
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-desc">
                    Updated (newest first)
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
                {createPermissionTooltip}
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

        <div className="min-h-0 flex-1 overflow-y-auto">
          {displayedSidebarTables.length === 0 && sidebarTablesLoading ? (
            <div className="p-2 text-center text-[12px] text-muted-foreground">
              Loading…
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
                          tableQueryOptions(projectId, databaseId, newTableId),
                        ),
                        queryClient.ensureQueryData(
                          collectionAttributesQueryOptions(
                            projectId,
                            databaseId,
                            newTableId,
                          ),
                        ),
                        queryClient.ensureQueryData(
                          tableRowsQueryOptions(
                            projectId,
                            databaseId,
                            newTableId,
                            0,
                            ROWS_DEFAULT_PAGE_SIZE,
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
        <div className="shrink-0 border-t border-border px-2 py-1.5">
          <div className="flex items-center justify-between gap-1 text-[11px] text-muted-foreground">
            <span className="shrink-0 tabular-nums">
              {sidebarTablesTotal === 0
                ? `0 ${dbLabels.containerPlural}`
                : `${(sidebarTablesDisplayedPage - 1) * sidebarTablesPageSize + 1}-${Math.min(sidebarTablesDisplayedPage * sidebarTablesPageSize, sidebarTablesTotal ?? 0)} of ${(sidebarTablesTotal ?? 0).toLocaleString()} ${dbLabels.containerPlural}`}
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
                aria-label="Previous page"
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
                aria-label="Next page"
              >
                <ChevronRight className="h-3 w-3" />
              </Button>
            </div>
          </div>
        </div>
      </div>

      {/* 4. Sticky bottom: Nav links (match main sidebar item size and spacing) */}
      <div className="shrink-0 space-y-0.5 border-t border-border bg-background px-2.5 py-2">
        <Link
          {...dbNav.visualizer(tableNavParams)}
          className={cn(
            secondarySidebarNavLinkClassName(databaseTab === 'visualizer'
              , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
          )}
        >
          <Network className="h-3.5 w-3.5 shrink-0" />
          <span>Visualizer</span>
        </Link>
        {features.usageStats && (
          <Link
            {...dbNav.monitor(tableNavParams)}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'monitor'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
          >
            <Activity className="h-3.5 w-3.5 shrink-0" />
            <span>Monitor</span>
          </Link>
        )}
        {!noCreateDbPermission && (
          <Link
            {...dbNav.dbSecurity(tableNavParams)}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'db-security'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span>Security</span>
          </Link>
        )}
        {features.databaseInsights && (
          <Link
            {...dbNav.insights(tableNavParams)}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'insights'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
          >
            <BarChart3 className="h-3.5 w-3.5 shrink-0" />
            <span>Insights</span>
          </Link>
        )}
        {features.databaseBackups && (
          <DatabaseBackupsNavLink
            projectId={projectId}
            databaseId={databaseId}
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
          <span>Export / Import</span>
        </Link>
        {!noCreateDbPermission && (
          <Link
            {...dbNav.dbSettings(tableNavParams)}
            className={cn(
              secondarySidebarNavLinkClassName(databaseTab === 'settings'
                , 'transition-colors duration-150'),
            SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
            )}
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span>Settings</span>
          </Link>
        )}
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
          ((activeTab === 'rows' || activeTab === 'documents'
            ? noCreateRowPermission
            : false) ||
            (activeTab === 'indexes' && !canCreateIndex))
        }
        createDisabledTooltip={
          !isDatabaseLevelView ? createPermissionTooltip : undefined
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
          (activeTab === 'rows' || activeTab === 'documents')
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
              filterScope={`databases.rows.${databaseId}.${tableId}`}
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
                  ...(activeTab === 'documents'
                    ? dbNav.dataJson(tableNavParams)
                    : dbNav.dataGrid(tableNavParams)),
                  search: { page: 1, limit: rowsUrlLimit },
                  replace: true,
                })
              }}
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
                    toast.success(
                      `${dbLabels.recordPluralTitle} refreshed successfully`,
                    )
                  } catch {
                    toast.error(`Failed to refresh ${dbLabels.recordPlural}`)
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
        collapsible={!isDatabaseLevelView}
        fullWidthBorder
        fullWidth={
          !isDatabaseLevelView ||
          databaseTab === 'visualizer' ||
          databaseTab === 'monitor'
        }
        titleRightContent={
          databaseTab === 'monitor' ? (
            <>
              <DatabaseMonitorHeaderActions
                projectId={projectId}
                databaseId={databaseId}
                dbKind={DB_KIND}
                dateRange={monitorDateRange}
                onDateRangeChange={(r) =>
                  setMonitorDateRange(r ?? getDefaultMonitorDateRange())
                }
                onRefresh={() => setMonitorChartTick((n) => n + 1)}
                showSpecActions={showDbSecuritySettings}
              />
              {isDatabaseLevelView &&
              isDebugModeOpen &&
              SHOW_GRID_DEBUG_TOOLS ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7"
                  onClick={() => createFiftyTablesMutation.mutate()}
                  disabled={createFiftyTablesMutation.isPending}
                >
                  {createFiftyTablesMutation.isPending
                    ? 'Creating…'
                    : dbLabels.debugCreateManyContainers}
                </Button>
              ) : null}
            </>
          ) : undefined
        }
        rightContent={
          isDatabaseLevelView &&
          isDebugModeOpen &&
          SHOW_GRID_DEBUG_TOOLS &&
          databaseTab !== 'monitor' ? (
            <Button
              variant="outline"
              size="sm"
              className="h-9"
              onClick={() => createFiftyTablesMutation.mutate()}
              disabled={createFiftyTablesMutation.isPending}
            >
              {createFiftyTablesMutation.isPending
                ? 'Creating…'
                : dbLabels.debugCreateManyContainers}
            </Button>
          ) : undefined
        }
        contentAfterBorder={
          <>
            {databaseTab === 'monitor' ? (
              <div className="border-b border-border px-4 py-3 sm:px-6 lg:hidden">
                <DatabaseMonitorMobileNav
                  projectId={projectId}
                  databaseId={databaseId}
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
                onSelect={async (newDatabaseId) => {
                  try {
                    const newDb = await queryClient.ensureQueryData(
                      databaseQueryOptions(projectId, newDatabaseId),
                    )
                    const nextDbKind = databaseRouteKindFromApiType(
                      (newDb as { databaseType?: ApiDatabaseType })
                        .databaseType,
                    )
                    const tablesData = await queryClient.ensureQueryData(
                      tablesQueryOptions(
                        projectId,
                        newDatabaseId,
                        0,
                        ROWS_DEFAULT_PAGE_SIZE,
                        undefined,
                        'asc',
                        '$createdAt',
                      ),
                    )
                    const firstTable = (tablesData.tables || [])[0] as
                      | { $id?: string }
                      | undefined
                    navigate({
                      ...dbNavLink(nextDbKind).dataGrid({
                        projectId,
                        dbKind: nextDbKind,
                        databaseId: newDatabaseId,
                        resourceId: firstTable?.$id ?? '-',
                      }),
                    })
                  } catch {
                    let nextDbKind: DatabaseRouteKind = DB_KIND
                    try {
                      const newDb = await queryClient.ensureQueryData(
                        databaseQueryOptions(projectId, newDatabaseId),
                      )
                      nextDbKind = databaseRouteKindFromApiType(
                        (newDb as { databaseType?: ApiDatabaseType })
                          .databaseType,
                      )
                    } catch {
                      // keep workspace db kind when type lookup fails
                    }
                    navigate({
                      ...dbNavLink(nextDbKind).dataGrid({
                        projectId,
                        dbKind: nextDbKind,
                        databaseId: newDatabaseId,
                        resourceId: '-',
                      }),
                    })
                  }
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
              <TableSelector
                projectId={projectId}
                databaseId={databaseId}
                value={tableId}
                selectedName={selectedTable?.name}
                placeholder={`Select ${dbLabels.containerSingular}`}
                emptyLabel={`No ${dbLabels.containerPlural}`}
                noResultsLabel={`No ${dbLabels.containerPlural} found`}
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
                <div
                  className={cn(
                    'px-4 py-3 sm:px-6',
                    isDatabaseLevelView &&
                      databaseTab !== 'visualizer' &&
                      databaseTab !== 'monitor' &&
                      'mx-auto w-full max-w-7xl',
                  )}
                >
                  <Alert
                    variant="default"
                    className="border-amber-500/30 bg-transparent"
                  >
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                      Database is disabled
                    </AlertTitle>
                    <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      <span className="inline">
                        This database is disabled and not accessible to end
                        users through the API. Console actions remain available.{' '}
                        <Link
                          to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
                          params={{
                            projectId,
                            dbKind: DB_KIND,
                            databaseId,
                          }}
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
            ) : tableDataForStatus && !tableDataForStatus.enabled ? (
              <div className="border-b border-border bg-amber-500/5">
                <div
                  className={cn(
                    'px-4 py-3 sm:px-6',
                    isDatabaseLevelView &&
                      databaseTab !== 'visualizer' &&
                      databaseTab !== 'monitor' &&
                      'mx-auto w-full max-w-7xl',
                  )}
                >
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
                          Enable it in the Settings tab
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

      <div className={cn('flex-1 min-h-0', 'overflow-y-auto')}>
        {isDatabaseLevelView ? (
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
        ) : (
          <>
            {activeTab === 'rows' && selectedTable ? (
              <div className="contents">
                <RowsSpreadsheet
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
                onIndexesAbilityChange={setCanCreateIndex}
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
        variant="vectors"
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
          open={exportCsvOpen}
          onOpenChange={setExportCsvOpen}
        />
      )}
    </div>
  )
}
