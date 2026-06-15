import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  formatPostgresForeignKeyReference,
  postgresForeignKeyStateToReference,
  type PostgresForeignKeyState,
} from '@/lib/postgres-column-metadata'
import { formatPostgresColumnType } from '@/lib/postgres-table-ddl'
import { isPostgresPrimaryKeyColumn } from '@/lib/postgres-sql'
import {
  postgresSchemasQueryOptions,
  postgresTableColumnsQueryOptions,
  postgresTablesQueryOptions,
} from '@/lib/react-query/hooks/postgres-databases'

type PostgresForeignKeySelectorProps = {
  value: PostgresForeignKeyState
  onChange: (value: PostgresForeignKeyState) => void
  projectId: string
  databaseId: string
  defaultSchema: string
  active?: boolean
  hasMultipleForeignKeys?: boolean
}

export function PostgresForeignKeySelector({
  value,
  onChange,
  projectId,
  databaseId,
  defaultSchema,
  active = true,
  hasMultipleForeignKeys = false,
}: PostgresForeignKeySelectorProps) {
  const { data: schemasData, isFetching: schemasLoading } = useQuery({
    ...postgresSchemasQueryOptions(projectId, databaseId),
    enabled: active && value.enabled,
  })

  const { data: tablesData, isFetching: tablesLoading } = useQuery({
    ...postgresTablesQueryOptions(projectId, databaseId, {
      schema: value.schema || defaultSchema,
    }),
    enabled: active && value.enabled && !!value.schema,
  })

  const referencedTableId =
    value.schema && value.table ? `${value.schema}.${value.table}` : null

  const { data: columnsData, isFetching: columnsLoading } = useQuery({
    ...postgresTableColumnsQueryOptions(projectId, databaseId, referencedTableId),
    enabled: active && value.enabled && !!referencedTableId,
  })

  const schemaItems = useMemo(
    () =>
      (schemasData?.schemas ?? []).map((schema) => ({
        value: schema,
        label: schema,
      })),
    [schemasData?.schemas],
  )

  const tableItems = useMemo(
    () =>
      (tablesData?.tables ?? []).map((table) => ({
        value: table.table_name,
        label: table.table_name,
      })),
    [tablesData?.tables],
  )

  const columnItems = useMemo(
    () =>
      (columnsData?.columns ?? []).map((column) => {
        const isPrimaryKey = isPostgresPrimaryKeyColumn(column)
        return {
          value: column.column_name,
          label: column.column_name,
          description: isPrimaryKey
            ? 'Primary key'
            : formatPostgresColumnType(column),
          searchText: `${column.column_name} ${formatPostgresColumnType(column)}`,
        }
      }),
    [columnsData?.columns],
  )

  const selectedReference = postgresForeignKeyStateToReference(value)

  const handleEnabledChange = (enabled: boolean) => {
    onChange({
      ...value,
      enabled,
      schema: value.schema || defaultSchema,
      table: enabled ? value.table : '',
      column: enabled ? value.column : '',
    })
  }

  const handleSchemaChange = (schema: string) => {
    onChange({
      ...value,
      schema,
      table: '',
      column: '',
    })
  }

  const handleTableChange = (table: string) => {
    onChange({
      ...value,
      table,
      column: '',
    })
  }

  const handleColumnChange = (column: string) => {
    onChange({
      ...value,
      column,
    })
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <div>
          <Label
            htmlFor="column-foreign-key-enabled"
            className="text-[12px] font-medium"
          >
            Foreign key
          </Label>
          <p className="text-[11px] text-muted-foreground mt-1">
            Reference a column in another table.
          </p>
        </div>
        <Switch
          id="column-foreign-key-enabled"
          checked={value.enabled}
          onCheckedChange={handleEnabledChange}
        />
      </div>

      {hasMultipleForeignKeys ? (
        <p className="text-[11px] text-muted-foreground">
          This column has multiple foreign keys. Saving replaces them with a
          single foreign key.
        </p>
      ) : null}

      {value.enabled ? (
        <div className="space-y-3 rounded-lg border border-border bg-muted/20 px-3 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            Reference
          </p>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-schema" className="text-[12px] font-medium">
              Schema
            </Label>
            <SearchableSelect
              value={value.schema}
              onValueChange={handleSchemaChange}
              items={schemaItems}
              placeholder={schemasLoading ? 'Loading schemas…' : 'Select schema'}
              searchPlaceholder="Search schemas…"
              emptyMessage="No schemas found"
              disabled={schemasLoading && schemaItems.length === 0}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-table" className="text-[12px] font-medium">
              Table
            </Label>
            <SearchableSelect
              value={value.table}
              onValueChange={handleTableChange}
              items={tableItems}
              placeholder={
                !value.schema
                  ? 'Select a schema first'
                  : tablesLoading
                    ? 'Loading tables…'
                    : 'Select table'
              }
              searchPlaceholder="Search tables…"
              emptyMessage="No tables found"
              disabled={!value.schema || (tablesLoading && tableItems.length === 0)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-column" className="text-[12px] font-medium">
              Column
            </Label>
            <SearchableSelect
              value={value.column}
              onValueChange={handleColumnChange}
              items={columnItems}
              placeholder={
                !value.table
                  ? 'Select a table first'
                  : columnsLoading
                    ? 'Loading columns…'
                    : 'Select column'
              }
              searchPlaceholder="Search columns…"
              emptyMessage="No columns found"
              disabled={!value.table || (columnsLoading && columnItems.length === 0)}
            />
          </div>
          {selectedReference ? (
            <p className="text-[11px] text-muted-foreground">
              References{' '}
              <code className="font-mono text-[11px] text-foreground">
                {formatPostgresForeignKeyReference(selectedReference)}
              </code>
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
