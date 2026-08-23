import { useAuth } from '@/components/global/auth/RequireAuth'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { useQueryClient } from '@tanstack/react-query'
import {
  parsePostgresTableId,
  postgresNav,
  postgresTableId,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import {
  usePostgresSidebarSchemas,
  usePostgresSidebarTables,
  usePostgresSidebarTablesSort,
} from '@/lib/react-query/hooks/postgres-databases'
import {
  POSTGRES_SIDEBAR_TABLES_SORT_OPTIONS,
  sortPostgresSidebarTableRows,
} from '@/lib/user-prefs-keys'
import { ArrowUpDown, Eye, Loader2, Plus, Search, Table2, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { usePostgresDatabase } from '@/lib/react-query/hooks'
import {
  DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE,
  isDedicatedDatabaseProvisioning,
} from '@/lib/databases/dedicated-database-status'
import { PostgresDatabaseNav } from './PostgresDatabaseNav'
import { PostgresSegmentedToggle } from './_components/PostgresSegmentedToggle'
import {
  usePostgresSidebar,
  type PostgresSidebarPanel,
} from './_components/PostgresSidebarContext'
import { PostgresHistorySidebarPanel } from './_components/PostgresHistorySidebarPanel'
import { PostgresQueriesSidebarPanel } from './_components/PostgresQueriesSidebarPanel'
import { PostgresSchemaSelector } from './_components/PostgresSchemaSelector'
import { NativeSidebarDatabaseBar } from '../_components/NativeSidebarDatabaseBar'
import { PostgresTableContextMenu } from './_components/PostgresTableContextMenu'
import { POSTGRES_TOP_HEADER_BAR_CLASS } from './_components/postgres-chrome'
import { useT } from '@/lib/i18n/translate'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import { CreateTable } from './_components/CreateTable'
import { CreateSchema } from './_components/CreateSchema'
import { PostgresSchemaToolsNav } from './_components/PostgresSchemaToolsNav'

type SchemaTablesSidebarProps = {
  projectId: string
  databaseId: string
  databaseName: string
  selectedTableId?: string
  databaseTab?: PostgresDatabaseTab
}

export function SchemaTablesSidebar({
  projectId,
  databaseId,
  databaseName,
  selectedTableId,
  databaseTab,
}: SchemaTablesSidebarProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { account } = useAuth()
  const { canWrite: canModifyTableStructure, writeTooltip: tableWriteTooltip } =
    useDatabaseTableOperationsAccess({
      permissionDeniedTooltip: t("You don't have permission to create schemas."),
    })
  const { database } = usePostgresDatabase(projectId, databaseId)
  const provisioning = isDedicatedDatabaseProvisioning(database?.status)
  const { panel, setPanel, selectedSchema, setSelectedSchema } = usePostgresSidebar()
  const [createSchemaOpen, setCreateSchemaOpen] = useState(false)
  const [createTableOpen, setCreateTableOpen] = useState(false)

  const [schemaPickerOpen, setSchemaPickerOpen] = useState(false)
  const [schemaPickerSearch, setSchemaPickerSearch] = useState('')
  const [debouncedSchemaPickerSearch, setDebouncedSchemaPickerSearch] =
    useState('')

  const [tableSearch, setTableSearch] = useState('')
  const [debouncedTableSearch, setDebouncedTableSearch] = useState('')

  const tablesScrollRef = useRef<HTMLDivElement>(null)
  const tablesSentinelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timeout = window.setTimeout(
      () => setDebouncedSchemaPickerSearch(schemaPickerSearch.trim()),
      300,
    )
    return () => window.clearTimeout(timeout)
  }, [schemaPickerSearch])

  useEffect(() => {
    const timeout = window.setTimeout(
      () => setDebouncedTableSearch(tableSearch.trim()),
      300,
    )
    return () => window.clearTimeout(timeout)
  }, [tableSearch])

  useEffect(() => {
    setTableSearch('')
    setDebouncedTableSearch('')
    setSchemaPickerSearch('')
    setDebouncedSchemaPickerSearch('')
    setSchemaPickerOpen(false)
  }, [databaseId])

  useEffect(() => {
    if (databaseTab === 'visualizer' || databaseTab === 'enums') {
      setPanel('schemas')
    }
  }, [databaseTab, setPanel])

  const {
    schemas: loadedSchemas,
    total: schemasTotal,
    isLoading: schemasLoading,
    isFetching: schemasFetching,
    isFetchingNextPage: isFetchingMoreSchemas,
    hasNextPage: hasMoreSchemas,
    fetchNextPage: fetchNextSchemaPage,
  } = usePostgresSidebarSchemas(
    projectId,
    databaseId,
    schemaPickerOpen ? debouncedSchemaPickerSearch : '',
  )

  const { sort: tablesSort, setSort: setTablesSort } =
    usePostgresSidebarTablesSort(
      databaseId,
      account as { prefs?: Record<string, unknown> } | undefined,
    )

  const {
    tables: visibleTables,
    total: tablesTotal,
    isLoading: tablesLoading,
    isFetching: tablesFetching,
    isFetchingNextPage: isFetchingMoreTables,
    hasNextPage: hasMoreTables,
    fetchNextPage: fetchNextTablePage,
  } = usePostgresSidebarTables(
    projectId,
    databaseId,
    selectedSchema,
    debouncedTableSearch,
  )

  const handleSchemaSearchChange = useCallback((search: string) => {
    setSchemaPickerSearch(search)
  }, [])

  const hasActiveSearch = debouncedTableSearch.length > 0
  const displayedTables = useMemo(
    () => sortPostgresSidebarTableRows(visibleTables, tablesSort),
    [visibleTables, tablesSort],
  )
  const showTablesLoading =
    tablesLoading && visibleTables.length === 0 && !!selectedSchema
  const showSchemasLoading =
    schemasLoading && loadedSchemas.length === 0 && !selectedSchema
  const createSchemaDisabledReason = provisioning
    ? t(DEDICATED_DATABASE_PROVISIONING_RESTRICTED_MESSAGE)
    : !canModifyTableStructure
      ? (tableWriteTooltip ??
        t("You don't have permission to create schemas."))
      : undefined
  const createTableDisabledReason = !selectedSchema
    ? t('Select a schema to create a table.')
    : !canModifyTableStructure
      ? (tableWriteTooltip ??
        t("You don't have permission to modify table structure."))
      : undefined

  const prevSelectedTableIdRef = useRef(selectedTableId)
  useEffect(() => {
    prevSelectedTableIdRef.current = undefined
  }, [databaseId])

  useEffect(() => {
    if (selectedTableId === prevSelectedTableIdRef.current) return
    prevSelectedTableIdRef.current = selectedTableId
    if (!selectedTableId) return
    const { schema } = parsePostgresTableId(selectedTableId)
    if (schema) {
      setSelectedSchema(schema)
    }
  }, [selectedTableId, setSelectedSchema])

  useEffect(() => {
    const sentinel = tablesSentinelRef.current
    const root = tablesScrollRef.current
    if (!sentinel || !root || !hasMoreTables || isFetchingMoreTables) return

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        if (entry.isIntersecting && hasMoreTables && !isFetchingMoreTables) {
          fetchNextTablePage()
        }
      },
      { root, rootMargin: '160px', threshold: 0.1 },
    )

    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [fetchNextTablePage, hasMoreTables, isFetchingMoreTables, visibleTables.length])

  return (
    <aside className="flex h-full min-h-0 w-full flex-col bg-muted/20">
      <NativeSidebarDatabaseBar
        projectId={projectId}
        databaseId={databaseId}
        databaseName={databaseName}
        nativeEngine="postgres"
      />
      <PostgresSegmentedToggle
        variant="bar"
        className={POSTGRES_TOP_HEADER_BAR_CLASS}
        value={panel}
        onValueChange={(value) => setPanel(value as PostgresSidebarPanel)}
        ariaLabel={t('Sidebar panel')}
        options={[
          { value: 'schemas', label: 'Data' },
          { value: 'queries', label: 'Queries' },
          { value: 'history', label: 'History' },
        ]}
      />
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden p-2">
        {panel === 'schemas' ? (
          showSchemasLoading ? (
            <p className="px-2 py-3 text-[12px] text-muted-foreground">
              {t('Loading schemas…')}
            </p>
          ) : (
            <div className="flex min-h-0 flex-1 flex-col gap-2">
              <PostgresSchemaSelector
                value={selectedSchema}
                schemas={loadedSchemas}
                total={schemasTotal}
                isLoading={schemasLoading}
                isFetching={schemasFetching}
                isFetchingNextPage={isFetchingMoreSchemas}
                hasNextPage={hasMoreSchemas}
                onSelect={setSelectedSchema}
                onSearchChange={handleSchemaSearchChange}
                onLoadMore={() => void fetchNextSchemaPage()}
                onOpenChange={setSchemaPickerOpen}
                action={
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="inline-flex">
                          <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="h-8 w-8 shrink-0"
                            aria-label={t('Create schema')}
                            onClick={() => setCreateSchemaOpen(true)}
                            disabled={Boolean(createSchemaDisabledReason)}
                          >
                            <Plus className="h-3.5 w-3.5" />
                          </Button>
                        </span>
                      </TooltipTrigger>
                      <TooltipContent side="top" className="text-xs">
                        {createSchemaDisabledReason ?? t('Create schema')}
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                }
              />
              <div
                className="-mx-2 h-px shrink-0 bg-border"
                role="separator"
                aria-hidden
              />
              <div className="flex min-w-0 shrink-0 items-center gap-2">
                <div className="relative min-w-0 flex-1">
                  <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    type="text"
                    value={tableSearch}
                    onChange={(event) => setTableSearch(event.target.value)}
                    placeholder={t('Search tables...')}
                    className="h-8 ps-8 pe-8 text-[13px]"
                    aria-label={t('Search tables...')}
                    disabled={!selectedSchema}
                  />
                  {tableSearch ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="absolute end-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
                      aria-label={t('Clear table search')}
                      onClick={() => setTableSearch('')}
                    >
                      <X className="h-3.5 w-3.5" />
                    </Button>
                  ) : tablesFetching ? (
                    <Loader2
                      className="pointer-events-none absolute end-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-muted-foreground"
                      aria-hidden
                    />
                  ) : null}
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
                            aria-label={t('Sort tables')}
                            disabled={!selectedSchema}
                          >
                            <ArrowUpDown className="h-3.5 w-3.5" />
                          </Button>
                        </DropdownMenuTrigger>
                      </TooltipTrigger>
                      <TooltipContent side="top" className="text-xs">
                        {t('Sort tables')}
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                  <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                      {t('Sort tables')}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuRadioGroup
                      value={tablesSort}
                      onValueChange={(value) => {
                        const option = POSTGRES_SIDEBAR_TABLES_SORT_OPTIONS.find(
                          (item) => item.value === value,
                        )
                        if (option) setTablesSort(option.value)
                      }}
                    >
                      {POSTGRES_SIDEBAR_TABLES_SORT_OPTIONS.map((option) => (
                        <DropdownMenuRadioItem
                          key={option.value}
                          value={option.value}
                        >
                          {t(option.label)}
                        </DropdownMenuRadioItem>
                      ))}
                    </DropdownMenuRadioGroup>
                  </DropdownMenuContent>
                </DropdownMenu>
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span className="inline-flex">
                        <Button
                          type="button"
                          variant="outline"
                          size="icon"
                          className="h-8 w-8 shrink-0"
                          aria-label={t('Create table')}
                          onClick={() => setCreateTableOpen(true)}
                          disabled={Boolean(createTableDisabledReason)}
                        >
                          <Plus className="h-3.5 w-3.5" />
                        </Button>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                      {createTableDisabledReason ?? t('Create table')}
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
              <div
                ref={tablesScrollRef}
                className="min-h-0 flex-1 overflow-y-auto"
              >
                {!selectedSchema ? (
                  <p className="px-2 py-3 text-[12px] text-muted-foreground">
                    {t('Select a schema to browse tables.')}
                  </p>
                ) : showTablesLoading ? (
                  <p className="px-2 py-3 text-[12px] text-muted-foreground">
                    {t('Loading tables…')}
                  </p>
                ) : displayedTables.length > 0 ? (
                  <div>
                    {displayedTables.map((table) => {
                      const id = postgresTableId(
                        table.table_schema,
                        table.table_name,
                      )
                      const isView = table.table_type === 'VIEW'
                      const TableListIcon = isView ? Eye : Table2
                      return (
                        <PostgresTableContextMenu
                          key={id}
                          projectId={projectId}
                          databaseId={databaseId}
                          tableId={id}
                          tableName={table.table_name}
                        >
                          <Link
                            {...postgresNav({ projectId, databaseId }).table({
                              tableId: id,
                            }).rows()}
                            replace
                            preload="intent"
                            className={cn(
                              'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-1.5 text-start transition-colors',
                              selectedTableId === id
                                ? 'bg-accent text-foreground'
                                : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                            )}
                          >
                            <TableListIcon className="h-3.5 w-3.5 shrink-0" />
                            <span className="min-w-0 truncate text-[13px] font-medium">
                              {table.table_name}
                            </span>
                          </Link>
                        </PostgresTableContextMenu>
                      )
                    })}
                    <div
                      ref={tablesSentinelRef}
                      className="h-px w-full shrink-0"
                      aria-hidden
                    />
                    {isFetchingMoreTables ? (
                      <div className="flex items-center justify-center py-2">
                        <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                      </div>
                    ) : null}
                  </div>
                ) : (
                  <p className="px-2 py-3 text-[12px] text-muted-foreground">
                    {hasActiveSearch
                      ? t('No tables match your search.')
                      : t('No tables in this schema.')}
                  </p>
                )}
                {selectedSchema && tablesTotal > visibleTables.length ? (
                  <p className="px-2 py-2 text-[11px] tabular-nums text-muted-foreground">
                    Showing {visibleTables.length.toLocaleString()} of{' '}
                    {tablesTotal.toLocaleString()} tables
                  </p>
                ) : null}
              </div>
              <div
                className="-mx-2 h-px shrink-0 bg-border"
                role="separator"
                aria-hidden
              />
              <PostgresSchemaToolsNav
                projectId={projectId}
                databaseId={databaseId}
                activeTab={databaseTab}
                className="shrink-0"
              />
            </div>
          )
        ) : panel === 'queries' ? (
          <PostgresQueriesSidebarPanel
            projectId={projectId}
            databaseId={databaseId}
          />
        ) : (
          <PostgresHistorySidebarPanel />
        )}
      </div>
      <PostgresDatabaseNav
        projectId={projectId}
        databaseId={databaseId}
        activeTab={databaseTab}
      />
      <CreateSchema
        open={createSchemaOpen}
        onOpenChange={setCreateSchemaOpen}
        projectId={projectId}
        databaseId={databaseId}
        onSuccess={async (schema) => {
          setSelectedSchema(schema)
          await Promise.all([
            queryClient.refetchQueries({
              queryKey: ['postgres-schemas', 'project', projectId, databaseId],
            }),
            queryClient.refetchQueries({
              queryKey: ['postgres-tables', 'project', projectId, databaseId],
            }),
          ])
        }}
      />
      <CreateTable
        open={createTableOpen}
        onOpenChange={setCreateTableOpen}
        projectId={projectId}
        databaseId={databaseId}
        defaultSchema={selectedSchema}
        onSuccess={async ({ tableId, schema }) => {
          setSelectedSchema(schema)
          await Promise.all([
            queryClient.refetchQueries({
              queryKey: ['postgres-schemas', 'project', projectId, databaseId],
            }),
            queryClient.refetchQueries({
              queryKey: ['postgres-tables', 'project', projectId, databaseId],
            }),
          ])
          navigate({
            ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
          })
        }}
      />
    </aside>
  )
}
