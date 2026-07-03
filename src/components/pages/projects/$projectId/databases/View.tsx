import { cn } from '@/lib/utils'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Database,
  Plus,
  List,
  Layers,
  LayoutGrid,
  Settings,
  Lock,
  Table2,
  ChevronLeft,
  ChevronDown,
  ChevronRight,
  CheckCircle2,
  AlertCircle,
  BarChart3,
  Cpu,
} from 'lucide-react'
import {
  databases,
  collections,
  type Database as DatabaseType,
} from '@/lib/utils/mock-data'
import { useState, useEffect, useRef, useMemo } from 'react'

import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectProductDatabases,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  databasesQueryOptions,
  createProjectDatabase,
  createProjectTable,
  invalidateDatabaseModel,
  deleteProjectDatabase,
} from '@/lib/react-query/hooks'
import {
  GRID_DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'

import { CreateDatabase } from './CreateDatabase'
import { CreateTable, createTableVariantForDbRoute } from './CreateTable'
import { TableContextMenu } from './_components/TableContextMenu'
import { DatabaseContextMenu } from './_components/DatabaseContextMenu'
import { DatabaseBackupsNavLink } from './_components/DatabaseBackupsNavLink'
import { DedicatedDatabasesSection } from './_components/DedicatedDatabasesSection'
import { ProductDatabasesSection } from './_components/ProductDatabasesSection'

import {
  canCreateDatabase,
  canShowDatabaseSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { Models } from '@appwrite.io/console'
import { DatabaseType as ApiDatabaseType } from '@appwrite.io/console'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { getDatabaseServiceLucideIcon } from '@/lib/databases/database-service-icons'
import { projectSupportsDedicatedDatabaseCompute } from '@/lib/databases/dedicated-database-regions'

const TABLESDB_LIST_ICON =
  getDatabaseServiceLucideIcon('tablesdb') ?? Database

/** Database list item: API may return extra backup/createdAt fields */
type DatabaseWithBackup = Models.Database & {
  hasBackupPolicy?: boolean
  backupPolicyCount?: number
  backupPolicy?: { name?: string }
  createdAt?: string
  updatedAt?: string
}

import { ServiceHeader } from '../shared/ServiceHeader'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ResourceCard,
  RESOURCE_CARD_GRID_CLASSNAME,
} from '../shared/ResourceCard'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'

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
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  parseListSearch,
  MIN_SEARCH_LENGTH,
  databasesFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useT } from '@/lib/i18n/translate'

export type {
  OverviewContentTab,
  DatabaseTabId,
  WorkspaceProps,
} from './workspace-types'
export { Workspace } from './Workspace'

// Main databases list view - used at /projects/:projectId/databases
export function View() {
  const { projectId } = useParams({
    from: '/_public/projects/$projectId/databases/',
  })
  const t = useT()
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
  const { project } = useProject(projectId)
  const supportsDedicatedDatabaseCompute =
    projectSupportsDedicatedDatabaseCompute(project?.region)
  const useCreateDatabaseWizard = features.dedicatedDbsSupport
  const queryClient = useQueryClient()
  const databaseDeepLink = (
    databaseId: string,
    apiType: ApiDatabaseType | undefined,
  ) => {
    const dbKind = databaseRouteKindFromApiType(apiType)
    return dbNavLink(dbKind).dataGrid({
      projectId: projectId!,
      dbKind,
      databaseId,
      resourceId: '-',
    })
  }
  const isDatabasesIndex =
    location.pathname.replace(/\/$/, '') === `/projects/${projectId}/databases`
  const databaseListParams = useMemo(() => {
    if (!isDatabasesIndex) return null
    const parsed = parseListSearch(search, { limit: GRID_DEFAULT_PAGE_SIZE })
    return {
      search: parsed.search,
      page: parsed.page,
      limit: parsed.limit,
      filterMap: parsed.filterMap,
    }
  }, [
    isDatabasesIndex,
    search?.search,
    search?.query,
    search?.page,
    search?.limit,
  ])

  const urlPage = databaseListParams?.page ?? 1
  const urlLimit = databaseListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
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
  const isMountedRef = useRef(false)
  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])
  const [pageSize, setPageSize] = useState(GRID_DEFAULT_PAGE_SIZE)
  const [selectedDatabases, setSelectedDatabases] = useState<Set<string>>(
    new Set(),
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [createDatabaseDialogOpen, setCreateDatabaseDialogOpen] =
    useState(false)
  const [filtersOpen, setFiltersOpen] = useState(false)

  // Open create database flow when ?create=database (wizard when feature enabled; else modal)
  useEffect(() => {
    if (!isMountedRef.current) return
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
  }, [
    search?.create,
    createDatabaseDialogOpen,
    useCreateDatabaseWizard,
    navigate,
    location.pathname,
    projectId,
  ])

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
  }, [
    isDatabasesIndex,
    databaseListParams,
    urlPage,
    urlSearch,
    filterQueryString,
  ])

  useEffect(() => {
    if (!isDatabasesIndex) return
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      if (!isMountedRef.current) return
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
  }, [
    searchInput,
    projectId,
    navigate,
    urlSearch,
    urlLimit,
    filterQueryString,
    isDatabasesIndex,
  ])

  const {
    total: databasesTotal,
    isLoading: databasesLoading,
    isFetching: databasesFetching,
    isFetched: databasesFetched,
  } = useProjectProductDatabases(
    projectId,
    ApiDatabaseType.Tablesdb,
    requestedPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    filterQueries,
  )

  const {
    databases: apiDatabases,
    total: displayedDatabasesTotal,
    isLoading: displayedLoading,
    isFetching: displayedDatabasesFetching,
    error: displayedDatabasesError,
    refetch: refetchDisplayedDatabases,
  } = useProjectProductDatabases(
    projectId,
    ApiDatabaseType.Tablesdb,
    displayedPage - 1,
    urlLimit,
    displayedSearch ?? undefined,
    displayedFilterQueries,
  )

  const databasesListErrorMessage = displayedDatabasesError
    ? getErrorMessage(displayedDatabasesError)
    : null

  useEffect(() => {
    if (
      !isDatabasesIndex ||
      databasesFetching ||
      databasesLoading ||
      !databasesFetched
    )
      return
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

  // Get total count (no search/filters) for plan limit check - uses same query as route loader prefetch to avoid layout shift when showing PlanLimitWarning
  const { data: totalDatabasesData } = useQuery(
    databasesQueryOptions(
      projectId,
      0,
      ROWS_DEFAULT_PAGE_SIZE,
      undefined,
      undefined,
    ),
  )

  // Paginated data - databases are already paginated by the API
  const paginatedDatabases = apiDatabases

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)

  // Total count of all databases (without search) - for limit checking
  const totalDatabasesCount = totalDatabasesData?.total || 0

  const showDbSecuritySettings = canShowDatabaseSecuritySettings(
    access,
    features,
  )

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

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(filterMap)
    if (replaceKey) newMap.delete(replaceKey)
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
      await Promise.all(
        databaseIds.map((databaseId) =>
          deleteProjectDatabase(projectId, databaseId),
        ),
      )
      databaseIds.forEach((id) => invalidateDatabaseModel(projectId, id))
    },
    onSuccess: async () => {
      // Refetch databases list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['databases', 'project', projectId],
      })
      toast.success(
        selectedDatabases.size === 1
          ? t('Database deleted successfully')
          : t('Databases deleted successfully'),
      )
      setSelectedDatabases(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete databases'))
    },
  })

  // Create database mutation
  const createDatabaseMutation = useMutation({
    mutationFn: (data: { databaseId?: string; name: string }) =>
      createProjectDatabase(projectId!, data),
    onSuccess: (database) => {
      toast.success(`${database.name} ${t('has been created')}`)
      queryClient.invalidateQueries({
        queryKey: ['databases', 'project', projectId],
      })
      setCreateDatabaseDialogOpen(false)
      navigate({
        ...databaseDeepLink(
          database.$id,
          (database as { databaseType?: ApiDatabaseType }).databaseType,
        ),
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to create database'))
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
        title={t('Databases')}
        searchPlaceholder={t('Search databases...')}
        searchValue={searchInput}
        onSearchChange={handleSearchChange}
        createLabel={t('Create database')}
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
            ? t("You don't have permission to create databases.")
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
            filterScope="databases"
            onApplyQuery={(queryParam) => {
              navigate({
                to: '/projects/$projectId/databases/',
                params: { projectId: projectId! },
                search: (prev: Record<string, unknown>) => ({
                  ...prev,
                  ...buildListSearchParams({
                    search: urlSearch,
                    query: queryParam ?? undefined,
                    page: 1,
                    limit: urlLimit,
                  }),
                }),
                replace: true,
              })
            }}
            teamId={project?.teamId}
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
              planName={resolveOrganizationPlanDisplayLabel({
                planName: organizationPlan?.name ?? null,
                planId: organizationPlan?.$id,
              })}
              resourceName="databases"
              orgId={project?.teamId}
              fullWidth={false}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        <div className="mb-4">
          <h2 className="text-[15px] font-semibold text-foreground">TablesDB</h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'Serverless and dedicated TablesDB databases for structured app data.',
            )}
          </p>
        </div>

        {databasesListErrorMessage && paginatedDatabases.length > 0 ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>{t("Couldn't refresh databases")}</AlertTitle>
            <AlertDescription className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-[13px]">{databasesListErrorMessage}</p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0 border-destructive/40 bg-background"
                onClick={() => void refetchDisplayedDatabases()}
                disabled={displayedDatabasesFetching}
              >
                {t('Try again')}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {showLoading ? (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <div className="text-muted-foreground">
              {t('Loading databases...')}
            </div>
          </div>
        ) : databasesListErrorMessage && paginatedDatabases.length === 0 ? (
          <div className="rounded-lg border border-destructive/30 bg-card py-12 px-6 text-center">
            <AlertCircle className="mx-auto h-9 w-9 text-destructive" />
            <h3 className="mt-4 text-[15px] font-semibold text-foreground">
              {t('Failed to load databases')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {databasesListErrorMessage}
            </p>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="mt-6"
              onClick={() => void refetchDisplayedDatabases()}
              disabled={displayedDatabasesFetching}
            >
              {t('Try again')}
            </Button>
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
                        {t('Database')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                        {t('Status')}
                      </TableHead>
                      {features.databaseBackups && (
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                          {t('Backups')}
                        </TableHead>
                      )}
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                        {t('Created')}
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                        {t('Updated')}
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
                              ...databaseDeepLink(
                                db.$id,
                                (db as { databaseType?: ApiDatabaseType })
                                  .databaseType,
                              ),
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
                              {...databaseDeepLink(
                                db.$id,
                                (db as { databaseType?: ApiDatabaseType })
                                  .databaseType,
                              )}
                              className="block group"
                            >
                              <div className="flex items-center gap-3 min-w-0">
                                <TABLESDB_LIST_ICON className="h-4 w-4 shrink-0 text-muted-foreground/60" />
                                <div className="flex-1 min-w-0">
                                  <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
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
                                  {t('Disabled')}
                                </Badge>
                              ) : (
                                <Badge
                                  variant="success"
                                  className="text-[11px] font-medium border px-2 py-0.5"
                                >
                                  {t('Enabled')}
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
                                      ? `${(db as DatabaseWithBackup).backupPolicyCount} ${(db as DatabaseWithBackup).backupPolicyCount === 1 ? t('policy') : t('policies')}`
                                      : (db as DatabaseWithBackup).backupPolicy
                                          ?.name || t('Enabled')}
                                  </Badge>
                                ) : (
                                  <Badge
                                    variant="warning"
                                    className="gap-1.5 text-[11px] font-medium border px-2 py-0.5"
                                  >
                                    <AlertCircle className="h-3 w-3" />
                                    {t('None')}
                                  </Badge>
                                )}
                              </div>
                            </TableCell>
                          )}
                          <TableCell className="px-4 py-3">
                            <Link
                              {...databaseDeepLink(
                                db.$id,
                                (db as { databaseType?: ApiDatabaseType })
                                  .databaseType,
                              )}
                              className="block text-end"
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
                              {...databaseDeepLink(
                                db.$id,
                                (db as { databaseType?: ApiDatabaseType })
                                  .databaseType,
                              )}
                              className="block text-end"
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
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('databases')}
              />
            </>
          ) : (
            <EmptyState
              icon={TABLESDB_LIST_ICON}
              title={
                urlSearch || filterMap.size > 0 ? undefined : t('No databases yet')
              }
              description={
                urlSearch || filterMap.size > 0
                  ? undefined
                  : t('Create your first database to get started')
              }
              isEmpty={!urlSearch && filterMap.size === 0}
              hasFilters={!!urlSearch || filterMap.size > 0}
              variant="card"
            />
          )
        ) : (
          <>
            <div className={RESOURCE_CARD_GRID_CLASSNAME}>
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
                  <DatabaseContextMenu
                    key={db.$id}
                    projectId={projectId}
                    database={{ $id: db.$id, name: db.name }}
                    showSecuritySettings={showDbSecuritySettings}
                    showMonitor={features.usageStats}
                    showBackups={features.databaseBackups}
                    showInsights={features.databaseInsights}
                  >
                    <Link
                      {...databaseDeepLink(
                        db.$id,
                        (db as { databaseType?: ApiDatabaseType }).databaseType,
                      )}
                    >
                      <ResourceCard
                        title={db.name}
                        resourceId={db.$id}
                        icon={TABLESDB_LIST_ICON}
                        iconColor="bg-muted text-muted-foreground"
                        status={db.enabled === false ? 'error' : undefined}
                        statusLabel={
                          db.enabled === false ? t('Disabled') : undefined
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
                                        ? `${(db as DatabaseWithBackup).backupPolicyCount} ${(db as DatabaseWithBackup).backupPolicyCount === 1 ? t('policy') : t('policies')}`
                                        : (db as DatabaseWithBackup)
                                            .backupPolicy?.name ||
                                          t('Backup Enabled')}
                                    </Badge>
                                  ) : (
                                    <Badge
                                      variant="warning"
                                      className="gap-1.5 text-[11px] font-medium"
                                    >
                                      <AlertCircle className="h-3 w-3" />
                                      {t('No backup policies')}
                                    </Badge>
                                  ),
                                },
                              ]
                            : []
                        }
                      />
                    </Link>
                  </DatabaseContextMenu>
                ),
              )}

              {paginatedDatabases.length === 0 && (
                <div className="col-span-full">
                  <EmptyState
                    icon={TABLESDB_LIST_ICON}
                    title={
                      urlSearch || filterMap.size > 0
                        ? undefined
                        : t('No databases yet')
                    }
                    description={
                      urlSearch || filterMap.size > 0
                        ? undefined
                        : t('Create your first database to get started')
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
                pageSizeOptions={[12, 18, 36, 72]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t('databases')}
              />
            )}
          </>
        )}

        {features.dedicatedDbsDocumentsDB && projectId ? (
          <ProductDatabasesSection
            projectId={projectId}
            backend={ApiDatabaseType.Documentsdb}
            title="DocumentsDB"
            description={t('Document-based databases with flexible schemas and dedicated compute.')}
            viewMode={viewMode}
            regionSupported={supportsDedicatedDatabaseCompute}
          />
        ) : null}

        {features.dedicatedDbsVectorsDB && projectId ? (
          <ProductDatabasesSection
            projectId={projectId}
            backend={ApiDatabaseType.Vectorsdb}
            title="VectorsDB"
            description={t('Vector databases for embeddings, semantic search, and AI workloads.')}
            viewMode={viewMode}
            regionSupported={supportsDedicatedDatabaseCompute}
          />
        ) : null}

        {features.nativeDbsPostgres && projectId ? (
          <DedicatedDatabasesSection
            projectId={projectId}
            viewMode={viewMode}
            regionSupported={supportsDedicatedDatabaseCompute}
            nativeEngine="postgres"
          />
        ) : null}

        {features.nativeDbsMySQL && projectId ? (
          <DedicatedDatabasesSection
            projectId={projectId}
            viewMode={viewMode}
            regionSupported={supportsDedicatedDatabaseCompute}
            nativeEngine="mysql"
          />
        ) : null}

        {features.dedicatedDbsSupport && projectId ? (
          <DedicatedDatabasesSection
            projectId={projectId}
            viewMode={viewMode}
            regionSupported={supportsDedicatedDatabaseCompute}
            excludeNativeEngines={
              features.nativeDbsPostgres || features.nativeDbsMySQL
            }
          />
        ) : null}

        {/* Bulk Delete Action Bar */}
        {selectedDatabases.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedDatabases.size}{' '}
                {selectedDatabases.size > 1
                  ? t('databases selected')
                  : t('database selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedDatabases(new Set())}
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

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete Databases')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {selectedDatabases.size > 1
                  ? t('Are you sure you want to delete the selected databases? This action cannot be undone.')
                  : t('Are you sure you want to delete this database? This action cannot be undone.')}
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
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
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const dbKind = (params.dbKind as DatabaseRouteKind | undefined) ?? 'tablesdb'
  const createTableVariantFromRoute = createTableVariantForDbRoute(dbKind)
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, dbKind)
  const ContainerListIcon =
    dbLabels.sdkListContainersMethod === 'listCollections' ? Layers : Table2
  const layoutNav = useMemo(() => dbNavLink(dbKind), [dbKind])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [tablesExpanded, setTablesExpanded] = useState(true)
  const [createTableDialogOpen, setCreateTableDialogOpen] = useState(false)
  const { features } = useConsoleProfile()

  const database = databases.find((db) => db.$id === databaseId)
  const dbTables = collections.filter((c) => c.databaseId === databaseId)

  const createTableMutation = useMutation({
    mutationFn: (data: { tableId?: string; name: string }) =>
      createProjectTable(projectId!, databaseId, data),
    onSuccess: async (table) => {
      toast.success(`${table.name} ${t('has been created')}`)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      navigate({
        ...layoutNav.dataGrid({
          projectId: projectId!,
          dbKind: dbKind,
          databaseId,
          resourceId: table.$id,
        }),
      })
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) ||
          t(`Failed to create ${dbLabels.containerSingular}`),
      )
    },
  })

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
    <div className="@container flex h-full">
      {/* Tables Sidebar */}
      <div className="hidden w-56 shrink-0 flex-col border-e border-border @[800px]:flex">
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
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            {tablesExpanded ? (
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            )}
            <ContainerListIcon className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px] font-medium">
              {t(dbLabels.containerPluralTitle)}
            </span>
            <span className="text-[11px] text-muted-foreground">
              {dbTables.length}
            </span>
          </button>

          {/* Tables List (collapsible) */}
          {tablesExpanded && (
            <div className="ms-3 mt-0.5 border-s border-border ps-2">
              {dbTables.map((table) =>
                projectId ? (
                  <TableContextMenu
                    key={table.$id}
                    projectId={projectId}
                    databaseId={databaseId}
                    dbKind={dbKind}
                    table={table}
                  >
                    <Link
                      {...layoutNav.dataGrid({
                        projectId,
                        dbKind: dbKind,
                        databaseId,
                        resourceId: table.$id,
                      })}
                      className={cn(
                        'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start transition-colors',
                        selectedTable?.$id === table.$id
                          ? 'bg-accent text-foreground'
                          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                      )}
                    >
                      <ContainerListIcon className="h-3.5 w-3.5 shrink-0" />
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
                    {...layoutNav.dataGrid({
                      projectId: '',
                      dbKind: dbKind,
                      databaseId,
                      resourceId: table.$id,
                    })}
                    className={cn(
                      'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start transition-colors',
                      selectedTable?.$id === table.$id
                        ? 'bg-accent text-foreground'
                        : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                    )}
                  >
                    <ContainerListIcon className="h-3.5 w-3.5 shrink-0" />
                    <span className="min-w-0 flex-1 truncate text-[13px]">
                      {table.name}
                    </span>
                    {table.enabled === false && (
                      <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                    )}
                  </Link>
                ),
              )}

              {/* Create container */}
              <button
                onClick={() => setCreateTableDialogOpen(true)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5 shrink-0" />
                <span className="text-[13px]">{t(dbLabels.createContainer)}</span>
              </button>
            </div>
          )}

          {/* Security Link */}
          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/db-security"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Security')}</span>
          </Link>

          {features.dedicatedDbsTablesDB && (
            <Link
              to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
              params={{ projectId, dbKind: dbKind, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
            >
              <Cpu className="h-3.5 w-3.5 shrink-0" />
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-[13px]">
                  {t('Upgrade database specs')}
                </span>
                <span className="text-[11px] text-muted-foreground/80">
                  {t('Serverless')}
                </span>
              </span>
            </Link>
          )}

          {features.databaseInsights && (
            <>
              {/* Insights Link - Coming Soon */}
              <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground/50">
                <BarChart3 className="h-3.5 w-3.5 shrink-0" />
                <span className="flex-1 text-[13px]">{t('Insights')}</span>
                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                  {t('Soon')}
                </span>
              </span>
            </>
          )}
          {features.databaseBackups && (
            <DatabaseBackupsNavLink
              projectId={projectId}
              databaseId={databaseId}
              to="/projects/$projectId/databases/$dbKind/$databaseId/backups"
              params={{ projectId, dbKind: dbKind, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
              labelClassName="flex-1 text-[13px]"
            />
          )}

          {/* Settings Link */}
          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Settings')}</span>
          </Link>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {selectedTable ? (
          <div className="flex flex-1 items-center justify-center">
            <div className="text-center">
              <p className="text-muted-foreground">
                {t(dbLabels.selectContainerHint)}
              </p>
            </div>
          </div>
        ) : (
          <div className="flex flex-1 items-center justify-center">
            <EmptyState
              icon={ContainerListIcon}
              title={t(dbLabels.emptyContainersTitle)}
              description={t(dbLabels.emptyContainersDescription)}
              isEmpty={true}
              iconSize="md"
            />
          </div>
        )}
      </div>
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
        variant={createTableVariantFromRoute}
      />
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
  const t = useT()
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const dbKind = (params.dbKind as DatabaseRouteKind | undefined) ?? 'tablesdb'
  const createTableVariant = createTableVariantForDbRoute(dbKind)
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, dbKind)
  const ContainerListIcon =
    dbLabels.sdkListContainersMethod === 'listCollections' ? Layers : Table2
  const emptyStateNav = useMemo(() => dbNavLink(dbKind), [dbKind])
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
      toast.success(`${table.name} ${t('has been created')}`)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setCreateTableDialogOpen(false)
      navigate({
        ...emptyStateNav.dataGrid({
          projectId: projectId!,
          dbKind: dbKind,
          databaseId: databaseId!,
          resourceId: table.$id,
        }),
      })
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) ||
          t(`Failed to create ${dbLabels.containerSingular}`),
      )
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
    <div className="@container flex h-full">
      {/* Containers sidebar */}
      <div className="hidden w-56 shrink-0 flex-col border-e border-border lg:flex">
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
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            {tablesExpanded ? (
              <ChevronDown className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            )}
            <ContainerListIcon className="h-3.5 w-3.5 shrink-0" />
            <span className="flex-1 text-[13px] font-medium">
              {t(dbLabels.containerPluralTitle)}
            </span>
            <span className="text-[11px] text-muted-foreground">0</span>
          </button>

          {/* Tables List (collapsible) - Empty state */}
          {tablesExpanded && (
            <div className="ms-3 mt-0.5 border-s border-border ps-2">
              <button
                onClick={() => setCreateTableDialogOpen(true)}
                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5 shrink-0" />
                <span className="text-[13px]">{t(dbLabels.createContainer)}</span>
              </button>
            </div>
          )}

          {/* Security Link */}
          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/db-security"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Security')}</span>
          </Link>

          {features.dedicatedDbsTablesDB && (
            <Link
              to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
              params={{ projectId, dbKind: dbKind, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
            >
              <Cpu className="h-3.5 w-3.5 shrink-0" />
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-[13px]">
                  {t('Upgrade database specs')}
                </span>
                <span className="text-[11px] text-muted-foreground/80">
                  {t('Serverless')}
                </span>
              </span>
            </Link>
          )}

          {features.databaseInsights && (
            <>
              {/* Insights Link - Coming Soon */}
              <span className="flex w-full cursor-not-allowed items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground/50">
                <BarChart3 className="h-3.5 w-3.5 shrink-0" />
                <span className="flex-1 text-[13px]">{t('Insights')}</span>
                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                  {t('Soon')}
                </span>
              </span>
            </>
          )}
          {features.databaseBackups && (
            <DatabaseBackupsNavLink
              projectId={projectId}
              databaseId={databaseId}
              to="/projects/$projectId/databases/$dbKind/$databaseId/backups"
              params={{ projectId, dbKind: dbKind, databaseId }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
              labelClassName="flex-1 text-[13px]"
            />
          )}

          {/* Settings Link */}
          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Settings className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Settings')}</span>
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
          {t('Back to databases')}
        </button>

        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <EmptyState
              icon={ContainerListIcon}
              title={t(dbLabels.emptyContainersTitle)}
              description={t(dbLabels.emptyContainersDescription)}
              isEmpty={true}
              iconSize="md"
            />
            <Button
              onClick={() => setCreateTableDialogOpen(true)}
              className="mt-4"
            >
              <Plus className="me-1.5 h-4 w-4" />
              {t(dbLabels.createContainer)}
            </Button>
          </div>
        </div>
      </div>
      <CreateTable
        open={createTableDialogOpen}
        onOpenChange={setCreateTableDialogOpen}
        onCreate={(data) => createTableMutation.mutate(data)}
        isLoading={createTableMutation.isPending}
        variant={createTableVariant}
      />
    </div>
  )
}
