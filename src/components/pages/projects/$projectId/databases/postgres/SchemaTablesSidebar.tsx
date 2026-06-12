import { useAuth } from '@/components/global/auth/RequireAuth'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import {
  parsePostgresTableId,
  postgresTableId,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import {
  usePostgresSelectedSchema,
  usePostgresSidebarSchemas,
  usePostgresSidebarTables,
} from '@/lib/react-query/hooks/postgres-databases'
import { Loader2, Search, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Button } from '@/components/ui/button'
import { PostgresDatabaseNav } from './PostgresDatabaseNav'
import { PostgresSpecificationCard } from './PostgresSpecificationCard'
import {
  usePostgresSidebar,
  type PostgresSidebarPanel,
} from './_components/PostgresSidebarContext'
import { PostgresHistorySidebarPanel } from './_components/PostgresHistorySidebarPanel'
import { PostgresQueriesSidebarPanel } from './_components/PostgresQueriesSidebarPanel'
import { PostgresSchemaSelector } from './_components/PostgresSchemaSelector'
import { PostgresSidebarDatabaseBar } from './_components/PostgresSidebarDatabaseBar'
import { PostgresTableContextMenu } from './_components/PostgresTableContextMenu'
import { POSTGRES_TOP_HEADER_BAR_CLASS } from './_components/postgres-chrome'

type SchemaTablesSidebarProps = {
  projectId: string
  databaseId: string
  databaseName: string
  selectedTableId?: string
  databaseTab?: PostgresDatabaseTab
  onSelectTable: (tableId: string) => void
}

export function SchemaTablesSidebar({
  projectId,
  databaseId,
  databaseName,
  selectedTableId,
  databaseTab,
  onSelectTable,
}: SchemaTablesSidebarProps) {
  const { account } = useAuth()
  const { panel, setPanel, recentQueries } = usePostgresSidebar()

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

  const { selectedSchema, setSelectedSchema } = usePostgresSelectedSchema(
    databaseId,
    loadedSchemas,
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
  const showTablesLoading =
    tablesLoading && visibleTables.length === 0 && !!selectedSchema
  const showSchemasLoading =
    schemasLoading && loadedSchemas.length === 0 && !selectedSchema

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
      <PostgresSidebarDatabaseBar
        projectId={projectId}
        databaseId={databaseId}
        databaseName={databaseName}
      />
      <div className={cn('flex px-2', POSTGRES_TOP_HEADER_BAR_CLASS)}>
        <ToggleGroup
          type="single"
          variant="outline"
          size="sm"
          value={panel}
          onValueChange={(value) => {
            if (
              value === 'schemas' ||
              value === 'queries' ||
              value === 'history'
            ) {
              setPanel(value as PostgresSidebarPanel)
            }
          }}
          className="w-full"
          aria-label="Sidebar panel"
        >
          <ToggleGroupItem
            value="schemas"
            className="h-8 flex-1 px-1.5 text-[11px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground sm:text-[12px]"
          >
            Data
          </ToggleGroupItem>
          <ToggleGroupItem
            value="queries"
            className="h-8 flex-1 px-1.5 text-[11px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground sm:text-[12px]"
          >
            Queries
          </ToggleGroupItem>
          <ToggleGroupItem
            value="history"
            className="h-8 flex-1 px-1.5 text-[11px] font-medium data-[state=on]:bg-background data-[state=on]:text-foreground sm:text-[12px]"
          >
            History
            {recentQueries.length > 0 ? (
              <span className="ml-1 text-[10px] tabular-nums text-muted-foreground">
                {recentQueries.length}
              </span>
            ) : null}
          </ToggleGroupItem>
        </ToggleGroup>
      </div>
      <div className="flex min-h-0 flex-1 flex-col overflow-hidden p-2">
        {panel === 'schemas' ? (
          showSchemasLoading ? (
            <p className="px-2 py-3 text-[12px] text-muted-foreground">
              Loading schemas…
            </p>
          ) : (
            <div className="flex min-h-0 flex-1 flex-col gap-2">
              <div className="shrink-0 space-y-2">
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
                />
                <div className="relative">
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    type="search"
                    value={tableSearch}
                    onChange={(event) => setTableSearch(event.target.value)}
                    placeholder="Search tables"
                    className="h-8 pl-8 pr-8 text-[13px]"
                    aria-label="Search tables"
                    disabled={!selectedSchema}
                  />
                  {tableSearch ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="absolute right-0.5 top-1/2 h-7 w-7 -translate-y-1/2 text-muted-foreground"
                      aria-label="Clear table search"
                      onClick={() => setTableSearch('')}
                    >
                      <X className="h-3.5 w-3.5" />
                    </Button>
                  ) : tablesFetching ? (
                    <Loader2
                      className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-muted-foreground"
                      aria-hidden
                    />
                  ) : null}
                </div>
              </div>
              <div
                ref={tablesScrollRef}
                className="min-h-0 flex-1 overflow-y-auto"
              >
                {!selectedSchema ? (
                  <p className="px-2 py-3 text-[12px] text-muted-foreground">
                    Select a schema to browse tables.
                  </p>
                ) : showTablesLoading ? (
                  <p className="px-2 py-3 text-[12px] text-muted-foreground">
                    Loading tables…
                  </p>
                ) : visibleTables.length > 0 ? (
                  <div className="space-y-0.5">
                    {visibleTables.map((table) => {
                      const id = postgresTableId(
                        table.table_schema,
                        table.table_name,
                      )
                      return (
                        <PostgresTableContextMenu
                          key={id}
                          projectId={projectId}
                          databaseId={databaseId}
                          tableId={id}
                          tableName={table.table_name}
                        >
                          <button
                            type="button"
                            onClick={() => onSelectTable(id)}
                            className={cn(
                              'flex w-full cursor-pointer items-center rounded-md px-3 py-2 text-left transition-colors',
                              selectedTableId === id
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-background/70 hover:text-foreground',
                            )}
                          >
                            <span className="truncate text-[13px] font-medium">
                              {table.table_name}
                            </span>
                          </button>
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
                      ? 'No tables match your search.'
                      : 'No tables in this schema.'}
                  </p>
                )}
                {selectedSchema && tablesTotal > visibleTables.length ? (
                  <p className="px-2 py-2 text-[11px] tabular-nums text-muted-foreground">
                    Showing {visibleTables.length.toLocaleString()} of{' '}
                    {tablesTotal.toLocaleString()} tables
                  </p>
                ) : null}
              </div>
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
      <PostgresSpecificationCard
        projectId={projectId}
        databaseId={databaseId}
      />
    </aside>
  )
}
