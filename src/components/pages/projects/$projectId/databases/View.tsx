import { cn } from '@/lib/utils'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Database,
  Plus,
  List,
  LayoutGrid,
  Settings,
  Key,
  Table2,
  ChevronLeft,
  ChevronDown,
  ChevronRight,
  MoreHorizontal,
  CheckCircle2,
  AlertCircle,
  ArrowUpDown,
  Calendar,
  Link2,
  Fingerprint,
  Archive,
  ArrowLeft,
  X,
  Check,
  BarChart3,
  Network,
  Sparkles,
  BookOpen,
  ExternalLink,
  Download,
  FileJson,
  FileText,
  Code,
  Copy,
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
import { useProjectDatabases, useProjectDatabase, useProjectTables, useProjectTableRows, useProjectTableColumns, deleteProjectTableRow, createProjectTableRows, createProjectTableRow, updateProjectTableRow, createProjectTableColumn, updateProjectTableColumn, deleteProjectTableColumn, useProjectTables as useTablesForColumns, useProjectTable, updateProjectTable, deleteProjectTable, useProject, useOrganizationPlan, fetchProjectDatabases } from '@/lib/react-query/hooks'
import { ColumnDrawer, ColumnFormData } from './tables/Column'
import { IndexDrawer, IndexFormData } from './tables/Index'
import { createProjectTableIndex, deleteProjectTableIndex, useProjectTableIndexes } from '@/lib/react-query/hooks'
import { BackupsView } from './Backups'
import { ComingSoonView } from '../shared/ComingSoon'
import { SchemaVisualizer } from './SchemaVisualizer'
import { SchemaExportDialog } from './SchemaExportDialog'
import { fetchDatabaseSchema, formatSchemaAsJSON, formatSchemaAsMarkdown, formatSchemaAsSVG, downloadAsFile, getCursorDeepLink, getLovableDeepLink, getChatGPTDeepLink, getClaudeDeepLink } from '@/lib/utils/database-schema-export'

interface IndexColumnEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { Query } from '@appwrite.io/console'
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
  DropdownMenuTrigger,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Link, useNavigate, useParams, useLocation } from '@tanstack/react-router'
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
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@/components/ui/alert'

// Reusable table styles for spreadsheet views
const stickyTheadClass =
  'sticky top-0 z-20 bg-background shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db] dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1)]'
const headerCellBorderClass = 'border-r border-gray-300 dark:border-border'
const bodyCellBorderClass =
  'border-b border-r border-gray-200 dark:border-border'
const lastCellBorderClass = 'border-b border-gray-200 dark:border-border'

// Main databases list view - used at /projects/:projectId/databases
export function DatabasesListView() {
  const { projectId } = useParams({
    from: '/_public/projects/$projectId/databases/',
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [selectedDatabases, setSelectedDatabases] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Convert 1-indexed page to 0-indexed for API
  const currentPageIndexed = currentPage - 1

  // Fetch databases from the project SDK
  const {
    databases: apiDatabases,
    total: databasesTotal,
    isLoading: databasesLoading,
  } = useProjectDatabases(projectId, currentPageIndexed, pageSize, searchValue)

  // Fetch total count of databases without search (for limit checking)
  // This is separate from the search query so the alert doesn't change when searching
  const {
    data: totalDatabasesData,
    isLoading: totalDatabasesLoading,
  } = useQuery({
    queryKey: ['databases', 'project', projectId, 'total'],
    queryFn: () => fetchProjectDatabases(projectId!, 0, 1, ''), // Only need total, so limit to 1
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
  })

  // Paginated data - databases are already paginated by the API
  const paginatedDatabases = apiDatabases

  // Get project to get teamId for organization plan
  const { project, isLoading: projectLoading } = useProject(projectId)
  
  // Get organization plan to check limits
  const { plan: organizationPlan, isLoading: planLoading } = useOrganizationPlan(project?.teamId)
  
  // Total count of all databases (without search) - for limit checking
  const totalDatabasesCount = totalDatabasesData?.total || 0
  
  // Check if create button should be disabled
  const databasesLimit = organizationPlan?.databases ?? 0
  const isCreateDisabled = databasesLimit > 0 && totalDatabasesCount >= databasesLimit

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedDatabases(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setCurrentPage(1)
    setSelectedDatabases(new Set()) // Clear selection on search change
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
        databaseIds.map((databaseId) => (projectSdk.tablesDB as any).delete(databaseId)),
      )
    },
    onSuccess: () => {
      // Invalidate and refetch databases
      queryClient.invalidateQueries({
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
      setSelectedDatabases(new Set(paginatedDatabases.map((db: DatabaseType) => db.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
    setSelectedDatabases(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
    setSelectedDatabases(new Set()) // Clear selection on page size change
  }

  const ViewToggle = () => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list'
            ? 'bg-background shadow-sm'
            : 'hover:bg-transparent',
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
          viewMode === 'grid'
            ? 'bg-background shadow-sm'
            : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title="Databases"
        searchPlaceholder="Search databases..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel="Create database"
        onCreate={() => console.log('Create database')}
        createDisabled={isCreateDisabled}
        showFilters={false}
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          <PlanLimitWarning
            currentCount={totalDatabasesCount}
            limit={databasesLimit}
            planName={organizationPlan?.name}
            resourceName="databases"
            orgId={project?.teamId}
            isLoading={projectLoading || planLoading || totalDatabasesLoading}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
        {databasesLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">Loading databases...</div>
              </div>
            ) : viewMode === 'list' ? (
              paginatedDatabases.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent">
                          <TableHead className="w-[40px]">
                            <Checkbox
                              checked={
                                paginatedDatabases.length > 0 &&
                                selectedDatabases.size === paginatedDatabases.length
                              }
                              onCheckedChange={toggleAllDatabases}
                            />
                          </TableHead>
                          <TableHead className="w-[180px]">Database ID</TableHead>
                          <TableHead className="w-[200px]">Name</TableHead>
                          <TableHead className="w-[150px]">Backups</TableHead>
                          <TableHead className="w-[120px]">Created</TableHead>
                          <TableHead className="w-[120px]">Updated</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedDatabases.map((db: DatabaseType & { createdAt?: string; updatedAt?: string; enabled?: boolean; hasBackupPolicy?: boolean; backupPolicy?: any; backupPolicyCount?: number }) => (
                          <TableRow
                            key={db.$id}
                            className={cn(
                              'cursor-pointer transition-colors',
                              selectedDatabases.has(db.$id)
                                ? 'bg-sky-100 dark:bg-sky-950'
                                : 'hover:bg-muted/50',
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
                                params: { projectId, databaseId: db.$id, tableId: '-' },
                              })
                            }}
                          >
                            <TableCell onClick={(e) => e.stopPropagation()}>
                              <Checkbox
                                checked={selectedDatabases.has(db.$id)}
                                onCheckedChange={() => toggleDatabase(db.$id)}
                              />
                            </TableCell>
                            <TableCell>
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{ projectId, databaseId: db.$id, tableId: '-' }}
                                className="block"
                              >
                                <CopyableId id={db.$id} size="xs" />
                              </Link>
                            </TableCell>
                            <TableCell>
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{ projectId, databaseId: db.$id, tableId: '-' }}
                                className="block"
                              >
                                <div className="flex items-center gap-2">
                                  <p className="text-[13px] font-medium text-foreground">
                                    {db.name}
                                  </p>
                                  {db.enabled === false && (
                                    <Badge variant="error" className="text-[11px]">
                                      Disabled
                                    </Badge>
                                  )}
                                </div>
                              </Link>
                            </TableCell>
                            <TableCell>
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{ projectId, databaseId: db.$id, tableId: '-' }}
                                className="block"
                              >
                                {(db as any).hasBackupPolicy ? (
                                  <Badge variant="success" className="gap-1.5 text-[11px] font-medium">
                                    <CheckCircle2 className="h-3 w-3" />
                                    {(db as any).backupPolicyCount > 0 
                                      ? `${(db as any).backupPolicyCount} ${(db as any).backupPolicyCount === 1 ? 'policy' : 'policies'}`
                                      : (db as any).backupPolicy?.name || 'Backup Enabled'}
                                  </Badge>
                                ) : (
                                  <Badge variant="warning" className="gap-1.5 text-[11px] font-medium">
                                    <AlertCircle className="h-3 w-3" />
                                    No backup policies
                                  </Badge>
                                )}
                              </Link>
                            </TableCell>
                            <TableCell>
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{ projectId, databaseId: db.$id, tableId: '-' }}
                                className="block"
                              >
                                <DateTooltip
                                  date={new Date((db as any).createdAt || new Date())}
                                  className="text-[12px] text-muted-foreground"
                                />
                              </Link>
                            </TableCell>
                            <TableCell>
                              <Link
                                to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                                params={{ projectId, databaseId: db.$id, tableId: '-' }}
                                className="block"
                              >
                                <DateTooltip
                                  date={new Date((db as any).updatedAt || (db as any).createdAt || new Date())}
                                  className="text-[12px] text-muted-foreground"
                                />
                              </Link>
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
              <Pagination
                currentPage={currentPage}
                totalItems={databasesTotal}
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
                  title="No databases found"
                  description="Create your first database to get started"
                  isEmpty={!searchValue}
                  hasFilters={!!searchValue}
                  variant="card"
                />
              )
            ) : (
              <>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {paginatedDatabases.map((db: DatabaseType & { createdAt?: string; updatedAt?: string; hasBackupPolicy?: boolean; backupPolicy?: any; backupPolicyCount?: number }) => (
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
                      statusLabel={db.enabled === false ? 'Disabled' : undefined}
                      metadata={[
                        {
                          label: '',
                          value: (db as any).hasBackupPolicy ? (
                            <Badge variant="success" className="gap-1.5 text-[11px] font-medium">
                              <CheckCircle2 className="h-3 w-3" />
                              {(db as any).backupPolicyCount > 0 
                                ? `${(db as any).backupPolicyCount} ${(db as any).backupPolicyCount === 1 ? 'policy' : 'policies'}`
                                : (db as any).backupPolicy?.name || 'Backup Enabled'}
                            </Badge>
                          ) : (
                            <Badge variant="warning" className="gap-1.5 text-[11px] font-medium">
                              <AlertCircle className="h-3 w-3" />
                              No backup policies
                            </Badge>
                          ),
                        },
                      ]}
                      onMenuClick={(e) => {
                        e.preventDefault()
                        e.stopPropagation()
                        console.log('Menu', db.$id)
                      }}
                    />
                  </Link>
                ))}

                  {paginatedDatabases.length === 0 && (
                    <div className="col-span-full">
                      <EmptyState
                        icon={Database}
                        title="No databases found"
                        description="Create your first database to get started"
                        isEmpty={!searchValue}
                        hasFilters={!!searchValue}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                <Pagination
                  currentPage={currentPage}
                  totalItems={databasesTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handlePageChange}
                  onPageSizeChange={handlePageSizeChange}
                  itemLabel="databases"
                />
              </>
            )}

        {/* Bulk Delete Action Bar */}
        {selectedDatabases.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDatabases.size} database{selectedDatabases.size > 1 ? 's' : ''} selected
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
                Are you sure you want to delete {selectedDatabases.size} database{selectedDatabases.size > 1 ? 's' : ''}? This action cannot be undone.
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

  const handleBackupsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/backups',
      params: { projectId, databaseId },
    })
  }

  const handleSettingsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/settings',
      params: { projectId, databaseId },
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
            className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
              {dbTables.map((table) => (
                <Link
                  key={table.$id}
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
              ))}

              {/* Create Table Button */}
              <button className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground">
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
            <Key className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Security</span>
          </Link>

          {/* Insights Link - Coming Soon */}
          <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground/50">
            <BarChart3 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px]">Insights</span>
            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
              Soon
            </span>
          </span>

          {/* Backups Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/backups"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Archive className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Backups</span>
          </Link>

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
            <div className="text-center">
              <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                <Table2 className="h-5 w-5 text-muted-foreground" />
              </div>
              <p className="mb-1 text-[14px] font-medium text-foreground">
                No tables yet
              </p>
              <p className="text-[13px] text-muted-foreground">
                Create your first table to get started
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

// Table View - shows table content with tabs
interface TableViewProps {
  databaseId: string
  tableId: string
  activeTab: 'rows' | 'columns' | 'indexes' | 'security' | 'settings'
}

export function TableView({ databaseId, tableId, activeTab }: TableViewProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const navigate = useNavigate()

  // Fetch database
  const { database, isLoading: databaseLoading } = useProjectDatabase(projectId, databaseId)

  // Fetch tables for the database
  const {
    tables: dbTables,
    isLoading: tablesLoading,
  } = useProjectTables(projectId, databaseId, 0, 100)
  
  // Sort tables by name in ascending order
  const sortedTables = useMemo(() => {
    return [...dbTables].sort((a, b) => {
      const nameA = a.name?.toLowerCase() || ''
      const nameB = b.name?.toLowerCase() || ''
      return nameA.localeCompare(nameB)
    })
  }, [dbTables])
  
  const selectedTable = dbTables.find((c) => c.$id === tableId)
  
  // Only show loading if we don't have data yet (account for prefetched data)
  const isActuallyLoading = (databaseLoading && !database) || (tablesLoading && dbTables.length === 0)
  const [searchValue, setSearchValue] = useState('')
  const [tablesExpanded, setTablesExpanded] = useState(true)
  const rowsRefetchRef = useRef<(() => Promise<any>) | null>(null)
  const openCreateRowDrawerRef = useRef<(() => void) | null>(null)
  const openCreateColumnDialogRef = useRef<(() => void) | null>(null)
  const openCreateIndexDialogRef = useRef<(() => void) | null>(null)
  const [isRefreshingRows, setIsRefreshingRows] = useState(false)
  const refreshStartTimeRef = useRef<number | null>(null)
  const minAnimationDuration = 1000 // 1 second for at least one full rotation
  const [hasRows, setHasRows] = useState(true) // Track if table has rows
  const [rowsTotal, setRowsTotal] = useState<number | undefined>(undefined) // Track total row count
  
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
    navigate({
      to: '/projects/$projectId/databases/$databaseId/tables',
      params: { projectId, databaseId },
    })
  }

  const handleBackupsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/backups',
      params: { projectId, databaseId },
    })
  }

  const handleSettingsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/settings',
      params: { projectId, databaseId },
    })
  }

  // Fetch columns to get the count for tabs
  const { columns: tableColumns } = useProjectTableColumns(projectId, databaseId, tableId)

  // Fetch full table data to check enabled status
  const { table: tableDataForStatus } = useProjectTable(projectId, databaseId, tableId)

  const tableTabs: Tab[] = [
    {
      id: 'rows',
      label: 'Rows',
      count: rowsTotal !== undefined ? rowsTotal : selectedTable?.rows,
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
      params: { projectId, databaseId, tableId },
    },
    {
      id: 'columns',
      label: 'Columns',
      count: tableColumns.length || selectedTable?.columns,
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/columns',
      params: { projectId, databaseId, tableId },
    },
    {
      id: 'indexes',
      label: 'Indexes',
      count: selectedTable?.indexes,
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/indexes',
      params: { projectId, databaseId, tableId },
    },
    {
      id: 'security',
      label: 'Security',
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/security',
      params: { projectId, databaseId, tableId },
    },
    {
      id: 'settings',
      label: 'Settings',
      to: '/projects/$projectId/databases/$databaseId/tables/$tableId/settings',
      params: { projectId, databaseId, tableId },
    },
  ]

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

  if (!database || !selectedTable) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <p className="text-[14px] font-medium text-foreground">
            {!database ? 'Database not found' : 'Table not found'}
          </p>
          <Button variant="link" onClick={!database ? handleBackToDatabases : handleBackToDatabase}>
            {!database ? 'Back to databases' : 'Back to database'}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="@container flex h-full">
      {/* Tables Sidebar - uses container query to show/hide based on available space */}
      <div className="hidden w-56 shrink-0 flex-col border-r border-border lg:flex">
        {/* Databases Header */}
        <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
          <button
            onClick={handleBackToDatabases}
            className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <span className="text-[13px] font-medium text-foreground">
            Databases
          </span>
        </div>

        {/* Tables List */}
        <div className="flex-1 overflow-y-auto p-2">
          {/* Database Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/tables"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Database className="h-3.5 w-3.5 shrink-0" />
            <span className="min-w-0 flex-1 truncate text-[13px]">
              {database.name}
            </span>
          </Link>

          {/* Tables Section Header */}
          <button
            onClick={() => setTablesExpanded(!tablesExpanded)}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Table2 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px] font-medium">Tables</span>
            <span className="text-[11px] text-muted-foreground">
              {dbTables.length}
            </span>
            {tablesExpanded ? (
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            )}
          </button>

          {/* Tables List (collapsible) */}
          {tablesExpanded && (
            <div className="ml-3 mt-0.5 border-l border-border pl-2">
              {sortedTables.map((table) => (
                <Link
                  key={table.$id}
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
              ))}

              {/* Create Table Button */}
              <button className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground">
                <Plus className="h-3.5 w-3.5 shrink-0" />
                <span className="text-[13px]">Create table</span>
              </button>
            </div>
          )}

          {/* Visualizer Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/visualizer"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Network className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Visualizer</span>
          </Link>

          {/* Security Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/security"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Key className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Security</span>
          </Link>

          {/* Insights Link - Coming Soon */}
          <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground/50">
            <BarChart3 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px]">Insights</span>
            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
              Soon
            </span>
          </span>

          {/* Backups Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/backups"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Archive className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Backups</span>
          </Link>

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
        <ServiceHeader
          title={selectedTable.name}
          tabs={tableTabs}
          activeTab={activeTab}
          searchPlaceholder={activeTab === 'settings' || activeTab === 'security' || activeTab === 'rows' ? undefined : `Search ${activeTab}...`}
          searchValue={activeTab === 'settings' || activeTab === 'security' || activeTab === 'rows' ? undefined : searchValue}
          onSearchChange={activeTab === 'settings' || activeTab === 'security' || activeTab === 'rows' ? undefined : setSearchValue}
          createLabel={getCreateLabel()}
          onCreate={() => {
            if (activeTab === 'rows' && openCreateRowDrawerRef.current) {
              openCreateRowDrawerRef.current()
            } else if (activeTab === 'columns' && openCreateColumnDialogRef.current) {
              openCreateColumnDialogRef.current()
            } else if (activeTab === 'indexes' && openCreateIndexDialogRef.current) {
              openCreateIndexDialogRef.current()
            } else {
              console.log('Create', activeTab)
            }
          }}
          showFilters={activeTab === 'rows' && hasRows}
          showRefresh={activeTab === 'rows'}
          onRefresh={async () => {
            if (rowsRefetchRef.current) {
              refreshStartTimeRef.current = Date.now()
              setIsRefreshingRows(true)
              try {
                await rowsRefetchRef.current()
                // Ensure minimum animation duration
                const elapsed = Date.now() - (refreshStartTimeRef.current || 0)
                const remaining = Math.max(0, minAnimationDuration - elapsed)
                await new Promise(resolve => setTimeout(resolve, remaining))
                toast.success('Rows refreshed successfully')
              } catch (error) {
                toast.error('Failed to refresh rows')
              } finally {
                setIsRefreshingRows(false)
                refreshStartTimeRef.current = null
              }
            }
          }}
          isRefreshing={isRefreshingRows}
          showImport={activeTab === 'rows'}
          onImport={() => console.log('Import data')}
          showExport={activeTab === 'rows'}
          onExport={() => console.log('Export data')}
          collapsible
          fullWidthBorder
          fullWidth
          contentAfterBorder={
            database && (database as any).enabled === false ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="px-4 py-3 sm:px-6">
                  <Alert variant="default" className="border-amber-500/30 bg-transparent">
                    <AlertCircle className="h-4 w-4 text-amber-500" />
                    <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                      Database is disabled
                    </AlertTitle>
                    <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      <span className="inline">
                        This database is disabled and not accessible to end users through the API. Console actions remain available.{' '}
                        <Link
                          to="/projects/$projectId/databases/$databaseId/settings"
                          params={{ projectId, databaseId }}
                          className="font-medium underline hover:no-underline inline"
                        >
                          Enable it in the Settings tab
                        </Link>
                        {' '}to make it available to end users.
                      </span>
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : tableDataForStatus && !tableDataForStatus.enabled ? (
              <div className="border-b border-border bg-amber-500/5">
                <div className="px-4 py-3 sm:px-6">
                  <Alert variant="default" className="border-amber-500/30 bg-transparent">
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
                        </Link>
                        {' '}to access its data and functionality.
                      </span>
                    </AlertDescription>
                  </Alert>
                </div>
              </div>
            ) : undefined
          }
        />

        {/* Mobile back button - shows when sidebar is hidden */}
        <button
          onClick={handleBackToDatabase}
          className="flex items-center gap-2 border-b border-border px-4 py-2 text-[13px] text-muted-foreground transition-colors hover:text-foreground lg:hidden"
        >
          <ChevronLeft className="h-4 w-4" />
          Back to {database.name}
        </button>

        <div className={cn(
          "flex-1",
          activeTab === 'settings' || activeTab === 'security' ? 'overflow-y-auto' : 'overflow-hidden'
        )}>
          {activeTab === 'rows' && (
            <RowsSpreadsheet
              table={selectedTable}
              onRefetchReady={(refetchFn) => {
                rowsRefetchRef.current = refetchFn
              }}
              onCreateRowReady={(openCreateDrawer) => {
                openCreateRowDrawerRef.current = openCreateDrawer
              }}
              onCreateColumnReady={openCreateColumnDialogRef.current}
              onRowsCountChange={handleRowsCountChange}
            />
          )}
          {activeTab === 'columns' && (
            <ColumnsSpreadsheet 
              table={selectedTable}
              onCreateReady={(openDialog) => {
                openCreateColumnDialogRef.current = openDialog
              }}
            />
          )}
          {activeTab === 'indexes' && (
            <IndexesSpreadsheet 
              table={selectedTable}
              onCreateReady={(openDialog) => {
                openCreateIndexDialogRef.current = openDialog
              }}
            />
          )}
          {activeTab === 'security' && <TableSecurity table={selectedTable} />}
          {activeTab === 'settings' && <TableSettings table={selectedTable} />}
        </div>
      </div>
    </div>
  )
}

// Legacy export for backwards compatibility
export function DatabasesView() {
  return <DatabasesListView />
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
  const [tablesExpanded, setTablesExpanded] = useState(true)

  const database = databases.find((db) => db.$id === databaseId)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/databases',
      params: { projectId },
    })
  }

  const handleBackupsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/backups',
      params: { projectId, databaseId },
    })
  }

  const handleSettingsClick = () => {
    navigate({
      to: '/projects/$projectId/databases/$databaseId/settings',
      params: { projectId, databaseId },
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
            className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
              <button className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground">
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
            <Key className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Security</span>
          </Link>

          {/* Insights Link - Coming Soon */}
          <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground/50">
            <BarChart3 className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px]">Insights</span>
            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
              Soon
            </span>
          </span>

          {/* Backups Link */}
          <Link
            to="/projects/$projectId/databases/$databaseId/backups"
            params={{ projectId, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Archive className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">Backups</span>
          </Link>

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
          className="flex items-center gap-2 border-b border-border px-4 py-2 text-[13px] text-muted-foreground transition-colors hover:text-foreground lg:hidden"
        >
          <ChevronLeft className="h-4 w-4" />
          Back to databases
        </button>

        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <Table2 className="h-5 w-5 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              No tables yet
            </p>
            <p className="mb-4 text-[13px] text-muted-foreground">
              Create your first table to get started
            </p>
            <Button>
              <Plus className="mr-2 h-4 w-4" />
              Create table
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

// Database Overview - shows database details with tabs for tables, backups, settings
interface DatabaseOverviewProps {
  databaseId: string
  activeTab: 'tables' | 'backups' | 'security' | 'insights' | 'settings' | 'visualizer'
}

export function DatabaseOverview({
  databaseId,
  activeTab,
}: DatabaseOverviewProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const location = useLocation()
  const [searchValue, setSearchValue] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [databaseName, setDatabaseName] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedTables, setSelectedTables] = useState<Set<string>>(new Set())
  const [bulkDeleteDialogOpen, setBulkDeleteDialogOpen] = useState(false)
  const [exportDialogOpen, setExportDialogOpen] = useState(false)

  // Convert 1-indexed page to 0-indexed for API
  const currentPageIndexed = currentPage - 1

  // Fetch database
  const { database, isLoading: databaseLoading, error: databaseError } = useProjectDatabase(projectId, databaseId)

  // Update databaseName and enabled when database changes
  useEffect(() => {
    if (database) {
      setDatabaseName(database.name)
      setEnabled((database as any).enabled !== false) // Default to true if not specified
      setDeleteConfirmation('')
    }
  }, [database])

  // Debug logging
  useEffect(() => {
    console.log('[DatabaseOverview] Component state:', {
      projectId,
      databaseId,
      databaseLoading,
      hasDatabase: !!database,
      databaseError: databaseError?.message,
    })
  }, [projectId, databaseId, databaseLoading, database, databaseError])

  // Fetch tables for the database with pagination
  const {
    tables: dbTables,
    total: tablesTotal,
    isLoading: tablesLoading,
  } = useProjectTables(projectId, databaseId, currentPageIndexed, pageSize, searchValue)

  // Fetch database schema for export
  const {
    data: databaseSchema,
    isLoading: schemaLoading,
  } = useQuery({
    queryKey: ['database-schema', 'project', projectId, databaseId],
    queryFn: () => fetchDatabaseSchema(projectId, databaseId),
    enabled: !!projectId && !!databaseId && (exportDialogOpen || activeTab === 'tables'),
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
    } catch (error) {
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
    } catch (error) {
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
    } catch (error) {
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
    } catch (error) {
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
    } catch (error) {
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
    } catch (error) {
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
    } catch (error) {
      toast.error('Failed to open Lovable')
    }
  }

  // Mutation to update database name
  const updateDatabaseNameMutation = useMutation({
    mutationFn: async ({ databaseId, name }: { databaseId: string; name: string }) => {
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
      await (projectSdk.tablesDB as any).update({ databaseId, name: trimmedName })
    },
    onSuccess: () => {
      // Invalidate database query to refetch with updated name
      queryClient.invalidateQueries({ queryKey: ['database', 'project', projectId, databaseId] })
      queryClient.invalidateQueries({ queryKey: ['databases', 'project', projectId] })
      toast.success('Database name updated successfully')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update database name')
    },
  })

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !databaseId || !database) throw new Error('Project ID, Database ID, and Database are required')
      const projectSdk = sdk.forProject(projectId)
      return await (projectSdk.tablesDB as any).update({
        databaseId,
        name: database.name, // Required parameter
        enabled,
      })
    },
    onSuccess: () => {
      toast.success(`Database has been ${enabled ? 'enabled' : 'disabled'}`)
      queryClient.invalidateQueries({ queryKey: ['database', 'project', projectId, databaseId] })
      queryClient.invalidateQueries({ queryKey: ['databases', 'project', projectId] })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (database) {
        setEnabled((database as any).enabled !== false)
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
      await (projectSdk.tablesDB as any).delete(databaseId)
    },
    onSuccess: () => {
      // Invalidate databases query to refetch the list
      queryClient.invalidateQueries({ queryKey: ['databases', 'project', projectId] })
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

  const databaseTabs: Tab[] = [
    {
      id: 'tables',
      label: 'Tables',
      count: tablesTotal,
      to: '/projects/$projectId/databases/$databaseId/tables',
      params: { projectId, databaseId },
    },
    {
      id: 'visualizer',
      label: 'Visualizer',
      to: '/projects/$projectId/databases/$databaseId/visualizer',
      params: { projectId, databaseId },
    },
    {
      id: 'security',
      label: 'Security',
      to: '/projects/$projectId/databases/$databaseId/security',
      params: { projectId, databaseId },
    },
    {
      id: 'insights',
      label: 'Insights',
      to: '/projects/$projectId/databases/$databaseId/insights',
      params: { projectId, databaseId },
    },
    {
      id: 'backups',
      label: 'Backups',
      to: '/projects/$projectId/databases/$databaseId/backups',
      params: { projectId, databaseId },
    },
    {
      id: 'settings',
      label: 'Settings',
      to: '/projects/$projectId/databases/$databaseId/settings',
      params: { projectId, databaseId },
    },
  ]

  // Tables are already paginated by the API
  const paginatedTables = dbTables

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedTables(new Set())
    setBulkDeleteDialogOpen(false)
  }, [location.pathname, projectId, databaseId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setCurrentPage(1)
    setSelectedTables(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation for tables
  const bulkDeleteTablesMutation = useMutation({
    mutationFn: async (tableIds: string[]) => {
      if (!projectId || !databaseId) {
        throw new Error('Project ID and Database ID are required')
      }
      // Delete all tables in parallel
      await Promise.all(
        tableIds.map((tableId) => deleteProjectTable(projectId, databaseId, tableId)),
      )
    },
    onSuccess: () => {
      // Invalidate and refetch tables
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, 'database', databaseId],
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
    setCurrentPage(page)
    setSelectedTables(new Set()) // Clear selection on page change
  }

  const handleTablesPageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
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
    <div className="flex h-full flex-col">
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
            <span>{database.name}</span>
          </div>
        }
        tabs={databaseTabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'tables' ? 'Search tables...' : undefined
        }
        searchValue={activeTab === 'tables' ? searchValue : ''}
        onSearchChange={activeTab === 'tables' ? handleSearchChange : undefined}
        createLabel={activeTab === 'tables' ? 'Create table' : undefined}
        onCreate={
          activeTab === 'tables' ? () => console.log('Create table') : undefined
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
                  <TooltipContent side="bottom">
                    Copy schema
                  </TooltipContent>
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
                <TooltipContent side="bottom">
                  Export as SVG
                </TooltipContent>
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
                  <TooltipContent side="bottom">
                    Open in...
                  </TooltipContent>
                </Tooltip>
                <DropdownMenuContent align="end" className="w-48">
                  <DropdownMenuItem onClick={handleOpenInChatGPT}>
                    <img src="/icons/chatgpt.svg" alt="ChatGPT" className="h-4 w-4 mr-2 brightness-0 dark:brightness-100" />
                    ChatGPT
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={handleOpenInClaude}>
                    <img src="/icons/claude.svg" alt="Claude" className="h-4 w-4 mr-2 brightness-0 dark:brightness-100" />
                    Claude
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={handleOpenInCursor}>
                    <img src="/icons/cursor-ai.svg" alt="Cursor" className="h-4 w-4 mr-2 brightness-0 dark:brightness-100" />
                    Cursor
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={handleOpenInLovable}>
                    <img src="/icons/lovable.svg" alt="Lovable" className="h-4 w-4 mr-2 brightness-0 dark:brightness-100" />
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
          database && (database as any).enabled === false ? (
            <div className="border-b border-border bg-amber-500/5">
              <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                <Alert variant="default" className="border-amber-500/30 bg-transparent">
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    Database is disabled
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                      <span className="inline">
                        This database is disabled and not accessible to end users through the API. Console actions remain available.{' '}
                        <Link
                          to="/projects/$projectId/databases/$databaseId/settings"
                          params={{ projectId: projectId!, databaseId: databaseId }}
                          className="font-medium underline hover:no-underline inline"
                        >
                          Enable it in the Settings tab
                        </Link>
                        {' '}to make it available to end users.
                      </span>
                    </AlertDescription>
                </Alert>
              </div>
            </div>
          ) : undefined
        }
      />

      <div className={cn("flex-1", activeTab !== 'visualizer' && "overflow-y-auto")}>
        {activeTab === 'tables' && (
          <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6">
            {tablesLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">Loading tables...</div>
              </div>
            ) : paginatedTables.length > 0 ? (
              <>
                <div className="rounded-lg border border-border bg-card">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent">
                        <TableHead className="w-[40px]">
                          <Checkbox
                            checked={
                              paginatedTables.length > 0 &&
                              selectedTables.size === paginatedTables.length
                            }
                            onCheckedChange={toggleAllTables}
                          />
                        </TableHead>
                        <TableHead className="w-[180px]">Table ID</TableHead>
                        <TableHead className="w-[200px]">Name</TableHead>
                        <TableHead className="w-[100px]">Columns</TableHead>
                        <TableHead className="w-[100px]">Rows</TableHead>
                        <TableHead className="w-[100px]">Indexes</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {paginatedTables.map((table) => (
                        <TableRow
                          key={table.$id}
                          className={cn(
                            'cursor-pointer transition-colors',
                            selectedTables.has(table.$id)
                              ? 'bg-sky-100 dark:bg-sky-950'
                              : 'hover:bg-muted/50',
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
                              params: { projectId, databaseId, tableId: table.$id },
                            })
                          }}
                        >
                          <TableCell onClick={(e) => e.stopPropagation()}>
                            <Checkbox
                              checked={selectedTables.has(table.$id)}
                              onCheckedChange={() => toggleTable(table.$id)}
                            />
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{ projectId, databaseId, tableId: table.$id }}
                              className="block"
                            >
                              <CopyableId id={table.$id} size="xs" />
                            </Link>
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{ projectId, databaseId, tableId: table.$id }}
                              className="block"
                            >
                              <div className="flex items-center gap-2">
                                <Table2 className="h-4 w-4 text-muted-foreground" />
                                <p className="text-[13px] font-medium text-foreground">
                                  {table.name}
                                </p>
                                {table.enabled === false && (
                                  <AlertCircle className="h-3.5 w-3.5 text-amber-500" />
                                )}
                              </div>
                            </Link>
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{ projectId, databaseId, tableId: table.$id }}
                              className="block"
                            >
                              <span className="text-[13px] text-foreground">
                                {table.columns}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{ projectId, databaseId, tableId: table.$id }}
                              className="block"
                            >
                              <span className="text-[13px] text-muted-foreground">
                                {formatNumber(table.rows)}
                              </span>
                            </Link>
                          </TableCell>
                          <TableCell>
                            <Link
                              to="/projects/$projectId/databases/$databaseId/tables/$tableId/rows"
                              params={{ projectId, databaseId, tableId: table.$id }}
                              className="block"
                            >
                              <span className="text-[13px] text-muted-foreground">
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
                  currentPage={currentPage}
                  totalItems={tablesTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handleTablesPageChange}
                  onPageSizeChange={handleTablesPageSizeChange}
                  itemLabel="tables"
                />

                {/* Bulk Delete Action Bar */}
                {selectedTables.size > 0 && (
                  <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
                    <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
                      <Badge variant="secondary" className="h-6 px-2.5">
                        {selectedTables.size} table{selectedTables.size > 1 ? 's' : ''} selected
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
                <Dialog open={bulkDeleteDialogOpen} onOpenChange={setBulkDeleteDialogOpen}>
                  <DialogContent className="sm:max-w-md p-0">
                    <DialogHeader className="px-6 pt-6 text-left">
                      <DialogTitle>Delete Tables</DialogTitle>
                      <DialogDescription className="text-[13px] mt-2">
                        Are you sure you want to delete {selectedTables.size} table{selectedTables.size > 1 ? 's' : ''}? This action cannot be undone.
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
            ) : (
              <div className="py-12 text-center">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
                  <Table2 className="h-5 w-5 text-muted-foreground" />
                </div>
                <p className="mb-1 text-[14px] font-medium text-foreground">
                  {searchValue ? 'No tables found' : 'No tables yet'}
                </p>
                <p className="mb-4 text-[13px] text-muted-foreground">
                  {searchValue
                    ? 'Try a different search term'
                    : 'Create your first table to get started'}
                </p>
                {!searchValue && (
                  <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Create table
                  </Button>
                )}
              </div>
            )}
          </div>
        )}

        {activeTab === 'backups' && <BackupsView databaseId={databaseId} />}

        {activeTab === 'visualizer' && <SchemaVisualizer databaseId={databaseId} />}

        {activeTab === 'insights' && <ComingSoonView title="Insights" comingSoon />}

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
                    Permissions are configured at the table or row level. You can select the permission model for each table in its settings. When Row Level Security (RLS) is enabled, you can also modify permissions per row when updating individual rows.
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
                    Update your database's display name. This will be
                    visible to all team members.
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
                      if (database && databaseName.trim() && databaseName !== database.name) {
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
                      <Label htmlFor="toggle" className="text-[13px] text-foreground">
                        {enabled ? 'Enabled' : 'Disabled'}
                      </Label>
                    </div>
                  </div>
                  <div className="mt-4 space-y-1">
                    <p className="text-[13px] text-muted-foreground">
                      Database ID: <span className="ml-1.5"><CopyableId id={database.$id} size="sm" /></span>
                    </p>
                    <p className="text-[13px] text-muted-foreground">
                      Created: <DateTooltip date={database.createdAt} showFormattedDate className="text-foreground" />
                    </p>
                    <p className="text-[13px] text-muted-foreground">
                      Last updated: <DateTooltip date={database.updatedAt || database.createdAt} showFormattedDate className="text-foreground" />
                    </p>
                  </div>
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30">
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={enabled === ((database as any).enabled !== false) || updateEnabledMutation.isPending}
                    onClick={() => {
                      if (enabled !== ((database as any).enabled !== false)) {
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
                    Permanently delete this database and all its tables. This action cannot be undone.
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
                        <DialogTitle>
                          Delete Database
                        </DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Are you sure you want to delete{' '}
                          {database && (
                            <span className="font-medium text-foreground">
                              {database.name}
                            </span>
                          )}{' '}
                          and all its tables and data? This action cannot be undone.
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
                                  {tablesTotal} table{tablesTotal !== 1 ? 's' : ''} will be deleted
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
                            if (database && deleteConfirmation === database.name) {
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
    </div>
  )
}

// Row type for the spreadsheet
interface RowData {
  $id: string
  rowNumber: number
  data: Record<string, string | number | boolean>
  $createdAt?: string
  $updatedAt?: string
}

import { PointEditor, LineEditor, PolygonEditor } from './tables/spatial'

// Row Update Drawer Component
interface RowEditDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  row: RowData | null
  tableName: string
  focusedField?: string | null
  columns?: any[]
  onSave: (
    rowId: string | null,
    data: Record<string, string | number | boolean | any[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => void
  isSaving?: boolean
}

function RowEditDrawer({
  open,
  onOpenChange,
  row,
  tableName,
  focusedField,
  columns = [],
  onSave,
  isSaving = false,
}: RowEditDrawerProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const isCreateMode = !row
  
  const [formData, setFormData] = useState<
    Record<string, string | number | boolean | any[] | null>
  >({})
  const [customRowId, setCustomRowId] = useState<string | undefined>(undefined)
  const fieldRefs = useRef<
    Record<string, HTMLInputElement | HTMLSelectElement | HTMLButtonElement | HTMLTextAreaElement | null>
  >({})
  const scrollContainerRef = useRef<HTMLDivElement | null>(null)
  const [newlyAddedItem, setNewlyAddedItem] = useState<{ key: string; index: number } | null>(null)
  const [linkCopied, setLinkCopied] = useState(false)
  const [activeTab, setActiveTab] = useState('overview')
  const [rowPermissions, setRowPermissions] = useState<string[]>([])

  // Initialize row permissions from row data
  useEffect(() => {
    if (row && (row as any).$permissions) {
      setRowPermissions((row as any).$permissions || [])
    } else if (row) {
      setRowPermissions([])
    }
  }, [row])

  // Update form data when row changes - only when row actually changes
  useEffect(() => {
    if (row) {
      // Preserve null values explicitly - ensure null is not converted to undefined
      const initialData: Record<string, string | number | boolean | any[] | null> = {}
      Object.entries(row.data).forEach(([key, value]) => {
        // Explicitly preserve null values
        initialData[key] = value === null || value === undefined ? null : value
      })
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when editing existing row
      setCustomRowId(undefined)
    } else {
      // Initialize form data from columns when creating a new row
      const initialData: Record<string, string | number | boolean | any[] | null> = {}
      columns.forEach((col: any) => {
        const colKey = col.key || col.name || col.$id || col.attribute || col.attributeId
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
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when creating new row
      setCustomRowId(undefined)
    }
  }, [row?.$id, columns]) // Re-run when row ID changes or columns change

  // Switch to data tab when a field is focused (cell clicked)
  // Also set initial tab when drawer opens
  useEffect(() => {
    if (focusedField) {
      setActiveTab('data')
    } else if (open) {
      // Reset to overview when opening without a focused field (only in edit mode)
      // In create mode, default to data tab
      setActiveTab(isCreateMode ? 'data' : 'overview')
    }
  }, [focusedField, open, isCreateMode])

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

  const handleFieldChange = (key: string, value: string | number | boolean | any[] | null) => {
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
    return columns.find((col: any) => {
      const colKey = col.key || col.name || col.$id || col.attribute || col.attributeId
      return colKey === key
    })
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern = /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  const handleArrayItemChange = (key: string, index: number, value: string | number | boolean | null) => {
    const currentArray = (formData[key] as any[]) || []
    const newArray = [...currentArray]
    newArray[index] = value
    handleFieldChange(key, newArray)
  }

  const handleAddArrayItem = (key: string) => {
    const currentArray = (formData[key] as any[]) || []
    const newIndex = currentArray.length
    handleFieldChange(key, [...currentArray, ''])
    // Track the newly added item to focus it after render
    setNewlyAddedItem({ key, index: newIndex })
  }

  const handleRemoveArrayItem = (key: string, index: number) => {
    const currentArray = (formData[key] as any[]) || []
    const newArray = currentArray.filter((_, i) => i !== index)
    handleFieldChange(key, newArray)
  }

  const handleSave = () => {
    // For create mode, pass customRowId if set, otherwise pass null to use auto-generated
    // For update mode, pass the existing row ID
    const idToSave = isCreateMode ? (customRowId || null) : (row?.$id || null)
    onSave(idToSave, formData, customRowId, rowPermissions.length > 0 ? rowPermissions : undefined)
    // Don't close drawer here - wait for mutation to complete
  }

  const getFieldType = (
    key: string,
    value: string | number | boolean | any[] | null,
    columnInfo?: any,
  ): string => {
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

  const getEnumOptions = (columnInfo?: any): string[] => {
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
      maxWidth="sm:max-w-lg"
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
          <div className="flex gap-0 overflow-x-auto border-b border-border px-6" role="tablist">
            {!isCreateMode && (
              <button
                role="tab"
                aria-selected={activeTab === 'overview'}
                onClick={() => setActiveTab('overview')}
                className={cn(
                  'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                  activeTab === 'overview'
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground/80',
                )}
              >
                Overview
                {activeTab === 'overview' && (
                  <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                )}
              </button>
            )}
            <button
              role="tab"
              aria-selected={activeTab === 'data'}
              onClick={() => setActiveTab('data')}
              className={cn(
                'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors',
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
                'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors',
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

          <div ref={scrollContainerRef} className="flex-1 overflow-y-auto">
            {activeTab === 'overview' && !isCreateMode && (
              <div className="px-6 py-6">
              <div className="space-y-5">
                {/* System fields (read-only) */}
                <div className="space-y-3">
                  <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    System Fields
                  </h4>
                  <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-3">
                    <div>
                      <Label className="text-[11px] text-muted-foreground">
                        $id
                      </Label>
                      <div className="mt-1">
                        <code className="font-mono text-[12px] text-foreground">
                          {row.$id}
                        </code>
                      </div>
                    </div>
                    <div>
                      <Label className="text-[11px] text-muted-foreground">
                        Row #
                      </Label>
                      <div className="mt-1">
                        <span className="text-[12px] text-foreground">
                          {row.rowNumber}
                        </span>
                      </div>
                    </div>
                    {row.$createdAt && (
                      <div>
                        <Label className="text-[11px] text-muted-foreground">
                          Created
                        </Label>
                        <div className="mt-1">
                          <DateTooltip
                            date={new Date(row.$createdAt)}
                            className="text-[12px] text-foreground"
                          />
                        </div>
                      </div>
                    )}
                    {row.$updatedAt && (
                      <div>
                        <Label className="text-[11px] text-muted-foreground">
                          Updated
                        </Label>
                        <div className="mt-1">
                          <DateTooltip
                            date={new Date(row.$updatedAt)}
                            className="text-[12px] text-foreground"
                          />
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </div>
              </div>
            )}

            {activeTab === 'data' && (
              <div className="px-6 py-6">
              <div className="space-y-5">
                {/* ID Input - Only shown in create mode */}
                {isCreateMode && (
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium text-foreground" htmlFor="row-id">
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
                {(isCreateMode ? Object.keys(formData) : Object.keys(row.data)).map((key) => {
                  const value = isCreateMode ? formData[key] : row.data[key]
                  const columnInfo = getColumnInfo(key)
                  const fieldType = getFieldType(key, value as string | number | boolean | any[] | null, columnInfo)
                  // Use formData if it exists, otherwise fall back to original value
                  // But check if key exists in formData to distinguish between undefined and null
                  const currentValue = key in formData ? formData[key] : value
                  const shouldFocus = focusedField === key
                  // Check multiple possible properties for required status
                  const isRequired = columnInfo?.required === true || 
                                    columnInfo?.required === 'true' ||
                                    columnInfo?.isRequired === true ||
                                    columnInfo?.isRequired === 'true' ||
                                    columnInfo?.nullable === false ||
                                    columnInfo?.nullable === 'false'

                  const arrayLength = fieldType === 'array' ? (currentValue as any[] || []).length : 0
                  const displayLabel = fieldType === 'array' && arrayLength > 0 
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
                          <span className="text-destructive text-[12px] font-semibold ml-0.5" aria-label="Required field">*</span>
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
                          onValueChange={(val) => handleFieldChange(key, val === 'null' ? null : val)}
                        >
                          <SelectTrigger
                            className="h-9 text-[13px]"
                            ref={(el) => {
                              fieldRefs.current[key] = el
                            }}
                            autoFocus={shouldFocus}
                          >
                            <SelectValue placeholder={isRequired ? undefined : 'NULL'} />
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
                      ) : fieldType === 'integer' || fieldType === 'double' || fieldType === 'number' ? (
                        <div className="space-y-1.5">
                          <Input
                            id={key}
                            type="number"
                            value={currentValue !== null && currentValue !== undefined ? String(currentValue) : ''}
                            ref={(el) => {
                              fieldRefs.current[key] = el
                            }}
                            autoFocus={shouldFocus}
                            onChange={(e) => {
                              const val = e.target.value === '' ? null : (fieldType === 'integer' ? parseInt(e.target.value) || null : parseFloat(e.target.value) || null)
                              handleFieldChange(key, val)
                            }}
                            min={columnInfo?.min}
                            max={columnInfo?.max}
                            step={fieldType === 'double' ? 0.1 : 1}
                            placeholder={isRequired ? undefined : 'NULL'}
                            className="h-9 text-[13px]"
                          />
                          {!isRequired && currentValue === null && (
                            <p className="text-[11px] text-muted-foreground">NULL</p>
                          )}
                        </div>
                      ) : fieldType === 'array' ? (
                        <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-2">
                          {(currentValue as any[] || []).length > 0 ? (
                            <div className="space-y-2">
                              {(currentValue as any[] || []).map((item, index) => {
                                const columnInfo = getColumnInfo(key)
                                const size = columnInfo?.size || null
                                // Check multiple possible properties for required status
                                const isRequired = columnInfo?.required === true || 
                                                  columnInfo?.required === 'true' ||
                                                  columnInfo?.isRequired === true ||
                                                  columnInfo?.isRequired === 'true' ||
                                                  columnInfo?.nullable === false ||
                                                  columnInfo?.nullable === 'false'
                                const isNull = item === null
                                const stringValue = isNull ? '' : String(item || '')
                                const charCount = stringValue.length
                                const hasLimit = size !== null && size > 0
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
                                          const newValue = e.target.value
                                          // Don't auto-convert empty to null - only checkbox sets null
                                          handleArrayItemChange(key, index, newValue)
                                        }}
                                        onFocus={() => {
                                          // Prevent browser from auto-scrolling focused element into view
                                          const scrollContainer = scrollContainerRef.current
                                          if (scrollContainer) {
                                            const scrollTop = scrollContainer.scrollTop
                                            const scrollLeft = scrollContainer.scrollLeft
                                            
                                            // Temporarily prevent scroll
                                            requestAnimationFrame(() => {
                                              scrollContainer.scrollTop = scrollTop
                                              scrollContainer.scrollLeft = scrollLeft
                                            })
                                          }
                                        }}
                                        ref={(el) => {
                                          if (el) {
                                            // Register ref for this specific array item
                                            const itemKey = `${key}-${index}`
                                            fieldRefs.current[itemKey] = el
                                            
                                            // Also register first textarea for auto-focus on drawer open
                                            if (index === 0) {
                                              fieldRefs.current[key] = el
                                            }
                                          } else {
                                            // Clear ref when unmounted
                                            const itemKey = `${key}-${index}`
                                            delete fieldRefs.current[itemKey]
                                            if (index === 0) {
                                              delete fieldRefs.current[key]
                                            }
                                          }
                                        }}
                                        autoFocus={shouldFocus && index === 0}
                                        disabled={isNull}
                                        dir={isRTLContent ? 'rtl' : 'ltr'}
                                        maxLength={hasLimit ? size : undefined}
                                        className={cn(
                                          "min-h-[32px] max-h-[600px] text-[13px] flex-1 border-0 bg-transparent px-0 py-1.5 resize-none focus-visible:ring-0 focus-visible:ring-offset-0",
                                          isNull && "opacity-50 cursor-not-allowed",
                                          showNullCheckbox ? "pb-7" : "pb-1"
                                        )}
                                        placeholder={`Item ${index + 1}`}
                                        rows={1}
                                      />
                                      <div className="absolute bottom-1 right-1 flex items-center gap-1.5 pointer-events-none">
                                        {hasLimit && (
                                          <span className={cn(
                                            "text-[10px] px-1 py-0.5 rounded pointer-events-auto whitespace-nowrap",
                                            charCount > size ? "text-destructive bg-destructive/10" : "text-muted-foreground bg-muted/80"
                                          )}>
                                            {charCount}/{size}
                                          </span>
                                        )}
                                        {showNullCheckbox && (
                                          <div className="pointer-events-auto flex items-center gap-1">
                                            <Checkbox
                                              id={`${key}-${index}-null`}
                                              checked={isNull}
                                              onCheckedChange={(checked) => {
                                                const newArray = [...(currentValue as any[] || [])]
                                                newArray[index] = checked ? null : ''
                                                handleFieldChange(key, newArray)
                                              }}
                                              onClick={(e) => e.stopPropagation()}
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
                                      onClick={() => handleRemoveArrayItem(key, index)}
                                    >
                                      <X className="h-3.5 w-3.5" />
                                    </Button>
                                  </div>
                                )
                              })}
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
                          value={currentValue ? new Date(currentValue as string).toISOString().slice(0, 16) : ''}
                          ref={(el) => {
                            fieldRefs.current[key] = el
                          }}
                          autoFocus={shouldFocus}
                          onChange={(e) => {
                            const val = e.target.value ? new Date(e.target.value).toISOString() : null
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
                            Relationship columns are managed through the relationship system. Edit related rows directly.
                          </p>
                        </div>
                      ) : fieldType === 'point' ? (
                        <PointEditor
                          value={currentValue as [number, number] | null}
                          onChange={(val: [number, number] | null) => handleFieldChange(key, val)}
                          isRequired={isRequired}
                          disabled={isSaving}
                        />
                      ) : fieldType === 'linestring' ? (
                        <LineEditor
                          value={currentValue as number[][] | null}
                          onChange={(val: number[][] | null) => handleFieldChange(key, val)}
                          isRequired={isRequired}
                          disabled={isSaving}
                        />
                      ) : fieldType === 'polygon' ? (
                        <PolygonEditor
                          value={currentValue as number[][][] | null}
                          onChange={(val: number[][][] | null) => handleFieldChange(key, val)}
                          isRequired={isRequired}
                          disabled={isSaving}
                        />
                      ) : (
                        (() => {
                          const size = columnInfo?.size || null
                          // Explicitly check for null - handle both null and undefined
                          const isNull = currentValue === null || currentValue === undefined
                          const stringValue = isNull ? '' : String(currentValue || '')
                          const charCount = stringValue.length
                          const hasLimit = size !== null && size > 0
                          const isRTLContent = isRTL(stringValue)
                          const showNullCheckbox = !isRequired
                          const useTextarea = size && size >= 50
                          const needsCounterSpace = hasLimit || showNullCheckbox
                          const counterPadding = needsCounterSpace ? (isRTLContent ? "pl-28" : "pr-28") : ""
                          
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
                                      const scrollContainer = scrollContainerRef.current
                                      if (scrollContainer) {
                                        const scrollTop = scrollContainer.scrollTop
                                        const scrollLeft = scrollContainer.scrollLeft
                                        requestAnimationFrame(() => {
                                          scrollContainer.scrollTop = scrollTop
                                          scrollContainer.scrollLeft = scrollLeft
                                        })
                                      }
                                    }}
                                    disabled={isNull}
                                    dir={isRTLContent ? 'rtl' : 'ltr'}
                                    maxLength={hasLimit ? size : undefined}
                                    className={cn(
                                      "min-h-[36px] max-h-[600px] text-[13px] resize-none",
                                      isNull && "opacity-50 cursor-not-allowed",
                                      showNullCheckbox ? "pb-8" : "pb-2",
                                      counterPadding
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
                                    maxLength={hasLimit ? size : undefined}
                                    placeholder={isRequired ? undefined : 'NULL'}
                                    className={cn(
                                      "h-9 text-[13px]",
                                      isNull && "opacity-50 cursor-not-allowed",
                                      counterPadding
                                    )}
                                  />
                                )}
                                <div className={cn(
                                  "absolute flex items-center gap-2 pointer-events-none",
                                  useTextarea 
                                    ? isRTLContent ? "bottom-2 left-2" : "bottom-2 right-2"
                                    : isRTLContent ? "top-1/2 -translate-y-1/2 left-2" : "top-1/2 -translate-y-1/2 right-2"
                                )}>
                                  {hasLimit && (
                                    <span className={cn(
                                      "text-[11px] px-1.5 py-0.5 rounded pointer-events-auto whitespace-nowrap",
                                      charCount > size ? "text-destructive bg-destructive/10" : "text-muted-foreground bg-muted/80"
                                    )}>
                                      {charCount}/{size}
                                    </span>
                                  )}
                                  {showNullCheckbox && (
                                    <div className="pointer-events-auto flex items-center gap-1.5">
                                      <Checkbox
                                        id={`${key}-null`}
                                        checked={isNull}
                                        onCheckedChange={(checked) => {
                                          handleNullToggle(key, checked as boolean)
                                        }}
                                        onClick={(e) => e.stopPropagation()}
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
                      Configure row-level access permissions to control who can read, write, and delete this row.
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
        <div className="flex items-center justify-start gap-2 border-t border-border px-6 pb-6 pt-4">
          <Button onClick={handleSave} disabled={isSaving}>
            {isCreateMode ? 'Create Row' : 'Update'}
          </Button>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
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
  onRefetchReady?: (refetch: () => Promise<any>) => void
  onCreateRowReady?: (openCreateDrawer: () => void) => void
  onCreateColumnReady?: (() => void) | null
  onCreateReady?: (openDialog: () => void) => void
  onRowsCountChange?: (count: number) => void
}

function RowsSpreadsheet({ table, onRefetchReady, onCreateRowReady, onCreateColumnReady, onRowsCountChange }: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set())
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [editDrawerOpen, setEditDrawerOpen] = useState(false)
  const [selectedRowForEdit, setSelectedRowForEdit] = useState<RowData | null>(
    null,
  )
  const [focusedField, setFocusedField] = useState<string | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [sampleDataModalOpen, setSampleDataModalOpen] = useState(false)
  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<any>(null)
  const openCreateRowFnRef = useRef<(() => void) | null>(null)
  const openCreateColumnFnRef = useRef<(() => void) | null>(null)
  
  const queryClient = useQueryClient()
  const location = useLocation()

  // Clear selection when navigating between pages/routes
  useEffect(() => {
    setSelectedRows(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, databaseId, tableId])

  // Convert 1-indexed page to 0-indexed for API
  const currentPageIndexed = currentPage - 1

  // Fetch rows from the project SDK
  const {
    rows: apiRows,
    total: rowsTotal,
    isLoading: rowsLoading,
    refetch,
    isFetching,
  } = useProjectTableRows(projectId, databaseId, tableId, currentPageIndexed, pageSize, '')

  // Notify parent of row count changes (only when count actually changes)
  const prevRowsTotalRef = useRef<number | null>(null)
  useEffect(() => {
    if (onRowsCountChange && prevRowsTotalRef.current !== rowsTotal) {
      prevRowsTotalRef.current = rowsTotal
      onRowsCountChange(rowsTotal)
    }
  }, [rowsTotal, onRowsCountChange])

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
      return await createProjectTableColumn(projectId, databaseId, tableId, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['columns', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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

  // Fetch columns from the project SDK
  const {
    columns: apiColumns,
    isLoading: columnsLoading,
  } = useProjectTableColumns(projectId, databaseId, tableId)

  // Fetch tables for relationship columns (needed for column creation)
  const { tables: availableTablesForColumns } = useTablesForColumns(projectId, databaseId, 0, 100, undefined)

  // Check if table has relationship columns
  const hasRelationshipColumns = apiColumns.some(
    (col: any) => col.type === 'relationship',
  )

  // Map API rows to RowData format
  const rows: RowData[] = apiRows.map((row: any, index: number) => {
    // Extract data from row, excluding system fields
    const data: Record<string, string | number | boolean> = {}
    Object.keys(row).forEach((key) => {
      if (!key.startsWith('$')) {
        data[key] = row[key]
      }
    })

        return {
      $id: row.$id,
      rowNumber: rowsTotal - (currentPageIndexed * pageSize + index),
      data,
      $createdAt: row.$createdAt,
      $updatedAt: row.$updatedAt,
    }
  })

  // Get column names from API columns or from first row
  const columns = apiColumns.length > 0
    ? apiColumns.map((col: any) => col.key || col.name || col.$id)
    : (rows[0] ? Object.keys(rows[0].data) : [])

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
    setCurrentPage(page)
    setSelectedRows(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setSelectedRows(new Set()) // Clear selection on page size change
  }

  const handleRowClick = (row: RowData) => {
    setSelectedRowForEdit(row)
    setFocusedField(null)
    setEditDrawerOpen(true)
  }

  const handleCellClick = (row: RowData, key: string) => {
    setSelectedRowForEdit(row)
    setFocusedField(key)
    setEditDrawerOpen(true)
  }

  // Create/Update row mutation
  const saveRowMutation = useMutation({
    mutationFn: async ({ rowId, data, customId, permissions }: { rowId: string | null; data: Record<string, string | number | boolean | any[] | null>; customId?: string | undefined; permissions?: string[] }) => {
      if (rowId) {
        // Update existing row
        return await updateProjectTableRow(projectId, databaseId, tableId, rowId, data, permissions)
      } else {
        // Create new row - use customId if provided, otherwise will auto-generate
        return await createProjectTableRow(projectId, databaseId, tableId, data, customId, permissions)
      }
    },
    onSuccess: (_, variables) => {
      // Invalidate and refetch rows
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(variables.rowId ? 'Row updated successfully' : 'Row created successfully')
      setEditDrawerOpen(false)
      setSelectedRowForEdit(null)
    },
    onError: (error: Error, variables) => {
      toast.error(error.message || (variables.rowId ? 'Failed to update row' : 'Failed to create row'))
    },
  })

  const handleSaveRow = (
    rowId: string | null,
    data: Record<string, string | number | boolean | any[] | null>,
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
    onSuccess: () => {
      // Invalidate and refetch rows
      queryClient.invalidateQueries({
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

  // Sample data generation mutation
  const sampleDataMutation = useMutation({
    mutationFn: async (rowCount: number) => {
      // Filter out system columns (those starting with $) and map API columns to Column type
      const columns: Column[] = apiColumns
        .filter((col: any) => {
          const colKey = col.key || col.name || col.$id
          // Exclude system columns (starting with $)
          return colKey && !colKey.startsWith('$')
        })
        .map((col: any) => ({
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

      let sampleRows: Record<string, any>[]

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
    value: string | number | boolean | Record<string, unknown> | null | undefined,
  ) => {
    if (value === null || value === undefined) return { full: 'null', display: 'null', isNull: true }
    const stringValue =
      typeof value === 'object' ? JSON.stringify(value) : String(value)
    const trimmed = stringValue.length > 80 ? `${stringValue.slice(0, 77)}…` : stringValue
    return { full: stringValue, display: trimmed, isNull: false }
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern = /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  // The parent container constrains height with overflow-hidden
  // This component fills available space and handles its own scrolling
  // Only show loading on initial load, not during pagination (use isFetching for that)
  if (rowsLoading || columnsLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading rows...</div>
      </div>
    )
  }

  // Check if table has custom columns (non-system columns)
  const hasCustomColumns = apiColumns.some(
    (col: any) => {
      const colKey = col.key || col.name || col.$id
      return colKey && !colKey.startsWith('$')
    }
  )

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
        setEditDrawerOpen(true)
      }
    }

    const handleCreateColumn = () => {
      // Always use the local column dialog in the rows view
      setSelectedColumn(null)
      setColumnDialogOpen(true)
    }

    const handleSuggestColumns = () => {
      // TODO: Implement AI column suggestions
      toast.info('Column suggestions coming soon')
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
      <div className="flex h-full flex-col">
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
              <Card
                onClick={handleSuggestColumns}
                className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
              >
                <div className="flex items-start gap-3 p-4">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <Sparkles className="h-5 w-5 text-muted-foreground" />
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
              {hasCustomColumns ? (
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
                  "transition-colors p-0 gap-0 shadow-none",
                  sampleDataMutation.isPending || columnsLoading
                    ? "opacity-50 cursor-not-allowed"
                    : "cursor-pointer hover:bg-accent/50"
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
          availableTables={availableTablesForColumns?.map((t: any) => ({ $id: t.$id, name: t.name })) || []}
          existingColumns={apiColumns.map((c: any) => ({ key: c.key || c.name || c.$id }))}
          isLoading={createColumnMutationForEmptyState.isPending}
        />
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      {/* Scrollable table area */}
      <div className="min-h-0 flex-1 overflow-auto overscroll-contain touch-pan-y">
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
            <col style={{ width: '40px', minWidth: '40px', maxWidth: '40px' }} />
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
              <th
                className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}
              >
                <div className="flex items-center gap-2">
                  <Fingerprint className="h-3.5 w-3.5 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $id
                  </span>
                  <ArrowUpDown className="ml-auto h-3 w-3 text-muted-foreground" />
                </div>
              </th>
              {columns.map((col: string, colIndex: number) => {
                // Get column info to determine icon
                const columnInfo = apiColumns.find((c: any) => {
                  const colKey = c.key || c.name || c.$id || c.attribute || c.attributeId
                  return colKey === col
                })
                const columnType = columnInfo?.type || 'string'
                const ColumnIcon = getColumnIcon(columnType)
                return (
                  <th
                    key={col}
                    className={cn('min-w-[150px] px-3 py-2', headerCellBorderClass)}
                  >
                    <div className="flex items-center gap-2">
                      <ColumnIcon className="h-3.5 w-3.5 text-muted-foreground" />
                      <span className="text-[12px] font-medium text-foreground">
                        {col}
                      </span>
                      <ArrowUpDown className="ml-auto h-3 w-3 text-muted-foreground" />
                    </div>
                  </th>
                )
              })}
              <th
                className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}
              >
                <div className="flex items-center gap-2">
                  <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $createdAt
                  </span>
                  <ArrowUpDown className="ml-auto h-3 w-3 text-muted-foreground" />
                </div>
              </th>
              <th
                className={cn('w-[180px] px-3 py-2', headerCellBorderClass)}
              >
                <div className="flex items-center gap-2">
                  <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    $updatedAt
                  </span>
                  <ArrowUpDown className="h-3 w-3 text-muted-foreground" />
                </div>
              </th>
              <th
                className={cn(
                  'sticky right-0 z-30 bg-background p-0',
                  'shadow-[inset_0_1px_0_0_#d1d5db,inset_0_-1px_0_0_#d1d5db,inset_1px_0_0_0_#d1d5db]',
                  'dark:shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.1),inset_0_-1px_0_0_rgb(255_255_255_/_0.1),inset_1px_0_0_0_rgb(255_255_255_/_0.1)]',
                )}
                style={{ width: '40px', minWidth: '40px', maxWidth: '40px' }}
              >
                <div className="flex h-full w-[40px] items-center justify-center py-2">
                  <Plus className="h-4 w-4 text-muted-foreground" />
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            {paginatedRows.map((row) => (
              <tr
                key={row.$id}
                className={cn(
                  'group cursor-pointer transition-colors',
                  selectedRows.has(row.$id)
                    ? 'bg-sky-100 dark:bg-sky-950'
                    : 'hover:bg-muted/50',
                )}
                onClick={() => handleRowClick(row)}
              >
                <td
                  className={cn(
                    'sticky left-0 w-10 border-b border-gray-200 dark:border-border bg-background px-2 py-1.5',
                    'shadow-[inset_-1px_0_0_0_#d1d5db] dark:shadow-[inset_-1px_0_0_0_rgb(255_255_255_/_0.1)]',
                    selectedRows.has(row.$id) && 'bg-sky-100 dark:bg-sky-950',
                  )}
                >
                  <Checkbox
                    checked={selectedRows.has(row.$id)}
                    onCheckedChange={() => toggleRow(row.$id)}
                    onClick={(e) => e.stopPropagation()}
                  />
                </td>
                <td className={cn('px-3 py-1.5', bodyCellBorderClass)}>
                  <span className="text-[12px] text-muted-foreground">
                    {row.rowNumber}
                  </span>
                </td>
                <td className={cn(
                  'w-[180px] px-3 py-1.5',
                  columns.length === 0 ? 'border-b border-gray-200 dark:border-border' : bodyCellBorderClass,
                )}>
                  <div className="flex items-center gap-2">
                    <Table2 className="h-3.5 w-3.5 text-muted-foreground" />
                    <code
                      className="block max-w-[140px] truncate whitespace-nowrap font-mono text-[12px] text-foreground"
                      title={row.$id}
                    >
                      {row.$id}
                    </code>
                  </div>
                </td>
                {columns.map((col: string, colIndex: number) => (
                  <td
                    key={col}
                    className={cn('px-3 py-1.5', bodyCellBorderClass)}
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
                      const isRTLContent = typeof cellValue === 'string' ? isRTL(cellValue) : false
                      return (
                        <span
                          className={cn(
                            "block max-w-[220px] truncate whitespace-nowrap text-[12px]",
                            isNull ? "text-foreground/60" : "text-foreground"
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
                  onClick={(e) => e.stopPropagation()}
                >
                  {row.$createdAt ? (
                    <DateTooltip
                      date={new Date(row.$createdAt)}
                      className="text-[12px] text-muted-foreground"
                    />
                  ) : (
                    <span className="text-[12px] text-foreground/60">N/A</span>
                  )}
                </td>
                <td
                  className={cn('w-[180px] px-3 py-1.5', bodyCellBorderClass)}
                  onClick={(e) => e.stopPropagation()}
                >
                  {row.$updatedAt ? (
                    <DateTooltip
                      date={new Date(row.$updatedAt)}
                      className="text-[12px] text-muted-foreground"
                    />
                  ) : (
                    <span className="text-[12px] text-foreground/60">N/A</span>
                  )}
                </td>
                <td
                  className={cn(
                    'sticky right-0 border-b border-gray-200 dark:border-border bg-background p-0',
                    'shadow-[inset_1px_0_0_0_#d1d5db] dark:shadow-[inset_1px_0_0_0_rgb(255_255_255_/_0.1)]',
                    selectedRows.has(row.$id) && 'bg-sky-100 dark:bg-sky-950',
                  )}
                  style={{ width: '40px', minWidth: '40px', maxWidth: '40px' }}
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
                        Update Row
                      </DropdownMenuItem>
                      <DropdownMenuItem>Duplicate</DropdownMenuItem>
                      <DropdownMenuItem className="text-destructive">
                        Delete Row
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Bulk Delete Action Bar */}
      {selectedRows.size > 0 && (
        <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
          <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
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
              currentPage={currentPage}
              totalItems={rowsTotal}
              pageSize={pageSize}
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
        onOpenChange={setEditDrawerOpen}
        row={selectedRowForEdit}
        tableName={table.name}
        focusedField={focusedField}
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
              Are you sure you want to delete {selectedRows.size} row{selectedRows.size > 1 ? 's' : ''}? This action cannot be undone.
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
        availableTables={availableTablesForColumns?.map((t: any) => ({ $id: t.$id, name: t.name })) || []}
        existingColumns={apiColumns.map((c: any) => ({ key: c.key || c.name || c.$id }))}
        isLoading={createColumnMutationForEmptyState.isPending}
      />
    </div>
  )
}

// Spreadsheet-like view for Columns
function ColumnsSpreadsheet({ table, onCreateReady }: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<any>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [columnToDelete, setColumnToDelete] = useState<string | null>(null)

  const queryClient = useQueryClient()

  // Fetch columns from the project SDK
  const {
    columns: apiColumns,
    isLoading: columnsLoading,
    refetch: refetchColumns,
  } = useProjectTableColumns(projectId, databaseId, tableId)

  // Fetch tables for relationship columns
  const { tables: availableTables } = useTablesForColumns(projectId, databaseId, 0, 100, undefined)

  // Create column mutation
  const createColumnMutation = useMutation({
    mutationFn: async (data: ColumnFormData) => {
      return await createProjectTableColumn(projectId, databaseId, tableId, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['columns', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
      setColumnDialogOpen(false)
      setSelectedColumn(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to create column')
    },
  })

  // Update column mutation
  const updateColumnMutation = useMutation({
    mutationFn: async ({ columnKey, data }: { columnKey: string; data: Partial<ColumnFormData> }) => {
      return await updateProjectTableColumn(projectId, databaseId, tableId, columnKey, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['columns', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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
      return await deleteProjectTableColumn(projectId, databaseId, tableId, columnKey)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['columns', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateColumn)
    }
  }, [onCreateReady])

  const handleEditColumn = (column: any) => {
    setSelectedColumn(column)
    setColumnDialogOpen(true)
  }

  const handleDeleteColumn = (columnKey: string) => {
    setColumnToDelete(columnKey)
    setDeleteDialogOpen(true)
  }

  const handleColumnSubmit = async (data: ColumnFormData) => {
    if (selectedColumn) {
      await updateColumnMutation.mutateAsync({
        columnKey: selectedColumn.key || selectedColumn.name || selectedColumn.$id,
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

  // Map API columns to the format expected by the component
  const columns = apiColumns.map((col: any) => ({
    key: col.key || col.name || col.$id,
    type: col.type || 'string',
    size: col.size || null,
    required: col.required || false,
    array: col.array || false,
    default: col.default || null,
  }))

  // Generate mock columns based on table (fallback if no API columns)
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

  // Use API columns if available, otherwise fallback to mock
  const displayColumns = columns.length > 0 ? columns : generateMockColumns()

  if (columnsLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading columns...</div>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-auto overscroll-contain touch-pan-y">
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
                  'min-w-[120px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Default
                </span>
              </th>
              <th className="w-10 px-2 py-2"></th>
            </tr>
          </thead>
          <tbody>
            {displayColumns.map((col: { key: string; type: string; size: number | null; required: boolean; array: boolean; default: string | null }) => {
              const Icon = getColumnIcon(col.type)
              const isSystem = col.key.startsWith('$')
              return (
                <tr
                  key={col.key}
                  className={cn(
                    'group transition-colors hover:bg-muted/50',
                    isSystem && 'bg-muted/30',
                  )}
                >
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex items-center gap-2">
                      <Icon className="h-3.5 w-3.5 text-muted-foreground" />
                      <code
                        className={cn(
                          'font-mono text-[12px]',
                          isSystem
                            ? 'text-muted-foreground'
                            : 'text-foreground',
                        )}
                      >
                        {col.key}
                      </code>
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                      {col.type}
                    </span>
                  </td>
                  <td
                    className={cn(
                      'px-3 py-2 text-[12px] text-muted-foreground',
                      bodyCellBorderClass,
                    )}
                  >
                    {col.size ?? '—'}
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
                    <code className="font-mono text-[11px] text-muted-foreground">
                      {col.default ?? 'NULL'}
                    </code>
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
                          <DropdownMenuItem onClick={() => handleEditColumn(col)}>
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

      {/* Footer */}
      <div className="flex items-center justify-between border-t border-border px-4 py-2 text-[12px] text-muted-foreground">
        <div className="flex items-center gap-2">
          <button
            onClick={handleCreateColumn}
            className="flex items-center gap-1.5 hover:text-foreground transition-colors"
          >
            <Plus className="h-3.5 w-3.5 cursor-pointer" />
            <span>Create column</span>
          </button>
        </div>
        <span>{columns.length} columns</span>
      </div>

      {/* Column Form Dialog */}
      <ColumnDrawer
        open={columnDialogOpen}
        onOpenChange={setColumnDialogOpen}
        onSubmit={handleColumnSubmit}
        column={selectedColumn}
        availableTables={availableTables.map(t => ({ $id: t.$id, name: t.name }))}
        existingColumns={columns.map((c: { key: string }) => ({ key: c.key }))}
        isLoading={createColumnMutation.isPending || updateColumnMutation.isPending}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete Column</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete the column "{columnToDelete}"? This action cannot be undone and may affect existing rows.
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
    </div>
  )
}

// Spreadsheet-like view for Indexes
function IndexesSpreadsheet({ table, onCreateReady }: SpreadsheetProps) {
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const [indexDialogOpen, setIndexDialogOpen] = useState(false)
  const [selectedIndex, setSelectedIndex] = useState<any>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [indexToDelete, setIndexToDelete] = useState<string | null>(null)

  const queryClient = useQueryClient()

  // Fetch indexes from the project SDK
  const {
    indexes: apiIndexes,
    isLoading: indexesLoading,
  } = useProjectTableIndexes(projectId, databaseId, tableId)

  // Fetch columns for index creation
  const {
    columns: availableColumns,
  } = useProjectTableColumns(projectId, databaseId, tableId)

  // Create index mutation
  const createIndexMutation = useMutation({
    mutationFn: async (data: IndexFormData) => {
      // Transform form data to API format
      const apiData: any = {
        key: data.key,
        type: data.type,
        columns: data.columns.map((c: IndexColumnEntry) => c.column),
        orders: data.columns.map((c: IndexColumnEntry) => c.order).filter((o: string | null) => o !== null) as string[],
      }
      if (data.type === 'key') {
        apiData.lengths = data.columns.map((c: IndexColumnEntry) => c.length).filter((l: number | null) => l !== null)
      }
      return await createProjectTableIndex(projectId, databaseId, tableId, apiData)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['indexes', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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
      return await deleteProjectTableIndex(projectId, databaseId, tableId, indexKey)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['indexes', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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

  const handleIndexSubmit = async (data: IndexFormData) => {
    await createIndexMutation.mutateAsync(data)
  }

  const handleConfirmDelete = () => {
    if (indexToDelete) {
      deleteIndexMutation.mutate(indexToDelete)
    }
  }

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateIndex)
    }
  }, [onCreateReady])

  // Use API indexes if available, otherwise fallback to mock
  const indexes = apiIndexes.length > 0 ? apiIndexes : [
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

  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto overscroll-contain touch-pan-y">
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
                  'min-w-[200px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Columns
                </span>
              </th>
              <th
                className={cn(
                  'min-w-[120px] px-3 py-2 text-left',
                  headerCellBorderClass,
                )}
              >
                <span className="text-[12px] font-medium text-foreground">
                  Order
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
              <th className="w-10 px-2 py-2"></th>
            </tr>
          </thead>
          <tbody>
            {indexes.map((index: any) => {
              const isSystem = index.key.startsWith('_key_')
              return (
                <tr
                  key={index.key}
                  className={cn(
                    'group transition-colors hover:bg-muted/50',
                    isSystem && 'bg-muted/30',
                  )}
                >
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
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
                        {index.key}
                      </code>
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <span
                      className={cn(
                        'inline-flex items-center gap-1 text-[11px]',
                        index.type === 'unique'
                          ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                          : 'bg-muted text-muted-foreground',
                      )}
                    >
                      <span
                        className={cn(
                          'h-1.5 w-1.5 rounded-full',
                          index.status === 'available'
                            ? 'bg-emerald-500'
                            : 'bg-amber-500',
                        )}
                      />
                      {index.type}
                    </span>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex flex-wrap gap-1">
                      {index.columns.map((col: string) => (
                        <code
                          key={col}
                          className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                        >
                          {col}
                        </code>
                      ))}
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex gap-1">
                      {(index.orders || []).map((order: string, i: number) => (
                        <span
                          key={i}
                          className="text-[11px] text-muted-foreground"
                        >
                          {order}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <span
                      className={cn(
                        'inline-flex items-center gap-1 text-[11px]',
                        index.status === 'available'
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : 'text-amber-600 dark:text-amber-400',
                      )}
                    >
                      <span
                        className={cn(
                          'h-1.5 w-1.5 rounded-full',
                          index.status === 'available'
                            ? 'bg-emerald-500'
                            : 'bg-amber-500',
                        )}
                      />
                      {index.status}
                    </span>
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

      {/* Footer */}
      <div className="flex items-center justify-between border-t border-border px-4 py-2 text-[12px] text-muted-foreground">
        <div className="flex items-center gap-2">
          <button
            onClick={handleCreateIndex}
            className="flex items-center gap-1.5 hover:text-foreground transition-colors"
          >
            <Plus className="h-3.5 w-3.5 cursor-pointer" />
            <span>Create index</span>
          </button>
        </div>
        <span>{indexes.length} indexes</span>
      </div>

      {/* Index Form Dialog */}
        <IndexDrawer
        open={indexDialogOpen}
        onOpenChange={setIndexDialogOpen}
        onSubmit={handleIndexSubmit}
        index={selectedIndex}
        availableColumns={availableColumns}
        existingIndexes={indexes.map((i: { key: string }) => ({ key: i.key }))}
        isLoading={createIndexMutation.isPending}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete index</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete the index "{indexToDelete}"? This action cannot be undone.
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
  const { table: tableData, isLoading: tableLoading } = useProjectTable(projectId, databaseId, tableId)

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
      queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId, databaseId, tableId] })
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
      queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId, databaseId, tableId] })
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
              Choose who can access your tables and rows. <a href="https://appwrite.io/docs/products/databases/permissions" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">Learn more</a>.
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
              disabled={arraysEqual(tablePermissions, tableData.$permissions || []) || updatePermissionsMutation.isPending}
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
                <Label htmlFor="security" className="text-[13px] text-foreground">
                  Row level security (RLS)
                </Label>
            </div>
            </div>
            <div className="mt-4 space-y-2">
              <p className="text-[13px] text-muted-foreground">
                When row security is enabled, users need <strong>both table permissions and row permissions</strong> to access rows. Row permissions are an additional layer, not an alternative to table permissions.
              </p>
              <p className="text-[13px] text-muted-foreground">
                <strong>Create operations</strong> always require table-level permissions, regardless of row security settings.
              </p>
              <p className="text-[13px] text-muted-foreground">
                If row security is disabled, users can access rows <strong>only if they have table permissions</strong>. Row permissions will be ignored.
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={tableRowSecurity === tableData.rowSecurity || updateSecurityMutation.isPending}
              onClick={() => {
                if (tableRowSecurity !== null && tableRowSecurity !== tableData.rowSecurity) {
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
  const { table: tableData, isLoading: tableLoading } = useProjectTable(projectId, databaseId, tableId)
  const { columns: tableColumns } = useProjectTableColumns(projectId, databaseId, tableId)

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
          const team = await sdk.forConsole.teams.get(organizationId)
          const prefs = team.prefs || {}
          const savedNames = prefs.displayNames?.[tableId] as string[] | undefined
          if (savedNames && savedNames.length > 0) {
            setDisplayNames(savedNames)
          }
        } catch (err) {
          console.warn('Failed to load display names:', err)
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
      queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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
      queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId, databaseId, tableId] })
      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
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
      const team = await sdk.forConsole.teams.get(organizationId)
      const prefs = team.prefs || {}
      const updatedPrefs = {
        ...prefs,
        displayNames: {
          ...(prefs.displayNames as Record<string, string[]> || {}),
          [tableId]: names,
        },
      }
      await sdk.forConsole.teams.updatePrefs(organizationId, updatedPrefs)
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
          const team = await sdk.forConsole.teams.get(organizationId)
          const prefs = team.prefs || {}
          const updatedPrefs = { ...prefs }
          if (updatedPrefs.displayNames) {
            delete (updatedPrefs.displayNames as Record<string, any>)[tableId]
          }
          if (updatedPrefs.tables) {
            delete (updatedPrefs.tables as Record<string, any>)[tableId]
          }
          if (updatedPrefs.columnOrder) {
            delete (updatedPrefs.columnOrder as Record<string, any>)[tableId]
          }
          if (updatedPrefs.columnWidths) {
            delete (updatedPrefs.columnWidths as Record<string, any>)[tableId]
            delete (updatedPrefs.columnWidths as Record<string, any>)[`${tableId}#columns`]
            delete (updatedPrefs.columnWidths as Record<string, any>)[`${tableId}#indexes`]
          }
          await sdk.forConsole.teams.updatePrefs(organizationId, updatedPrefs)
        } catch (err) {
          console.warn('Failed to delete table preferences:', err)
        }
      }

      queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId, databaseId] })
      toast.success(`${tableData?.name || 'Table'} has been deleted`)
      setShowDelete(false)
      setDeleteError(null)

      // Navigate to database tables list
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables',
        params: { projectId, databaseId },
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

  // Get valid string columns for display names
  const validStringColumns = tableColumns.filter(
    (col: any) => col.type === 'string' && col.array === false
  )

  // Filter display name options (exclude already selected except current)
  const getDisplayNameOptions = (index: number) => {
    return validStringColumns
      .filter((col: any) => {
        const key = col.key
        // Include if not selected elsewhere, or if it's the current selection
        return !displayNames.some((name, idx) => name === key && idx !== index)
      })
      .map((col: any) => ({
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
                Created: <DateTooltip date={tableData.$createdAt} showFormattedDate className="text-foreground" />
              </p>
              <p className="text-[13px] text-muted-foreground">
                Last updated: <DateTooltip date={tableData.$updatedAt} showFormattedDate className="text-foreground" />
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={enabled === tableData.enabled || toggleTableMutation.isPending}
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
            <h3 className="text-[15px] font-semibold text-foreground">
              Name
            </h3>
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
              disabled={tableName === tableData.name || !tableName.trim() || updateNameMutation.isPending}
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
              Select up to 5 string columns to display as row names in the Appwrite console. These help identify rows in places like relationships.
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
                      onValueChange={(value) => handleDisplayNameChange(index, value)}
                    >
                      <SelectTrigger className="h-9 w-[200px] border-border bg-background text-[13px]">
                        <SelectValue placeholder="Select column" />
                      </SelectTrigger>
                      <SelectContent>
                        {getDisplayNameOptions(index).map((option: { value: string; label: string }) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
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
            {displayNames.length < 5 && validStringColumns.length > displayNames.filter((n) => n && n !== '$id').length && (
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
                    const saved = (account?.prefs as any)?.displayNames?.[tableId] as string[] | undefined
                    const savedNames = saved && saved.length > 0 ? saved : ['$id']
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
              The table will be permanently deleted, including all the rows within it. This action is irreversible.
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
                  Last updated: <DateTooltip date={tableData.$updatedAt} showFormattedDate className="text-foreground" />
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
                    Are you sure you want to delete <strong>{tableData.name}</strong>? This action cannot be undone.
                  </DialogDescription>
                </DialogHeader>
                {deleteError && (
                  <div className="px-6 pt-4">
                    <p className="text-[13px] text-destructive">{deleteError}</p>
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
