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
  AlertCircle,
  Cpu,
} from 'lucide-react'
import {
  databases,
  collections,
} from '@/lib/utils/mock-data'
import { useState, useEffect, useRef, useMemo } from 'react'

import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  databasesQueryOptions,
  createProjectDatabase,
  createProjectTable,
} from '@/lib/react-query/hooks'
import {
  GRID_DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'

import { CreateDatabase } from './CreateDatabase'
import { CreateTable, createTableVariantForDbRoute } from './CreateTable'
import { TableContextMenu } from './_components/TableContextMenu'
import { DatabaseBackupsNavLink } from './_components/DatabaseBackupsNavLink'
import { AllDatabasesSection } from './_components/AllDatabasesSection'

import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import {
  databaseRouteKindFromApiType,
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { ServiceHeader } from '../shared/ServiceHeader'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import {
  Link,
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import {
  mapToQueryParam,
  buildListSearchParams,
  parseListSearch,
  MIN_SEARCH_LENGTH,
  getDatabasesFilterColumns,
  getDatabaseTypeFilterOptions,
  getSelectedDatabaseTypesFromFilterMap,
  omitDatabaseTypeFilters,
  setSelectedDatabaseTypesInFilterMap,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { DatabaseTypeFilterDropdown } from './_components/DatabaseTypeFilterDropdown'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'

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
  const databasesFilterColumns = useMemo(
    () => getDatabasesFilterColumns(features),
    [
      features.dedicatedDbsSupport,
      features.dedicatedDbsDocumentsDB,
      features.dedicatedDbsVectorsDB,
    ],
  )
  const databaseTypeFilterOptions = useMemo(
    () => getDatabaseTypeFilterOptions(features),
    [
      features.dedicatedDbsDocumentsDB,
      features.dedicatedDbsVectorsDB,
    ],
  )
  const selectedDatabaseTypes = useMemo(
    () => getSelectedDatabaseTypesFromFilterMap(filterMap),
    [filterQueryString],
  )
  const [searchInput, setSearchInput] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const isMountedRef = useRef(false)
  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])
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

  const applyDatabaseTypesFilter = (
    types: ApiDatabaseType[],
  ) => {
    const newMap = setSelectedDatabaseTypesInFilterMap(filterMap, types)
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

  const handleSearchChange = (value: string) => {
    setSearchInput(value)
  }

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    // Type filters are single-valued in the map (droplist + Filters share one key).
    const newMap =
      compactKey.c === 'type'
        ? omitDatabaseTypeFilters(filterMap)
        : new Map(filterMap)
    if (replaceKey) {
      const existing = [...newMap.keys()].find(
        (key) =>
          key.c === replaceKey.c &&
          key.o === replaceKey.o &&
          key.v === replaceKey.v,
      )
      if (existing) newMap.delete(existing)
      else newMap.delete(replaceKey)
    }
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

  const handlePageChange = (page: number) => {
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
        createAnalyticsAction="create-database"
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
          <div className="flex shrink-0 items-center gap-2">
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
            {useCreateDatabaseWizard ? (
              <DatabaseTypeFilterDropdown
                options={databaseTypeFilterOptions}
                selectedTypes={selectedDatabaseTypes}
                onSelectedTypesChange={applyDatabaseTypesFilter}
              />
            ) : null}
          </div>
        }
        fullWidthBorder
        fullWidth
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
              fullWidth
            />
          ) : undefined
        }
      />

      <div className="w-full flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {projectId ? (
          <AllDatabasesSection
            projectId={projectId}
            viewMode={viewMode}
            search={urlSearch ?? undefined}
            filterQueries={filterQueries}
            page={urlPage}
            limit={urlLimit}
            onPageChange={handlePageChange}
            onPageSizeChange={handlePageSizeChange}
          />
        ) : null}

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
      createProjectTable(projectId!, databaseId, dbKind, data),
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
        getErrorMessage(error) || dbLabels.failedToCreateContainer,
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
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings/security"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Security')}</span>
          </Link>

          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings/specification"
            params={{ projectId, dbKind: dbKind, databaseId }}
            hash="card-specification"
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
      createProjectTable(projectId!, databaseId!, dbKind, data),
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
        getErrorMessage(error) || dbLabels.failedToCreateContainer,
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
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings/security"
            params={{ projectId, dbKind: dbKind, databaseId }}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
          >
            <Lock className="h-3.5 w-3.5 shrink-0" />
            <span className="text-[13px]">{t('Security')}</span>
          </Link>

          <Link
            to="/projects/$projectId/databases/$dbKind/$databaseId/settings/specification"
            params={{ projectId, dbKind: dbKind, databaseId }}
            hash="card-specification"
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
