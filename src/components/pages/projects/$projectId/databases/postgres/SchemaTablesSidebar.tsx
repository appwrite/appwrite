import { cn } from '@/lib/utils'
import type { PostgresTableRow } from '@/lib/postgres-sql'
import { postgresTableId, type PostgresDatabaseTab } from '@/lib/postgres-database-routes'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { PostgresDatabaseNav } from './PostgresDatabaseNav'
import { PostgresSpecificationCard } from './PostgresSpecificationCard'
import {
  usePostgresSidebar,
  type PostgresSidebarPanel,
} from './_components/PostgresSidebarContext'
import { PostgresHistorySidebarPanel } from './_components/PostgresHistorySidebarPanel'
import { PostgresQueriesSidebarPanel } from './_components/PostgresQueriesSidebarPanel'
import { PostgresSidebarDatabaseBar } from './_components/PostgresSidebarDatabaseBar'
import { POSTGRES_TOP_HEADER_BAR_CLASS } from './_components/postgres-chrome'

type SchemaTablesSidebarProps = {
  projectId: string
  databaseId: string
  databaseName: string
  databaseEngine?: string | null
  schemas: string[]
  tables: PostgresTableRow[]
  selectedTableId?: string
  databaseTab?: PostgresDatabaseTab
  editorActive?: boolean
  isLoading?: boolean
  onSelectTable: (tableId: string) => void
}

export function SchemaTablesSidebar({
  projectId,
  databaseId,
  databaseName,
  databaseEngine,
  schemas,
  tables,
  selectedTableId,
  databaseTab,
  editorActive,
  isLoading,
  onSelectTable,
}: SchemaTablesSidebarProps) {
  const { panel, setPanel, recentQueries } = usePostgresSidebar()

  const tablesBySchema = useMemo(() => {
    const map = new Map<string, PostgresTableRow[]>()
    for (const schema of schemas) {
      map.set(schema, [])
    }
    for (const table of tables) {
      const schema = table.table_schema
      if (!schema) continue
      const existing = map.get(schema) ?? []
      existing.push(table)
      map.set(schema, existing)
    }
    for (const [schema, schemaTables] of map) {
      schemaTables.sort((a, b) => a.table_name.localeCompare(b.table_name))
      map.set(schema, schemaTables)
    }
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b))
  }, [schemas, tables])

  const [expandedSchemas, setExpandedSchemas] = useState<Set<string>>(
    () => new Set(schemas),
  )

  useEffect(() => {
    setExpandedSchemas((prev) => {
      const next = new Set(prev)
      for (const schema of schemas) {
        next.add(schema)
      }
      return next
    })
  }, [schemas])

  const toggleSchema = (schema: string) => {
    setExpandedSchemas((prev) => {
      const next = new Set(prev)
      if (next.has(schema)) {
        next.delete(schema)
      } else {
        next.add(schema)
      }
      return next
    })
  }

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
            Schemas
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
      <div className="min-h-0 flex-1 overflow-y-auto p-2">
        {panel === 'schemas' ? (
          isLoading && tablesBySchema.length === 0 ? (
            <p className="px-2 py-3 text-[12px] text-muted-foreground">
              Loading schemas…
            </p>
          ) : tablesBySchema.length > 0 ? (
            tablesBySchema.map(([schema, schemaTables]) => {
              const isExpanded = expandedSchemas.has(schema)
              return (
                <div key={schema} className="mb-1">
                  <button
                    type="button"
                    onClick={() => toggleSchema(schema)}
                    className="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-[12px] font-semibold uppercase tracking-wide text-muted-foreground transition-colors hover:bg-background/70 hover:text-foreground"
                  >
                    {isExpanded ? (
                      <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                    ) : (
                      <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                    )}
                    <span className="truncate">{schema}</span>
                    <span className="ml-auto text-[10px] font-medium normal-case tracking-normal">
                      {schemaTables.length}
                    </span>
                  </button>
                  {isExpanded ? (
                    <div className="mt-0.5 space-y-0.5 pl-2">
                      {schemaTables.length > 0 ? (
                        schemaTables.map((table) => {
                          const id = postgresTableId(
                            table.table_schema,
                            table.table_name,
                          )
                          return (
                            <button
                              key={id}
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
                          )
                        })
                      ) : (
                        <p className="px-3 py-2 text-[11px] text-muted-foreground">
                          No tables
                        </p>
                      )}
                    </div>
                  ) : null}
                </div>
              )
            })
          ) : (
            <p className="px-2 py-3 text-[12px] text-muted-foreground">
              No schemas found.
            </p>
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
        databaseEngine={databaseEngine}
        activeTab={databaseTab}
        editorActive={editorActive}
      />
      <PostgresSpecificationCard
        projectId={projectId}
        databaseId={databaseId}
      />
    </aside>
  )
}
