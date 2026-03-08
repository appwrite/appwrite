import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { isTextType } from '@/lib/utils/database-columns'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Database,
  Plus,
  List,
  LayoutGrid,
  Settings,
  Key,
  Lock,
  Table2,
  ChevronLeft,
  ChevronDown,
  ChevronRight,
  MoreHorizontal,
  CheckCircle2,
  AlertCircle,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  Calendar,
  Link2,
  Fingerprint,
  Archive,
  ArrowLeft,
  X,
  Check,
  BarChart3,
  Network,
  Lightbulb,
  BookOpen,
  ExternalLink,
  Download,
  FileJson,
  FileText,
  Copy,
  Pencil,
  Search,
} from 'lucide-react'
import {
  databases,
  collections,
  formatNumber,
  type Collection,
  type Database as DatabaseType,
} from '@/lib/utils/mock-data'
import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectDatabases,
  useProjectDatabase,
  useProjectTables,
  useProjectTableRows,
  useProjectTableColumns,
  deleteProjectTableRow,
  createProjectTableRows,
  createProjectTableRow,
  updateProjectTableRow,
  fetchProjectTableRow,
  createProjectTableColumn,
  updateProjectTableColumn,
  deleteProjectTableColumn,
  useProjectTables as useTablesForColumns,
  useProjectTable,
  updateProjectTable,
  deleteProjectTable,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  fetchProjectDatabases,
  createProjectDatabase,
  createProjectTable,
  tablesQueryOptions,
  tableQueryOptions,
  tableColumnsQueryOptions,
  tableRowsQueryOptions,
  type TablesSortBy,
} from '@/lib/react-query/hooks'
import {
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
  DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
import { ColumnDrawer, ColumnFormData, type ColumnType } from './tables/Column'
import { IndexDrawer, IndexFormData } from './tables/Index'
import {
  createProjectTableIndex,
  deleteProjectTableIndex,
  useProjectTableIndexes,
} from '@/lib/react-query/hooks'
import { BackupsView } from './Backups'
import { ExportImportView } from './ExportImportView'
import { CreateDatabase } from './CreateDatabase'
import { CreateTable } from './CreateTable'
import { TableContextMenu } from './_components/TableContextMenu'
import { DatabaseSelector } from './_components/DatabaseSelector'
import { TableSelector } from './_components/TableSelector'
import { RowContextMenu } from './_components/RowContextMenu'
import { ImportCsv } from './_components/ImportCsv'
import { ExportCsv } from './_components/ExportCsv'
import { ComingSoonCurtain } from '@/components/ui/coming-soon-curtain'
import { SchemaVisualizer } from './SchemaVisualizer'
import { SchemaExportDialog } from './SchemaExport'
import {
  fetchDatabaseSchema,
  formatSchemaAsJSON,
  formatSchemaAsMarkdown,
  formatSchemaAsSVG,
  downloadAsFile,
  getCursorDeepLink,
  getLovableDeepLink,
  getChatGPTDeepLink,
  getClaudeDeepLink,
} from '@/lib/utils/database-schema-export'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import {
  canCreateDatabase,
  canCreateRow,
  canShowTableSecuritySettings,
  canShowDatabaseSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { Models } from '@appwrite.io/console'

/** Database list item: API may return extra backup/createdAt fields */
type DatabaseWithBackup = Models.Database & {
  hasBackupPolicy?: boolean
  backupPolicyCount?: number
  backupPolicy?: { name?: string }
  createdAt?: string
  updatedAt?: string
}

interface IndexColumnEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}

// Helper function for column type colors
const getColumnTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    string:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    varchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    text: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    mediumtext:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    longtext:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    integer:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
    float:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
    boolean:
      'bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20',
    datetime:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
    email: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-500/20',
    ip: 'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20',
    url: 'bg-pink-500/10 text-pink-600 dark:text-pink-400 border-pink-500/20',
    enum: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    relationship:
      'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
  }
  return colors[type] || 'bg-muted text-muted-foreground border-border'
}

// Helper function for index type colors
const getIndexTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    key: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    unique:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    fulltext:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
  }
  return colors[type] || 'bg-muted text-muted-foreground border-border'
}

import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { sdk } from '@/lib/appwrite/sdk'
import { ResourceCard } from '../shared/ResourceCard'
import { Card } from '@/components/ui/card'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { SampleDataModal } from './tables/SampleData'
import { generateSampleRows, type Column } from '@/lib/utils/sample-data'
import { IdInput } from '@/components/ui/id-input'
import { Button } from '@/components/ui/button'
import { PermissionsEditor } from '../auth/PermissionsEditor'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
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
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  MIN_SEARCH_LENGTH,
  databasesFilterColumns,
  rowsFilterColumnsFromAttributes,
  tableColumnsFilterColumns,
  tableIndexesFilterColumns,
  type TableIndexForFilters,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

// Reusable table styles for spreadsheet views
const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-r border-gray-200 dark:border-border shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db] dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1)]'
const bodyCellBorderClass =
  'border-b border-r border-gray-200 dark:border-border'
const lastCellBorderClass = 'border-b border-gray-200 dark:border-border'

// Main databases list view - used at /projects/:projectId/databases
export function View() {
  const { projectId } = useParams({
    from: '/_public/projects/$projectId/databases/',
  })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as {
    create?: string
    search?: string
    query?: string
    page?: number
    limit?: number
  }
  const { features } = useConsoleProfile()
  const useCreateDatabaseWizard = features.createDatabaseWizard
  const queryClient = useQueryClient()
  const isDatabasesIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/databases`
  const databaseListParams = useMemo(() => {
    if (!isDatabasesIndex || typeof search !== 'object') return null
    const url = new URL(location.pathname + location.search, window.location.origin)
    return {
      search: getSearch(url) ?? search.search,
      page: getPage(url, 1),
      limit: getLimit(url, ROWS_DEFAULT_PAGE_SIZE),
      filterMap: queryParamToMap(getQueryParam(url) ?? search.query ?? null),
    }
  }, [isDatabasesIndex, search?.search, search?.query, search?.page, search?.limit, location.pathname, location.search])

  const urlPage = databaseListParams?.page ?? 1
  const urlLimit = databaseListParams?.limit ?? ROWS_DEFAULT_PAGE_SIZE
  const urlSearch = databaseListParams?.search
  const filterMap = databaseListParams?.filterMap ?? new Map()
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const filterQueryString = filterMap.size > 0 ? mapToQueryParam(filterMap) : ''

  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState<string | undefined>(
    undefined,
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState('')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [pageSize, setPageSize] = useState(ROWS_DEFAULT_PAGE_SIZE)
  const [selectedDatabases, setSelectedDatabases] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [createDatabaseDialogOpen, setCreateDatabaseDialogOpen] =
    useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)

  // Open create database flow when ?create=database (wizard when feature enabled; else modal)
  useEffect(() => {
    if (search?.create === 'database' && !createDatabaseDialogOpen) {
      if (useCreateDatabaseWizard) {
        navigate({
          to: '/projects/$projectId/databases/create',
          params: { projectId: projectId! },
          search: (prev: Record<string, unknown>) => {
            if (!prev || typeof prev !== 'object') return {}
            const next = { ...prev }
            delete next.create
            return Object.keys(next).length === 0 ? {} : next
          },
          replace: true,
        })
      } else {
        setCreateDatabaseDialogOpen(true)
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
    }
  }, [search?.create, createDatabaseDialogOpen, useCreateDatabaseWizard, navigate, location.pathname, projectId])

  useEffect(() => {
    setSearchInput(urlSearch ?? '')
  }, [urlSearch])

  useEffect(() => {
    if (!isDatabasesIndex) return
    setRequestedPage((prev) => (prev === urlPage ? prev : urlPage))
    setPageSize((prev) => (prev === urlLimit ? prev : urlLimit))
  }, [isDatabasesIndex, urlPage, urlLimit])

  useEffect(() => {
    if (!isDatabasesIndex || !databaseListParams) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedFilterQueryString(filterQueryString)
      hasInitedDisplayedRef.current = true
    }
  }, [isDatabasesIndex, databaseListParams, urlPage, urlSearch, filterQueryString])

  useEffect(() => {
    if (!isDatabasesIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = searchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigate({
        to: '/projects/$projectId/databases/',
        params: { projectId: projectId! },
        search: (prev: Record<string, unknown>) => {
          const next = {
            ...prev,
            ...buildListSearchParams({
              search: trimmed || undefined,
              query: filterQueryString || undefined,
              page: 1,
              limit: urlLimit,
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
  }, [searchInput, projectId, navigate, urlSearch, urlLimit, filterQueryString, isDatabasesIndex])

  const {
    total: databasesTotal,
    isLoading: databasesLoading,
    isFetching: databasesFetching,
    isFetched: databasesFetched,
  } = useProjectDatabases(
    projectId,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
  )

  const {
    databases: apiDatabases,
    total: displayedDatabasesTotal,
    isLoading: displayedLoading,
  } = useProjectDatabases(
    projectId,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
  )

  useEffect(() => {
    if (!isDatabasesIndex || databasesFetching || databasesLoading || !databasesFetched) return
    const match =
      urlPage === displayedPage &&
      (urlSearch ?? '') === (displayedSearch ?? '') &&
      filterQueryString === displayedFilterQueryString
    if (!match) {
      setDisplayedPage(urlPage)
      setDisplayedSearch(urlSearch ?? undefined)
      setDisplayedFilterQueryString(filterQueryString)
    }
  }, [
    isDatabasesIndex,
    databasesFetching,
    databasesLoading,
    databasesFetched,
    urlPage,
    urlSearch,
    filterQueryString,
    displayedPage,
    displayedSearch,
    displayedFilterQueryString,
  ])

  // Only show full loading when we have no data to display (initial load)
  const showLoading = displayedLoading && apiDatabases.length === 0

  // Get total count from the first page query (no search) - already fetched in route loader
  // This is used for limit checking and doesn't change when searching
  const { data: totalDatabasesData } = useQuery({
    queryKey: ['databases', 'project', projectId, 0, pageSize, ''],
    queryFn: () => fetchProjectDatabases(projectId!, 0, pageSize, ''),
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
    refetchOnMount: false, // Data is fresh from route loader, no need to refetch
  })

  // Paginated data - databases are already paginated by the API
  const paginatedDatabases = apiDatabases

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)

  // Total count of all databases (without search) - for limit checking
  const totalDatabasesCount = totalDatabasesData?.total || 0

  // Check if create button should be disabled (plan limit or missing write scope)
  const noCreateDbPermission = !canCreateDatabase(access, features)
  const databasesLimit = organizationPlan?.databases ?? 0
  const isCreateDisabled =
    noCreateDbPermission ||
    (databasesLimit > 0 && totalDatabasesCount >= databasesLimit)

  // Clear selection when navigating or when search/filters change
  useEffect(() => {
    setSelectedDatabases(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, urlSearch, filterMap.size])

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedDatabases(new Set())
  }

  const applyFilter = (compactKey: CompactFilterKey, queryStr: string) => {
    const newMap = new Map(filterMap)
    newMap.set(compactKey, queryStr)
    navigate({
      to: '/projects/$projectId/databases/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: mapToQueryParam(newMap),
          page: 1,
          limit: urlLimit,
        }),
      }),
      replace: true,
    })
    setFiltersOpen(false)
  }

  const removeFilter = (key: CompactFilterKey) => {
    const newMap = new Map(filterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/databases/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
            page: 1,
            limit: urlLimit,
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
      to: '/projects/$projectId/databases/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            page: 1,
            limit: urlLimit,
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
    mutationFn: async (databaseIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all databases in parallel
      await Promise.all(
        databaseIds.map((databaseId) =>
          projectSdk.tablesDB.delete({ databaseId }),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch databases list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success(
        `Successfully deleted ${selectedDatabases.size} database${selectedDatabases.size > 1 ? 's' : ''}`,
      )
      setSelectedDatabases(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete databases')
    },
  })

  // Create database mutation
  const createDatabaseMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(projectId!, data),
    onSuccess: (database) => {
      toast.success(`${database.name} has been created`)
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
      setCreateDatabaseDialogOpen(false)
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: {
          projectId: projectId!,
          databaseId: database.$id,
          tableId: '-',
        },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to create database')
    },
  })

  const handleBulkDelete = () => {
    if (selectedDatabases.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedDatabases.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedDatabases))
  }

  const toggleDatabase = (databaseId: string) => {
    const newSelected = new Set(selectedDatabases)
    if (newSelected.has(databaseId)) {
      newSelected.delete(databaseId)
    } else {
      newSelected.add(databaseId)
    }
    setSelectedDatabases(newSelected)
  }

  const toggleAllDatabases = () => {
    if (selectedDatabases.size === paginatedDatabases.length) {
      setSelectedDatabases(new Set())
    } else {
      setSelectedDatabases(
        new Set(paginatedDatabases.map((db: DatabaseType) => db.$id)),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedDatabases(new Set())
    navigate({
      to: '/projects/$projectId/databases/',
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
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedDatabases(new Set())
    navigate({
      to: '/projects/$projectId/databases/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: filterQueryString || undefined,
          page: 1,
          limit: newPageSize,
        }),
      }),
      replace: true,
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
        title="Databases"
        searchPlaceholder="Search databases..."
        searchValue={searchInput}
        onSearchChange={handleSearchChange}
        createLabel="Create database"
        onCreate={() =>
          useCreateDatabaseWizard
            ? navigate({
                to: '/projects/$projectId/databases/create',
                params: { projectId: projectId! },
              })
            : setCreateDatabaseDialogOpen(true)
        }
        createDisabled={isCreateDisabled}
        createDisabledTooltip={
          noCreateDbPermission
            ? "You don't have permission to create databases."
            : undefined
        }
        showFilters={true}
        filterTrigger={
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={databasesFilterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearAllFilters}
            onApplyFilter={applyFilter}
            resourceLabel="databases"
          />
        }
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          // Data is prefetched in route loader, only render if data exists
          // PlanLimitWarning handles its own visibility logic
          project &&
          organizationPlan !== undefined &&
          totalDatabasesData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalDatabasesCount}
              limit={databasesLimit}
              planName={organizationPlan?.name}
              resourceName="databases"
              orgId={project?.teamId}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <div className="text-muted-foreground">Loading databases...</div>
          </div>
        ) : viewMode === 'list' ? (
          paginatedDatabases.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedDatabases.length > 0 &&
                            selectedDatabases.size === paginatedDatabases.length
                          }
                          onCheckedChange={toggleAllDatabases}
                        />
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Database
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                        Status
                      </TableHead>
                      {features.databaseBackups && (
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                          Backups
                        </TableHead>
                      )}
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Created
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Updated
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedDatabases.map(
                      (
                        db: DatabaseType & {
                          createdAt?: string
                          updatedAt?: string
                          enabled?: boolean
                          hasBackupPolicy?: boolean
                          backupPolicy?: unknown
                          backupPolicyCount?: number
                        },
                      ) => (
                        <TableRow
                          key={db.$id}
                          className={cn(
                            'cursor-pointer transition-colors border-b border-border/50',
                            selectedDatabases.has(db.$id)
                              ? 'bg-muted'
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
                              to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                              params: {
                                projectId,
                                databaseId: db.$id,
                                tableId: '-',
                              },
                            })
                          }}
                        >
                          <TableCell
                            onClick={(e) => e.stopPropagation()}
                            className="px-4 py-3"
                          >
                            <Checkbox
                              checked={selectedDatabases.has(db.$id)}
                              onCheckedChange={() => toggleDatabase(db.$id)}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId: db.$id,
                                tableId: '-',
                              }}
                              className="block group"
                            >
                              <div className="flex items-center gap-3 min-w-0">
                                <div className="flex-1 min-w-0">
                                  <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                    {db.name}
                                  </p>
                                  <div className="mt-0.5">
                                    <CopyableId id={db.$id} size="xs" />
                                  </div>
                                </div>
                              </div>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <div className="flex items-center justify-center">
                              {db.enabled === false ? (
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
                          {features.databaseBackups && (
                            <TableCell className="px-4 py-3">
                              <div className="flex items-center justify-center">
                                {(db as DatabaseWithBackup).hasBackupPolicy ? (
                                  <Badge
                                    variant="success"
                                    className="gap-1.5 text-[11px] font-medium border px-2 py-0.5"
                                  >
                                    <CheckCircle2 className="h-3 w-3" />
                                    {(db as DatabaseWithBackup)
                                      .backupPolicyCount > 0
                                      ? `${(db as DatabaseWithBackup).backupPolicyCount} ${(db as DatabaseWithBackup).backupPolicyCount === 1 ? 'policy' : 'policies'}`
                                      : (db as DatabaseWithBackup).backupPolicy
                                          ?.name || 'Enabled'}
                                  </Badge>
                                ) : (
                                  <Badge
                                    variant="warning"
                                    className="gap-1.5 text-[11px] font-medium border px-2 py-0.5"
                                  >
                                    <AlertCircle className="h-3 w-3" />
                                    None
                                  </Badge>
                                )}
                              </div>
                            </TableCell>
                          )}
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId: db.$id,
                                tableId: '-',
                              }}
                              className="block text-right"
                            >
                              <DateTooltip
                                date={
                                  new Date(
                                    (db as DatabaseWithBackup).createdAt ||
                                      new Date(),
                                  )
                                }
                                className="text-[12px] text-muted-foreground font-mono"
                              />
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId: db.$id,
                                tableId: '-',
                              }}
                              className="block text-right"
                            >
                              <DateTooltip
                                date={
                                  new Date(
                                    (db as DatabaseWithBackup).updatedAt ||
                                      (db as DatabaseWithBackup).createdAt ||
                                      new Date(),
                                  )
                                }
                                className="text-[12px] text-muted-foreground font-mono"
                              />
                            </Link>
                          </TableCell>
                        </TableRow>
                      ),
                    )}
                  </TableBody>
                </Table>
              </div>
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedDatabasesTotal ?? databasesTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="databases"
              />
            </>
          ) : (
            <EmptyState
              icon={Database}
              title={urlSearch || filterMap.size > 0 ? undefined : 'No databases yet'}
              description={
                urlSearch || filterMap.size > 0
                  ? undefined
                  : 'Create your first database to get started'
              }
              isEmpty={!urlSearch && filterMap.size === 0}
              hasFilters={!!urlSearch || filterMap.size > 0}
              variant="card"
            />
          )
        ) : (
          <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {paginatedDatabases.map(
                (
                  db: DatabaseType & {
                    createdAt?: string
                    updatedAt?: string
                    hasBackupPolicy?: boolean
                    backupPolicy?: unknown
                    backupPolicyCount?: number
                  },
                ) => (
                  <Link
                    key={db.$id}
                    to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                    params={{ projectId, databaseId: db.$id, tableId: '-' }}
                  >
                    <ResourceCard
                      title={db.name}
                      resourceId={db.$id}
                      icon={Database}
                      iconColor="bg-muted text-muted-foreground"
                      status={db.enabled === false ? 'error' : undefined}
                      statusLabel={
                        db.enabled === false ? 'Disabled' : undefined
                      }
                      metadata={
                        features.databaseBackups
                          ? [
                              {
                                label: '',
                                value: (db as DatabaseWithBackup)
                                  .hasBackupPolicy ? (
                                  <Badge
                                    variant="success"
                                    className="gap-1.5 text-[11px] font-medium"
                                  >
                                    <CheckCircle2 className="h-3 w-3" />
                                    {(db as DatabaseWithBackup)
                                      .backupPolicyCount > 0
                                      ? `${(db as DatabaseWithBackup).backupPolicyCount} ${(db as DatabaseWithBackup).backupPolicyCount === 1 ? 'policy' : 'policies'}`
                                      : (db as DatabaseWithBackup).backupPolicy
                                          ?.name || 'Backup Enabled'}
                                  </Badge>
                                ) : (
                                  <Badge
                                    variant="warning"
                                    className="gap-1.5 text-[11px] font-medium"
                                  >
                                    <AlertCircle className="h-3 w-3" />
                                    No backup policies
                                  </Badge>
                                ),
                              },
                            ]
                          : []
                      }
                    />
                  </Link>
                ),
              )}

              {paginatedDatabases.length === 0 && (
                <div className="col-span-full">
                  <EmptyState
                    icon={Database}
                    title={urlSearch || filterMap.size > 0 ? undefined : 'No databases yet'}
                    description={
                      urlSearch || filterMap.size > 0
                        ? undefined
                        : 'Create your first database to get started'
                    }
                    isEmpty={!urlSearch && filterMap.size === 0}
                    hasFilters={!!urlSearch || filterMap.size > 0}
                    variant="card"
                  />
                </div>
              )}
            </div>
            {paginatedDatabases.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedDatabasesTotal ?? databasesTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="databases"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedDatabases.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDatabases.size} database
                {selectedDatabases.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedDatabases(new Set())}
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
              <DialogTitle>Delete Databases</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedDatabases.size}{' '}
                database{selectedDatabases.size > 1 ? 's' : ''}? This action
                cannot be undone.
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

        <CreateDatabase
          open={createDatabaseDialogOpen}
          onOpenChange={setCreateDatabaseDialogOpen}
          onCreate={(data) => createDatabaseMutation.mutate(data)}
          isLoading={createDatabaseMutation.isPending}
          backupsEnabled={organizationPlan?.backupsEnabled}
        />
      </div>
    </div>
  )
}

// Database Detail Layout - shows tables in sidebar, renders table content
interface DatabaseDetailLayoutProps {
  databaseId: string
}

export function DatabaseDetailLayout({
  databaseId,
}: DatabaseDetailLayoutProps) {
  const { projectId } = useParams({
    from: '/_public/projects/$projectId/databases/$databaseId',
  })
  const navigate = useNavigate()
  const [tablesExpanded, setTablesExpanded] = useState(true)
  const { features } = useConsoleProfile()

  const database = databases.find((db) => db.$id === databaseId)
  const dbTables = collections.filter((c) => c.databaseId === databaseId)

  // Get current tableId from URL if we're on a table route
  const currentTableId = window.location.pathname.split('/').pop()
  const selectedTable = collections.find((c) => c.$id === currentTableId)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  if (!database) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            Database not found
          </p>
          <Button variant="link" onClick={handleBack}>
            Back to databases
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="@container flex h-full">
      {/* Tables Sidebar */}
      <div className="hidden w-56 shrink-0 flex-col border-r border-border @[800px]:flex">
        {/* Database Header with back button */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <button
            onClick={handleBack}
            className="flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <Database className="h-4 w-4 text-muted-foreground" />
          <span className="text-[13px] font-medium text-foreground">
            {database.name}
          </span>
        </div>

        {/* Tables List */}
        <div className="flex-1 overflow-y-auto p-2">
          {/* Tables Section Header */}
          <button
            onClick={() => setTablesExpanded(!tablesExpanded)}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            {tablesExpanded ? (
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            )}
            <Table2 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px] font-medium">Tables</span>
            <span className="text-[11px] text-muted-foreground">
              {dbTables.length}
            </span>
          </button>

          {/* Tables List (collapsible) */}
          {tablesExpanded && (
            <div className="ml-3 mt-0.5 border-l border-border pl-2">
              {dbTables.map((table) =>
                projectId ? (
                  <TableContextMenu
                    key={table.$id}
                    projectId={projectId}
                    databaseId={databaseId}
                    table={table}
                  >
                    <Link
                      to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                      params={{ projectId, databaseId, tableId: table.$id }}
                      className={cn(
                        'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors',
                        selectedTable?.$id === table.$id
                          ? 'bg-accent text-foreground'
                          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                      )}
                    >
                      <Table2 className="h-3.5 w-3.5 shrink-0" />
                      <span className="min-w-0 flex-1 truncate text-[13px]">
                        {table.name}
                      </span>
                      {table.enabled === false && (
                        <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                      )}
                    </Link>
                  </TableContextMenu>
                ) : (
                  <Link
                    key={table.$id}
                    to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                    params={{ projectId: '', databaseId, tableId: table.$id }}
                    className={cn(
                      'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors',
                      selectedTable?.$id === table.$id
                        ? 'bg-accent text-foreground'
                        : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                    )}
                  >
                    <Table2 className="h-3.5 w-3.5 shrink-0" />
                    <span className="min-w-0 flex-1 truncate text-[13px]">
                      {table.name}
                    </span>
                    {table.enabled === false && (
                      <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                    )}
                  </Link>
                ),
              )}

              {/* Create Table Button */}
              <button
                onClick={() => setCreateTableDialogOpen(true)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5 shrink-0" />
                <span className="text-[13px]">Create table</span>
              </button>
            </div>
          )}

          {/* Security Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/security"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Security</span>
          </Link>

          {features.databaseInsights && (
            <>
              {/* Insights Link - Coming Soon */}
              <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground/50">
                <BarChart3 className="h-3.5 w-3.5 shrink-0" />
                <span className="flex-1 text-[13px]">Insights</span>
                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                  Soon
                </span>
              </span>
            </>
          )}
          {features.databaseBackups && (
            <Link
              to="/projects/$projectId/databases/$databaseId/backups"
              params={{ projectId, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
            >
              <Archive className="h-3.5 w-3.5 shrink-0" />
              <span className="text-[13px]">Backups</span>
            </Link>
          )}

          {/* Settings Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/settings"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Settings</span>
          </Link>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {selectedTable ? (
          <div className="flex flex-1 items-center justify-center">
            <div className="text-center">
              <p className="text-muted-foreground">
                Select a table from the sidebar
              </p>
            </div>
          </div>
        ) : (
          <div className="flex flex-1 items-center justify-center">
            <EmptyState
              icon={Table2}
              title="No tables yet"
              description="Create your first table to get started"
              isEmpty={true}
              iconSize="md"
            />
          </div>
        )}
      </div>
    </div>
  )
}

// Database-level tab when embedded in TableView (under tables/$tableId/...)
export type DatabaseTabId =
  | 'visualizer'
  | 'insights'
  | 'backups'
  | 'export-import'
  | 'db-security'
  | 'db-settings'
  | 'browser'

// Table View - shows table content with tabs; can also show database-level tabs when databaseTab is set or tableId is '-'
interface TableViewProps {
  databaseId: string
  tableId: string
  activeTab: 'rows' | 'columns' | 'indexes' | 'security' | 'settings'
  /** When set, main content shows database-level tab (visualizer, backups, etc.) instead of table tabs */
  databaseTab?: DatabaseTabId
}

const DATABASE_TAB_TO_OVERVIEW: Record<
  DatabaseTabId,
  DatabaseOverviewProps['activeTab']
> = {
  visualizer: 'visualizer',
  insights: 'insights',
  backups: 'backups',
  'export-import': 'export-import',
  'db-security': 'security',
  'db-settings': 'settings',
  browser: 'browser',
}

const DATABASE_TAB_LABELS: Record<DatabaseTabId, string> = {
  visualizer: 'Visualizer',
  insights: 'Insights',
  backups: 'Backups',
  'export-import': 'Export / Import',
  'db-security': 'Security',
  'db-settings': 'Settings',
  browser: 'Browser',
}

export function TableView({
  databaseId,
  tableId,
  activeTab,
  databaseTab,
}: TableViewProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as Record<string, unknown> | undefined
  const isDatabaseLevelView = tableId === '-' || databaseTab != null
  const { isDebugModeOpen } = useDebugMode()
  const { features } = useConsoleProfile()
  const useCreateDatabaseWizard = features.createDatabaseWizard

  // Debug: create 50 random tables (only when debug mode is open and on tables list)
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
      toast.success('Created 50 tables')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create tables')
    },
  })

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

  // Fetch tables for the database (full list for selectedTable and total count)
  const { tables: dbTables, isLoading: tablesLoading } = useProjectTables(
    projectId,
    databaseId,
    0,
    100,
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
  const openCreateColumnDialogRef = useRef<(() => void) | null>(null)
  const openSuggestColumnsDialogRef = useRef<(() => void) | null>(null)
  const openCreateIndexDialogRef = useRef<(() => void) | null>(null)
  const openSuggestIndexesDialogRef = useRef<(() => void) | null>(null)
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
  const queryClient = useQueryClient()

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showTableSecuritySettings =
    canShowTableSecuritySettings(access, features)
  const noCreateTablePermission = !canShowTableSecuritySettings(access, features)
  const noCreateDbPermission = !canCreateDatabase(access, features)
  const noCreateRowPermission = !canCreateRow(access, features)
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
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId },
        replace: true,
      })
    }
  }, [
    showTableSecuritySettings,
    activeTab,
    selectedTable,
    projectId,
    databaseId,
    tableId,
    navigate,
  ])

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
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId: '-' },
      })
    }
  }

  // Create table mutation for TableView
  const createTableMutation = useMutation({
    mutationFn: (data: { tableId?: string; name: string }) =>
      createProjectTable(projectId!, databaseId!, data),
    onSuccess: async (table) => {
      toast.success(`${table.name} has been created`)
      // Refetch tables and wait for it to complete before navigating
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      // Navigate to the new table's rows tab
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: {
          projectId: projectId!,
          databaseId: databaseId!,
          tableId: table.$id,
        },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to create table')
    },
  })

  // Create database mutation for TableView (rows view sidebar)
  const createDatabaseMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(projectId!, data),
    onSuccess: async (database) => {
      toast.success(`${database.name} has been created`)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      setCreateDatabaseDialogOpen(false)
      try {
        const tablesData = await queryClient.ensureQueryData(
          tablesQueryOptions(projectId, database.$id, 0, 100, undefined),
        )
        const sortedTables = [...(tablesData.tables || [])].sort((a, b) =>
          (a.name?.toLowerCase() || '').localeCompare(
            b.name?.toLowerCase() || '',
          ),
        )
        const firstTable = sortedTables[0]
        if (firstTable?.$id) {
          navigate({
            to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
            params: {
              projectId,
              databaseId: database.$id,
              tableId: firstTable.$id,
            },
          })
        } else {
          navigate({
            to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
            params: { projectId, databaseId: database.$id, tableId: '-' },
          })
        }
      } catch {
        navigate({
          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
          params: { projectId, databaseId: database.$id, tableId: '-' },
        })
      }
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to create database')
    },
  })

  const effectiveTableId = tableId === '-' ? undefined : tableId
  const { columns: tableColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    effectiveTableId,
  )
  const { indexes: tableIndexes } = useProjectTableIndexes(
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
    const all: Tab[] = [
      {
        id: 'rows',
        label: 'Rows',
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId },
      },
      {
        id: 'columns',
        label: 'Columns',
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/columns',
        params: { projectId, databaseId, tableId },
      },
      {
        id: 'indexes',
        label: 'Indexes',
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/indexes',
        params: { projectId, databaseId, tableId },
      },
      ...(showTableSecuritySettings
        ? [
            {
              id: 'security' as const,
              label: 'Security',
              to: '/projects/$projectId/databases/$databaseId/tables/$tableId/security',
              params: { projectId, databaseId, tableId },
            },
            {
              id: 'settings' as const,
              label: 'Settings',
              to: '/projects/$projectId/databases/$databaseId/tables/$tableId/settings',
              params: { projectId, databaseId, tableId },
            },
          ]
        : []),
    ]
    return all
  }, [
    projectId,
    databaseId,
    tableId,
    showTableSecuritySettings,
  ])

  const isRowsTab = activeTab === 'rows' && tableId !== '-'
  const rowsListParams = useMemo(() => {
    if (!isRowsTab || typeof search !== 'object') return null
    const url = new URL(location.pathname + location.search, window.location.origin)
    return {
      search: getSearch(url) ?? (search?.search as string | undefined),
      page: getPage(url, 1),
      limit: getLimit(url, ROWS_DEFAULT_PAGE_SIZE),
      filterMap: queryParamToMap(getQueryParam(url) ?? (search?.query as string | undefined) ?? null),
    }
  }, [isRowsTab, search, location.pathname, location.search])

  const rowsUrlPage = rowsListParams?.page ?? 1
  const rowsUrlLimit = rowsListParams?.limit ?? ROWS_DEFAULT_PAGE_SIZE
  const rowsUrlSearch = rowsListParams?.search
  const rowsFilterMap = rowsListParams?.filterMap ?? new Map()
  const rowsFilterQueries =
    rowsFilterMap.size > 0 ? Array.from(rowsFilterMap.values()) : undefined
  const rowsFilterQueryString =
    rowsFilterMap.size > 0 ? mapToQueryParam(rowsFilterMap) : ''

  const rowsFilterColumns = useMemo(
    () =>
      rowsFilterColumnsFromAttributes(
        tableColumns,
        tableIndexes as TableIndexForFilters[],
      ),
    [tableColumns, tableIndexes],
  )

  const [rowsFiltersOpen, setRowsFiltersOpen] = useState(false)

  const navigateToRowsList = (params: {
    search?: string
    query?: string
    page?: number
    limit?: number
  }) => {
    const hasQueryKey = 'query' in params
    navigate({
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params: { projectId, databaseId, tableId },
      search: (prev: Record<string, unknown>) => {
        const built = buildListSearchParams({
          search: params.search ?? rowsUrlSearch ?? undefined,
          query: hasQueryKey
            ? params.query
            : (rowsFilterQueryString || undefined),
          page: params.page ?? rowsUrlPage,
          limit: params.limit ?? rowsUrlLimit,
        })
        const next = { ...prev, ...built }
        if (hasQueryKey && params.query === undefined) delete next.query
        return next
      },
      replace: true,
    })
  }

  const rowsApplyFilter = (compactKey: CompactFilterKey, queryStr: string) => {
    const next = new Map(rowsFilterMap)
    next.set(compactKey, queryStr)
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: mapToQueryParam(next) || undefined,
      page: 1,
      limit: rowsUrlLimit,
    })
    setRowsFiltersOpen(false)
  }

  const rowsRemoveFilter = (compactKey: CompactFilterKey) => {
    const next = new Map(rowsFilterMap)
    next.delete(compactKey)
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: next.size > 0 ? mapToQueryParam(next) : undefined,
      page: 1,
      limit: rowsUrlLimit,
    })
  }

  const rowsClearAllFilters = () => {
    navigateToRowsList({
      search: rowsUrlSearch ?? undefined,
      query: undefined,
      page: 1,
      limit: rowsUrlLimit,
    })
    setRowsFiltersOpen(false)
  }

  // Columns/indexes filter state (URL query) and handlers for header FiltersPopover
  const tableDetailFilterMap = useMemo(
    () =>
      queryParamToMap((search?.query as string | undefined) ?? null),
    [search?.query],
  )
  const [columnsFiltersOpen, setColumnsFiltersOpen] = useState(false)
  const [indexesFiltersOpen, setIndexesFiltersOpen] = useState(false)
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
  const columnsApplyFilter = (key: CompactFilterKey, queryStr: string) => {
    const newMap = new Map(tableDetailFilterMap)
    newMap.set(key, queryStr)
    navigateTableDetailSearch({
      query: mapToQueryParam(newMap) || undefined,
    })
  }
  const columnsRemoveFilter = (key: CompactFilterKey) => {
    const newMap = new Map(tableDetailFilterMap)
    newMap.delete(key)
    navigateTableDetailSearch({
      query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
    })
  }
  const columnsClearAllFilters = () => {
    navigateTableDetailSearch({ query: undefined })
    setColumnsFiltersOpen(false)
  }
  const indexesApplyFilter = (key: CompactFilterKey, queryStr: string) => {
    const newMap = new Map(tableDetailFilterMap)
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
        return 'Create row'
      case 'columns':
        return 'Create column'
      case 'indexes':
        return 'Create index'
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
            Table not found
          </p>
          <Button variant="link" onClick={handleBackToDatabase}>
            Back to database
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="@container flex h-full">
      {/* Tables Sidebar - sticky sections: database selector, create table, scrollable list, bottom nav */}
      <div className="hidden w-56 shrink-0 flex-col border-r border-border lg:flex lg:h-full">
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
              createDisabled={noCreateDbPermission}
              createDisabledTooltip={createPermissionTooltip}
              onSelect={async (newDatabaseId) => {
                try {
                  const tablesData = await queryClient.ensureQueryData(
                    tablesQueryOptions(
                      projectId,
                      newDatabaseId,
                      0,
                      100,
                      undefined,
                    ),
                  )
                  const sorted = [...(tablesData.tables || [])].sort(
                    (a: { name?: string }, b: { name?: string }) => {
                      const nameA = a.name?.toLowerCase() || ''
                      const nameB = b.name?.toLowerCase() || ''
                      return nameA.localeCompare(nameB)
                    },
                  )
                  const firstTable = sorted[0] as { $id?: string } | undefined
                  navigate({
                    to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                    params: {
                      projectId,
                      databaseId: newDatabaseId,
                      tableId: firstTable?.$id ?? '-',
                    },
                  })
                } catch {
                  navigate({
                    to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                    params: {
                      projectId,
                      databaseId: newDatabaseId,
                      tableId: '-',
                    },
                  })
                }
              }}
              onCreateClick={() =>
                useCreateDatabaseWizard
                  ? navigate({
                      to: '/projects/$projectId/databases/create',
                      params: { projectId },
                    })
                  : setCreateDatabaseDialogOpen(true)
              }
            />
          </div>
        </div>

        {/* 2. Scrollable: search, tables list, create table (sticky above pagination), pagination */}
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <div className="shrink-0 space-y-2 border-b border-border px-2 py-2">
            <div className="flex min-w-0 items-center gap-2">
              <div className="relative min-w-0 flex-1">
                <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  type="text"
                  placeholder="Search tables..."
                  value={sidebarTablesSearch}
                  onChange={(e) => setSidebarTablesSearch(e.target.value)}
                  className="h-8 pl-8 pr-2 text-[13px]"
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
                          aria-label="Sort tables"
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
                    Sort tables
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
          <div className="min-h-0 flex-1 overflow-y-auto">
            {displayedSidebarTables.length === 0 && sidebarTablesLoading ? (
              <div className="p-2 text-center text-[12px] text-muted-foreground">
                Loading…
              </div>
            ) : (
              <div className="space-y-0.5 px-2.5 py-1">
                {displayedSidebarTables.map((table) => {
                  const isTableSelected =
                    selectedTable?.$id === table.$id && !databaseTab
                  return (
                    <TableContextMenu
                      key={table.$id}
                      projectId={projectId!}
                      databaseId={databaseId}
                      table={table}
                      showSecuritySettings={showTableSecuritySettings}
                      onCreateSimilar={async (newTableId) => {
                        await queryClient.refetchQueries({
                          queryKey: [
                            'tables',
                            'project',
                            projectId,
                            databaseId,
                          ],
                        })
                        // Prefetch new table data before navigating to avoid layout shift / loading screen
                        await Promise.all([
                          queryClient.ensureQueryData(
                            tableQueryOptions(
                              projectId,
                              databaseId,
                              newTableId,
                            ),
                          ),
                          queryClient.ensureQueryData(
                            tableColumnsQueryOptions(
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
                          to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                          params: {
                            projectId: projectId!,
                            databaseId,
                            tableId: newTableId,
                          },
                        })
                      }}
                    >
                      <Link
                        to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                        params={{ projectId, databaseId, tableId: table.$id }}
                        className={cn(
                          'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
                          isTableSelected
                            ? 'bg-accent text-foreground'
                            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                        )}
                      >
                        <Table2 className="h-3.5 w-3.5 shrink-0" />
                        <span className="min-w-0 flex-1 truncate">
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
                  ? '0 tables'
                  : `${(sidebarTablesDisplayedPage - 1) * sidebarTablesPageSize + 1}-${Math.min(sidebarTablesDisplayedPage * sidebarTablesPageSize, sidebarTablesTotal ?? 0)} of ${(sidebarTablesTotal ?? 0).toLocaleString()}`}
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
                  onClick={() =>
                    setSidebarTablesRequestedPage((p) => p + 1)
                  }
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
          <div className="shrink-0 border-t border-border px-2 py-2">
            {noCreateTablePermission ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="block w-full">
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 w-full gap-2 pl-6 pr-6 text-[13px] font-medium"
                      onClick={() => setCreateTableDialogOpen(true)}
                      disabled
                    >
                      <Plus className="h-4 w-4" />
                      Create table
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
                className="h-9 w-full gap-2 pl-6 pr-6 text-[13px] font-medium"
                onClick={() => setCreateTableDialogOpen(true)}
              >
                <Plus className="h-4 w-4" />
                Create table
              </Button>
            )}
          </div>
        </div>

        {/* 4. Sticky bottom: Nav links (match main sidebar item size and spacing) */}
        <div className="shrink-0 space-y-0.5 border-t border-border bg-background px-2.5 py-2">
          <Link
            to="/projects/$projectId/databases/$databaseId/tables/$tableId/visualizer"
            params={{ projectId, databaseId, tableId }}
            className={cn(
              'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
              databaseTab === 'visualizer'
                ? 'bg-accent text-foreground'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
            )}
          >
            <Network className="h-3.5 w-3.5 shrink-0" />
            <span>Visualizer</span>
          </Link>
          {!noCreateDbPermission && (
            <Link
              to="/projects/$projectId/databases/$databaseId/tables/$tableId/db-security"
              params={{ projectId, databaseId, tableId }}
              className={cn(
                'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
                databaseTab === 'db-security'
                  ? 'bg-accent text-foreground'
                  : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
              )}
            >
              <Lock className="h-3.5 w-3.5 shrink-0" />
              <span>Security</span>
            </Link>
          )}
          {features.databaseInsights && (
            <Link
              to="/projects/$projectId/databases/$databaseId/tables/$tableId/insights"
              params={{ projectId, databaseId, tableId }}
              className={cn(
                  'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
                  databaseTab === 'insights'
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                )}
            >
              <BarChart3 className="h-3.5 w-3.5 shrink-0" />
              <span>Insights</span>
            </Link>
          )}
          {features.databaseBackups && (
            <Link
              to="/projects/$projectId/databases/$databaseId/tables/$tableId/backups"
              params={{ projectId, databaseId, tableId }}
              className={cn(
                  'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
                  databaseTab === 'backups'
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                )}
              >
                <Archive className="h-3.5 w-3.5 shrink-0" />
              <span>Backups</span>
            </Link>
          )}
          <Link
            to="/projects/$projectId/databases/$databaseId/tables/$tableId/export-import"
            params={{ projectId, databaseId, tableId }}
            className={cn(
              'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
              databaseTab === 'export-import'
                ? 'bg-accent text-foreground'
                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
            )}
          >
            <Download className="h-3.5 w-3.5 shrink-0" />
            <span>Export / Import</span>
          </Link>
          {!noCreateDbPermission && (
            <Link
              to="/projects/$projectId/databases/$databaseId/tables/$tableId/db-settings"
              params={{ projectId, databaseId, tableId }}
              className={cn(
                'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors duration-150',
                databaseTab === 'db-settings'
                  ? 'bg-accent text-foreground'
                  : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
              )}
            >
              <Settings className="h-3.5 w-3.5 shrink-0" />
              <span>Settings</span>
            </Link>
          )}
        </div>
      </div>

      {/* Main Content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        <ServiceHeader
          title={
            isDatabaseLevelView ? (
              databaseTab ? (
                DATABASE_TAB_LABELS[databaseTab]
              ) : (
                'Tables'
              )
            ) : (
              <div className="flex items-center gap-2 min-w-0">
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
            (activeTab === 'rows'
              ? noCreateRowPermission
              : (activeTab === 'columns' || activeTab === 'indexes')
                ? noCreateTablePermission
                : false)
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
                  } else if (
                    activeTab === 'columns' &&
                    openCreateColumnDialogRef.current
                  ) {
                    openCreateColumnDialogRef.current()
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
              activeTab === 'columns' ||
              activeTab === 'indexes')
          }
          filterTrigger={
            !isDatabaseLevelView && activeTab === 'rows' ? (
              <FiltersPopover
                open={rowsFiltersOpen}
                onOpenChange={setRowsFiltersOpen}
                columns={rowsFilterColumns}
                filterMap={rowsFilterMap}
                onRemoveFilter={rowsRemoveFilter}
                onClearAll={rowsClearAllFilters}
                onApplyFilter={rowsApplyFilter}
                resourceLabel="rows"
              />
            ) : !isDatabaseLevelView && activeTab === 'columns' ? (
              <FiltersPopover
                open={columnsFiltersOpen}
                onOpenChange={setColumnsFiltersOpen}
                columns={tableColumnsFilterColumns}
                filterMap={tableDetailFilterMap}
                onRemoveFilter={columnsRemoveFilter}
                onClearAll={columnsClearAllFilters}
                onApplyFilter={columnsApplyFilter}
                resourceLabel="columns"
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
                resourceLabel="indexes"
              />
            ) : undefined
          }
          showRefresh={!isDatabaseLevelView && activeTab === 'rows'}
          onRefresh={
            !isDatabaseLevelView && activeTab === 'rows'
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
                      toast.success('Rows refreshed successfully')
                    } catch {
                      toast.error('Failed to refresh rows')
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
          beforeCreateButtons={
            isDatabaseLevelView || !showTableSecuritySettings
              ? undefined
              :               activeTab === 'columns'
                ? (
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            if (openSuggestColumnsDialogRef.current) {
                              openSuggestColumnsDialogRef.current()
                            }
                          }}
                          className="h-9"
                        >
                          <Lightbulb className="h-3.5 w-3.5 shrink-0 sm:mr-1.5" />
                          <span className="hidden sm:inline">
                            Suggest columns
                          </span>
                        </Button>
                      </TooltipTrigger>
                      <TooltipContent side="bottom">
                        Suggest columns
                      </TooltipContent>
                    </Tooltip>
                  )
                : activeTab === 'indexes'
                  ? (
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              if (openSuggestIndexesDialogRef.current) {
                                openSuggestIndexesDialogRef.current()
                              }
                            }}
                            className="h-9"
                          >
                            <Lightbulb className="h-3.5 w-3.5 shrink-0 sm:mr-1.5" />
                            <span className="hidden sm:inline">
                              Suggest indexes
                            </span>
                          </Button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                          Suggest indexes
                        </TooltipContent>
                      </Tooltip>
                    )
                  : undefined
          }
          collapsible={!isDatabaseLevelView}
          fullWidthBorder
          fullWidth={!isDatabaseLevelView || databaseTab === 'visualizer'}
          rightContent={
            isDatabaseLevelView && isDebugModeOpen ? (
              <Button
                variant="outline"
                size="sm"
                className="h-9"
                onClick={() => createFiftyTablesMutation.mutate()}
                disabled={createFiftyTablesMutation.isPending}
              >
                {createFiftyTablesMutation.isPending
                  ? 'Creating…'
                  : 'Debug: Create 50 tables'}
              </Button>
            ) : undefined
          }
          contentAfterBorder={
            <>
              {/* Mobile DB + table selector - above toolbar, shows when sidebar is hidden */}
              <div className="flex flex-col gap-2 border-b border-border px-4 py-3 lg:hidden">
                <DatabaseSelector
                  projectId={projectId}
                  value={databaseId}
                  selectedName={database?.name}
                  createDisabled={noCreateDbPermission}
                  createDisabledTooltip={createPermissionTooltip}
                  onSelect={async (newDatabaseId) => {
                    try {
                      const tablesData =
                        await queryClient.ensureQueryData(
                          tablesQueryOptions(
                            projectId,
                            newDatabaseId,
                            0,
                            100,
                            undefined,
                          ),
                        )
                      const sorted = [...(tablesData.tables || [])].sort(
                        (a: { name?: string }, b: { name?: string }) => {
                          const nameA = a.name?.toLowerCase() || ''
                          const nameB = b.name?.toLowerCase() || ''
                          return nameA.localeCompare(nameB)
                        },
                      )
                      const firstTable = sorted[0] as { $id?: string } | undefined
                      navigate({
                        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                        params: {
                          projectId,
                          databaseId: newDatabaseId,
                          tableId: firstTable?.$id ?? '-',
                        },
                      })
                    } catch {
                      navigate({
                        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                        params: {
                          projectId,
                          databaseId: newDatabaseId,
                          tableId: '-',
                        },
                      })
                    }
                  }}
                  onCreateClick={() =>
                    useCreateDatabaseWizard
                      ? navigate({
                          to: '/projects/$projectId/databases/create',
                          params: { projectId },
                        })
                      : setCreateDatabaseDialogOpen(true)
                  }
                />
                <TableSelector
                  projectId={projectId}
                  databaseId={databaseId}
                  value={tableId}
                  selectedName={selectedTable?.name}
                  createDisabled={noCreateTablePermission}
                  createDisabledTooltip={createPermissionTooltip}
                  onSelect={(newTableId) => {
                    navigate({
                      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                      params: {
                        projectId,
                        databaseId,
                        tableId: newTableId,
                      },
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
                        Database is disabled
                      </AlertTitle>
                      <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                        <span className="inline">
                          This database is disabled and not accessible to end
                          users through the API. Console actions remain
                          available.{' '}
                          <Link
                            to="/projects/$projectId/databases/$databaseId/settings"
                            params={{ projectId, databaseId }}
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
                  <div className="px-4 py-3 sm:px-6">
                    <Alert
                      variant="default"
                      className="border-amber-500/30 bg-transparent"
                    >
                      <AlertCircle className="h-4 w-4 text-amber-500" />
                      <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                        Table is disabled
                      </AlertTitle>
                      <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                        <span className="inline">
                          This table is currently disabled.{' '}
                          <Link
                            to="/projects/$projectId/databases/$databaseId/tables/$tableId/settings"
                            params={{ projectId, databaseId, tableId }}
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

        <div className={cn('flex-1 min-h-0 overflow-y-auto')}>
          {isDatabaseLevelView ? (
            <DatabaseOverview
              databaseId={databaseId}
              activeTab={
                databaseTab ? DATABASE_TAB_TO_OVERVIEW[databaseTab] : 'tables'
              }
              contentOnly
            />
          ) : (
            <>
              {activeTab === 'rows' && (
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
                  onCreateColumnReady={openCreateColumnDialogRef.current}
                  onRowsCountChange={handleRowsCountChange}
                  rowsUrlSearch={rowsUrlSearch}
                  rowsUrlPage={rowsUrlPage}
                  rowsUrlLimit={rowsUrlLimit}
                  rowsFilterQueries={rowsFilterQueries}
                  rowsFilterQueryString={rowsFilterQueryString}
                  onNavigateToRowsList={navigateToRowsList}
                />
              )}
              {activeTab === 'columns' && (
                <ColumnsSpreadsheet
                  table={selectedTable}
                  canWriteTables={!noCreateTablePermission}
                  filterMap={tableDetailFilterMap}
                  onCreateReady={(openDialog) => {
                    openCreateColumnDialogRef.current = openDialog
                  }}
                  onSuggestReady={(openDialog) => {
                    openSuggestColumnsDialogRef.current = openDialog
                  }}
                />
              )}
              {activeTab === 'indexes' && (
                <IndexesSpreadsheet
                  table={selectedTable}
                  canWriteTables={!noCreateTablePermission}
                  filterMap={tableDetailFilterMap}
                  onCreateReady={(openDialog) => {
                    openCreateIndexDialogRef.current = openDialog
                  }}
                  onSuggestReady={(openDialog) => {
                    openSuggestIndexesDialogRef.current = openDialog
                  }}
                />
              )}
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

      {/* Create Database Dialog */}
      <CreateDatabase
        open={createDatabaseDialogOpen}
        onOpenChange={setCreateDatabaseDialogOpen}
        onCreate={(data) => createDatabaseMutation.mutate(data)}
        isLoading={createDatabaseMutation.isPending}
        backupsEnabled={organizationPlan?.backupsEnabled}
      />
      {/* Create Table Dialog */}
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
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

// Legacy export for backwards compatibility
export function DatabasesView() {
  return <View />
}

// Empty state when database has no tables
interface DatabaseEmptyStateProps {
  databaseId: string
}

export function DatabaseEmptyState({ databaseId }: DatabaseEmptyStateProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [tablesExpanded, setTablesExpanded] = useState(true)
  const { features } = useConsoleProfile()
  const [createTableDialogOpen, setCreateTableDialogOpen] = useState(false)

  const database = databases.find((db) => db.$id === databaseId)
  const createTableMutation = useMutation({
    mutationFn: (data: { tableId?: string; name: string }) =>
      createProjectTable(projectId!, databaseId!, data),
    onSuccess: async (table) => {
      toast.success(`${table.name} has been created`)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: {
          projectId: projectId!,
          databaseId: databaseId!,
          tableId: table.$id,
        },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to create table')
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  if (!database) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            Database not found
          </p>
          <Button variant="link" onClick={handleBack}>
            Back to databases
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="@container flex h-full">
      {/* Tables Sidebar */}
      <div className="hidden w-56 shrink-0 flex-col border-r border-border lg:flex">
        {/* Database Header with back button */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <button
            onClick={handleBack}
            className="flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <Database className="h-4 w-4 text-muted-foreground" />
          <span className="text-[13px] font-medium text-foreground">
            {database.name}
          </span>
        </div>

        {/* Tables List */}
        <div className="flex-1 overflow-y-auto p-2">
          {/* Tables Section Header */}
          <button
            onClick={() => setTablesExpanded(!tablesExpanded)}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            {tablesExpanded ? (
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            )}
            <Table2 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px] font-medium">Tables</span>
            <span className="text-[11px] text-muted-foreground">0</span>
          </button>

          {/* Tables List (collapsible) - Empty state */}
          {tablesExpanded && (
            <div className="ml-3 mt-0.5 border-l border-border pl-2">
              {/* Create Table Button */}
              <button
                onClick={() => setCreateTableDialogOpen(true)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5 shrink-0" />
                <span className="text-[13px]">Create table</span>
              </button>
            </div>
          )}

          {/* Security Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/security"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Security</span>
          </Link>

          {features.databaseInsights && (
            <>
              {/* Insights Link - Coming Soon */}
              <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground/50">
                <BarChart3 className="h-3.5 w-3.5 shrink-0" />
                <span className="flex-1 text-[13px]">Insights</span>
                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                  Soon
                </span>
              </span>
            </>
          )}
          {features.databaseBackups && (
            <Link
              to="/projects/$projectId/databases/$databaseId/backups"
              params={{ projectId, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
            >
              <Archive className="h-3.5 w-3.5 shrink-0" />
              <span className="text-[13px]">Backups</span>
            </Link>
          )}

          {/* Settings Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/settings"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Settings</span>
          </Link>
        </div>
      </div>

      {/* Main Content - Empty State */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Mobile back button */}
        <button
          onClick={handleBack}
          className="flex cursor-pointer items-center gap-2 border-b border-border px-4 py-2 text-[13px] text-muted-foreground transition-colors hover:text-foreground lg:hidden"
        >
          <ChevronLeft className="h-4 w-4" />
          Back to databases
        </button>

        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <EmptyState
              icon={Table2}
              title="No tables yet"
              description="Create your first table to get started"
              isEmpty={true}
              iconSize="md"
            />
            <Button
              onClick={() => setCreateTableDialogOpen(true)}
              className="mt-4"
            >
              <Plus className="mr-1.5 h-4 w-4" />
              Create table
            </Button>
          </div>
        </div>
      </div>
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
      />
    </div>
  )
}

// Database Overview - shows database details with tabs for tables, backups, settings
interface DatabaseOverviewProps {
  databaseId: string
  activeTab:
    | 'tables'
    | 'backups'
    | 'export-import'
    | 'security'
    | 'insights'
    | 'settings'
    | 'visualizer'
    | 'browser'
  /** When true, only the tab content is rendered (no header). Used when embedded in TableView. */
  contentOnly?: boolean
}

export function DatabaseOverview({
  databaseId,
  activeTab,
  contentOnly = false,
}: DatabaseOverviewProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const location = useLocation()
  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [databaseName, setDatabaseName] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedTables, setSelectedTables] = useState<Set<string>>(new Set())
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)
  const [exportDialogOpen, setExportDialogOpen] = useState(false)
  const [createTableDialogOpen, setCreateTableDialogOpen] = useState(false)

  // Fetch database
  const { database, isLoading: databaseLoading } = useProjectDatabase(
    projectId,
    databaseId,
  )

  // Update databaseName and enabled when database changes
  useEffect(() => {
    if (database) {
      setDatabaseName(database.name)
      setEnabled((database as Models.Database).enabled !== false) // Default to true if not specified
      setDeleteConfirmation('')
    }
  }, [database])

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total: tablesTotal,
    isLoading: tablesLoading,
    isFetching: tablesFetching,
  } = useProjectTables(
    projectId,
    databaseId,
    requestedPage - 1,
    pageSize,
    searchValue,
  )

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    tables: dbTables,
    total: displayedTablesTotal,
    isLoading: displayedTablesLoading,
  } = useProjectTables(
    projectId,
    databaseId,
    displayedPage - 1,
    pageSize,
    searchValue,
  )

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (!tablesFetching && requestedPage !== displayedPage && !tablesLoading) {
      setDisplayedPage(requestedPage)
    }
  }, [tablesFetching, tablesLoading, requestedPage, displayedPage])

  // Only show full loading when we have no data to display (initial load)
  const showTablesLoading = displayedTablesLoading && dbTables.length === 0

  // Fetch database schema for export
  const { data: databaseSchema, isLoading: schemaLoading } = useQuery({
    queryKey: ['database-schema', 'project', projectId, databaseId],
    queryFn: () => fetchDatabaseSchema(projectId, databaseId),
    enabled:
      !!projectId &&
      !!databaseId &&
      (exportDialogOpen || activeTab === 'tables'),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })

  // Export handlers
  const handleCopyJSON = async () => {
    if (!databaseSchema) {
      toast.error('Schema not loaded yet')
      return
    }
    try {
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      toast.success('Schema copied to clipboard')
    } catch {
      toast.error('Failed to copy schema')
    }
  }

  const handleCopyMarkdown = async () => {
    if (!databaseSchema) {
      toast.error('Schema not loaded yet')
      return
    }
    try {
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      toast.success('Schema copied to clipboard')
    } catch {
      toast.error('Failed to copy schema')
    }
  }

  const handleExportSVG = async () => {
    if (!databaseSchema) {
      toast.error('Schema not loaded yet')
      return
    }
    try {
      const svg = formatSchemaAsSVG(databaseSchema)
      const filename = `database-schema-${databaseId}.svg`
      downloadAsFile(svg, filename, 'image/svg+xml')
      toast.success('Schema exported as SVG')
    } catch {
      toast.error('Failed to export SVG')
    }
  }

  // Open in AI tools using deep links
  const handleOpenInChatGPT = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info('Loading schema...')
      return
    }
    try {
      const deepLink = getChatGPTDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success('Opening ChatGPT with schema context...')
    } catch {
      toast.error('Failed to open ChatGPT')
    }
  }

  const handleOpenInClaude = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info('Loading schema...')
      return
    }
    try {
      const deepLink = getClaudeDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success('Opening Claude with schema context...')
    } catch {
      toast.error('Failed to open Claude')
    }
  }

  const handleOpenInCursor = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info('Loading schema...')
      return
    }
    try {
      const deepLink = getCursorDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      // Try to open app protocol link, fallback to clipboard message
      try {
        window.location.href = deepLink
        toast.success('Opening Cursor with schema context...')
      } catch {
        toast.success('Schema copied to clipboard. Paste it in Cursor.')
      }
    } catch {
      toast.error('Failed to open Cursor')
    }
  }

  const handleOpenInLovable = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info('Loading schema...')
      return
    }
    try {
      const deepLink = getLovableDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      window.open(deepLink, '_blank')
      toast.success('Opening Lovable with schema context...')
    } catch {
      toast.error('Failed to open Lovable')
    }
  }

  // Mutation to update database name
  const updateDatabaseNameMutation = useMutation({
    mutationFn: async ({
      databaseId,
      name,
    }: {
      databaseId: string
      name: string
    }) => {
      // Validate and trim name (1-128 chars)
      if (!name || typeof name !== 'string') {
        throw new Error('Name must be a valid string')
      }
      const trimmedName = name.trim()
      if (trimmedName.length < 1) {
        throw new Error('Name must be at least 1 character')
      }
      if (trimmedName.length > 128) {
        throw new Error('Name must be no longer than 128 characters')
      }

      // Ensure we have a valid databaseId
      if (!databaseId || typeof databaseId !== 'string') {
        throw new Error('Database ID is required')
      }

      const projectSdk = sdk.forProject(projectId)
      // Use object parameter format: update({ databaseId, name })
      await projectSdk.tablesDB.update({
        databaseId,
        name: trimmedName,
      })
    },
    onSuccess: () => {
      // Invalidate database query to refetch with updated name
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success('Database name updated successfully')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update database name')
    },
  })

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !databaseId || !database)
        throw new Error('Project ID, Database ID, and Database are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.tablesDB.update({
        databaseId,
        name: database.name, // Required parameter
        enabled,
      })
    },
    onSuccess: () => {
      toast.success(`Database has been ${enabled ? 'enabled' : 'disabled'}`)
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (database) {
        setEnabled((database as Models.Database).enabled !== false)
      }
    },
  })

  const handleEnabledToggle = (checked: boolean) => {
    setEnabled(checked)
  }

  // Mutation to delete database
  const deleteDatabaseMutation = useMutation({
    mutationFn: async (databaseId: string) => {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.tablesDB.delete({ databaseId })
    },
    onSuccess: async () => {
      // Refetch databases list so the list view shows updated data (uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success('Database deleted successfully')

      // Close the dialog and reset confirmation
      setDeleteDialogOpen(false)
      setDeleteConfirmation('')

      // Navigate back to databases list
      navigate({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete database')
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const showDbSecuritySettings =
    canShowDatabaseSecuritySettings(access, features)
  const noCreateTablePermission = !canShowTableSecuritySettings(access, features)
  const showTableSecuritySettings = canShowTableSecuritySettings(access, features)

  // Redirect from backups/insights when feature disabled
  useEffect(() => {
    if (
      (activeTab === 'backups' && !features.databaseBackups) ||
      (activeTab === 'insights' && !features.databaseInsights)
    ) {
      navigate({
        to: '/projects/$projectId/databases/$databaseId',
        params: { projectId, databaseId },
        replace: true,
      })
    }
  }, [
    activeTab,
    features.databaseBackups,
    features.databaseInsights,
    projectId,
    databaseId,
    navigate,
  ])

  // Redirect from security/settings when user lacks permission
  useEffect(() => {
    if (
      !showDbSecuritySettings &&
      (activeTab === 'security' || activeTab === 'settings')
    ) {
      navigate({
        to: '/projects/$projectId/databases/$databaseId',
        params: { projectId, databaseId },
        replace: true,
      })
    }
  }, [
    showDbSecuritySettings,
    activeTab,
    projectId,
    databaseId,
    navigate,
  ])

  const databaseTabs: Tab[] = useMemo(
    () =>
      [
        {
          id: 'tables',
          label: 'Tables',
          to: '/projects/$projectId/databases/$databaseId/',
          params: { projectId, databaseId },
        },
        {
          id: 'visualizer',
          label: 'Visualizer',
          to: '/projects/$projectId/databases/$databaseId/visualizer',
          params: { projectId, databaseId },
        },
        ...(showDbSecuritySettings
          ? [
              {
                id: 'security' as const,
                label: 'Security',
                to: '/projects/$projectId/databases/$databaseId/security',
                params: { projectId, databaseId },
              },
            ]
          : []),
        ...(features.databaseInsights
          ? [
              {
                id: 'insights' as const,
                label: 'Insights',
                to: '/projects/$projectId/databases/$databaseId/insights',
                params: { projectId, databaseId },
              },
            ]
          : []),
        ...(features.databaseBackups
          ? [
              {
                id: 'backups' as const,
                label: 'Backups',
                to: '/projects/$projectId/databases/$databaseId/backups',
                params: { projectId, databaseId },
              },
            ]
          : []),
        {
          id: 'export-import',
          label: 'Export / Import',
          to: '/projects/$projectId/databases/$databaseId/export-import',
          params: { projectId, databaseId },
        },
        ...(showDbSecuritySettings
          ? [
              {
                id: 'settings' as const,
                label: 'Settings',
                to: '/projects/$projectId/databases/$databaseId/settings',
                params: { projectId, databaseId },
              },
            ]
          : []),
      ] as Tab[],
    [
      projectId,
      databaseId,
      features.databaseBackups,
      features.databaseInsights,
      showDbSecuritySettings,
    ],
  )

  // Tables are already paginated by the API
  const paginatedTables = dbTables

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedTables(new Set())
    setBulkDeleteDialogOpen(false)
  }, [location.pathname, projectId, databaseId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedTables(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation for tables
  // Create table mutation
  const createTableMutation = useMutation({
    mutationFn: (data: { tableId?: string; name: string }) =>
      createProjectTable(projectId!, databaseId!, data),
    onSuccess: async (table) => {
      toast.success(`${table.name} has been created`)
      // Refetch tables and wait for it to complete before navigating
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      // Navigate to the new table's rows tab
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: {
          projectId: projectId!,
          databaseId: databaseId!,
          tableId: table.$id,
        },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to create table')
    },
  })

  const bulkDeleteTablesMutation = useMutation({
    mutationFn: async (tableIds: string[]) => {
      if (!projectId || !databaseId) {
        throw new Error('Project ID and Database ID are required')
      }
      // Delete all tables in parallel
      await Promise.all(
        tableIds.map((tableId) =>
          deleteProjectTable(projectId, databaseId, tableId),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch tables list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(
        `Successfully deleted ${selectedTables.size} table${selectedTables.size > 1 ? 's' : ''}`,
      )
      setSelectedTables(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete tables')
    },
  })

  const handleBulkDeleteTables = () => {
    if (selectedTables.size === 0) return
    setBulkDeleteDialogOpen(true)
  }

  const confirmBulkDeleteTables = () => {
    if (selectedTables.size === 0) return
    bulkDeleteTablesMutation.mutate(Array.from(selectedTables))
  }

  const toggleTable = (tableId: string) => {
    const newSelected = new Set(selectedTables)
    if (newSelected.has(tableId)) {
      newSelected.delete(tableId)
    } else {
      newSelected.add(tableId)
    }
    setSelectedTables(newSelected)
  }

  const toggleAllTables = () => {
    if (selectedTables.size === paginatedTables.length) {
      setSelectedTables(new Set())
    } else {
      setSelectedTables(new Set(paginatedTables.map((t) => t.$id)))
    }
  }

  const handleTablesPageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedTables(new Set()) // Clear selection on page change
  }

  const handleTablesPageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedTables(new Set()) // Clear selection on page size change
  }

  if (databaseLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading database...</div>
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
          <Button variant="link" onClick={handleBack}>
            Back to databases
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div
      className="flex h-full flex-col min-h-0"
      data-content-only={contentOnly || undefined}
    >
      {!contentOnly && (
        <ServiceHeader
          title={
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 cursor-pointer p-0"
                onClick={handleBack}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <span>{database.name}</span>
            </div>
          }
          tabs={databaseTabs}
          activeTab={activeTab}
          searchPlaceholder={
            activeTab === 'tables' ? 'Search tables...' : undefined
          }
          searchValue={activeTab === 'tables' ? searchValue : ''}
          onSearchChange={
            activeTab === 'tables' ? handleSearchChange : undefined
          }
          createLabel={activeTab === 'tables' ? 'Create table' : undefined}
          createDisabled={
            activeTab === 'tables' ? noCreateTablePermission : false
          }
          createDisabledTooltip={
            activeTab === 'tables'
              ? "You don't have permission to perform this action."
              : undefined
          }
          onCreate={
            activeTab === 'tables'
              ? () => setCreateTableDialogOpen(true)
              : undefined
          }
          beforeCreateButtons={
            activeTab === 'tables' ? (
              <>
                {/* Copy dropdown */}
                <DropdownMenu>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                          <Copy className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">Copy schema</TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem onClick={handleCopyJSON}>
                      <FileJson className="h-4 w-4 mr-2" />
                      Copy as JSON
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleCopyMarkdown}>
                      <FileText className="h-4 w-4 mr-2" />
                      Copy as Markdown
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>

                {/* Export SVG button */}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground"
                      onClick={handleExportSVG}
                    >
                      <Download className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">Export as SVG</TooltipContent>
                </Tooltip>

                {/* Open in dropdown */}
                <DropdownMenu>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                          <ExternalLink className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">Open in...</TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem onClick={handleOpenInChatGPT}>
                      <img
                        src="/icons/chatgpt.svg"
                        alt="ChatGPT"
                        className={`h-4 w-4 mr-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      ChatGPT
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInClaude}>
                      <img
                        src="/icons/claude.svg"
                        alt="Claude"
                        className={`h-4 w-4 mr-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      Claude
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInCursor}>
                      <img
                        src="/icons/cursor-ai.svg"
                        alt="Cursor"
                        className={`h-4 w-4 mr-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      Cursor
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInLovable}>
                      <img
                        src="/icons/lovable.svg"
                        alt="Lovable"
                        className={`h-4 w-4 mr-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      Lovable
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </>
            ) : undefined
          }
          showFilters={false}
          fullWidthBorder
          contentAfterBorder={
            database && (database as Models.Database).enabled === false ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
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
                          to="/projects/$projectId/databases/$databaseId/settings"
                          params={{
                            projectId: projectId!,
                            databaseId: databaseId,
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
            ) : undefined
          }
        />
      )}

      <div className="flex-1 min-h-0 flex flex-col overflow-y-auto">
        {activeTab === 'tables' && (
          <div className="mx-auto w-full max-w-7xl px-4 pt-4 pb-4 sm:px-6 sm:pt-6 sm:pb-6">
            {showTablesLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">Loading tables...</div>
              </div>
            ) : paginatedTables.length > 0 ? (
              <>
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="w-[40px] px-4">
                          <Checkbox
                            checked={
                              paginatedTables.length > 0 &&
                              selectedTables.size === paginatedTables.length
                            }
                            onCheckedChange={toggleAllTables}
                          />
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Table
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                          Columns
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                          Rows
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                          Indexes
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {paginatedTables.map((table) => (
                        <TableRow
                          key={table.$id}
                          className={cn(
                            'cursor-pointer transition-colors border-b border-border/50',
                            selectedTables.has(table.$id)
                              ? 'bg-muted'
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
                              to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
                              params: {
                                projectId,
                                databaseId,
                                tableId: table.$id,
                              },
                            })
                          }}
                        >
                          <TableCell
                            onClick={(e) => e.stopPropagation()}
                            className="px-4 py-3"
                          >
                            <Checkbox
                              checked={selectedTables.has(table.$id)}
                              onCheckedChange={() => toggleTable(table.$id)}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <TableContextMenu
                              projectId={projectId}
                              databaseId={databaseId}
                              table={table}
                              showSecuritySettings={showTableSecuritySettings}
                              onCreateSimilar={async () => {
                                await queryClient.refetchQueries({
                                  queryKey: [
                                    'tables',
                                    'project',
                                    projectId,
                                    databaseId,
                                  ],
                                })
                              }}
                            >
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{
                                  projectId,
                                  databaseId,
                                  tableId: table.$id,
                                }}
                                className="block group"
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <Table2 className="h-4 w-4 text-muted-foreground/60 shrink-0" />
                                  <div className="flex-1 min-w-0">
                                    <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                      {table.name}
                                    </p>
                                    <div className="mt-0.5">
                                      <CopyableId id={table.$id} size="xs" />
                                    </div>
                                  </div>
                                  {table.enabled === false && (
                                    <Badge
                                      variant="error"
                                      className="text-[11px] font-medium border px-2 py-0.5 shrink-0"
                                    >
                                      Disabled
                                    </Badge>
                                  )}
                                </div>
                              </Link>
                            </TableContextMenu>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId,
                                tableId: table.$id,
                              }}
                              className="block text-right"
                            >
                              <span className="text-[12px] text-foreground font-mono">
                                {table.columns}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId,
                                tableId: table.$id,
                              }}
                              className="block text-right"
                            >
                              <span className="text-[12px] text-muted-foreground font-mono">
                                {formatNumber(table.rows)}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{
                                projectId,
                                databaseId,
                                tableId: table.$id,
                              }}
                              className="block text-right"
                            >
                              <span className="text-[12px] text-muted-foreground font-mono">
                                {table.indexes}
                              </span>
                            </Link>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
                <Pagination
                  currentPage={displayedPage}
                  totalItems={displayedTablesTotal ?? tablesTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handleTablesPageChange}
                  onPageSizeChange={handleTablesPageSizeChange}
                  itemLabel="tables"
                  className="py-2"
                />

                {/* Bulk Delete Action Bar */}
                {selectedTables.size > 0 && (
                  <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                    <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                      <Badge variant="secondary" className="h-6 px-2.5">
                        {selectedTables.size} table
                        {selectedTables.size > 1 ? 's' : ''} selected
                      </Badge>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setSelectedTables(new Set())}
                          className="h-8 text-xs"
                        >
                          Cancel
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={handleBulkDeleteTables}
                          disabled={bulkDeleteTablesMutation.isPending}
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
                  open={bulkDeleteDialogOpen}
                  onOpenChange={setBulkDeleteDialogOpen}
                >
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-left">
                      <DialogTitle>Delete Tables</DialogTitle>
                      <DialogDescription className="text-[13px] mt-2">
                        Are you sure you want to delete {selectedTables.size}{' '}
                        table{selectedTables.size > 1 ? 's' : ''}? This action
                        cannot be undone.
                      </DialogDescription>
                    </DialogHeader>

                    <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                      <Button
                        variant="outline"
                        onClick={() => setBulkDeleteDialogOpen(false)}
                        disabled={bulkDeleteTablesMutation.isPending}
                      >
                        Cancel
                      </Button>
                      <Button
                        variant="destructive"
                        onClick={confirmBulkDeleteTables}
                        disabled={bulkDeleteTablesMutation.isPending}
                      >
                        Delete
                      </Button>
                    </div>
                  </DialogContent>
                </Dialog>
              </>
            ) : searchValue ? (
              <EmptyState
                icon={Table2}
                isEmpty={false}
                hasFilters={true}
                variant="card"
              />
            ) : (
              <EmptyState
                icon={Table2}
                title="No tables yet"
                description="Create your first table to get started"
                isEmpty={true}
                variant="card"
              >
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <Table2 className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <p className="mb-1 text-[14px] font-medium text-foreground">
                    No tables yet
                  </p>
                  <p className="mb-4 text-[13px] text-muted-foreground">
                    Create your first table to get started
                  </p>
                  <Button
                    onClick={() => setCreateTableDialogOpen(true)}
                    className="gap-1.5"
                  >
                    <Plus className="h-4 w-4" />
                    Create table
                  </Button>
                </div>
              </EmptyState>
            )}
          </div>
        )}

        {activeTab === 'backups' && <BackupsView databaseId={databaseId} />}

        {activeTab === 'export-import' && (
          <ExportImportView databaseId={databaseId} />
        )}

        {activeTab === 'visualizer' && (
          <div className="flex-1 min-h-0">
            <SchemaVisualizer databaseId={databaseId} />
          </div>
        )}

        {activeTab === 'insights' && (
          <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
            <ComingSoonCurtain
              featureId="database-insights"
              message="Get powerful analytics and insights about your database performance and usage patterns."
            >
              <div className="space-y-6">
                {/* Placeholder content for coming soon feature */}
                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      Database Analytics
                    </h3>
                    <p className="text-[13px] text-muted-foreground mt-2">
                      View detailed metrics about your database performance
                    </p>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4">
                    <div className="h-64 bg-muted/30 rounded-lg flex items-center justify-center">
                      <BarChart3 className="h-16 w-16 text-muted-foreground/30" />
                    </div>
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        Query Performance
                      </h3>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-4">
                      <div className="h-48 bg-muted/30 rounded-lg" />
                    </div>
                  </div>

                  <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                    <div className="px-6 py-4">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        Usage Patterns
                      </h3>
                    </div>
                    <div className="border-t border-border" />
                    <div className="px-6 py-4">
                      <div className="h-48 bg-muted/30 rounded-lg" />
                    </div>
                  </div>
                </div>
              </div>
            </ComingSoonCurtain>
          </div>
        )}

        {activeTab === 'security' && (
          <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
            <div className="space-y-6">
              {/* Permissions */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Permissions
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    Permissions are configured at the table or row level. You
                    can select the permission model for each table in its
                    settings. When Row Level Security (RLS) is enabled, you can
                    also modify permissions per row when updating individual
                    rows.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'settings' && (
          <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
            <div className="space-y-6">
              {/* Update Database Name */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Name
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    Update your database's display name. This will be visible to
                    all organization members.
                  </p>
                  <Input
                    value={databaseName}
                    onChange={(e) => setDatabaseName(e.target.value)}
                    placeholder="Database name"
                    className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={
                      !database ||
                      databaseName === database.name ||
                      !databaseName.trim() ||
                      updateDatabaseNameMutation.isPending
                    }
                    onClick={() => {
                      if (
                        database &&
                        databaseName.trim() &&
                        databaseName !== database.name
                      ) {
                        updateDatabaseNameMutation.mutate({
                          databaseId: database.$id,
                          name: databaseName.trim(),
                        })
                      }
                    }}
                  >
                    Update
                  </Button>
                </div>
              </div>

              {/* Database Information */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {database.name}
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <Switch
                        id="toggle"
                        checked={enabled ?? false}
                        onCheckedChange={handleEnabledToggle}
                        disabled={updateEnabledMutation.isPending}
                      />
                      <Label
                        htmlFor="toggle"
                        className="text-[13px] text-foreground"
                      >
                        {enabled ? 'Enabled' : 'Disabled'}
                      </Label>
                    </div>
                  </div>
                  <div className="mt-4 space-y-1">
                    <p className="text-[13px] text-muted-foreground">
                      Database ID:{' '}
                      <span className="ml-1.5">
                        <CopyableId id={database.$id} size="sm" />
                      </span>
                    </p>
                    <p className="text-[13px] text-muted-foreground">
                      Created:{' '}
                      <DateTooltip
                        date={database.createdAt}
                        showFormattedDate
                        className="text-foreground"
                      />
                    </p>
                    <p className="text-[13px] text-muted-foreground">
                      Last updated:{' '}
                      <DateTooltip
                        date={database.updatedAt || database.createdAt}
                        showFormattedDate
                        className="text-foreground"
                      />
                    </p>
                  </div>
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={
                      enabled ===
                        ((database as Models.Database).enabled !== false) ||
                      updateEnabledMutation.isPending
                    }
                    onClick={() => {
                      if (
                        enabled !==
                        ((database as Models.Database).enabled !== false)
                      ) {
                        updateEnabledMutation.mutate(enabled)
                      }
                    }}
                  >
                    Update
                  </Button>
                </div>
              </div>

              {/* Overview */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Overview
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <div className="space-y-4">
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                          Database ID
                        </p>
                        <CopyableId id={database.$id} size="sm" />
                      </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                          Created
                        </p>
                        <DateTooltip
                          date={database.createdAt}
                          className="text-[13px] text-foreground"
                          showFormattedDate
                        />
                      </div>
                      <div>
                        <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                          Updated
                        </p>
                        <DateTooltip
                          date={database.updatedAt || database.createdAt}
                          className="text-[13px] text-foreground"
                          showFormattedDate
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Delete Database */}
              <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    Delete Database
                  </h3>
                </div>
                <div className="border-t border-destructive/20" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    Permanently delete this database and all its tables. This
                    action cannot be undone.
                  </p>

                  {/* Database Info Summary */}
                  {database && (
                    <div className="flex items-center gap-3 mt-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                        <Database className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-[14px] font-medium text-foreground truncate">
                          {database.name}
                        </p>
                        <p className="text-[12px] text-muted-foreground">
                          {tablesTotal} table{tablesTotal !== 1 ? 's' : ''}
                        </p>
                      </div>
                    </div>
                  )}
                </div>

                <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                  <Dialog
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                  >
                    <DialogTrigger asChild>
                      <Button
                        variant="destructive"
                        size="sm"
                        className="h-9 text-[13px]"
                      >
                        Delete database
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-left">
                        <DialogTitle>Delete Database</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Are you sure you want to delete{' '}
                          {database && (
                            <span className="font-medium text-foreground">
                              {database.name}
                            </span>
                          )}{' '}
                          and all its tables and data? This action cannot be
                          undone.
                        </DialogDescription>
                      </DialogHeader>
                      <div className="border-t border-border" />
                      <div className="px-6 pb-4 pt-0">
                        <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                          {database && (
                            <div className="flex items-center gap-3">
                              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                                <Database className="h-5 w-5 text-muted-foreground" />
                              </div>
                              <div>
                                <p className="text-[13px] font-medium text-foreground">
                                  {database.name}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                  {tablesTotal} table
                                  {tablesTotal !== 1 ? 's' : ''} will be deleted
                                </p>
                              </div>
                            </div>
                          )}
                        </div>

                        <label className="text-[13px] text-muted-foreground">
                          Type{' '}
                          {database && (
                            <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                              {database.name}
                            </span>
                          )}{' '}
                          to confirm
                        </label>
                        <Input
                          value={deleteConfirmation}
                          onChange={(e) =>
                            setDeleteConfirmation(e.target.value)
                          }
                          placeholder="Enter database name"
                          className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
                        />
                      </div>

                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => {
                            setDeleteDialogOpen(false)
                            setDeleteConfirmation('')
                          }}
                        >
                          Cancel
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                          disabled={
                            !database ||
                            deleteConfirmation !== database.name ||
                            deleteDatabaseMutation.isPending
                          }
                          onClick={() => {
                            if (
                              database &&
                              deleteConfirmation === database.name
                            ) {
                              deleteDatabaseMutation.mutate(database.$id)
                            }
                          }}
                        >
                          Delete
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Schema Export Dialog */}
      <SchemaExportDialog
        open={exportDialogOpen}
        onOpenChange={setExportDialogOpen}
        schema={databaseSchema || null}
        isLoading={schemaLoading}
      />

      {/* Create Table Dialog */}
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
      />
    </div>
  )
}

// Row type for the spreadsheet
interface RowData {
  $id: string
  /** Server-provided sequence (stable); fallback to computed rowNumber when absent */
  $sequence?: number
  rowNumber: number
  data: Record<string, string | number | boolean>
  $createdAt?: string
  $updatedAt?: string
  $permissions?: string[]
}

import { PointEditor, LineEditor, PolygonEditor } from './tables/spatial'

// Row Update Drawer Component
interface RowEditDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  row: RowData | null
  tableName: string
  focusedField?: string | null
  /** When set, drawer opens with this tab selected (e.g. 'data' for Update row, 'permissions' from context menu) */
  initialTab?: 'data' | 'permissions'
  columns?: unknown[]
  onSave: (
    rowId: string | null,
    data: Record<string, string | number | boolean | unknown[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => void
  isSaving?: boolean
}

function formatDateTimeLocalForInput(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function RowEditDrawer({
  open,
  onOpenChange,
  row,
  focusedField,
  initialTab,
  columns = [],
  onSave,
  isSaving = false,
}: RowEditDrawerProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const isCreateMode = !row

  const [formData, setFormData] = useState<
    Record<string, string | number | boolean | unknown[] | null>
  >({})
  const [customRowId, setCustomRowId] = useState<string | undefined>(undefined)
  const fieldRefs = useRef<
    Record<
      string,
      | HTMLInputElement
      | HTMLSelectElement
      | HTMLButtonElement
      | HTMLTextAreaElement
      | null
    >
  >({})
  const scrollContainerRef = useRef<HTMLDivElement | null>(null)
  const [newlyAddedItem, setNewlyAddedItem] = useState<{
    key: string
    index: number
  } | null>(null)
  const [linkCopied, setLinkCopied] = useState(false)
  const [activeTab, setActiveTab] = useState('data')
  const [rowPermissions, setRowPermissions] = useState<string[]>([])

  // When drawer opens with initialTab (e.g. from "Update permissions" context menu), switch to that tab
  useEffect(() => {
    if (open && initialTab) {
      setActiveTab(initialTab)
    }
  }, [open, initialTab])

  // Initialize row permissions from row data
  useEffect(() => {
    if (row) {
      setRowPermissions(row.$permissions || [])
    } else {
      // Reset permissions when drawer closes or in create mode
      setRowPermissions([])
    }
  }, [row])

  // Update form data when row changes - only when row actually changes
  useEffect(() => {
    if (row) {
      // Preserve null values explicitly - ensure null is not converted to undefined
      const initialData: Record<
        string,
        string | number | boolean | unknown[] | null
      > = {}
      Object.entries(row.data).forEach(([key, value]) => {
        // Explicitly preserve null values
        initialData[key] = value === null || value === undefined ? null : value
      })
      initialData['$createdAt'] = row.$createdAt ?? null
      initialData['$updatedAt'] = row.$updatedAt ?? null
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when editing existing row
      setCustomRowId(undefined)
    } else {
      // Initialize form data from columns when creating a new row
      const initialData: Record<
        string,
        string | number | boolean | unknown[] | null
      > = {}
      columns.forEach((col: unknown) => {
        const colKey =
          col.key || col.name || col.$id || col.attribute || col.attributeId
        if (colKey && !colKey.startsWith('$')) {
          // Initialize with default value or empty string
          if (col.default !== undefined && col.default !== null) {
            initialData[colKey] = col.default
          } else if (col.type === 'boolean') {
            initialData[colKey] = false
          } else if (col.array) {
            initialData[colKey] = []
          } else {
            initialData[colKey] = ''
          }
        }
      })
      initialData['$createdAt'] = new Date().toISOString()
      initialData['$updatedAt'] = new Date().toISOString()
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when creating new row
      setCustomRowId(undefined)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row?.$id, columns]) // Re-run when row ID changes or columns change

  // Switch to data tab when a field is focused (cell clicked)
  // Also set initial tab when drawer opens (unless parent passed initialTab e.g. "Update permissions")
  useEffect(() => {
    if (focusedField) {
      setActiveTab('data')
    } else if (open && !initialTab) {
      setActiveTab('data')
    }
  }, [focusedField, open, initialTab])

  // Focus the requested field when drawer opens
  useEffect(() => {
    if (!open || !focusedField) return
    const el = fieldRefs.current[focusedField]
    if (el) {
      // Defer twice to run after focus trap mounts children
      requestAnimationFrame(() => {
        requestAnimationFrame(() => el.focus())
      })
    }
  }, [open, focusedField])

  // Focus newly added array item
  useEffect(() => {
    if (!newlyAddedItem) return

    const itemKey = `${newlyAddedItem.key}-${newlyAddedItem.index}`
    const el = fieldRefs.current[itemKey] as HTMLTextAreaElement | null

    if (el) {
      // Defer to ensure the element is fully rendered
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          el.focus()
          // Move cursor to end
          el.setSelectionRange(el.value.length, el.value.length)
          setNewlyAddedItem(null) // Clear after focusing
        })
      })
    }
  }, [newlyAddedItem])

  // Handle drawer open/close
  const handleOpenChange = (newOpen: boolean) => {
    // Don't reset form data here - let the useEffect handle it based on row changes
    // This prevents overwriting user edits when drawer opens/closes
    onOpenChange(newOpen)
  }

  const handleFieldChange = (
    key: string,
    value: string | number | boolean | unknown[] | null,
  ) => {
    setFormData((prev) => ({ ...prev, [key]: value }))
  }

  const handleNullToggle = (key: string, isNull: boolean) => {
    if (isNull) {
      // Set to null explicitly
      setFormData((prev) => ({ ...prev, [key]: null }))
    } else {
      // Unset null - restore to empty string (user can then type)
      setFormData((prev) => ({ ...prev, [key]: '' }))
    }
  }

  // Get column info for a field
  const getColumnInfo = (key: string) => {
    return columns.find((col: unknown) => {
      const colKey =
        col.key || col.name || col.$id || col.attribute || col.attributeId
      return colKey === key
    })
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern =
      /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  const handleArrayItemChange = (
    key: string,
    index: number,
    value: string | number | boolean | null,
  ) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newArray = [...currentArray]
    newArray[index] = value
    handleFieldChange(key, newArray)
  }

  const handleAddArrayItem = (key: string) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newIndex = currentArray.length
    handleFieldChange(key, [...currentArray, ''])
    // Track the newly added item to focus it after render
    setNewlyAddedItem({ key, index: newIndex })
  }

  const handleRemoveArrayItem = (key: string, index: number) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newArray = currentArray.filter((_, i) => i !== index)
    handleFieldChange(key, newArray)
  }

  const handleSave = () => {
    // For create mode, pass customRowId if set, otherwise pass null to use auto-generated
    // For update mode, pass the existing row ID
    const idToSave = isCreateMode ? customRowId || null : row?.$id || null
    // Always pass permissions when updating (even if empty, to allow clearing permissions)
    // For create mode, only pass if permissions are set
    const permissionsToSave = isCreateMode
      ? rowPermissions.length > 0
        ? rowPermissions
        : undefined
      : rowPermissions // Always pass for updates, even if empty
    const now = new Date().toISOString()
    const payload = { ...formData }
    if (
      payload['$createdAt'] === null ||
      payload['$createdAt'] === undefined ||
      payload['$createdAt'] === ''
    ) {
      payload['$createdAt'] = now
    }
    if (
      payload['$updatedAt'] === null ||
      payload['$updatedAt'] === undefined ||
      payload['$updatedAt'] === ''
    ) {
      payload['$updatedAt'] = now
    }
    onSave(idToSave, payload, customRowId, permissionsToSave)
    // Don't close drawer here - wait for mutation to complete
  }

  const getFieldType = (
    key: string,
    value: string | number | boolean | unknown[] | null,
    columnInfo?: unknown,
  ): string => {
    if (key === '$createdAt' || key === '$updatedAt') return 'datetime'
    // Use column type from metadata if available
    if (columnInfo?.type) {
      return columnInfo.type
    }
    // Fallback to value-based inference
    if (Array.isArray(value)) return 'array'
    if (typeof value === 'boolean') return 'boolean'
    if (typeof value === 'number') return 'number'
    return 'string'
  }

  const getEnumOptions = (columnInfo?: unknown): string[] => {
    if (columnInfo?.elements && Array.isArray(columnInfo.elements)) {
      return columnInfo.elements
    }
    // Fallback for legacy support
    return []
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isCreateMode ? 'Create Row' : 'Update Row'}
      maxWidth="sm:max-w-2xl"
      headerActions={
        !isCreateMode ? (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 cursor-pointer"
                  onClick={() => {
                    const currentUrl = window.location.href
                    navigator.clipboard.writeText(currentUrl)
                    setLinkCopied(true)
                    setTimeout(() => setLinkCopied(false), 2000)
                  }}
                >
                  {linkCopied ? (
                    <Check className="h-4 w-4 text-emerald-500" />
                  ) : (
                    <Link2 className="h-4 w-4 text-muted-foreground" />
                  )}
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                <p>{linkCopied ? 'Link copied!' : 'Copy link'}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ) : undefined
      }
    >
      <>
        <div className="border-t border-border shrink-0" />

        <div className="flex flex-col flex-1 min-h-0">
          <div
            className="flex gap-0 overflow-x-auto border-b border-border px-6 pt-4"
            role="tablist"
          >
            <button
              role="tab"
              aria-selected={activeTab === 'data'}
              onClick={() => setActiveTab('data')}
              className={cn(
                'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors cursor-pointer',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                activeTab === 'data'
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground/80',
              )}
            >
              Data
              {activeTab === 'data' && (
                <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
              )}
            </button>
            <button
              role="tab"
              aria-selected={activeTab === 'permissions'}
              onClick={() => setActiveTab('permissions')}
              className={cn(
                'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors cursor-pointer',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                activeTab === 'permissions'
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground/80',
              )}
            >
              Permissions
              {activeTab === 'permissions' && (
                <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
              )}
            </button>
          </div>

          <div
            ref={scrollContainerRef}
            className="flex-1 overflow-y-auto min-h-0"
          >
            {activeTab === 'data' && (
              <div className="px-6 py-6">
                <div className="space-y-5">
                  {/* System fields (read-only) - only when updating a row */}
                  {!isCreateMode && row && (
                    <div className="space-y-3">
                      <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-3">
                        <div>
                          <Label className="text-[11px] text-muted-foreground">
                            $id
                          </Label>
                          <div className="mt-1">
                            <CopyableId id={row.$id} size="sm" />
                          </div>
                        </div>
                        <div>
                          <Label className="text-[11px] text-muted-foreground">
                            Row #
                          </Label>
                          <div className="mt-1">
                            <CopyableId
                              id={String(row.$sequence ?? row.rowNumber ?? '')}
                              size="sm"
                            />
                          </div>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* ID Input - Only shown in create mode */}
                  {isCreateMode && (
                    <div className="space-y-2">
                      <Label
                        className="text-[12px] font-medium text-foreground"
                        htmlFor="row-id"
                      >
                        Row ID
                      </Label>
                      <IdInput
                        id="row-id"
                        value={customRowId}
                        onChange={setCustomRowId}
                        maxLength={36}
                        disabled={isSaving}
                        placeholder="Leave blank to auto-generate"
                      />
                    </div>
                  )}

                  {/* Editable fields */}
                  <div className="space-y-3">
                    <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      Row Data
                    </h4>
                    <div className="space-y-4">
                      {(isCreateMode
                        ? [
                            '$createdAt',
                            '$updatedAt',
                            ...Object.keys(formData).filter(
                              (k) => k !== '$createdAt' && k !== '$updatedAt',
                            ),
                          ]
                        : ['$createdAt', '$updatedAt', ...Object.keys(row.data)]
                      ).map((key) => {
                        const value = isCreateMode
                          ? formData[key]
                          : key === '$createdAt' || key === '$updatedAt'
                            ? (row as RowData)[key as keyof RowData]
                            : row.data[key]
                        const columnInfo = getColumnInfo(key)
                        const fieldType = getFieldType(
                          key,
                          value as string | number | boolean | unknown[] | null,
                          columnInfo,
                        )
                        // Use formData if it exists, otherwise fall back to original value
                        // But check if key exists in formData to distinguish between undefined and null
                        const currentValue =
                          key in formData ? formData[key] : value
                        const shouldFocus = focusedField === key
                        // Check multiple possible properties for required status
                        const isRequired =
                          columnInfo?.required === true ||
                          columnInfo?.required === 'true' ||
                          columnInfo?.isRequired === true ||
                          columnInfo?.isRequired === 'true' ||
                          columnInfo?.nullable === false ||
                          columnInfo?.nullable === 'false'

                        const arrayLength =
                          fieldType === 'array'
                            ? ((currentValue as unknown[]) || []).length
                            : 0
                        const displayLabel =
                          fieldType === 'array' && arrayLength > 0
                            ? `${key} (${arrayLength})`
                            : key

                        return (
                          <div key={key} className="space-y-1.5">
                            <Label
                              htmlFor={key}
                              className="text-[12px] font-medium text-foreground flex items-center gap-1.5"
                            >
                              {displayLabel}
                              {isRequired && (
                                <span
                                  className="text-destructive text-[12px] font-semibold ml-0.5"
                                  aria-label="Required field"
                                >
                                  *
                                </span>
                              )}
                            </Label>

                            {fieldType === 'boolean' ? (
                              <div className="flex items-center gap-2">
                                <Switch
                                  id={key}
                                  checked={currentValue as boolean}
                                  onCheckedChange={(checked) =>
                                    handleFieldChange(key, checked)
                                  }
                                />
                                <span className="text-[12px] text-muted-foreground">
                                  {currentValue ? 'True' : 'False'}
                                </span>
                              </div>
                            ) : fieldType === 'enum' ? (
                              <Select
                                value={currentValue ? String(currentValue) : ''}
                                onValueChange={(val) =>
                                  handleFieldChange(
                                    key,
                                    val === 'null' ? null : val,
                                  )
                                }
                              >
                                <SelectTrigger
                                  className="h-9 text-[13px]"
                                  ref={(el) => {
                                    fieldRefs.current[key] = el
                                  }}
                                  autoFocus={shouldFocus}
                                >
                                  <SelectValue
                                    placeholder={
                                      isRequired ? undefined : 'NULL'
                                    }
                                  />
                                </SelectTrigger>
                                <SelectContent>
                                  {!isRequired && (
                                    <SelectItem value="null">NULL</SelectItem>
                                  )}
                                  {getEnumOptions(columnInfo).map((option) => (
                                    <SelectItem key={option} value={option}>
                                      {option}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            ) : fieldType === 'integer' ||
                              fieldType === 'double' ||
                              fieldType === 'number' ? (
                              <div className="space-y-1.5">
                                <Input
                                  id={key}
                                  type="number"
                                  value={
                                    currentValue !== null &&
                                    currentValue !== undefined
                                      ? String(currentValue)
                                      : ''
                                  }
                                  ref={(el) => {
                                    fieldRefs.current[key] = el
                                  }}
                                  autoFocus={shouldFocus}
                                  onChange={(e) => {
                                    const val =
                                      e.target.value === ''
                                        ? null
                                        : fieldType === 'integer'
                                          ? parseInt(e.target.value) || null
                                          : parseFloat(e.target.value) || null
                                    handleFieldChange(key, val)
                                  }}
                                  min={columnInfo?.min}
                                  max={columnInfo?.max}
                                  step={fieldType === 'double' ? 0.1 : 1}
                                  placeholder={isRequired ? undefined : 'NULL'}
                                  className="h-9 text-[13px]"
                                />
                                {!isRequired && currentValue === null && (
                                  <p className="text-[11px] text-muted-foreground">
                                    NULL
                                  </p>
                                )}
                              </div>
                            ) : fieldType === 'array' ? (
                              <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-2">
                                {((currentValue as unknown[]) || []).length >
                                0 ? (
                                  <div className="space-y-2">
                                    {((currentValue as unknown[]) || []).map(
                                      (item, index) => {
                                        const columnInfo = getColumnInfo(key)
                                        const size = columnInfo?.size || null
                                        const colType =
                                          columnInfo?.type || 'string'
                                        // Check multiple possible properties for required status
                                        const isRequired =
                                          columnInfo?.required === true ||
                                          columnInfo?.required === 'true' ||
                                          columnInfo?.isRequired === true ||
                                          columnInfo?.isRequired === 'true' ||
                                          columnInfo?.nullable === false ||
                                          columnInfo?.nullable === 'false'
                                        const isNull = item === null
                                        const stringValue = isNull
                                          ? ''
                                          : String(item || '')
                                        const charCount = stringValue.length
                                        const hasLimit =
                                          (colType === 'string' ||
                                            colType === 'varchar') &&
                                          size !== null &&
                                          size > 0
                                        const isRTLContent = isRTL(stringValue)
                                        const showNullCheckbox = !isRequired

                                        return (
                                          <div
                                            key={index}
                                            className="flex items-start gap-2 rounded-md border border-border bg-background px-2 py-1.5"
                                          >
                                            <div className="relative flex-1">
                                              <Textarea
                                                value={stringValue}
                                                onChange={(e) => {
                                                  e.stopPropagation()
                                                  const newValue =
                                                    e.target.value
                                                  // Don't auto-convert empty to null - only checkbox sets null
                                                  handleArrayItemChange(
                                                    key,
                                                    index,
                                                    newValue,
                                                  )
                                                }}
                                                onFocus={() => {
                                                  // Prevent browser from auto-scrolling focused element into view
                                                  const scrollContainer =
                                                    scrollContainerRef.current
                                                  if (scrollContainer) {
                                                    const scrollTop =
                                                      scrollContainer.scrollTop
                                                    const scrollLeft =
                                                      scrollContainer.scrollLeft

                                                    // Temporarily prevent scroll
                                                    requestAnimationFrame(
                                                      () => {
                                                        scrollContainer.scrollTop =
                                                          scrollTop
                                                        scrollContainer.scrollLeft =
                                                          scrollLeft
                                                      },
                                                    )
                                                  }
                                                }}
                                                ref={(el) => {
                                                  if (el) {
                                                    // Register ref for this specific array item
                                                    const itemKey = `${key}-${index}`
                                                    fieldRefs.current[itemKey] =
                                                      el

                                                    // Also register first textarea for auto-focus on drawer open
                                                    if (index === 0) {
                                                      fieldRefs.current[key] =
                                                        el
                                                    }
                                                  } else {
                                                    // Clear ref when unmounted
                                                    const itemKey = `${key}-${index}`
                                                    delete fieldRefs.current[
                                                      itemKey
                                                    ]
                                                    if (index === 0) {
                                                      delete fieldRefs.current[
                                                        key
                                                      ]
                                                    }
                                                  }
                                                }}
                                                autoFocus={
                                                  shouldFocus && index === 0
                                                }
                                                disabled={isNull}
                                                dir={
                                                  isRTLContent ? 'rtl' : 'ltr'
                                                }
                                                maxLength={
                                                  hasLimit ? size : undefined
                                                }
                                                className={cn(
                                                  'min-h-[32px] max-h-[600px] text-[13px] flex-1 border-0 bg-transparent px-0 py-1.5 resize-none focus-visible:ring-0 focus-visible:ring-offset-0',
                                                  isNull &&
                                                    'opacity-50 cursor-not-allowed',
                                                  showNullCheckbox
                                                    ? 'pb-7'
                                                    : 'pb-1',
                                                )}
                                                placeholder={`Item ${index + 1}`}
                                                rows={1}
                                              />
                                              <div className="absolute bottom-1 right-1 flex items-center gap-1.5 pointer-events-none">
                                                {hasLimit && (
                                                  <span
                                                    className={cn(
                                                      'text-[10px] px-1 py-0.5 rounded pointer-events-auto whitespace-nowrap',
                                                      charCount > size
                                                        ? 'text-destructive bg-destructive/10'
                                                        : 'text-muted-foreground bg-muted/80',
                                                    )}
                                                  >
                                                    {charCount}/{size}
                                                  </span>
                                                )}
                                                {showNullCheckbox && (
                                                  <div className="pointer-events-auto flex items-center gap-1">
                                                    <Checkbox
                                                      id={`${key}-${index}-null`}
                                                      checked={isNull}
                                                      onCheckedChange={(
                                                        checked,
                                                      ) => {
                                                        const newArray = [
                                                          ...((currentValue as unknown[]) ||
                                                            []),
                                                        ]
                                                        newArray[index] =
                                                          checked ? null : ''
                                                        handleFieldChange(
                                                          key,
                                                          newArray,
                                                        )
                                                      }}
                                                      onClick={(e) =>
                                                        e.stopPropagation()
                                                      }
                                                      className="h-3.5 w-3.5"
                                                      disabled={false}
                                                    />
                                                    <label
                                                      htmlFor={`${key}-${index}-null`}
                                                      className="text-[10px] text-muted-foreground cursor-pointer select-none"
                                                    >
                                                      Null
                                                    </label>
                                                  </div>
                                                )}
                                              </div>
                                            </div>
                                            <Button
                                              type="button"
                                              variant="ghost"
                                              size="icon"
                                              className="h-7 w-7 shrink-0 text-muted-foreground hover:text-destructive mt-0.5"
                                              onClick={() =>
                                                handleRemoveArrayItem(
                                                  key,
                                                  index,
                                                )
                                              }
                                            >
                                              <X className="h-3.5 w-3.5" />
                                            </Button>
                                          </div>
                                        )
                                      },
                                    )}
                                  </div>
                                ) : (
                                  <p className="text-[12px] text-muted-foreground py-2 text-center">
                                    No items. Click the button below to add one.
                                  </p>
                                )}
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleAddArrayItem(key)}
                                  className="h-8 w-full text-[12px] text-muted-foreground hover:text-foreground"
                                >
                                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                                  Add item
                                </Button>
                              </div>
                            ) : fieldType === 'datetime' ? (
                              <Input
                                id={key}
                                type="datetime-local"
                                value={
                                  currentValue
                                    ? formatDateTimeLocalForInput(
                                        new Date(currentValue as string),
                                      )
                                    : ''
                                }
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value
                                    ? new Date(e.target.value).toISOString()
                                    : null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'email' ? (
                              <Input
                                id={key}
                                type="email"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'url' ? (
                              <Input
                                id={key}
                                type="url"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'ip' ? (
                              <Input
                                id={key}
                                type="text"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'relationship' ? (
                              <div className="rounded-lg border border-border bg-muted/30 p-3">
                                <p className="text-[12px] text-muted-foreground">
                                  Relationship columns are managed through the
                                  relationship system. Edit related rows
                                  directly.
                                </p>
                              </div>
                            ) : fieldType === 'point' ? (
                              <PointEditor
                                value={currentValue as [number, number] | null}
                                onChange={(val: [number, number] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : fieldType === 'linestring' ? (
                              <LineEditor
                                value={currentValue as number[][] | null}
                                onChange={(val: number[][] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : fieldType === 'polygon' ? (
                              <PolygonEditor
                                value={currentValue as number[][][] | null}
                                onChange={(val: number[][][] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : (
                              (() => {
                                const size = columnInfo?.size || null
                                // Explicitly check for null - handle both null and undefined
                                const isNull =
                                  currentValue === null ||
                                  currentValue === undefined
                                const stringValue = isNull
                                  ? ''
                                  : String(currentValue || '')
                                const charCount = stringValue.length
                                // Only string and varchar have maxlength in UI
                                const hasLimit =
                                  (fieldType === 'string' ||
                                    fieldType === 'varchar') &&
                                  size !== null &&
                                  size > 0
                                const isRTLContent = isRTL(stringValue)
                                const showNullCheckbox = !isRequired
                                const useTextarea =
                                  (size && size >= 50) ||
                                  fieldType === 'text' ||
                                  fieldType === 'mediumtext' ||
                                  fieldType === 'longtext'
                                const needsCounterSpace =
                                  hasLimit || showNullCheckbox
                                const counterPadding = needsCounterSpace
                                  ? isRTLContent
                                    ? 'pl-28'
                                    : 'pr-28'
                                  : ''

                                return (
                                  <div className="space-y-1.5">
                                    <div className="relative">
                                      {useTextarea ? (
                                        <Textarea
                                          id={key}
                                          value={stringValue}
                                          ref={(el) => {
                                            if (el) {
                                              fieldRefs.current[key] = el
                                            }
                                          }}
                                          autoFocus={shouldFocus}
                                          onChange={(e) => {
                                            e.stopPropagation()
                                            const newValue = e.target.value
                                            handleFieldChange(key, newValue)
                                          }}
                                          onFocus={() => {
                                            const scrollContainer =
                                              scrollContainerRef.current
                                            if (scrollContainer) {
                                              const scrollTop =
                                                scrollContainer.scrollTop
                                              const scrollLeft =
                                                scrollContainer.scrollLeft
                                              requestAnimationFrame(() => {
                                                scrollContainer.scrollTop =
                                                  scrollTop
                                                scrollContainer.scrollLeft =
                                                  scrollLeft
                                              })
                                            }
                                          }}
                                          disabled={isNull}
                                          dir={isRTLContent ? 'rtl' : 'ltr'}
                                          maxLength={
                                            hasLimit ? size : undefined
                                          }
                                          className={cn(
                                            'min-h-[36px] max-h-[600px] text-[13px] resize-none',
                                            isNull &&
                                              'opacity-50 cursor-not-allowed',
                                            showNullCheckbox ? 'pb-8' : 'pb-2',
                                            counterPadding,
                                          )}
                                          rows={1}
                                        />
                                      ) : (
                                        <Input
                                          id={key}
                                          type="text"
                                          value={stringValue}
                                          ref={(el) => {
                                            if (el) {
                                              fieldRefs.current[key] = el
                                            }
                                          }}
                                          autoFocus={shouldFocus}
                                          onChange={(e) => {
                                            e.stopPropagation()
                                            const newValue = e.target.value
                                            handleFieldChange(key, newValue)
                                          }}
                                          disabled={isNull}
                                          dir={isRTLContent ? 'rtl' : 'ltr'}
                                          maxLength={
                                            hasLimit ? size : undefined
                                          }
                                          placeholder={
                                            isRequired ? undefined : 'NULL'
                                          }
                                          className={cn(
                                            'h-9 text-[13px]',
                                            isNull &&
                                              'opacity-50 cursor-not-allowed',
                                            counterPadding,
                                          )}
                                        />
                                      )}
                                      <div
                                        className={cn(
                                          'absolute flex items-center gap-2 pointer-events-none',
                                          useTextarea
                                            ? isRTLContent
                                              ? 'bottom-2 left-2'
                                              : 'bottom-2 right-2'
                                            : isRTLContent
                                              ? 'top-1/2 -translate-y-1/2 left-2'
                                              : 'top-1/2 -translate-y-1/2 right-2',
                                        )}
                                      >
                                        {hasLimit && (
                                          <span
                                            className={cn(
                                              'text-[11px] px-1.5 py-0.5 rounded pointer-events-auto whitespace-nowrap',
                                              charCount > size
                                                ? 'text-destructive bg-destructive/10'
                                                : 'text-muted-foreground bg-muted/80',
                                            )}
                                          >
                                            {charCount}/{size}
                                          </span>
                                        )}
                                        {showNullCheckbox && (
                                          <div className="pointer-events-auto flex items-center gap-1.5">
                                            <Checkbox
                                              id={`${key}-null`}
                                              checked={isNull}
                                              onCheckedChange={(checked) => {
                                                handleNullToggle(
                                                  key,
                                                  checked as boolean,
                                                )
                                              }}
                                              onClick={(e) =>
                                                e.stopPropagation()
                                              }
                                              className="h-4 w-4"
                                              disabled={false}
                                            />
                                            <label
                                              htmlFor={`${key}-null`}
                                              className="text-[11px] text-muted-foreground cursor-pointer select-none"
                                            >
                                              Null
                                            </label>
                                          </div>
                                        )}
                                      </div>
                                    </div>
                                  </div>
                                )
                              })()
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {activeTab === 'permissions' && (
              <div className="px-6 py-6">
                <div className="space-y-5">
                  <div className="space-y-3">
                    <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      Permissions
                    </h4>
                    <div className="rounded-lg border border-border bg-muted/30 p-4">
                      <p className="text-[13px] text-muted-foreground">
                        Configure row-level access permissions to control who
                        can read, write, and delete this row.
                      </p>
                    </div>
                  </div>
                  <PermissionsEditor
                    permissions={rowPermissions}
                    onPermissionsChange={setRowPermissions}
                    withCreate={false}
                    projectId={projectId}
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Footer with actions */}
        <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
          <Button onClick={handleSave} disabled={isSaving}>
            {isCreateMode ? 'Create Row' : 'Update'}
          </Button>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSaving}
          >
            Cancel
          </Button>
        </div>
      </>
    </BaseDrawer>
  )
}

// Spreadsheet-like view for Rows
interface SpreadsheetProps {
  table: Collection
  onRefetchReady?: (refetch: () => Promise<unknown>) => void
  onCreateRowReady?: (openCreateDrawer: () => void) => void
  onCreateColumnReady?: (() => void) | null
  onCreateReady?: (openDialog: () => void) => void
  onSuggestReady?: (openDialog: () => void) => void
  onRowsCountChange?: (count: number) => void
  /** When false, create row/column and suggest actions are disabled (e.g. read-only roles) */
  canWriteRows?: boolean
  canWriteTables?: boolean
  /** URL-driven rows list (when set, search/page/limit/filters come from URL) */
  rowsUrlSearch?: string
  rowsUrlPage?: number
  rowsUrlLimit?: number
  rowsFilterQueries?: string[]
  rowsFilterQueryString?: string
  onNavigateToRowsList?: (params: {
    search?: string
    query?: string
    page?: number
    limit?: number
  }) => void
  /** When provided (e.g. from table view header), used for client-side filtering instead of URL */
  filterMap?: Map<CompactFilterKey, string>
}

function RowsSpreadsheet({
  table,
  onRefetchReady,
  onCreateRowReady,
  onCreateColumnReady,
  onRowsCountChange,
  canWriteRows = true,
  canWriteTables = true,
  rowsUrlSearch,
  rowsUrlPage = 1,
  rowsUrlLimit = ROWS_DEFAULT_PAGE_SIZE,
  rowsFilterQueries,
  rowsFilterQueryString,
  onNavigateToRowsList,
}: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const urlDriven = onNavigateToRowsList != null

  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set())
  const [requestedPage, setRequestedPage] = useState(urlDriven ? rowsUrlPage : 1)
  const [displayedPage, setDisplayedPage] = useState(urlDriven ? rowsUrlPage : 1)
  const [displayedSearch, setDisplayedSearch] = useState<string>(
    urlDriven ? (rowsUrlSearch ?? '') : '',
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState<string>(urlDriven ? (rowsFilterQueryString ?? '') : '')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const hasInitedDisplayedRef = useRef(false)
  const [displayedSortBy, setDisplayedSortBy] = useState<string>('$createdAt')
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    'desc',
  )
  const [pageSize, setPageSize] = useState(urlDriven ? rowsUrlLimit : ROWS_DEFAULT_PAGE_SIZE)
  const [sortBy, setSortBy] = useState<string>('$createdAt')
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc')
  const [editDrawerOpen, setEditDrawerOpen] = useState(false)
  const [drawerInitialTab, setDrawerInitialTab] = useState<
    'data' | 'permissions' | null
  >(null)
  const [selectedRowForEdit, setSelectedRowForEdit] = useState<RowData | null>(
    null,
  )
  const [focusedField, setFocusedField] = useState<string | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [sampleDataModalOpen, setSampleDataModalOpen] = useState(false)
  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<unknown>(null)
  const [contextCellColumnKey, setContextCellColumnKey] = useState<
    string | null
  >(null)
  const openCreateRowFnRef = useRef<(() => void) | null>(null)
  const openCreateColumnFnRef = useRef<(() => void) | null>(null)

  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()

  useEffect(() => {
    if (urlDriven && rowsUrlPage != null && rowsUrlLimit != null) {
      setRequestedPage((p) => (p === rowsUrlPage ? p : rowsUrlPage))
      setPageSize((s) => (s === rowsUrlLimit ? s : rowsUrlLimit))
    }
  }, [urlDriven, rowsUrlPage, rowsUrlLimit])

  useEffect(() => {
    if (!urlDriven || rowsUrlPage == null || rowsUrlLimit == null) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(rowsUrlPage)
      setDisplayedSearch(rowsUrlSearch ?? '')
      setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
      hasInitedDisplayedRef.current = true
    }
  }, [urlDriven, rowsUrlPage, rowsUrlLimit, rowsUrlSearch, rowsFilterQueryString])

  useEffect(() => {
    setSelectedRows(new Set())
    setDeleteDialogOpen(false)
    if (!urlDriven) {
      setRequestedPage(1)
      setDisplayedPage(1)
    }
    setDisplayedSortBy('$createdAt')
    setDisplayedSortOrder('desc')
    setSortBy('$createdAt')
    setSortOrder('desc')
  }, [location.pathname, projectId, databaseId, tableId, urlDriven])

  const effectiveSearch = urlDriven ? (rowsUrlSearch ?? '') : ''
  const effectivePageSize = urlDriven ? rowsUrlLimit : pageSize
  const effectiveRequestedPage = urlDriven ? requestedPage : requestedPage
  const effectiveDisplayedPage = urlDriven ? displayedPage : displayedPage
  const effectiveDisplayedSearch = urlDriven ? (displayedSearch ?? '') : ''
  const effectiveFilterQueries = urlDriven ? rowsFilterQueries : undefined
  const effectiveDisplayedFilterQueries = urlDriven
    ? displayedFilterQueries
    : undefined

  const {
    total: rowsTotal,
    isLoading: rowsLoading,
    refetch,
    isFetching: rowsFetching,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    effectiveRequestedPage - 1,
    effectivePageSize,
    effectiveSearch,
    sortOrder,
    sortBy,
    effectiveFilterQueries,
  )

  const {
    rows: apiRows,
    total: displayedRowsTotal,
    isLoading: displayedRowsLoading,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    effectiveDisplayedPage - 1,
    effectivePageSize,
    effectiveDisplayedSearch,
    displayedSortOrder,
    displayedSortBy,
    effectiveDisplayedFilterQueries,
  )

  useEffect(() => {
    if (!urlDriven || rowsFetching || rowsLoading) return
    const urlSearchMatch = (rowsUrlSearch ?? '') === (displayedSearch ?? '')
    const filterMatch =
      (rowsFilterQueryString ?? '') === (displayedFilterQueryString ?? '')
    const match =
      requestedPage === displayedPage &&
      sortBy === displayedSortBy &&
      sortOrder === displayedSortOrder &&
      urlSearchMatch &&
      filterMatch
    if (!match) {
      setDisplayedPage(requestedPage)
      setDisplayedSortBy(sortBy)
      setDisplayedSortOrder(sortOrder)
      setDisplayedSearch(rowsUrlSearch ?? '')
      setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
    }
  }, [
    urlDriven,
    rowsFetching,
    rowsLoading,
    requestedPage,
    displayedPage,
    sortBy,
    sortOrder,
    displayedSortBy,
    displayedSortOrder,
    rowsUrlSearch,
    displayedSearch,
    rowsFilterQueryString,
    displayedFilterQueryString,
  ])

  // Only show full loading when we have no data to display (initial load)
  const showRowsLoading = displayedRowsLoading && apiRows.length === 0

  const currentPageIndexed = displayedPage - 1

  // Notify parent of row count changes (only when count actually changes)
  const prevRowsTotalRef = useRef<number | null>(null)
  useEffect(() => {
    const total = displayedRowsTotal ?? rowsTotal
    if (onRowsCountChange && prevRowsTotalRef.current !== total) {
      prevRowsTotalRef.current = total
      onRowsCountChange(total)
    }
  }, [displayedRowsTotal, rowsTotal, onRowsCountChange])

  // Expose refetch function to parent component
  useEffect(() => {
    if (onRefetchReady) {
      onRefetchReady(refetch)
    }
  }, [refetch, onRefetchReady])

  // Expose create drawer open function to parent component
  useEffect(() => {
    if (onCreateRowReady) {
      const openFn = () => {
        setSelectedRowForEdit(null)
        setFocusedField(null)
        setDrawerInitialTab(null)
        setEditDrawerOpen(true)
      }
      onCreateRowReady(openFn)
      openCreateRowFnRef.current = openFn
    }
  }, [onCreateRowReady])

  // Store create column function from parent
  useEffect(() => {
    if (onCreateColumnReady) {
      openCreateColumnFnRef.current = onCreateColumnReady
    }
  }, [onCreateColumnReady])

  // Create column mutation for empty state
  const createColumnMutationForEmptyState = useMutation({
    mutationFn: async (data: ColumnFormData) => {
      return await createProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        data,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setColumnDialogOpen(false)
      setSelectedColumn(null)
      toast.success('Column created successfully')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create column')
    },
  })

  const handleColumnSubmit = async (data: ColumnFormData) => {
    await createColumnMutationForEmptyState.mutateAsync(data)
  }

  const handleCreateColumn = () => {
    // Always use the local column dialog in the rows view
    setSelectedColumn(null)
    setColumnDialogOpen(true)
  }

  // Fetch columns from the project SDK
  const { columns: apiColumns, isLoading: columnsLoading } =
    useProjectTableColumns(projectId, databaseId, tableId)

  // Fetch tables for relationship columns (needed for column creation)
  const { tables: availableTablesForColumns } = useTablesForColumns(
    projectId,
    databaseId,
    0,
    100,
    undefined,
  )

  // Check if table has relationship columns
  const hasRelationshipColumns = apiColumns.some(
    (col: unknown) => col.type === 'relationship',
  )

  // Map API rows to RowData format
  const rows: RowData[] = apiRows.map((row: unknown, index: number) => {
    // Extract data from row, excluding system fields
    const data: Record<string, string | number | boolean> = {}
    Object.keys(row).forEach((key) => {
      if (!key.startsWith('$')) {
        data[key] = row[key]
      }
    })

    return {
      $id: row.$id,
      $sequence: row.$sequence,
      rowNumber:
        (displayedRowsTotal ?? rowsTotal) -
        (currentPageIndexed * effectivePageSize + index),
      data,
      $createdAt: row.$createdAt,
      $updatedAt: row.$updatedAt,
      $permissions: row.$permissions || [],
    }
  })

  // Get column names from API columns or from first row
  const columns =
    apiColumns.length > 0
      ? apiColumns.map((col: unknown) => col.key || col.name || col.$id)
      : rows[0]
        ? Object.keys(rows[0].data)
        : []

  const toggleRow = (id: string) => {
    const newSelected = new Set(selectedRows)
    if (newSelected.has(id)) {
      newSelected.delete(id)
    } else {
      newSelected.add(id)
    }
    setSelectedRows(newSelected)
  }

  const toggleAll = () => {
    // Rows are already paginated by the API
    const paginatedRows = rows
    if (selectedRows.size === paginatedRows.length) {
      setSelectedRows(new Set())
    } else {
      setSelectedRows(new Set(paginatedRows.map((r) => r.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedRows(new Set())
    if (urlDriven && onNavigateToRowsList) {
      onNavigateToRowsList({ page })
    }
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedRows(new Set())
    if (urlDriven && onNavigateToRowsList) {
      onNavigateToRowsList({ page: 1, limit: newPageSize })
    }
  }

  const handleSortColumn = (columnKey: string) => {
    if (sortBy === columnKey) {
      // 3-click cycle: asc → desc → reset to default
      if (sortOrder === 'asc') {
        setSortOrder('desc')
      } else {
        // sortOrder === 'desc': reset to default (or toggle if $createdAt)
        if (columnKey === '$createdAt') {
          setSortOrder('asc')
        } else {
          setSortBy('$createdAt')
          setSortOrder('desc')
        }
      }
    } else {
      setSortBy(columnKey)
      setSortOrder('asc')
    }
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedRows(new Set())
  }

  const handleRowClick = (row: RowData) => {
    setSelectedRowForEdit(row)
    setFocusedField(null)
    setDrawerInitialTab('data')
    setEditDrawerOpen(true)
  }

  const handleOpenPermissionsRow = (row: RowData) => {
    setSelectedRowForEdit(row)
    setFocusedField(null)
    setDrawerInitialTab('permissions')
    setEditDrawerOpen(true)
  }

  const handleCellClick = (row: RowData, key: string) => {
    setSelectedRowForEdit(row)
    setFocusedField(key)
    setDrawerInitialTab(null)
    setEditDrawerOpen(true)
  }

  // Open row drawer when URL has #row-<id> or #row-<id>-permissions (e.g. from copied link).
  // Use window.location.hash and hashchange so it works on new-tab load and when hash is set after load.
  const lastProcessedHashRef = useRef<string | null>(null)
  const openRowDrawerFromHash = useCallback(
    (hash: string, currentRows: RowData[]) => {
      const rawHash = hash.replace(/^#/, '')
      const match = rawHash.match(/^row-(.+?)(-permissions)?$/)
      if (!match || !projectId || !databaseId || !tableId) {
        lastProcessedHashRef.current = null
        return
      }
      if (lastProcessedHashRef.current === hash) return
      lastProcessedHashRef.current = hash
      const rowId = match[1]
      const openToPermissions = !!match[2]

      const fromCurrentPage = currentRows.find((r) => r.$id === rowId)
      if (fromCurrentPage) {
        setSelectedRowForEdit(fromCurrentPage)
        setFocusedField(null)
        setDrawerInitialTab(openToPermissions ? 'permissions' : 'data')
        setEditDrawerOpen(true)
        return
      }

      fetchProjectTableRow(projectId, databaseId, tableId, rowId).then(
        (apiRow: unknown) => {
          if (!apiRow || typeof apiRow !== 'object') return
          const rowObj = apiRow as Record<string, unknown>
          const data: Record<string, string | number | boolean> = {}
          Object.keys(rowObj).forEach((key) => {
            if (!key.startsWith('$'))
              data[key] = rowObj[key] as string | number | boolean
          })
          const rowData: RowData = {
            $id: (rowObj.$id as string) ?? rowId,
            $sequence: rowObj.$sequence as number | undefined,
            rowNumber: 0,
            data,
            $createdAt: rowObj.$createdAt as string | undefined,
            $updatedAt: rowObj.$updatedAt as string | undefined,
            $permissions: (rowObj.$permissions as string[]) || [],
          }
          setSelectedRowForEdit(rowData)
          setFocusedField(null)
          setDrawerInitialTab(openToPermissions ? 'permissions' : 'data')
          setEditDrawerOpen(true)
        },
      )
    },
    [projectId, databaseId, tableId],
  )

  useEffect(() => {
    const hash = window.location.hash
    if (hash) openRowDrawerFromHash(hash, rows)
  }, [rows, openRowDrawerFromHash])

  useEffect(() => {
    const onHashChange = () => {
      const hash = window.location.hash
      if (!hash) {
        lastProcessedHashRef.current = null
        return
      }
      openRowDrawerFromHash(hash, rows)
    }
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [rows, openRowDrawerFromHash])

  // Create/Update row mutation
  const saveRowMutation = useMutation({
    mutationFn: async ({
      rowId,
      data,
      customId,
      permissions,
    }: {
      rowId: string | null
      data: Record<string, string | number | boolean | unknown[] | null>
      customId?: string | undefined
      permissions?: string[]
    }) => {
      if (rowId) {
        // Update existing row
        return await updateProjectTableRow(
          projectId,
          databaseId,
          tableId,
          rowId,
          data,
          permissions,
        )
      } else {
        // Create new row - use customId if provided, otherwise will auto-generate
        return await createProjectTableRow(
          projectId,
          databaseId,
          tableId,
          data,
          customId,
          permissions,
        )
      }
    },
    onSuccess: (_, variables) => {
      // Invalidate and refetch rows
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        variables.rowId
          ? 'Row updated successfully'
          : 'Row created successfully',
      )
      setEditDrawerOpen(false)
      setSelectedRowForEdit(null)
    },
    onError: (error: Error, variables) => {
      toast.error(
        error.message ||
          (variables.rowId ? 'Failed to update row' : 'Failed to create row'),
      )
    },
  })

  const handleSaveRow = (
    rowId: string | null,
    data: Record<string, string | number | boolean | unknown[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => {
    saveRowMutation.mutate({ rowId, data, customId, permissions })
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (rowIds: string[]) => {
      // Delete all rows in parallel
      await Promise.all(
        rowIds.map((rowId) =>
          deleteProjectTableRow(projectId, databaseId, tableId, rowId),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch rows list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        `Successfully deleted ${selectedRows.size} row${selectedRows.size > 1 ? 's' : ''}`,
      )
      setSelectedRows(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete rows')
    },
  })

  const handleBulkDelete = () => {
    if (selectedRows.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedRows.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedRows))
  }

  const duplicateRowMutation = useMutation({
    mutationFn: async (row: RowData) => {
      const data = { ...row.data } as Record<string, unknown>
      if (Object.prototype.hasOwnProperty.call(data, '$id')) delete data.$id
      return createProjectTableRow(projectId, databaseId, tableId, data)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success('Row duplicated')
    },
    onError: (error: Error) => {
      toast.error(error.message ?? 'Failed to duplicate row')
    },
  })

  // Sample data generation mutation
  const sampleDataMutation = useMutation({
    mutationFn: async (rowCount: number) => {
      // Filter out system columns (those starting with $) and map API columns to Column type
      const columns: Column[] = apiColumns
        .filter((col: unknown) => {
          const colKey = col.key || col.name || col.$id
          // Exclude system columns (starting with $)
          return colKey && !colKey.startsWith('$')
        })
        .map((col: unknown) => ({
          key: col.key || col.name || col.$id,
          type: col.type || 'string',
          size: col.size || null,
          required: col.required || false,
          array: col.array || false,
          default: col.default || null,
          format: col.format || null,
          elements: col.elements || null,
          min: col.min ?? null,
          max: col.max ?? null,
          status: col.status || 'available',
        }))

      let sampleRows: Record<string, unknown>[]

      // If no custom columns, generate rows with just custom IDs
      if (columns.length === 0) {
        const { ID } = await import('@appwrite.io/console')
        sampleRows = Array.from({ length: rowCount }, () => ({
          $id: ID.unique(),
        }))
      } else {
        // Generate sample rows with data for custom columns
        sampleRows = generateSampleRows(columns, rowCount)
      }

      // Insert rows
      const result = await createProjectTableRows(
        projectId,
        databaseId,
        tableId,
        sampleRows,
        hasRelationshipColumns,
      )

      return result
    },
    onSuccess: (result) => {
      // Invalidate and refetch rows
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        `Sample data added successfully. ${result.created} row${result.created !== 1 ? 's' : ''} created.`,
      )
      setSampleDataModalOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to generate sample data')
    },
  })

  const handleGenerateSampleData = (rowCount: number) => {
    sampleDataMutation.mutate(rowCount)
  }

  // Rows are already paginated by the API
  const paginatedRows = rows

  const formatCellValue = (
    value:
      | string
      | number
      | boolean
      | Record<string, unknown>
      | null
      | undefined,
  ) => {
    if (value === null || value === undefined)
      return { full: 'null', display: 'null', isNull: true }
    const stringValue =
      typeof value === 'object' ? JSON.stringify(value) : String(value)
    const trimmed =
      stringValue.length > 80 ? `${stringValue.slice(0, 77)}…` : stringValue
    return { full: stringValue, display: trimmed, isNull: false }
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern =
      /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  // The parent container constrains height with overflow-hidden
  // This component fills available space and handles its own scrolling
  // Only show loading if we don't have data yet (data is prefetched in route loader)
  // This prevents showing loading when switching tables since data is already cached
  if (showRowsLoading || (columnsLoading && apiColumns.length === 0)) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading rows...</div>
      </div>
    )
  }

  // Check if table has custom columns (non-system columns)
  const hasCustomColumns = apiColumns.some((col: unknown) => {
    const colKey = col.key || col.name || col.$id
    return colKey && !colKey.startsWith('$')
  })

  // Check if table has any columns at all
  const hasColumns = apiColumns.length > 0

  // Show empty state outside the table when there are no rows
  if (paginatedRows.length === 0) {
    const handleCreateRow = () => {
      if (openCreateRowFnRef.current) {
        openCreateRowFnRef.current()
      } else {
        // Fallback: directly open the drawer
        setSelectedRowForEdit(null)
        setFocusedField(null)
        setDrawerInitialTab(null)
        setEditDrawerOpen(true)
      }
    }

    const handleSuggestColumns = () => {
      // Navigate to columns tab with search parameter to auto-open modal
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/columns',
        params: { projectId, databaseId, tableId },
        search: { openSuggest: 'true' },
      })
    }

    const handleDocumentation = () => {
      window.open('https://appwrite.io/docs/products/databases', '_blank')
    }

    const handleOpenSampleDataModal = () => {
      if (!sampleDataMutation.isPending && !columnsLoading) {
        setSampleDataModalOpen(true)
      }
    }

    return (
      <div className="flex flex-col">
        <div className="flex flex-1 items-start justify-center pt-32">
          <div className="flex flex-col items-center justify-center gap-6">
            <Table2 className="h-12 w-12 text-muted-foreground/50" />
            <div className="space-y-2 text-center">
              <p className="text-sm font-medium text-foreground">
                {hasColumns ? 'No rows found' : 'No columns yet'}
              </p>
              <p className="text-xs text-muted-foreground max-w-sm">
                {hasColumns
                  ? 'This table is empty. Get started by creating a row, adding columns, or generating sample data.'
                  : 'This table has no columns yet. Create your first column to get started.'}
              </p>
            </div>
            <div className="grid grid-cols-2 gap-3 w-full max-w-2xl">
              {/* Row 1 */}
              {!canWriteTables ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Card
                      className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none"
                    >
                      <div className="flex items-start gap-3 p-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                          <Lightbulb className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0 flex-1">
                          <h3 className="text-sm font-medium text-foreground">
                            Suggest columns
                          </h3>
                          <p className="mt-1 text-xs text-muted-foreground">
                            Use AI to generate columns
                          </p>
                        </div>
                      </div>
                    </Card>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    You don't have permission to perform this action.
                  </TooltipContent>
                </Tooltip>
              ) : (
                <Card
                  onClick={handleSuggestColumns}
                  className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                >
                  <div className="flex items-start gap-3 p-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                      <Lightbulb className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="text-sm font-medium text-foreground">
                        Suggest columns
                      </h3>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Use AI to generate columns
                      </p>
                    </div>
                  </div>
                </Card>
              )}
              {hasCustomColumns ? (
                !canWriteRows ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                        <div className="flex items-start gap-3 p-4">
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <Plus className="h-5 w-5 text-muted-foreground" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <h3 className="text-sm font-medium text-foreground">
                              Create row
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                              Add a new row to this table
                            </p>
                          </div>
                        </div>
                      </Card>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      You don't have permission to perform this action.
                    </TooltipContent>
                  </Tooltip>
                ) : (
                  <Card
                    onClick={handleCreateRow}
                    className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                  >
                    <div className="flex items-start gap-3 p-4">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <Plus className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium text-foreground">
                          Create row
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                          Add a new row to this table
                        </p>
                      </div>
                    </div>
                  </Card>
                )
              ) : !canWriteTables ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                      <div className="flex items-start gap-3 p-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                          <Plus className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0 flex-1">
                          <h3 className="text-sm font-medium text-foreground">
                            Create column
                          </h3>
                          <p className="mt-1 text-xs text-muted-foreground">
                            Create columns manually
                          </p>
                        </div>
                      </div>
                    </Card>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    You don't have permission to perform this action.
                  </TooltipContent>
                </Tooltip>
              ) : (
                <Card
                  onClick={handleCreateColumn}
                  className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                >
                  <div className="flex items-start gap-3 p-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                      <Plus className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="text-sm font-medium text-foreground">
                        Create column
                      </h3>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Create columns manually
                      </p>
                    </div>
                  </div>
                </Card>
              )}
              {/* Row 2 */}
              <Card
                onClick={handleOpenSampleDataModal}
                className={cn(
                  'transition-colors p-0 gap-0 shadow-none',
                  sampleDataMutation.isPending || columnsLoading
                    ? 'opacity-50 cursor-not-allowed'
                    : 'cursor-pointer hover:bg-accent/50',
                )}
              >
                <div className="flex items-start gap-3 p-4">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <BarChart3 className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-medium text-foreground">
                      Generate sample data
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Generate data for testing
                    </p>
                  </div>
                </div>
              </Card>
              <Card
                onClick={handleDocumentation}
                className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
              >
                <div className="flex items-start gap-3 p-4">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <BookOpen className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-medium text-foreground">
                      Documentation
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Read the Appwrite docs
                    </p>
                  </div>
                </div>
              </Card>
            </div>
          </div>
        </div>

        {/* Row Update Drawer */}
        <RowEditDrawer
          open={editDrawerOpen}
          onOpenChange={setEditDrawerOpen}
          row={selectedRowForEdit}
          tableName={table.name}
          focusedField={focusedField}
          columns={apiColumns}
          onSave={handleSaveRow}
          isSaving={saveRowMutation.isPending}
        />

        {/* Sample Data Modal */}
        <SampleDataModal
          open={sampleDataModalOpen}
          onOpenChange={setSampleDataModalOpen}
          onConfirm={handleGenerateSampleData}
          isLoading={sampleDataMutation.isPending}
        />

        {/* Column Form Dialog for empty state */}
        <ColumnDrawer
          open={columnDialogOpen}
          onOpenChange={setColumnDialogOpen}
          onSubmit={handleColumnSubmit}
          column={selectedColumn}
          availableTables={
            availableTablesForColumns?.map((t: unknown) => ({
              $id: t.$id,
              name: t.name,
            })) || []
          }
          existingColumns={apiColumns.map((c: unknown) => ({
            key: c.key || c.name || c.$id,
          }))}
          isLoading={createColumnMutationForEmptyState.isPending}
        />
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col min-h-0">
      {/* Scrollable table area */}
      <div className="min-h-0 flex-1 overflow-auto overscroll-contain">
        <table className="w-full border-collapse">
          <colgroup>
            <col style={{ width: '40px' }} />
            <col style={{ width: '64px' }} />
            <col style={{ width: '180px' }} />
            {columns.map((col: string, index: number) => (
              <col key={`col-${col}-${index}`} style={{ minWidth: '150px' }} />
            ))}
            <col style={{ width: '180px' }} />
            <col style={{ width: '180px' }} />
            <col
              style={{ width: '40px', minWidth: '40px', maxWidth: '40px' }}
            />
          </colgroup>
          <thead className={stickyTheadClass}>
            <tr>
              <th
                className={cn(
                  'sticky left-0 z-40 w-10 bg-background px-2 py-2 text-left',
                  'shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db,inset_-1px_0_0_0_#d1d5db]',
                  'dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1),inset_-1px_0_0_0_rgb(255_255_255_/_0.1)]',
                )}
              >
                <Checkbox
                  checked={
                    selectedRows.size === paginatedRows.length &&
                    paginatedRows.length > 0
                  }
                  onCheckedChange={toggleAll}
                />
              </th>
              <th
                className={cn(
                  'w-16 px-3 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-muted-foreground',
                  headerCellBorderClass,
                )}
              >
                #
              </th>
              <th className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}>
                <div className="flex items-center gap-2">
                  <Fingerprint className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $id
                  </span>
                  <button
                    type="button"
                    onClick={() => handleSortColumn('$id')}
                    className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  >
                    {sortBy === '$id' ? (
                      sortOrder === 'asc' ? (
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
              {columns.map((col: string) => {
                // Get column info to determine icon
                const columnInfo = apiColumns.find((c: unknown) => {
                  const colKey =
                    c.key || c.name || c.$id || c.attribute || c.attributeId
                  return colKey === col
                })
                const columnType = columnInfo?.type || 'string'
                const ColumnIcon = getColumnIcon(columnType)
                return (
                  <th
                    key={col}
                    className={cn(
                      'min-w-[150px] px-3 py-2',
                      headerCellBorderClass,
                    )}
                  >
                    <div className="flex items-center gap-2">
                      <ColumnIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="text-[12px] font-medium text-foreground">
                        {col}
                      </span>
                      <button
                        type="button"
                        onClick={() => handleSortColumn(col)}
                        className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      >
                        {sortBy === col ? (
                          sortOrder === 'asc' ? (
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
                )
              })}
              <th className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}>
                <div className="flex items-center gap-2">
                  <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $createdAt
                  </span>
                  <button
                    type="button"
                    onClick={() => handleSortColumn('$createdAt')}
                    className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  >
                    {sortBy === '$createdAt' ? (
                      sortOrder === 'asc' ? (
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
              <th className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}>
                <div className="flex items-center gap-2">
                  <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $updatedAt
                  </span>
                  <button
                    type="button"
                    onClick={() => handleSortColumn('$updatedAt')}
                    className="ml-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  >
                    {sortBy === '$updatedAt' ? (
                      sortOrder === 'asc' ? (
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
                  'relative sticky right-0 z-30 bg-background p-0',
                  'shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db,inset_1px_0_0_0_#d1d5db]',
                  'dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1),inset_1px_0_0_0_rgb(255_255_255_/_0.1)]',
                )}
                style={{ width: '40px', minWidth: '40px', maxWidth: '40px' }}
              >
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="absolute inset-0 flex items-center justify-center">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          if (canWriteTables) handleCreateColumn()
                        }}
                        disabled={!canWriteTables}
                        className={cn(
                          'flex items-center justify-center transition-colors',
                          canWriteTables
                            ? 'cursor-pointer hover:bg-muted/50'
                            : 'cursor-not-allowed opacity-50',
                        )}
                      >
                        <Plus className="h-4 w-4 text-muted-foreground" />
                      </button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    {canWriteTables
                      ? 'Create column'
                      : "You don't have permission to perform this action."}
                  </TooltipContent>
                </Tooltip>
              </th>
            </tr>
          </thead>
          <tbody>
            {paginatedRows.map((row) => (
              <RowContextMenu
                key={row.$id}
                projectId={projectId}
                databaseId={databaseId}
                tableId={tableId}
                row={row}
                contextColumnKey={contextCellColumnKey}
                onUpdateRow={(r) => handleRowClick(r as RowData)}
                onUpdatePermissions={(r) =>
                  handleOpenPermissionsRow(r as RowData)
                }
                queryKey={['rows', 'project', projectId, databaseId, tableId]}
              >
                <tr
                  className={cn(
                    'group cursor-pointer transition-colors',
                    selectedRows.has(row.$id)
                      ? 'bg-muted'
                      : 'hover:bg-muted/50',
                  )}
                  onClick={() => handleRowClick(row)}
                  onContextMenu={(e) => {
                    const td = (e.target as HTMLElement).closest('td')
                    const key = td?.getAttribute('data-column') ?? null
                    setContextCellColumnKey(key)
                  }}
                >
                  <td
                    className={cn(
                      'sticky left-0 w-10 border-b border-gray-200 dark:border-border bg-background px-2 py-1.5',
                      'shadow-[inset_-1px_0_0_0_#d1d5db] dark:shadow-[inset_-1px_0_0_0_rgb(255_255_255_/_0.1)]',
                      selectedRows.has(row.$id) && 'bg-muted',
                    )}
                  >
                    <Checkbox
                      checked={selectedRows.has(row.$id)}
                      onCheckedChange={() => toggleRow(row.$id)}
                      onClick={(e) => e.stopPropagation()}
                    />
                  </td>
                  <td
                    className={cn('px-3 py-1.5', bodyCellBorderClass)}
                    data-column="$sequence"
                  >
                    <span className="text-[12px] text-muted-foreground">
                      {row.$sequence ?? row.rowNumber}
                    </span>
                  </td>
                  <td
                    className={cn(
                      'w-[180px] px-3 py-1.5',
                      columns.length === 0
                        ? 'border-b border-gray-200 dark:border-border'
                        : bodyCellBorderClass,
                    )}
                    data-column="$id"
                  >
                    <CopyableId id={row.$id} size="xs" />
                  </td>
                  {columns.map((col: string) => (
                    <td
                      key={col}
                      className={cn('px-3 py-1.5', bodyCellBorderClass)}
                      data-column={col}
                      onClick={(e) => {
                        e.stopPropagation()
                        handleCellClick(row, col)
                      }}
                    >
                      {(() => {
                        const { full, display, isNull } = formatCellValue(
                          row.data[col as keyof typeof row.data] as
                            | string
                            | number
                            | boolean
                            | Record<string, unknown>
                            | null
                            | undefined,
                        )
                        // Only apply RTL detection to string values
                        const cellValue = row.data[col as keyof typeof row.data]
                        const isRTLContent =
                          typeof cellValue === 'string'
                            ? isRTL(cellValue)
                            : false
                        return (
                          <span
                            className={cn(
                              'block max-w-[220px] truncate whitespace-nowrap text-[12px]',
                              isNull ? 'text-foreground/60' : 'text-foreground',
                            )}
                            title={full}
                            dir={isRTLContent ? 'rtl' : 'ltr'}
                          >
                            {display}
                          </span>
                        )
                      })()}
                    </td>
                  ))}
                  <td
                    className={cn('w-[180px] px-3 py-1.5', bodyCellBorderClass)}
                    data-column="$createdAt"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {row.$createdAt ? (
                      <DateTooltip
                        date={new Date(row.$createdAt)}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-foreground/60">
                        N/A
                      </span>
                    )}
                  </td>
                  <td
                    className={cn('w-[180px] px-3 py-1.5', bodyCellBorderClass)}
                    data-column="$updatedAt"
                    onClick={(e) => e.stopPropagation()}
                  >
                    {row.$updatedAt ? (
                      <DateTooltip
                        date={new Date(row.$updatedAt)}
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
                      'sticky right-0 border-b border-gray-200 dark:border-border bg-background p-0',
                      'shadow-[inset_1px_0_0_0_#d1d5db] dark:shadow-[inset_1px_0_0_0_rgb(255_255_255_/_0.1)]',
                      selectedRows.has(row.$id) && 'bg-muted',
                    )}
                    style={{
                      width: '40px',
                      minWidth: '40px',
                      maxWidth: '40px',
                    }}
                  >
                    <div className="flex h-full w-[40px] items-center justify-center py-1.5">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <button
                            className="rounded p-1 hover:bg-muted"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                          </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              handleRowClick(row)
                            }}
                          >
                            Update row
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              duplicateRowMutation.mutate(row)
                            }}
                            disabled={duplicateRowMutation.isPending}
                          >
                            Duplicate
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={(e) => {
                              e.stopPropagation()
                              setSelectedRows(new Set([row.$id]))
                              setDeleteDialogOpen(true)
                            }}
                          >
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>
              </RowContextMenu>
            ))}
          </tbody>
        </table>
      </div>

      {/* Bulk Delete Action Bar */}
      {selectedRows.size > 0 && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedRows.size} row{selectedRows.size > 1 ? 's' : ''} selected
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedRows(new Set())}
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

      {/* Sticky Pagination Footer */}
      <div className="shrink-0 bg-background border-t border-border">
        <div className="@container flex items-center justify-between gap-4 px-4">
          <div className="flex-1 min-w-0">
            <Pagination
              currentPage={displayedPage}
              totalItems={displayedRowsTotal ?? rowsTotal}
              pageSize={effectivePageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              itemLabel="rows"
            />
          </div>
          <div className="flex-shrink-0">
            <Button
              size="sm"
              variant="outline"
              onClick={() => setSampleDataModalOpen(true)}
              disabled={sampleDataMutation.isPending || columnsLoading}
              className="h-8 gap-2 text-[12px] font-medium"
            >
              <Plus className="h-3.5 w-3.5" />
              <span className="hidden @[500px]:inline">Sample data</span>
              <span className="@[500px]:hidden">Sample</span>
            </Button>
          </div>
        </div>
      </div>

      {/* Row Update Drawer */}
      <RowEditDrawer
        open={editDrawerOpen}
        onOpenChange={(open) => {
          setEditDrawerOpen(open)
          if (!open) setDrawerInitialTab(null)
        }}
        row={selectedRowForEdit}
        tableName={table.name}
        focusedField={focusedField}
        initialTab={drawerInitialTab ?? undefined}
        columns={apiColumns}
        onSave={handleSaveRow}
        isSaving={saveRowMutation.isPending}
      />

      {/* Bulk Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete Rows</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete {selectedRows.size} row
              {selectedRows.size > 1 ? 's' : ''}? This action cannot be undone.
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

      {/* Sample Data Modal */}
      <SampleDataModal
        open={sampleDataModalOpen}
        onOpenChange={setSampleDataModalOpen}
        onConfirm={handleGenerateSampleData}
        isLoading={sampleDataMutation.isPending}
      />

      {/* Column Form Dialog for empty state */}
      <ColumnDrawer
        open={columnDialogOpen}
        onOpenChange={setColumnDialogOpen}
        onSubmit={handleColumnSubmit}
        column={selectedColumn}
        availableTables={
          availableTablesForColumns?.map((t: unknown) => ({
            $id: t.$id,
            name: t.name,
          })) || []
        }
        existingColumns={apiColumns.map((c: unknown) => ({
          key: c.key || c.name || c.$id,
        }))}
        isLoading={createColumnMutationForEmptyState.isPending}
      />
    </div>
  )
}

// Spreadsheet-like view for Columns
function ColumnsSpreadsheet({
  table,
  onCreateReady,
  onSuggestReady,
  canWriteTables = true,
  filterMap: filterMapProp,
}: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<unknown>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [columnToDelete, setColumnToDelete] = useState<string | null>(null)
  const [contextDialogOpen, setContextDialogOpen] = useState(false)
  const [suggestedColumns, setSuggestedColumns] = useState<unknown[]>([])
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false)

  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { query?: string } | undefined
  const columnsFilterMapFromUrl = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const columnsFilterMap = filterMapProp ?? columnsFilterMapFromUrl
  const columnsFilterQueries =
    columnsFilterMap.size > 0 ? Array.from(columnsFilterMap.values()) : undefined

  const columnsListParams = useMemo(() => {
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    return {
      page: getPage(url, 1),
      limit: getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE),
    }
  }, [location.pathname, location.search])
  const columnsPage = columnsListParams.page
  const columnsLimit = columnsListParams.limit
  const columnsPageIndexed = Math.max(0, columnsPage - 1)

  // Fetch columns from the project SDK (listColumns with optional filter queries, ordered by $createdAt asc, paginated)
  const {
    columns: apiColumns,
    total: columnsTotal,
    isLoading: columnsLoading,
    isFetching: columnsFetching,
  } = useProjectTableColumns(
    projectId,
    databaseId,
    tableId,
    columnsFilterQueries,
    columnsPageIndexed,
    columnsLimit,
  )

  const navigateColumnsList = (updates: { page?: number; limit?: number }) => {
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          ...(updates.page != null && { page: updates.page }),
          ...(updates.limit != null && { limit: updates.limit }),
        }
        return next
      },
      replace: true,
    })
  }

  // Fetch full table for row size metadata (bytesUsed, bytesMax) when creating varchar columns
  const { table: fullTable } = useProjectTable(projectId, databaseId, tableId)

  // Fetch tables for relationship columns
  const { tables: availableTables } = useTablesForColumns(
    projectId,
    databaseId,
    0,
    100,
    undefined,
  )

  // Create column mutation
  const createColumnMutation = useMutation({
    mutationFn: async (data: ColumnFormData) => {
      return await createProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        data,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setColumnDialogOpen(false)
      setSelectedColumn(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create column')
    },
  })

  // Update column mutation
  const updateColumnMutation = useMutation({
    mutationFn: async ({
      columnKey,
      data,
    }: {
      columnKey: string
      data: Partial<ColumnFormData>
    }) => {
      return await updateProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
        data,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setColumnDialogOpen(false)
      setSelectedColumn(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update column')
    },
  })

  // Delete column mutation
  const deleteColumnMutation = useMutation({
    mutationFn: async (columnKey: string) => {
      return await deleteProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setDeleteDialogOpen(false)
      setColumnToDelete(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete column')
    },
  })

  const handleCreateColumn = () => {
    setSelectedColumn(null)
    setColumnDialogOpen(true)
  }

  const handleGenerateSuggestions = async (context: string) => {
    setIsLoadingSuggestions(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.console.suggestColumns({
        databaseId,
        tableId,
        context: context || undefined,
        min: 3,
        max: 7,
      })

      // Map API suggestions to display format with suggestion flag
      const mapped = result.columns.map((col: unknown) => ({
        key: col.key || col.name || col.$id || 'unnamed',
        type: col.type || 'string',
        // String fields
        size: col.size,
        encrypt: col.encrypt,
        // Number fields
        min: col.min,
        max: col.max,
        // Enum fields
        elements: col.elements,
        // Relationship fields
        relatedTableId: col.relatedTableId,
        relationshipType: col.relationshipType,
        twoWay: col.twoWay,
        twoWayKey: col.twoWayKey,
        onDelete: col.onDelete,
        // Common fields
        required: col.required ?? false,
        array: col.array ?? false,
        xdefault: col.default ?? col.xdefault,
        default: col.default, // Keep for backward compatibility
        isSuggestion: true,
        originalData: col,
      }))

      setSuggestedColumns(mapped)
      setContextDialogOpen(false)
      toast.success(`Generated ${mapped.length} column suggestions`)

      // Scroll to first suggestion after DOM updates
      setTimeout(() => {
        const firstSuggestionRow = document.querySelector(
          '[data-suggestion-row="true"]',
        )
        if (firstSuggestionRow) {
          firstSuggestionRow.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
          })
        }
      }, 100)
    } catch (error) {
      toast.error(getErrorMessage(error))
      setContextDialogOpen(false)
    } finally {
      setIsLoadingSuggestions(false)
    }
  }

  const handleApproveSuggestion = async (suggestionKey: string) => {
    try {
      // Get the current suggestion data from state (in case it was edited)
      const suggestion = suggestedColumns.find((s) => s.key === suggestionKey)
      if (!suggestion) {
        toast.error('Suggestion not found')
        return
      }

      // Extract all relevant fields, ensuring we get updated values
      // Only set size for string and varchar (index/suggestions payload)
      const suggestionType = suggestion.type as string
      const columnData: ColumnFormData = {
        key: suggestion.key,
        type: suggestion.type as ColumnType,
        required: suggestion.required ?? false,
        array: suggestion.array ?? false,
        // Type-specific fields
        size:
          suggestionType === 'string' || suggestionType === 'varchar'
            ? suggestion.size
            : undefined,
        encrypt:
          ['string', 'text', 'mediumtext', 'longtext', 'varchar'].includes(
            suggestionType,
          )
            ? suggestion.encrypt
            : undefined,
        min: suggestion.min,
        max: suggestion.max,
        elements: suggestion.elements,
        xdefault: suggestion.xdefault ?? suggestion.default,
        // Relationship fields
        relatedTableId: suggestion.relatedTableId,
        relationshipType: suggestion.relationshipType,
        twoWay: suggestion.twoWay,
        twoWayKey: suggestion.twoWayKey,
        onDelete: suggestion.onDelete,
      }

      await createColumnMutation.mutateAsync(columnData)

      // Remove from suggestions
      setSuggestedColumns((prev) => prev.filter((s) => s.key !== suggestionKey))

      toast.success(`Column "${suggestion.key}" created successfully`)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleRemoveSuggestion = (key: string) => {
    setSuggestedColumns((prev) => prev.filter((s) => s.key !== key))
  }

  const handleEditSuggestion = (suggestion: unknown) => {
    setSelectedColumn({ ...suggestion, isSuggestion: true })
    setColumnDialogOpen(true)
  }

  const handleSuggestionSubmit = (key: string, data: ColumnFormData) => {
    // Update the suggestion in the list, ensuring all fields are properly merged
    setSuggestedColumns((prev) =>
      prev.map((s) =>
        s.key === key ? { ...s, ...data, isSuggestion: true } : s,
      ),
    )
    setColumnDialogOpen(false)
    setSelectedColumn(null)
    toast.success('Suggestion updated')
  }

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateColumn)
    }
  }, [onCreateReady])

  // Expose suggest function to parent
  useEffect(() => {
    if (onSuggestReady) {
      onSuggestReady(() => setContextDialogOpen(true))
    }
  }, [onSuggestReady])

  // Check for openSuggest URL parameter and auto-open modal
  useEffect(() => {
    const searchParams = new URLSearchParams(location.search)
    if (searchParams.get('openSuggest') === 'true') {
      setContextDialogOpen(true)
      // Clean up URL parameter
      searchParams.delete('openSuggest')
      const newSearch = searchParams.toString()
      navigate({
        to: location.pathname,
        search: newSearch ? `?${newSearch}` : '',
        replace: true,
      })
    }
  }, [location.search, location.pathname, navigate])

  const handleEditColumn = (column: unknown) => {
    setSelectedColumn(column)
    setColumnDialogOpen(true)
  }

  const handleDeleteColumn = (columnKey: string) => {
    setColumnToDelete(columnKey)
    setDeleteDialogOpen(true)
  }

  const handleColumnSubmit = async (data: ColumnFormData) => {
    if (selectedColumn?.isSuggestion) {
      // Update suggestion in place
      handleSuggestionSubmit(selectedColumn.key, data)
    } else if (selectedColumn) {
      await updateColumnMutation.mutateAsync({
        columnKey:
          selectedColumn.key || selectedColumn.name || selectedColumn.$id,
        data,
      })
    } else {
      await createColumnMutation.mutateAsync(data)
    }
  }

  const handleConfirmDelete = () => {
    if (columnToDelete) {
      deleteColumnMutation.mutate(columnToDelete)
    }
  }

  const clearAllColumnsFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  // Map API columns to the format expected by the component (use filtered list for display)
  const columns = apiColumns.map((col: unknown) => ({
    key: col.key || col.name || col.$id,
    type: col.type || 'string',
    // String fields
    size: col.size || null,
    encrypt: col.encrypt || false,
    // Number fields
    min: col.min,
    max: col.max,
    // Enum fields
    elements: col.elements || null,
    // Relationship fields
    relatedTableId: col.relatedTableId,
    relationshipType: col.relationshipType,
    twoWay: col.twoWay,
    twoWayKey: col.twoWayKey,
    onDelete: col.onDelete,
    // Common fields
    required: col.required || false,
    array: col.array || false,
    default: col.default || null,
    xdefault: col.xdefault || col.default || null,
    // Keep reference to original for debugging
    $id: col.$id,
  }))

  // Local fallback only when API returns no columns (e.g. new table). Not fetched; not filtered.
  const generateMockColumns = () => {
    const baseColumns = [
      {
        key: '$id',
        type: 'string',
        size: 36,
        required: true,
        array: false,
        default: null,
      },
      {
        key: '$createdAt',
        type: 'datetime',
        size: null,
        required: true,
        array: false,
        default: null,
      },
      {
        key: '$updatedAt',
        type: 'datetime',
        size: null,
        required: true,
        array: false,
        default: null,
      },
    ]

    const tableColumns: Record<
      string,
      Array<{
        key: string
        type: string
        size: number | null
        required: boolean
        array: boolean
        default: string | null
      }>
    > = {
      users: [
        {
          key: 'name',
          type: 'string',
          size: 128,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'email',
          type: 'email',
          size: 320,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'status',
          type: 'enum',
          size: null,
          required: true,
          array: false,
          default: 'pending',
        },
        {
          key: 'role',
          type: 'enum',
          size: null,
          required: true,
          array: false,
          default: 'user',
        },
        {
          key: 'avatar',
          type: 'url',
          size: 2000,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'phone',
          type: 'string',
          size: 32,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'lastLogin',
          type: 'datetime',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'address',
          type: 'string',
          size: 256,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'preferences',
          type: 'json',
          size: null,
          required: false,
          array: false,
          default: '{}',
        },
      ],
      products: [
        {
          key: 'name',
          type: 'string',
          size: 256,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'price',
          type: 'float',
          size: null,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'stock',
          type: 'integer',
          size: null,
          required: true,
          array: false,
          default: '0',
        },
        {
          key: 'category',
          type: 'string',
          size: 64,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'tags',
          type: 'string',
          size: 32,
          required: false,
          array: true,
          default: null,
        },
        {
          key: 'description',
          type: 'string',
          size: 2000,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'sku',
          type: 'string',
          size: 64,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'images',
          type: 'url',
          size: 2000,
          required: false,
          array: true,
          default: null,
        },
        {
          key: 'isPublished',
          type: 'boolean',
          size: null,
          required: true,
          array: false,
          default: 'false',
        },
        {
          key: 'rating',
          type: 'float',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'brand',
          type: 'string',
          size: 128,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'barcode',
          type: 'string',
          size: 64,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'dimensions',
          type: 'string',
          size: 64,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'weight',
          type: 'float',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'metadata',
          type: 'json',
          size: null,
          required: false,
          array: false,
          default: '{}',
        },
      ],
      orders: [
        {
          key: 'orderId',
          type: 'string',
          size: 32,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'total',
          type: 'float',
          size: null,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'status',
          type: 'enum',
          size: null,
          required: true,
          array: false,
          default: 'pending',
        },
        {
          key: 'items',
          type: 'integer',
          size: null,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'userId',
          type: 'string',
          size: 36,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'paymentStatus',
          type: 'enum',
          size: null,
          required: true,
          array: false,
          default: 'pending',
        },
        {
          key: 'shippingAddress',
          type: 'string',
          size: 256,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'billingAddress',
          type: 'string',
          size: 256,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'shippedAt',
          type: 'datetime',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'deliveredAt',
          type: 'datetime',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'trackingNumber',
          type: 'string',
          size: 64,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'discounts',
          type: 'float',
          size: null,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'taxes',
          type: 'float',
          size: null,
          required: false,
          array: false,
          default: null,
        },
      ],
      reviews: [
        {
          key: 'productId',
          type: 'string',
          size: 36,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'userId',
          type: 'string',
          size: 36,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'rating',
          type: 'integer',
          size: null,
          required: true,
          array: false,
          default: '5',
        },
        {
          key: 'title',
          type: 'string',
          size: 256,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'comment',
          type: 'string',
          size: 2000,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'sentiment',
          type: 'enum',
          size: null,
          required: false,
          array: false,
          default: 'positive',
        },
        {
          key: 'helpfulCount',
          type: 'integer',
          size: null,
          required: false,
          array: false,
          default: '0',
        },
      ],
      categories: [
        {
          key: 'name',
          type: 'string',
          size: 128,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'slug',
          type: 'string',
          size: 128,
          required: true,
          array: false,
          default: null,
        },
        {
          key: 'description',
          type: 'string',
          size: 512,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'parentId',
          type: 'string',
          size: 36,
          required: false,
          array: false,
          default: null,
        },
        {
          key: 'isActive',
          type: 'boolean',
          size: null,
          required: true,
          array: false,
          default: 'true',
        },
        {
          key: 'sortOrder',
          type: 'integer',
          size: null,
          required: false,
          array: false,
          default: '0',
        },
        {
          key: 'icon',
          type: 'url',
          size: 2000,
          required: false,
          array: false,
          default: null,
        },
      ],
    }

    return [...baseColumns, ...(tableColumns[table.name] || [])]
  }

  // When no filters: show built-in columns then API columns. When filters applied: show only API result.
  const mockColumns = generateMockColumns()
  const displayColumns =
    columnsFilterMap.size > 0
      ? columns.length > 0
        ? columns
        : mockColumns
      : columns.length > 0
        ? [...mockColumns, ...columns]
        : mockColumns

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastDisplayColumnsRef = useRef<typeof displayColumns>([])
  useEffect(() => {
    if (!columnsFetching && displayColumns.length > 0) {
      lastDisplayColumnsRef.current = displayColumns
    }
  }, [columnsFetching, displayColumns])
  const columnsToShow =
    columnsFilterMap.size > 0 &&
    columnsFetching &&
    lastDisplayColumnsRef.current.length > 0
      ? lastDisplayColumnsRef.current
      : displayColumns

  // Combine regular columns with suggestions
  const allColumns = [...columnsToShow, ...suggestedColumns]

  if (columnsLoading && lastDisplayColumnsRef.current.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading columns...</div>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col relative">
      {!columnsFetching &&
      apiColumns.length === 0 &&
      columnsFilterMap.size > 0 ? (
        <div className="flex flex-1 items-center justify-center py-12">
          <div className="text-center">
            <p className="text-[14px] font-medium text-foreground">
              No columns match your filters
            </p>
            <p className="text-[13px] text-muted-foreground mt-1">
              Try adjusting or clearing filters to see more results
            </p>
            <Button
              variant="outline"
              size="sm"
              className="mt-4"
              onClick={clearAllColumnsFilters}
            >
              Clear filters
            </Button>
          </div>
        </div>
      ) : (
      <>
      <div
        className={cn(
          'flex-1 overflow-auto overscroll-contain',
          suggestedColumns.length > 0 && 'pb-24',
        )}
      >
        <table className="w-full border-collapse">
          <thead className={stickyTheadClass}>
            <tr>
              <th
                className={cn(
                  'min-w-[200px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Key
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[120px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Type
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[80px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Size
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[80px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Required
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[80px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Array
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[80px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Encrypted
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[120px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Default
                </span>
              </th>
              <th
                className={cn(
                  'w-10 px-2 py-2 bg-background',
                  'shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db]',
                  'dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1)]',
                )}
              />
            </tr>
          </thead>
          <tbody>
            {allColumns.map((col: unknown) => {
              const Icon = getColumnIcon(col.type)
              const isSystem = col.key ? col.key.startsWith('$') : false
              const isSuggestion = col.isSuggestion
              return (
                <tr
                  key={col.key}
                  data-suggestion-row={isSuggestion ? 'true' : undefined}
                  className={cn(
                    'group transition-colors hover:bg-muted/50',
                    isSystem && 'bg-muted/30',
                    isSuggestion && 'bg-amber-500/5',
                  )}
                >
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <Icon className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                        <code
                          className={cn(
                            'font-mono text-[12px]',
                            isSystem
                              ? 'text-muted-foreground'
                              : 'text-foreground',
                          )}
                        >
                          {col.key || 'unnamed'}
                        </code>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation()
                                const name = col.key || 'unnamed'
                                navigator.clipboard.writeText(name)
                                toast.success('Column name copied')
                              }}
                              className="flex h-6 w-6 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground group-hover:opacity-100"
                            >
                              <Copy className="h-3 w-3" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="top">
                            <p>Copy column name</p>
                          </TooltipContent>
                        </Tooltip>
                        {isSuggestion && (
                          <span className="inline-flex items-center rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                            Suggested
                          </span>
                        )}
                      </div>
                      {isSuggestion && (
                        <div className="flex items-center gap-1">
                          <button
                            onClick={() => handleApproveSuggestion(col.key)}
                            className="flex h-6 w-6 items-center justify-center rounded bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors cursor-pointer"
                          >
                            <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                          </button>
                          <button
                            onClick={() => handleRemoveSuggestion(col.key)}
                            className="flex h-6 w-6 items-center justify-center rounded bg-destructive/10 hover:bg-destructive/20 transition-colors cursor-pointer"
                          >
                            <X className="h-3.5 w-3.5 text-destructive" />
                          </button>
                          <div className="h-4 w-px bg-border mx-0.5" />
                          <button
                            onClick={() => handleEditSuggestion(col)}
                            className="flex h-6 w-6 items-center justify-center rounded bg-blue-500/10 hover:bg-blue-500/20 transition-colors cursor-pointer"
                          >
                            <Pencil className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                          </button>
                        </div>
                      )}
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <Badge
                      variant="outline"
                      className={cn(
                        'text-[11px] font-medium border',
                        getColumnTypeColor(col.type),
                      )}
                    >
                      {col.type}
                    </Badge>
                  </td>
                  <td
                    className={cn(
                      'px-3 py-2 text-[12px] text-muted-foreground',
                      bodyCellBorderClass,
                    )}
                  >
                    {col.key !== '$id' &&
                    (col.type === 'string' || col.type === 'varchar')
                      ? (col.size ?? '—')
                      : '—'}
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    {col.required ? (
                      <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        —
                      </span>
                    )}
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    {col.array ? (
                      <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        —
                      </span>
                    )}
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    {col.encrypt ? (
                      <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        —
                      </span>
                    )}
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <code className="font-mono text-[11px] text-muted-foreground">
                      {col.default ?? 'NULL'}
                    </code>
                  </td>
                  <td className={cn('px-2 py-2', lastCellBorderClass)}>
                    {!isSuggestion && !isSystem && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <button className="rounded p-1 opacity-0 transition-opacity hover:bg-muted group-hover:opacity-100">
                            <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                          </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            onClick={() => handleEditColumn(col)}
                          >
                            Update Column
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={() => handleDeleteColumn(col.key)}
                          >
                            Delete Column
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Sticky Pagination Footer */}
      <div className="shrink-0 bg-background border-t border-border">
        <div className="@container flex items-center justify-between gap-4 px-4">
          <div className="flex-1 min-w-0">
            <Pagination
              currentPage={columnsPage}
              totalItems={columnsTotal ?? 0}
              pageSize={columnsLimit}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={(page) => navigateColumnsList({ page })}
              onPageSizeChange={(newLimit) =>
                navigateColumnsList({ page: 1, limit: newLimit })
              }
              itemLabel="columns"
            />
          </div>
          {suggestedColumns.length > 0 && (
            <div className="flex-shrink-0 text-[12px] text-amber-600 dark:text-amber-400">
              {suggestedColumns.length} suggestion
              {suggestedColumns.length !== 1 ? 's' : ''}
            </div>
          )}
        </div>
      </div>
      </>
      )}

      {/* Bulk Action Bar for Suggestions */}
      {suggestedColumns.length > 0 && (
        <div className="absolute bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              <Lightbulb className="h-3 w-3 mr-1.5" />
              {suggestedColumns.length} suggestion
              {suggestedColumns.length !== 1 ? 's' : ''}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSuggestedColumns([])}
                className="h-8"
              >
                Clear all
              </Button>
              <Button
                size="sm"
                onClick={async () => {
                  for (const suggestion of suggestedColumns) {
                    await handleApproveSuggestion(suggestion.key)
                  }
                }}
                disabled={createColumnMutation.isPending}
                className="h-8"
              >
                <Check className="h-3.5 w-3.5 mr-1.5" />
                Approve all
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Column Form Dialog */}
      <ColumnDrawer
        open={columnDialogOpen}
        onOpenChange={(open) => {
          setColumnDialogOpen(open)
          if (!open && selectedColumn?.isSuggestion) {
            setSelectedColumn(null)
          }
        }}
        onSubmit={handleColumnSubmit}
        column={selectedColumn}
        availableTables={availableTables.map((t) => ({
          $id: t.$id,
          name: t.name,
        }))}
        existingColumns={apiColumns.map((c: unknown) => ({
          key: (c as { key?: string }).key || (c as { name?: string }).name || (c as { $id?: string }).$id,
        }))}
        isLoading={
          createColumnMutation.isPending || updateColumnMutation.isPending
        }
        table={(() => {
          const t = fullTable ?? table
          const bytesUsed = (t as { bytesUsed?: number })?.bytesUsed
          const bytesMax = (t as { bytesMax?: number })?.bytesMax
          return bytesUsed !== undefined && bytesMax !== undefined
            ? { bytesUsed, bytesMax }
            : undefined
        })()}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete Column</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete the column "{columnToDelete}"?
              This action cannot be undone and may affect existing rows.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteColumnMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteColumnMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Context Input Dialog */}
      <Dialog
        open={contextDialogOpen}
        onOpenChange={(open) => {
          if (!isLoadingSuggestions) {
            setContextDialogOpen(open)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          {isLoadingSuggestions ? (
            <>
              <DialogHeader className="px-6 pt-6 pb-4 text-left">
                <DialogTitle>Generating suggestions</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  AI is analyzing your table structure and generating column
                  suggestions...
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0 mt-8 mb-4 flex flex-col items-center justify-center gap-4">
                <div className="relative">
                  <Lightbulb className="h-12 w-12 text-amber-500 animate-pulse" />
                  <div className="absolute inset-0 bg-amber-500/20 rounded-full animate-ping" />
                </div>
                <p className="text-[13px] text-muted-foreground text-center">
                  This may take a few seconds...
                </p>
              </div>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                const formData = new FormData(e.currentTarget)
                const context = formData.get('context') as string
                handleGenerateSuggestions(context)
              }}
            >
              <DialogHeader className="px-6 pt-6 pb-4 text-left">
                <DialogTitle>AI column suggestions</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Provide optional context or instructions to help generate
                  better column suggestions for "{table.name}".
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="space-y-2 mt-4">
                  <Label htmlFor="context" className="text-[12px] font-medium">
                    Context (Optional)
                  </Label>
                  <Textarea
                    id="context"
                    name="context"
                    placeholder="E.g., This is a social media app with user posts and comments..."
                    className="min-h-[100px] text-[13px]"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    The AI will analyze your table name and existing database
                    structure to suggest relevant columns.
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setContextDialogOpen(false)}
                >
                  Cancel
                </Button>
                <Button type="submit">Generate suggestions</Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}

// Spreadsheet-like view for Indexes
function IndexesSpreadsheet({
  table,
  onCreateReady,
  onSuggestReady,
  canWriteTables = true,
  filterMap: filterMapProp,
}: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { query?: string } | undefined
  const indexesFilterMapFromUrl = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const indexesFilterMap = filterMapProp ?? indexesFilterMapFromUrl
  const indexesFilterQueries =
    indexesFilterMap.size > 0 ? Array.from(indexesFilterMap.values()) : undefined

  const indexesListParams = useMemo(() => {
    const url = new URL(
      location.pathname + location.search,
      window.location.origin,
    )
    return {
      page: getPage(url, 1),
      limit: getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE),
    }
  }, [location.pathname, location.search])
  const indexesPage = indexesListParams.page
  const indexesLimit = indexesListParams.limit
  const indexesPageIndexed = Math.max(0, indexesPage - 1)

  const [indexDialogOpen, setIndexDialogOpen] = useState(false)
  const [selectedIndex, setSelectedIndex] = useState<unknown>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [indexToDelete, setIndexToDelete] = useState<string | null>(null)
  const [contextDialogOpen, setContextDialogOpen] = useState(false)
  const [suggestedIndexes, setSuggestedIndexes] = useState<unknown[]>([])
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false)

  const queryClient = useQueryClient()

  // Fetch indexes from the project SDK (listIndexes with optional filter queries, ordered by $createdAt asc, paginated)
  const {
    indexes: apiIndexes,
    total: indexesTotal,
    isFetching: indexesFetching,
  } = useProjectTableIndexes(
    projectId,
    databaseId,
    tableId,
    indexesFilterQueries,
    indexesPageIndexed,
    indexesLimit,
  )

  const navigateIndexesList = (updates: { page?: number; limit?: number }) => {
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          ...(updates.page != null && { page: updates.page }),
          ...(updates.limit != null && { limit: updates.limit }),
        }
        return next
      },
      replace: true,
    })
  }

  // Fetch columns for index creation (first page, default limit)
  const { columns: availableColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    tableId,
  )

  // Create index mutation
  const createIndexMutation = useMutation({
    mutationFn: async (data: IndexFormData) => {
      // Transform form data to API format - arrays must correspond to columns
      const apiData: unknown = {
        key: data.key,
        type: data.type,
        columns: data.columns.map((c: IndexColumnEntry) => c.column),
      }

      // Orders array must match columns array length
      const allOrders = data.columns.map((c: IndexColumnEntry) => c.order)
      const hasAnyOrders = allOrders.some((o) => o !== null)
      if (hasAnyOrders) {
        apiData.orders = allOrders
      }

      // Lengths array must match columns array length (only for key indexes)
      if (data.type === 'key') {
        const allLengths = data.columns.map((c: IndexColumnEntry) => c.length)
        const hasAnyLengths = allLengths.some((l) => l !== null && l > 0)
        if (hasAnyLengths) {
          apiData.lengths = allLengths
        }
      }

      return await createProjectTableIndex(
        projectId,
        databaseId,
        tableId,
        apiData,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setIndexDialogOpen(false)
      setSelectedIndex(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create index')
    },
  })

  // Delete index mutation
  const deleteIndexMutation = useMutation({
    mutationFn: async (indexKey: string) => {
      return await deleteProjectTableIndex(
        projectId,
        databaseId,
        tableId,
        indexKey,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setDeleteDialogOpen(false)
      setIndexToDelete(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete index')
    },
  })

  const handleCreateIndex = () => {
    setSelectedIndex(null)
    setIndexDialogOpen(true)
  }

  const handleDeleteIndex = (indexKey: string) => {
    setIndexToDelete(indexKey)
    setDeleteDialogOpen(true)
  }

  const handleConfirmDelete = () => {
    if (indexToDelete) {
      deleteIndexMutation.mutate(indexToDelete)
    }
  }

  // AI Suggestion handlers
  const handleGenerateSuggestions = async () => {
    setIsLoadingSuggestions(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.console.suggestIndexes({
        databaseId,
        tableId,
        min: 3,
        max: 5,
      })

      // Map API suggestions to display format with suggestion flag
      const mapped = result.indexes.map((idx: unknown) => ({
        key: idx.key || 'unnamed',
        type: idx.type || 'key',
        columns: idx.columns || [],
        orders: idx.orders || [],
        lengths: idx.lengths || [],
        isSuggestion: true,
        originalData: idx,
      }))

      setSuggestedIndexes(mapped)
      setContextDialogOpen(false)
      toast.success(`Generated ${mapped.length} index suggestions`)

      // Scroll to first suggestion after DOM updates
      setTimeout(() => {
        const firstSuggestionRow = document.querySelector(
          '[data-suggestion-row="true"]',
        )
        if (firstSuggestionRow) {
          firstSuggestionRow.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
          })
        }
      }, 100)
    } catch (error) {
      toast.error(getErrorMessage(error))
      setContextDialogOpen(false)
    } finally {
      setIsLoadingSuggestions(false)
    }
  }

  const handleApproveSuggestion = async (suggestionKey: string) => {
    try {
      // Get the current suggestion data from state (in case it was edited)
      const suggestion = suggestedIndexes.find((s) => s.key === suggestionKey)
      if (!suggestion) {
        toast.error('Suggestion not found')
        return
      }

      // Map columns and filter out invalid ones
      const validColumns = suggestion.columns
        .map((col: string, idx: number) => {
          // Find the column definition
          const columnDef = availableColumns.find((c) => c.key === col)

          // Skip array columns - they're not supported for indexes
          if (columnDef?.array) {
            return null
          }

          const columnType = columnDef?.type

          // Only key indexes on string and varchar columns support length
          const supportsLength =
            suggestion.type === 'key' &&
            (columnType === 'string' || columnType === 'varchar')

          // Cap length at maximum of 767
          let length = suggestion.lengths?.[idx] || null
          if (length && length > 767) {
            length = 767
          }

          return {
            column: col,
            order: suggestion.orders?.[idx] || null,
            length: supportsLength ? length : null,
          }
        })
        .filter(Boolean) // Remove null entries (array columns)

      // Validate that we still have columns after filtering
      if (validColumns.length === 0) {
        toast.error(
          'Cannot create index: Array columns are not supported for indexes',
        )
        return
      }

      const indexData: IndexFormData = {
        key: suggestion.key,
        type: suggestion.type,
        columns: validColumns,
      }

      await createIndexMutation.mutateAsync(indexData)

      // Remove from suggestions
      setSuggestedIndexes((prev) => prev.filter((s) => s.key !== suggestionKey))

      toast.success(`Index "${suggestion.key}" created successfully`)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleRemoveSuggestion = (suggestionKey: string) => {
    setSuggestedIndexes((prev) => prev.filter((s) => s.key !== suggestionKey))
  }

  const handleEditSuggestion = (suggestion: unknown) => {
    // Pass suggestion as-is with isSuggestion flag
    // IndexDrawer will handle the conversion from columns array to IndexColumnEntry objects
    setSelectedIndex({
      ...suggestion,
      isSuggestion: true,
    })
    setIndexDialogOpen(true)
  }

  const handleSuggestionSubmit = (key: string, data: IndexFormData) => {
    // Update the suggestion in the list, ensuring all fields are properly merged
    setSuggestedIndexes((prev) =>
      prev.map((s) =>
        s.key === key
          ? {
              ...s,
              key: data.key,
              type: data.type,
              columns: data.columns.map((c) => c.column),
              orders: data.columns.map((c) => c.order), // Keep nulls to maintain array indices
              lengths: data.columns.map((c) => c.length), // Keep nulls to maintain array indices
              isSuggestion: true,
            }
          : s,
      ),
    )
    setIndexDialogOpen(false)
    setSelectedIndex(null)
    toast.success('Suggestion updated')
  }

  const handleIndexSubmitWrapper = async (data: IndexFormData) => {
    if (selectedIndex?.isSuggestion) {
      // Update suggestion in place
      handleSuggestionSubmit(selectedIndex.key, data)
    } else {
      await createIndexMutation.mutateAsync(data)
    }
  }

  const handleOpenSuggestDialog = () => {
    setContextDialogOpen(true)
  }

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateIndex)
    }
  }, [onCreateReady])

  // Expose suggest function to parent
  useEffect(() => {
    if (onSuggestReady) {
      onSuggestReady(handleOpenSuggestDialog)
    }
  }, [onSuggestReady])

  const clearAllIndexesFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  // Map API indexes to ensure all fields are present
  const mappedIndexes = apiIndexes.map((idx: unknown) => ({
    key: idx.key,
    type: idx.type,
    columns: idx.columns || [],
    orders: idx.orders || [],
    lengths: idx.lengths || [],
    status: idx.status || 'available',
    $id: idx.$id,
  }))

  // Use API indexes only when present; mock indexes only as empty-state fallback (so filters apply to the list)
  const mockIndexes = [
    {
      key: '_key_$id',
      type: 'unique',
      columns: ['$id'],
      orders: ['ASC'],
      status: 'available',
    },
    {
      key: '_key_$createdAt',
      type: 'key',
      columns: ['$createdAt'],
      orders: ['DESC'],
      status: 'available',
    },
    ...(table.name === 'users'
      ? [
          {
            key: 'email_unique',
            type: 'unique',
            columns: ['email'],
            orders: ['ASC'],
            status: 'available',
          },
          {
            key: 'status_idx',
            type: 'key',
            columns: ['status'],
            orders: ['ASC'],
            status: 'available',
          },
        ]
      : []),
    ...(table.name === 'products'
      ? [
          {
            key: 'category_idx',
            type: 'key',
            columns: ['category'],
            orders: ['ASC'],
            status: 'available',
          },
          {
            key: 'price_idx',
            type: 'key',
            columns: ['price'],
            orders: ['DESC'],
            status: 'available',
          },
        ]
      : []),
    ...(table.name === 'orders'
      ? [
          {
            key: 'orderId_unique',
            type: 'unique',
            columns: ['orderId'],
            orders: ['ASC'],
            status: 'available',
          },
          {
            key: 'status_created',
            type: 'key',
            columns: ['status', '$createdAt'],
            orders: ['ASC', 'DESC'],
            status: 'building',
          },
        ]
      : []),
  ]

  // When no filters: show built-in indexes then API indexes. When filters applied: show only API result.
  const displayIndexes =
    indexesFilterMap.size > 0
      ? mappedIndexes.length > 0
        ? mappedIndexes
        : mockIndexes
      : mappedIndexes.length > 0
        ? [...mockIndexes, ...mappedIndexes]
        : mockIndexes

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastDisplayIndexesRef = useRef<typeof displayIndexes>([])
  useEffect(() => {
    if (!indexesFetching && displayIndexes.length > 0) {
      lastDisplayIndexesRef.current = displayIndexes
    }
  }, [indexesFetching, displayIndexes])
  const indexesToShow =
    indexesFilterMap.size > 0 &&
    indexesFetching &&
    lastDisplayIndexesRef.current.length > 0
      ? lastDisplayIndexesRef.current
      : displayIndexes

  // Combine regular indexes with suggestions
  const allIndexes = [...indexesToShow, ...suggestedIndexes]

  return (
    <div className="flex h-full flex-col relative">
      {!indexesFetching &&
      apiIndexes.length === 0 &&
      indexesFilterMap.size > 0 ? (
        <div className="flex flex-1 items-center justify-center py-12">
          <div className="text-center">
            <p className="text-[14px] font-medium text-foreground">
              No indexes match your filters
            </p>
            <p className="text-[13px] text-muted-foreground mt-1">
              Try adjusting or clearing filters to see more results
            </p>
            <Button
              variant="outline"
              size="sm"
              className="mt-4"
              onClick={clearAllIndexesFilters}
            >
              Clear filters
            </Button>
          </div>
        </div>
      ) : (
      <>
      <div
        className={cn(
          'flex-1 overflow-y-auto overscroll-contain',
          suggestedIndexes.length > 0 && 'pb-24',
        )}
      >
        <table className="w-full border-collapse">
          <thead className={stickyTheadClass}>
            <tr>
              <th
                className={cn(
                  'min-w-[200px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Key
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[100px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Type
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[300px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Columns
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[100px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Status
                </span>
              </th>
              <th
                className={cn(
                  'w-10 px-2 py-2 bg-background',
                  'shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db]',
                  'dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1)]',
                )}
              />
            </tr>
          </thead>
          <tbody>
            {allIndexes.map((index: unknown) => {
              const isSystem = index.key?.startsWith('_key_')
              const isSuggestion = index.isSuggestion === true
              return (
                <tr
                  key={index.key || 'unnamed'}
                  data-suggestion-row={isSuggestion ? 'true' : undefined}
                  className={cn(
                    'group transition-colors hover:bg-muted/50',
                    isSystem && 'bg-muted/30',
                    isSuggestion && 'bg-amber-500/5',
                  )}
                >
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex items-center gap-2 justify-between">
                      <div className="flex items-center gap-2">
                        <Key className="h-3.5 w-3.5 text-muted-foreground" />
                        <code
                          className={cn(
                            'font-mono text-[12px]',
                            isSystem
                              ? 'text-muted-foreground'
                              : 'text-foreground',
                          )}
                        >
                          {index.key || 'unnamed'}
                        </code>
                        {isSuggestion && (
                          <Badge
                            variant="secondary"
                            className="h-5 px-1.5 text-[10px] bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20"
                          >
                            Suggested
                          </Badge>
                        )}
                      </div>
                      {isSuggestion && (
                        <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleApproveSuggestion(index.key)}
                            className="h-6 w-6 p-0 rounded bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 cursor-pointer"
                            title="Approve"
                          >
                            <Check className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleRemoveSuggestion(index.key)}
                            className="h-6 w-6 p-0 rounded bg-red-500/10 hover:bg-red-500/20 text-red-600 dark:text-red-400 cursor-pointer"
                            title="Reject"
                          >
                            <X className="h-3.5 w-3.5" />
                          </Button>
                          <div className="h-4 w-px bg-border mx-0.5" />
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleEditSuggestion(index)}
                            className="h-6 w-6 p-0 rounded bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 dark:text-blue-400 cursor-pointer"
                            title="Edit"
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      )}
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <Badge
                      variant="outline"
                      className={cn(
                        'text-[11px] font-medium border',
                        getIndexTypeColor(index.type),
                      )}
                    >
                      {index.type}
                    </Badge>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex flex-wrap gap-2">
                      {index.columns.map((col: string, i: number) => {
                        const order = index.orders?.[i]
                        const length = index.lengths?.[i]
                        // Ensure length is a number and greater than 0
                        const hasLength = length != null && Number(length) > 0
                        return (
                          <div key={i} className="flex items-center gap-1">
                            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                              {col}
                            </code>
                            {order && (
                              <span className="text-[10px] text-muted-foreground/70">
                                {order}
                              </span>
                            )}
                            {hasLength && (
                              <span className="text-[10px] text-muted-foreground/70">
                                ({length})
                              </span>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <Badge
                      variant={
                        index.status === 'available' ? 'success' : 'processing'
                      }
                      className="text-[11px] font-medium capitalize"
                    >
                      {index.status}
                    </Badge>
                  </td>
                  <td className={cn('px-2 py-2', lastCellBorderClass)}>
                    {!isSystem && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <button className="rounded p-1 opacity-0 transition-opacity hover:bg-muted group-hover:opacity-100">
                            <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                          </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={() => handleDeleteIndex(index.key)}
                          >
                            Delete Index
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Sticky Pagination Footer */}
      <div className="shrink-0 bg-background border-t border-border">
        <div className="@container flex items-center justify-between gap-4 px-4">
          <div className="flex-1 min-w-0">
            <Pagination
              currentPage={indexesPage}
              totalItems={indexesTotal ?? 0}
              pageSize={indexesLimit}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={(page) => navigateIndexesList({ page })}
              onPageSizeChange={(newLimit) =>
                navigateIndexesList({ page: 1, limit: newLimit })
              }
              itemLabel="indexes"
            />
          </div>
          {suggestedIndexes.length > 0 && (
            <div className="flex-shrink-0 text-[12px] text-amber-600 dark:text-amber-400">
              {suggestedIndexes.length} suggestion
              {suggestedIndexes.length !== 1 ? 's' : ''}
            </div>
          )}
        </div>
      </div>
      </>
      )}

      {/* Index Form Dialog */}
      <IndexDrawer
        open={indexDialogOpen}
        onOpenChange={(open) => {
          setIndexDialogOpen(open)
          if (!open && selectedIndex?.isSuggestion) {
            setSelectedIndex(null)
          }
        }}
        onSubmit={handleIndexSubmitWrapper}
        index={selectedIndex}
        availableColumns={availableColumns}
        existingIndexes={indexesToShow.map((i: { key: string }) => ({
          key: i.key,
        }))}
        isLoading={createIndexMutation.isPending}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete index</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete the index "{indexToDelete}"? This
              action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteIndexMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteIndexMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Context Input Dialog */}
      <Dialog
        open={contextDialogOpen}
        onOpenChange={(open) => {
          if (!isLoadingSuggestions) {
            setContextDialogOpen(open)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          {isLoadingSuggestions ? (
            <>
              <DialogHeader className="px-6 pt-6 pb-4 text-left">
                <DialogTitle>Generating suggestions</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  AI is analyzing your table structure and generating index
                  suggestions...
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0 mt-8 mb-4 flex flex-col items-center justify-center gap-4">
                <div className="relative">
                  <Lightbulb className="h-12 w-12 text-amber-500 animate-pulse" />
                  <div className="absolute inset-0 bg-amber-500/20 rounded-full animate-ping" />
                </div>
                <p className="text-[13px] text-muted-foreground text-center">
                  This may take a few seconds...
                </p>
              </div>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                const formData = new FormData(e.currentTarget)
                const context = formData.get('context') as string
                handleGenerateSuggestions(context)
              }}
            >
              <DialogHeader className="px-6 pt-6 pb-4 text-left">
                <DialogTitle>AI index suggestions</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Provide optional context or instructions to help generate
                  better index suggestions for "{table.name}".
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="space-y-2 mt-4">
                  <Label htmlFor="context" className="text-[12px] font-medium">
                    Context (Optional)
                  </Label>
                  <Textarea
                    id="context"
                    name="context"
                    placeholder="E.g., This table will have millions of records and needs optimized search..."
                    className="min-h-[100px] text-[13px]"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    The AI will analyze your table columns and structure to
                    suggest relevant indexes.
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setContextDialogOpen(false)}
                >
                  Cancel
                </Button>
                <Button type="submit">Generate suggestions</Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* Bulk Action Bar for Suggestions */}
      {suggestedIndexes.length > 0 && (
        <div className="absolute bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              <Lightbulb className="h-3 w-3 mr-1.5" />
              {suggestedIndexes.length} suggestion
              {suggestedIndexes.length !== 1 ? 's' : ''}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSuggestedIndexes([])}
                className="h-8"
              >
                Clear all
              </Button>
              <Button
                size="sm"
                onClick={async () => {
                  for (const suggestion of suggestedIndexes) {
                    await handleApproveSuggestion(suggestion.key)
                  }
                }}
                disabled={createIndexMutation.isPending}
                className="h-8"
              >
                <Check className="h-3.5 w-3.5 mr-1.5" />
                Approve all
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// Table Security
function TableSecurity({ table }: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const queryClient = useQueryClient()

  // Fetch full table data
  const { table: tableData, isLoading: tableLoading } = useProjectTable(
    projectId,
    databaseId,
    tableId,
  )

  // State for Permissions
  const [tablePermissions, setTablePermissions] = useState<string[]>([])

  // State for Security
  const [tableRowSecurity, setTableRowSecurity] = useState<boolean | null>(null)

  // Initialize state from table data
  useEffect(() => {
    if (tableData) {
      // Always sync permissions from table data to ensure we have the latest
      const tablePerms = tableData.$permissions || []
      // Only update if permissions actually changed (avoid unnecessary re-renders)
      const currentPermsStr = JSON.stringify([...tablePermissions].sort())
      const newPermsStr = JSON.stringify([...tablePerms].sort())
      if (currentPermsStr !== newPermsStr) {
        setTablePermissions(tablePerms)
      }
      if (tableRowSecurity === null) setTableRowSecurity(tableData.rowSecurity)
    }
  }, [tableData, tablePermissions, tableRowSecurity])

  // Helper to check if arrays are different
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Handle permissions change from PermissionsEditor
  const handlePermissionsChange = (newPermissions: string[]) => {
    setTablePermissions(newPermissions)
  }

  // Update Permissions mutation
  const updatePermissionsMutation = useMutation({
    mutationFn: async (newPermissions: string[]) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, tableId, {
        name: tableData.name,
        permissions: newPermissions,
        rowSecurity: tableData.rowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      toast.success('Permissions have been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update permissions')
    },
  })

  // Update Security mutation
  const updateSecurityMutation = useMutation({
    mutationFn: async (newRowSecurity: boolean) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, tableId, {
        name: tableData.name,
        permissions: tableData.$permissions || [],
        rowSecurity: newRowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      toast.success('Security has been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update security')
    },
  })

  if (tableLoading || !tableData) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading...</div>
      </div>
    )
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Permissions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Permissions
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Choose who can access your tables and rows.{' '}
              <a
                href="https://appwrite.io/docs/products/databases/permissions"
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary hover:underline"
              >
                Learn more
              </a>
              .
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <PermissionsEditor
              permissions={tablePermissions}
              onPermissionsChange={handlePermissionsChange}
              withCreate={true}
              projectId={projectId}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                arraysEqual(tablePermissions, tableData.$permissions || []) ||
                updatePermissionsMutation.isPending
              }
              onClick={() => {
                updatePermissionsMutation.mutate(tablePermissions)
              }}
            >
              Update
            </Button>
          </div>
        </div>

        {/* Row Level Security (RLS) */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Row level security (RLS)
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="security"
                  checked={tableRowSecurity ?? false}
                  onCheckedChange={(checked) => setTableRowSecurity(checked)}
                />
                <Label
                  htmlFor="security"
                  className="text-[13px] text-foreground"
                >
                  Row level security (RLS)
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-2">
              <p className="text-[13px] text-muted-foreground">
                When row security is enabled, users need{' '}
                <strong>both table permissions and row permissions</strong> to
                access rows. Row permissions are an additional layer, not an
                alternative to table permissions.
              </p>
              <p className="text-[13px] text-muted-foreground">
                <strong>Create operations</strong> always require table-level
                permissions, regardless of row security settings.
              </p>
              <p className="text-[13px] text-muted-foreground">
                If row security is disabled, users can access rows{' '}
                <strong>only if they have table permissions</strong>. Row
                permissions will be ignored.
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                tableRowSecurity === tableData.rowSecurity ||
                updateSecurityMutation.isPending
              }
              onClick={() => {
                if (
                  tableRowSecurity !== null &&
                  tableRowSecurity !== tableData.rowSecurity
                ) {
                  updateSecurityMutation.mutate(tableRowSecurity)
                }
              }}
            >
              Update
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

// Table Settings
function TableSettings({ table }: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const organizationId = account?.prefs?.organization as string | undefined

  // Fetch full table data
  const { table: tableData, isLoading: tableLoading } = useProjectTable(
    projectId,
    databaseId,
    tableId,
  )
  const { columns: tableColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    tableId,
  )

  // State for Update Status
  const [enabled, setEnabled] = useState<boolean | null>(null)

  // State for Update Name
  const [tableName, setTableName] = useState('')

  // State for Display Names
  const [displayNames, setDisplayNames] = useState<string[]>(['$id'])

  // State for Delete
  const [showDelete, setShowDelete] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  // Initialize state from table data
  useEffect(() => {
    if (tableData) {
      if (enabled === null) setEnabled(tableData.enabled)
      if (!tableName) setTableName(tableData.name)
    }
  }, [tableData, enabled, tableName])

  // Load display names from preferences
  useEffect(() => {
    if (organizationId && tableId) {
      const loadDisplayNames = async () => {
        try {
          const team = await sdk.forConsole.teams.get({
            teamId: organizationId,
          })
          const prefs = team.prefs || {}
          const savedNames = prefs.displayNames?.[tableId] as
            | string[]
            | undefined
          if (savedNames && savedNames.length > 0) {
            setDisplayNames(savedNames)
          }
        } catch {
          // Silently handle display names loading error
        }
      }
      loadDisplayNames()
    }
  }, [organizationId, tableId])

  // Helper to check if arrays are different
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Update Status mutation
  const toggleTableMutation = useMutation({
    mutationFn: async (newEnabled: boolean) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, tableId, {
        name: tableData.name,
        permissions: tableData.$permissions || [],
        rowSecurity: tableData.rowSecurity,
        enabled: newEnabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${tableData?.name || 'Table'} has been updated`)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update table')
    },
  })

  // Update Name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (newName: string) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, tableId, {
        name: newName,
        permissions: tableData.$permissions || [],
        rowSecurity: tableData.rowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success('Name has been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update name')
    },
  })

  // Update Display Names mutation
  const updateDisplayNamesMutation = useMutation({
    mutationFn: async (names: string[]) => {
      if (!organizationId) throw new Error('Organization ID not available')
      const team = await sdk.forConsole.teams.get({ teamId: organizationId })
      const prefs = team.prefs || {}
      const updatedPrefs = {
        ...prefs,
        displayNames: {
          ...((prefs.displayNames as Record<string, string[]>) || {}),
          [tableId]: names,
        },
      }
      await sdk.forConsole.teams.updatePrefs({
        teamId: organizationId,
        prefs: updatedPrefs,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams', 'console'] })
      toast.success('Display names have been updated')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update display names')
    },
  })

  // Delete mutation
  const deleteTableMutation = useMutation({
    mutationFn: async () => {
      return await deleteProjectTable(projectId, databaseId, tableId)
    },
    onSuccess: async () => {
      // Delete table preferences
      if (organizationId) {
        try {
          const team = await sdk.forConsole.teams.get({
            teamId: organizationId,
          })
          const prefs = team.prefs || {}
          const updatedPrefs = { ...prefs }
          if (updatedPrefs.displayNames) {
            delete (updatedPrefs.displayNames as Record<string, unknown>)[
              tableId
            ]
          }
          if (updatedPrefs.tables) {
            delete (updatedPrefs.tables as Record<string, unknown>)[tableId]
          }
          if (updatedPrefs.columnOrder) {
            delete (updatedPrefs.columnOrder as Record<string, unknown>)[
              tableId
            ]
          }
          if (updatedPrefs.columnWidths) {
            delete (updatedPrefs.columnWidths as Record<string, unknown>)[
              tableId
            ]
            delete (updatedPrefs.columnWidths as Record<string, unknown>)[
              `${tableId}#columns`
            ]
            delete (updatedPrefs.columnWidths as Record<string, unknown>)[
              `${tableId}#indexes`
            ]
          }
          await sdk.forConsole.teams.updatePrefs({
            teamId: organizationId,
            prefs: updatedPrefs,
          })
        } catch {
          // Silently handle preference deletion error
        }
      }

      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${tableData?.name || 'Table'} has been deleted`)
      setShowDelete(false)
      setDeleteError(null)

      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId: '-' },
        replace: true,
      })
    },
    onError: (error: Error) => {
      setDeleteError(error.message || 'Failed to delete table')
    },
  })

  if (tableLoading || !tableData) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading...</div>
      </div>
    )
  }

  // Get valid text-type columns for display names (string, varchar, text, mediumtext, longtext; not array)
  const validStringColumns = tableColumns.filter(
    (col: unknown) => isTextType(col.type) && col.array === false,
  )

  // Filter display name options (exclude already selected except current)
  const getDisplayNameOptions = (index: number) => {
    return validStringColumns
      .filter((col: unknown) => {
        const key = col.key
        // Include if not selected elsewhere, or if it's the current selection
        return !displayNames.some((name, idx) => name === key && idx !== index)
      })
      .map((col: unknown) => ({
        value: col.key,
        label: col.key,
      }))
  }

  const handleAddDisplayNameColumn = () => {
    if (displayNames.length < 5) {
      setDisplayNames([...displayNames, ''])
    }
  }

  const handleRemoveDisplayNameColumn = (index: number) => {
    if (displayNames.length > 1 && index > 0) {
      const newNames = [...displayNames]
      newNames.splice(index, 1)
      setDisplayNames(newNames)
    }
  }

  const handleDisplayNameChange = (index: number, value: string) => {
    const newNames = [...displayNames]
    newNames[index] = value
    setDisplayNames(newNames)
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Status */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {tableData.name}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="toggle"
                  checked={enabled ?? false}
                  onCheckedChange={(checked) => setEnabled(checked)}
                />
                <Label htmlFor="toggle" className="text-[13px] text-foreground">
                  {enabled ? 'Enabled' : 'Disabled'}
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-1">
              <p className="text-[13px] text-muted-foreground">
                Created:{' '}
                <DateTooltip
                  date={tableData.$createdAt}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
              <p className="text-[13px] text-muted-foreground">
                Last updated:{' '}
                <DateTooltip
                  date={tableData.$updatedAt}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                enabled === tableData.enabled || toggleTableMutation.isPending
              }
              onClick={() => {
                if (enabled !== null && enabled !== tableData.enabled) {
                  toggleTableMutation.mutate(enabled)
                }
              }}
            >
              Update
            </Button>
          </div>
        </div>

        {/* Update Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">Name</h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Input
              id="name"
              value={tableName}
              onChange={(e) => setTableName(e.target.value)}
              placeholder="Enter name"
              autoComplete="off"
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                tableName === tableData.name ||
                !tableName.trim() ||
                updateNameMutation.isPending
              }
              onClick={() => {
                if (tableName.trim() && tableName !== tableData.name) {
                  updateNameMutation.mutate(tableName.trim())
                }
              }}
            >
              Update
            </Button>
          </div>
        </div>

        {/* Display Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Display name
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Select up to 5 string columns to display as row names in the
              Appwrite console. These help identify rows in places like
              relationships.
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-3">
            {displayNames.map((name, index) => (
              <div key={index} className="flex items-center gap-2">
                {index === 0 ? (
                  <>
                    <Input
                      value="Row ID"
                      readOnly
                      className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                    />
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-9 w-9 p-0 invisible"
                      disabled
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </>
                ) : (
                  <>
                    <Select
                      value={name || ''}
                      onValueChange={(value) =>
                        handleDisplayNameChange(index, value)
                      }
                    >
                      <SelectTrigger className="h-9 w-[200px] border-border bg-background text-[13px]">
                        <SelectValue placeholder="Select column" />
                      </SelectTrigger>
                      <SelectContent>
                        {getDisplayNameOptions(index).map(
                          (option: { value: string; label: string }) => (
                            <SelectItem key={option.value} value={option.value}>
                              {option.label}
                            </SelectItem>
                          ),
                        )}
                      </SelectContent>
                    </Select>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-9 w-9 p-0"
                      onClick={() => handleRemoveDisplayNameColumn(index)}
                      disabled={displayNames.length === 1}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </>
                )}
              </div>
            ))}
            {displayNames.length < 5 &&
              validStringColumns.length >
                displayNames.filter((n) => n && n !== '$id').length && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleAddDisplayNameColumn}
                  disabled={displayNames[displayNames.length - 1] === ''}
                >
                  <Plus className="h-4 w-4 mr-1.5" />
                  Add column
                </Button>
              )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                (() => {
                  try {
                    const saved = (account?.prefs as unknown)?.displayNames?.[
                      tableId
                    ] as string[] | undefined
                    const savedNames =
                      saved && saved.length > 0 ? saved : ['$id']
                    const currentNames = displayNames.filter(Boolean)
                    return arraysEqual(currentNames, savedNames)
                  } catch {
                    return arraysEqual(displayNames.filter(Boolean), ['$id'])
                  }
                })() ||
                displayNames[displayNames.length - 1] === '' ||
                updateDisplayNamesMutation.isPending
              }
              onClick={() => {
                const namesToSave = displayNames.filter(Boolean)
                if (namesToSave.length > 0) {
                  updateDisplayNamesMutation.mutate(namesToSave)
                }
              }}
            >
              Update
            </Button>
          </div>
        </div>

        {/* Danger Zone */}
        <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-red-600 dark:text-red-400">
              Delete table
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              The table will be permanently deleted, including all the rows
              within it. This action is irreversible.
            </p>
          </div>
          <div className="border-t border-red-500/20" />
          <div className="px-6 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <Table2 className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-[14px] font-medium text-foreground truncate">
                  {tableData.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  Last updated:{' '}
                  <DateTooltip
                    date={tableData.$updatedAt}
                    showFormattedDate
                    className="text-foreground"
                  />
                </p>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-red-500/20 bg-red-500/5">
            <Dialog open={showDelete} onOpenChange={setShowDelete}>
              <DialogTrigger asChild>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                >
                  Delete
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-left">
                  <DialogTitle>Delete table</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Are you sure you want to delete{' '}
                    <strong>{tableData.name}</strong>? This action cannot be
                    undone.
                  </DialogDescription>
                </DialogHeader>
                {deleteError && (
                  <div className="px-6 pt-4">
                    <p className="text-[13px] text-destructive">
                      {deleteError}
                    </p>
                  </div>
                )}
                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      setShowDelete(false)
                      setDeleteError(null)
                    }}
                    disabled={deleteTableMutation.isPending}
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      deleteTableMutation.mutate()
                    }}
                    disabled={deleteTableMutation.isPending}
                  >
                    Delete
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        </div>
      </div>
    </div>
  )
}
