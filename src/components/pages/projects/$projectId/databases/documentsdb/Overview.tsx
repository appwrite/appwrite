// Database-level overview (tabs: tables list, visualizer, monitor, …) - per product copy.
import { cn } from '@/lib/utils'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Database,
  Plus,
  Layers,
  Table2,
  CheckCircle2,
  AlertCircle,
  ExternalLink,
  Download,
  FileJson,
  FileText,
  Copy,
} from 'lucide-react'
import { formatNumber } from '@/lib/utils/mock-data'
import { useState, useEffect, useMemo } from 'react'

import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectDatabase,
  useProjectTables,
  deleteProjectTable,
  useProject,
  useOrganizationScopes,
  createProjectTable,
  invalidateDatabaseModel,
  updateProjectDatabase,
  deleteProjectDatabase,
  refetchProjectDatabaseLists,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

import { BackupsView } from '../Backups'
import { ExportImportView } from '../ExportImportView'
import { CreateTable } from '../CreateTable'
import { TableContextMenu } from '../_components/TableContextMenu'
import { DatabaseMonitorView } from '../_components/DatabaseMonitorView'
import {
  DatabaseMonitorHeaderActions,
  getDefaultMonitorDateRange,
} from '../_components/DatabaseMonitorHeaderActions'
import { DatabaseMonitorMobileNav } from '../_components/DatabaseMonitorMobileNav'
import type { DateRange } from 'react-day-picker'
import { SchemaVisualizer } from './SchemaVisualizer'
import { SchemaExportDialog } from '../SchemaExport'
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
import {
  canShowTableSecuritySettings,
  canShowDatabaseSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  formatDedicatedDatabaseRegionUnavailableDescription,
  projectSupportsDedicatedDatabaseCompute,
} from '@/lib/databases/dedicated-database-regions'
import { DedicatedDatabaseRegionUnavailableBadge } from '../_components/DedicatedDatabaseRegionUnavailableCard'
import {
  SERVERLESS_DATABASE_SPEC_ID,
  TABLE_DB_SPEC_OPTIONS,
  hasLockedDatabaseSpecifications,
} from '@/lib/database-specs'
import { SpecificationsUpgradeNote } from '@/components/global/shared/SpecificationsUpgradeNote'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import type { Models } from '@appwrite.io/console'
import { dbNavLink, type DatabaseRouteKind } from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'

import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'

import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
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
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import {
  Link,
  useNavigate,
  useParams,
  useLocation,
} from '@tanstack/react-router'

import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useT } from '@/lib/i18n/translate'

// Overview - database details with tabs (tables, backups, settings, …)
export interface OverviewProps {
  databaseId: string
  activeTab:
    | 'tables'
    | 'backups'
    | 'export-import'
    | 'security'
    | 'settings'
    | 'visualizer'
    | 'monitor'
    | 'browser'
  /** When true, only the tab content is rendered (no header). Used when embedded in Workspace. */
  contentOnly?: boolean
  /** Chart state when Monitor is embedded in Workspace (header/actions live in Workspace). */
  monitorEmbed?: {
    dateRange: DateRange
    chartTick: number
  }
}

const CURRENT_TIER_ID = SERVERLESS_DATABASE_SPEC_ID

const DB_KIND = 'documentsdb' as const satisfies DatabaseRouteKind

export function Overview({
  databaseId,
  activeTab,
  contentOnly = false,
  monitorEmbed,
}: OverviewProps) {
  const t = useT()
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const location = useLocation()
  const { features } = useConsoleProfile()
  useScrollToCard()
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
  const [localMonitorDateRange, setLocalMonitorDateRange] = useState<DateRange>(
    () => getDefaultMonitorDateRange(),
  )
  const [localMonitorChartTick, setLocalMonitorChartTick] = useState(0)

  const monitorDateRange = monitorEmbed?.dateRange ?? localMonitorDateRange
  const monitorChartTick = monitorEmbed?.chartTick ?? localMonitorChartTick

  // Fetch database
  const { database, isLoading: databaseLoading } = useProjectDatabase(
    projectId,
    databaseId,
  )

  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const ContainerListIcon =
    dbLabels.sdkListContainersMethod === 'listCollections' ? Layers : Table2
  const overviewDbNav = useMemo(() => dbNavLink(DB_KIND), [])

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
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      toast.success(t('Schema copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy schema'))
    }
  }

  const handleCopyMarkdown = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      toast.success(t('Schema copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy schema'))
    }
  }

  const handleExportSVG = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const svg = formatSchemaAsSVG(databaseSchema)
      const filename = `database-schema-${databaseId}.svg`
      downloadAsFile(svg, filename, 'image/svg+xml')
      toast.success(t('Schema exported as SVG'))
    } catch {
      toast.error(t('Failed to export SVG'))
    }
  }

  // Open in AI tools using deep links
  const handleOpenInChatGPT = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info(t('Loading schema...'))
      return
    }
    try {
      const deepLink = getChatGPTDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success(t('Opening ChatGPT with schema context...'))
    } catch {
      toast.error(t('Failed to open ChatGPT'))
    }
  }

  const handleOpenInClaude = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info(t('Loading schema...'))
      return
    }
    try {
      const deepLink = getClaudeDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success(t('Opening Claude with schema context...'))
    } catch {
      toast.error(t('Failed to open Claude'))
    }
  }

  const handleOpenInCursor = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info(t('Loading schema...'))
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
        toast.success(t('Opening Cursor with schema context...'))
      } catch {
        toast.success(t('Schema copied to clipboard. Paste it in Cursor.'))
      }
    } catch {
      toast.error(t('Failed to open Cursor'))
    }
  }

  const handleOpenInLovable = async () => {
    if (!databaseSchema) {
      setExportDialogOpen(true)
      toast.info(t('Loading schema...'))
      return
    }
    try {
      const deepLink = getLovableDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      window.open(deepLink, '_blank')
      toast.success(t('Opening Lovable with schema context...'))
    } catch {
      toast.error(t('Failed to open Lovable'))
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

      await updateProjectDatabase(projectId, databaseId, {
        name: trimmedName,
      })
    },
    onSuccess: () => {
      // Invalidate database query to refetch with updated name
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.invalidateQueries({
        queryKey: ['database', 'project', projectId, databaseId],
      })
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success(t('Database name updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update database name'))
    },
  })

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !databaseId || !database)
        throw new Error('Project ID, Database ID, and Database are required')
      return await updateProjectDatabase(projectId, databaseId, {
        name: database.name,
        enabled,
      })
    },
    onSuccess: () => {
      toast.success(
        enabled ? t('Database has been enabled') : t('Database has been disabled'),
      )
      invalidateDatabaseModel(projectId, databaseId)
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
      await deleteProjectDatabase(projectId, databaseId)
    },
    onSuccess: async () => {
      invalidateDatabaseModel(projectId, databaseId)
      await refetchProjectDatabaseLists(queryClient, projectId)
      toast.success(t('Database deleted successfully'))

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
      toast.error(error.message || t('Failed to delete database'))
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  const { project } = useProject(projectId)
  const supportsDedicatedDatabaseCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const { access } = useOrganizationScopes(project?.teamId)
  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )
  const noCreateTablePermission = !canShowTableSecuritySettings(
    access,
    features,
  )
  const showTableSecuritySettings = canShowTableSecuritySettings(
    access,
    features,
  )

  // Redirect from monitor/backups when feature disabled
  useEffect(() => {
    if (
      (activeTab === 'monitor' && !features.usageStats) ||
      (activeTab === 'backups' && !features.databaseBackups)
    ) {
      navigate({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: { projectId, dbKind: DB_KIND, databaseId },
        replace: true,
      })
    }
  }, [
    activeTab,
    features.usageStats,
    features.databaseBackups,
    projectId,
    databaseId,
    DB_KIND,
    navigate,
  ])

  // Redirect from security/settings when user lacks permission
  useEffect(() => {
    if (
      !showDbSecuritySettings &&
      (activeTab === 'security' || activeTab === 'settings')
    ) {
      navigate({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: { projectId, dbKind: DB_KIND, databaseId },
        replace: true,
      })
    }
  }, [
    showDbSecuritySettings,
    activeTab,
    projectId,
    databaseId,
    DB_KIND,
    navigate,
  ])

  useEffect(() => {
    setLocalMonitorDateRange(getDefaultMonitorDateRange())
    setLocalMonitorChartTick(0)
  }, [databaseId])

  const databaseTabs: Tab[] = useMemo(
    () =>
      [
        {
          id: 'tables',
          label: dbLabels.databaseOverviewTabLabel,
          to: '/projects/$projectId/databases/$dbKind/$databaseId/',
          params: { projectId, dbKind: DB_KIND, databaseId },
        },
        {
          id: 'visualizer',
          label: 'Visualizer',
          to: '/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
          params: { projectId, dbKind: DB_KIND, databaseId },
        },
        ...(features.usageStats
          ? [
              {
                id: 'monitor' as const,
                label: 'Monitor',
                to: '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
                params: { projectId, dbKind: DB_KIND, databaseId },
              },
            ]
          : []),
        ...(showDbSecuritySettings
          ? [
              {
                id: 'security' as const,
                label: 'Security',
                to: '/projects/$projectId/databases/$dbKind/$databaseId/db-security',
                params: { projectId, dbKind: DB_KIND, databaseId },
              },
            ]
          : []),
        ...(features.databaseBackups
          ? [
              {
                id: 'backups' as const,
                label: 'Backups',
                to: '/projects/$projectId/databases/$dbKind/$databaseId/backups',
                params: { projectId, dbKind: DB_KIND, databaseId },
              },
            ]
          : []),
        {
          id: 'export-import',
          label: 'Export / Import',
          to: '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
          params: { projectId, dbKind: DB_KIND, databaseId },
        },
        ...(showDbSecuritySettings
          ? [
              {
                id: 'settings' as const,
                label: 'Settings',
                to: '/projects/$projectId/databases/$dbKind/$databaseId/settings',
                params: { projectId, dbKind: DB_KIND, databaseId },
              },
            ]
          : []),
      ] as Tab[],
    [
      projectId,
      databaseId,
      DB_KIND,
      dbLabels.databaseOverviewTabLabel,
      features.usageStats,
      features.databaseBackups,
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
      toast.success(`${table.name} ${t('has been created')}`)
      // Refetch tables and wait for it to complete before navigating
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      navigate({
        ...overviewDbNav.dataGrid({
          projectId: projectId!,
          dbKind: DB_KIND,
          databaseId: databaseId!,
          resourceId: table.$id,
        }),
      })
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) || dbLabels.failedToCreateContainer,
      )
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
        `${t('Successfully deleted')} ${selectedTables.size} ${
          selectedTables.size === 1
            ? dbLabels.containerSingular
            : dbLabels.containerPlural
        }`,
      )
      setSelectedTables(new Set())
      setBulkDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(
        error.message ||
          dbLabels.failedToDeleteContainers,
      )
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
        <div className="text-muted-foreground">{t('Loading database...')}</div>
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
          <Button variant="link" onClick={handleBack}>
            {t('Back to databases')}
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
            <DetailResourceHeaderTitle
              kind="database"
              label={database.name}
              resourceId={database.$id}
              projectId={projectId}
              back={{
                onClick: handleBack,
                'aria-label': 'Back to databases',
              }}
            />
          }
          tabs={databaseTabs}
          activeTab={activeTab}
          searchPlaceholder={
            activeTab === 'tables'
              ? dbLabels.searchContainersPlaceholder
              : undefined
          }
          searchValue={activeTab === 'tables' ? searchValue : ''}
          onSearchChange={
            activeTab === 'tables' ? handleSearchChange : undefined
          }
          createLabel={
            activeTab === 'tables' ? dbLabels.createContainer : undefined
          }
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
                    <TooltipContent side="bottom">{t('Copy schema')}</TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem onClick={handleCopyJSON}>
                      <FileJson className="h-4 w-4 me-2" />
                      {t('Copy as JSON')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleCopyMarkdown}>
                      <FileText className="h-4 w-4 me-2" />
                      {t('Copy as Markdown')}
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
                  <TooltipContent side="bottom">{t('Export as SVG')}</TooltipContent>
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
                    <TooltipContent side="bottom">{t('Open in...')}</TooltipContent>
                  </Tooltip>
                  <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem onClick={handleOpenInChatGPT}>
                      <img
                        src="/icons/chatgpt.svg"
                        alt="ChatGPT"
                        className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      ChatGPT
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInClaude}>
                      <img
                        src="/icons/claude.svg"
                        alt="Claude"
                        className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      Claude
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInCursor}>
                      <img
                        src="/icons/cursor-ai.svg"
                        alt="Cursor"
                        className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
                      />
                      Cursor
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={handleOpenInLovable}>
                      <img
                        src="/icons/lovable.svg"
                        alt="Lovable"
                        className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
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
          fullWidth={activeTab === 'monitor'}
          titleRightContent={
            activeTab === 'monitor' ? (
              <DatabaseMonitorHeaderActions
                dateRange={localMonitorDateRange}
                onDateRangeChange={(r) =>
                  setLocalMonitorDateRange(r ?? getDefaultMonitorDateRange())
                }
                onRefresh={() => setLocalMonitorChartTick((n) => n + 1)}
              />
            ) : undefined
          }
          contentAfterBorder={
            <>
              {activeTab === 'monitor' ? (
                <div className="border-b border-border px-4 py-3 sm:px-6 lg:hidden">
                  <DatabaseMonitorMobileNav
                    projectId={projectId}
                    databaseId={databaseId}
                  />
                </div>
              ) : null}
              {database && (database as Models.Database).enabled === false ? (
                <div className="border-b border-border bg-amber-500/5">
                  <div
                    className={cn(
                      'w-full px-4 py-3 sm:px-6',
                      activeTab !== 'monitor' && 'mx-auto max-w-7xl',
                    )}
                  >
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
                              projectId: projectId!,
                              dbKind: DB_KIND,
                              databaseId: databaseId,
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
              ) : null}
            </>
          }
        />
      )}

      <div
        className={cn(
          'flex-1 min-h-0 flex flex-col',
          activeTab === 'monitor'
            ? 'min-h-0 overflow-hidden'
            : 'overflow-y-auto',
        )}
      >
        {activeTab === 'tables' && (
          <div className="mx-auto w-full max-w-7xl px-4 pt-4 pb-4 sm:px-6 sm:pt-6 sm:pb-6">
            {showTablesLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {dbLabels.loadingContainersLabel}
                </div>
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
                          {dbLabels.containerSingularTitle}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                          {dbLabels.schemaPluralTitle}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                          {dbLabels.recordPluralTitle}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                          {t('Indexes')}
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
                              ...overviewDbNav.dataGrid({
                                projectId,
                                dbKind: DB_KIND,
                                databaseId,
                                resourceId: table.$id,
                              }),
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
                              dbKind={DB_KIND}
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
                                {...overviewDbNav.dataGrid({
                                  projectId,
                                  dbKind: DB_KIND,
                                  databaseId,
                                  resourceId: table.$id,
                                })}
                                className="block group"
                              >
                                <div className="flex items-center gap-3 min-w-0">
                                  <ContainerListIcon className="h-4 w-4 text-muted-foreground/60 shrink-0" />
                                  <div className="flex-1 min-w-0">
                                    <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
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
                                      {t('Disabled')}
                                    </Badge>
                                  )}
                                </div>
                              </Link>
                            </TableContextMenu>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              {...overviewDbNav.dataGrid({
                                projectId,
                                dbKind: DB_KIND,
                                databaseId,
                                resourceId: table.$id,
                              })}
                              className="block text-end"
                            >
                              <span className="text-[12px] text-foreground font-mono">
                                {table.columns}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              {...overviewDbNav.dataGrid({
                                projectId,
                                dbKind: DB_KIND,
                                databaseId,
                                resourceId: table.$id,
                              })}
                              className="block text-end"
                            >
                              <span className="text-[12px] text-muted-foreground font-mono">
                                {formatNumber(table.rows)}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              {...overviewDbNav.dataGrid({
                                projectId,
                                dbKind: DB_KIND,
                                databaseId,
                                resourceId: table.$id,
                              })}
                              className="block text-end"
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
                  itemLabel={dbLabels.paginationItemLabel}
                  className="py-2"
                />

                {/* Bulk Delete Action Bar */}
                {selectedTables.size > 0 && (
                  <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
                    <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                      <Badge variant="secondary" className="h-6 px-2.5">
                        {selectedTables.size}{' '}
                        {selectedTables.size === 1
                          ? dbLabels.containerSingular
                          : dbLabels.containerPlural}{' '}
                        {t('selected')}
                      </Badge>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setSelectedTables(new Set())}
                          className="h-8 text-xs"
                        >
                          {t('Cancel')}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={handleBulkDeleteTables}
                          disabled={bulkDeleteTablesMutation.isPending}
                          className="h-8 gap-2"
                        >
                          {t('Delete')}
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
                    <DialogHeader className="px-6 pt-6 text-start">
                      <DialogTitle>
                        {t('Delete')} {dbLabels.containerPluralTitle}
                      </DialogTitle>
                      <DialogDescription className="text-[13px] mt-2">
                        {t('Are you sure you want to delete')}{' '}
                        {selectedTables.size}{' '}
                        {selectedTables.size === 1
                          ? dbLabels.containerSingular
                          : dbLabels.containerPlural}
                        ? {t('This action cannot be undone.')}
                      </DialogDescription>
                    </DialogHeader>

                    <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                      <Button
                        variant="outline"
                        onClick={() => setBulkDeleteDialogOpen(false)}
                        disabled={bulkDeleteTablesMutation.isPending}
                      >
                        {t('Cancel')}
                      </Button>
                      <Button
                        variant="destructive"
                        onClick={confirmBulkDeleteTables}
                        disabled={bulkDeleteTablesMutation.isPending}
                      >
                        {t('Delete')}
                      </Button>
                    </div>
                  </DialogContent>
                </Dialog>
              </>
            ) : searchValue ? (
              <EmptyState
                icon={ContainerListIcon}
                isEmpty={false}
                hasFilters={true}
                variant="card"
              />
            ) : (
              <EmptyState
                icon={ContainerListIcon}
                title={dbLabels.emptyContainersTitle}
                description={dbLabels.emptyContainersDescription}
                isEmpty={true}
                variant="card"
              >
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <ContainerListIcon className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <p className="mb-1 text-[14px] font-medium text-foreground">
                    {dbLabels.emptyContainersTitle}
                  </p>
                  <p className="mb-4 text-[13px] text-muted-foreground">
                    {dbLabels.emptyContainersDescription}
                  </p>
                  <Button
                    onClick={() => setCreateTableDialogOpen(true)}
                    className="gap-1.5"
                  >
                    <Plus className="h-4 w-4" />
                    {dbLabels.createContainer}
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

        {activeTab === 'monitor' && (
          <div className="flex min-h-0 flex-1 flex-col">
            <DatabaseMonitorView
              databaseId={databaseId}
              dateRange={monitorDateRange}
              chartTick={monitorChartTick}
            />
          </div>
        )}

        {activeTab === 'security' && (
          <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
            <div className="space-y-6">
              {/* Permissions */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('Permissions')}
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    {t(
                      'Permissions are configured at the collection or document level. You can select the permission model for each collection in its settings. When document level security is enabled, you can also modify permissions per document when updating individual documents.',
                    )}
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
                    {t('Name')}
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    {t("Update your database's display name. This will be visible to all organization members.")}
                  </p>
                  <Input
                    value={databaseName}
                    onChange={(e) => setDatabaseName(e.target.value)}
                    placeholder={t('Database name')}
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
                    {t('Update')}
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
                      <span className="ms-1.5">
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
                    {t('Update')}
                  </Button>
                </div>
              </div>

              {features.dedicatedDbsDocumentsDB &&
                (supportsDedicatedDatabaseCompute ? (
                <div
                  data-card-id="specification"
                  className="rounded-xl border border-border bg-card/50 overflow-hidden"
                >
                  <div className="px-6 py-4">
                    <h3 className="text-[15px] font-semibold text-foreground">
                      {t('Specification')}
                    </h3>
                    <p className="text-[13px] text-muted-foreground mt-2">
                      {t('Current tier: Serverless. Dedicated tiers are coming soon.')}
                    </p>
                  </div>
                  <div className="border-t border-border" />
                  <div className="px-6 py-4">
                    <div className="rounded-lg border border-border overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border bg-muted/40">
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {t('Tier')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              CPU
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {t('Memory')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {t('Connections')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[180px]">
                              {t('Price')}
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {TABLE_DB_SPEC_OPTIONS.map((spec) => {
                            const isCurrent = spec.id === CURRENT_TIER_ID
                            const locked = spec.comingSoon === true
                            return (
                              <TableRow
                                key={spec.id}
                                className={cn(
                                  'border-b border-border last:border-b-0 transition-colors',
                                  isCurrent && 'bg-primary/5',
                                )}
                              >
                                <TableCell className="px-4 py-3">
                                  <span className="flex items-center gap-2 flex-wrap">
                                    <span className="text-[13px] font-medium text-foreground">
                                      {spec.label}
                                    </span>
                                    {isCurrent && (
                                      <Badge
                                        variant="success"
                                        className="gap-1 text-[10px] shrink-0"
                                      >
                                        <CheckCircle2 className="h-3 w-3" />
                                        {t('Current')}
                                      </Badge>
                                    )}
                                    {locked && (
                                      <Badge
                                        variant="inactive"
                                        className="text-[10px] shrink-0"
                                      >
                                        {t('Coming soon')}
                                      </Badge>
                                    )}
                                  </span>
                                </TableCell>
                                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                                  {spec.cpu}
                                </TableCell>
                                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                                  {spec.memory}
                                </TableCell>
                                <TableCell className="px-4 py-3 text-[13px] tabular-nums text-muted-foreground">
                                  {spec.connections}
                                </TableCell>
                                <TableCell className="px-4 py-3 text-end">
                                  {locked ? (
                                    <span className="text-[13px] text-muted-foreground">
                                      {spec.price}
                                    </span>
                                  ) : (
                                    <span className="text-[13px] font-medium tabular-nums text-foreground">
                                      {spec.price}
                                    </span>
                                  )}
                                </TableCell>
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                    {hasLockedDatabaseSpecifications(TABLE_DB_SPEC_OPTIONS) && (
                      <div className="mt-3">
                        <SpecificationsUpgradeNote
                          orgId={project?.teamId}
                          showContactSales
                        />
                      </div>
                    )}
                  </div>
                </div>
                ) : (
                <div
                  data-card-id="specification"
                  className="rounded-xl border border-border bg-card/50 overflow-hidden opacity-80"
                >
                  <div className="px-6 py-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-[15px] font-semibold text-foreground">
                        {t('Specification')}
                      </h3>
                      <DedicatedDatabaseRegionUnavailableBadge />
                    </div>
                    <p className="text-[13px] text-muted-foreground mt-2">
                      {formatDedicatedDatabaseRegionUnavailableDescription(t)}
                    </p>
                  </div>
                </div>
                ))}

              {/* Delete Database */}
              <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {t('Delete database')}
                  </h3>
                </div>
                <div className="border-t border-destructive/20" />
                <div className="px-6 py-4">
                  <p className="text-[13px] text-muted-foreground">
                    {dbLabels.deleteDatabaseContainersDescription}
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
                          {tablesTotal}{' '}
                          {tablesTotal === 1
                            ? dbLabels.containerSingular
                            : dbLabels.containerPlural}
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
                        {t('Delete database')}
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-start">
                        <DialogTitle>{t('Delete Database')}</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          {t('Are you sure you want to delete')}{' '}
                          {database && (
                            <span className="font-medium text-foreground">
                              {database.name}
                            </span>
                          )}{' '}
                          {dbLabels.deleteDatabaseConfirmSuffix}
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
                          placeholder={t('Enter database name')}
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
                          {t('Cancel')}
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
                          {t('Delete')}
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
        variant="documents"
      />
    </div>
  )
}
