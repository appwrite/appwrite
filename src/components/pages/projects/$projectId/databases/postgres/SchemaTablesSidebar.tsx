import { cn } from '@/lib/utils'
import type { PostgresTableRow } from '@/lib/postgres-sql'
import { postgresTableId, type PostgresDatabaseTab } from '@/lib/postgres-database-routes'
import { ChevronDown, ChevronRight, Database } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { PostgresDatabaseNav } from './PostgresDatabaseNav'
import { PostgresSpecificationCard } from './PostgresSpecificationCard'

type SchemaTablesSidebarProps = {
  projectId: string
  databaseId: string
  schemas: string[]
  tables: PostgresTableRow[]
  selectedTableId?: string
  databaseTab?: PostgresDatabaseTab
  isLoading?: boolean
  onSelectTable: (tableId: string) => void
}

export function SchemaTablesSidebar({
  projectId,
  databaseId,
  schemas,
  tables,
  selectedTableId,
  databaseTab,
  isLoading,
  onSelectTable,
}: SchemaTablesSidebarProps) {
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
      <div className="flex h-11 shrink-0 items-center border-b border-border bg-muted/20 px-4 sm:px-6">
        <div className="flex items-center gap-2 text-[13px] font-semibold text-foreground">
          <Database className="h-4 w-4 text-muted-foreground" />
          Schemas
        </div>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto p-2">
        {isLoading && tablesBySchema.length === 0 ? (
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
                              'flex w-full items-center rounded-md px-3 py-2 text-left transition-colors',
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
