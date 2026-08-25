import { useEffect, useMemo, useState } from 'react'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  formatMysqlForeignKeyReference,
  mysqlForeignKeyStateToReference,
  type MysqlForeignKeyState,
} from '@/lib/mysql-column-metadata'
import { formatMysqlColumnType } from '@/lib/mysql-table-ddl'
import { isMysqlPrimaryKeyColumn } from '@/lib/mysql-sql'
import {
  mysqlSidebarSchemasInfiniteQueryOptions,
  mysqlSidebarTablesInfiniteQueryOptions,
  mysqlTableColumnsQueryOptions,
} from '@/lib/react-query/hooks/mysql-databases'
import { useT } from '@/lib/i18n/translate'

type MysqlForeignKeySelectorProps = {
  value: MysqlForeignKeyState
  onChange: (value: MysqlForeignKeyState) => void
  projectId: string
  databaseId: string
  defaultSchema: string
  active?: boolean
  hasMultipleForeignKeys?: boolean
}

export function MysqlForeignKeySelector({
  value,
  onChange,
  projectId,
  databaseId,
  defaultSchema,
  active = true,
  hasMultipleForeignKeys = false,
}: MysqlForeignKeySelectorProps) {
  const t = useT()
  const [schemaSearch, setSchemaSearch] = useState('')
  const [tableSearch, setTableSearch] = useState('')
  const [debouncedSchemaSearch, setDebouncedSchemaSearch] = useState('')
  const [debouncedTableSearch, setDebouncedTableSearch] = useState('')

  useEffect(() => {
    const timeout = window.setTimeout(
      () => setDebouncedSchemaSearch(schemaSearch.trim()),
      300,
    )
    return () => window.clearTimeout(timeout)
  }, [schemaSearch])

  useEffect(() => {
    const timeout = window.setTimeout(
      () => setDebouncedTableSearch(tableSearch.trim()),
      300,
    )
    return () => window.clearTimeout(timeout)
  }, [tableSearch])

  const {
    data: schemasData,
    isLoading: schemasLoading,
    isFetching: schemasFetching,
    isFetchingNextPage: schemasFetchingNextPage,
    fetchNextPage: fetchNextSchemaPage,
    hasNextPage: hasMoreSchemas,
  } = useInfiniteQuery({
    ...mysqlSidebarSchemasInfiniteQueryOptions(
      projectId,
      databaseId,
      debouncedSchemaSearch,
    ),
    enabled: active && value.enabled,
  })

  const {
    data: tablesData,
    isLoading: tablesLoading,
    isFetching: tablesFetching,
    isFetchingNextPage: tablesFetchingNextPage,
    fetchNextPage: fetchNextTablePage,
    hasNextPage: hasMoreTables,
  } = useInfiniteQuery({
    ...mysqlSidebarTablesInfiniteQueryOptions(
      projectId,
      databaseId,
      value.schema || defaultSchema,
      debouncedTableSearch,
    ),
    enabled: active && value.enabled && !!value.schema,
  })

  const referencedTableId =
    value.schema && value.table ? `${value.schema}.${value.table}` : null

  const { data: columnsData, isFetching: columnsLoading } = useQuery({
    ...mysqlTableColumnsQueryOptions(projectId, databaseId, referencedTableId),
    enabled: active && value.enabled && !!referencedTableId,
  })

  const schemas = useMemo(
    () => schemasData?.pages.flatMap((page) => page.schemas) ?? [],
    [schemasData?.pages],
  )
  const schemasTotal = schemasData?.pages[0]?.total ?? schemas.length

  const tables = useMemo(
    () => tablesData?.pages.flatMap((page) => page.tables) ?? [],
    [tablesData?.pages],
  )
  const tablesTotal = tablesData?.pages[0]?.total ?? tables.length

  const schemaItems = useMemo(
    () =>
      schemas.map((schema) => ({
        value: schema,
        label: schema,
      })),
    [schemas],
  )

  const tableItems = useMemo(
    () =>
      tables.map((table) => ({
        value: table.table_name,
        label: table.table_name,
      })),
    [tables],
  )

  const columnItems = useMemo(
    () =>
      (columnsData?.columns ?? []).map((column) => {
        const isPrimaryKey = isMysqlPrimaryKeyColumn(column)
        return {
          value: column.column_name,
          label: column.column_name,
          description: isPrimaryKey
            ? t('Primary key')
            : formatMysqlColumnType(column),
          searchText: `${column.column_name} ${formatMysqlColumnType(column)}`,
        }
      }),
    [columnsData?.columns, t],
  )

  const selectedReference = mysqlForeignKeyStateToReference(value)

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
            {t('Foreign key')}
          </Label>
          <p className="text-[11px] text-muted-foreground mt-1">
            {t('Reference a column in another table.')}
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
          {t('This column has multiple foreign keys. Saving replaces them with a single foreign key.')}
        </p>
      ) : null}

      {value.enabled ? (
        <div className="space-y-3 rounded-lg border border-border bg-muted/20 px-3 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Reference')}
          </p>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-schema" className="text-[12px] font-medium">
              {t('Schema')}
            </Label>
            <SearchableSelect
              value={value.schema}
              onValueChange={handleSchemaChange}
              items={schemaItems}
              placeholder={schemasLoading ? t('Loading schemas…') : t('Select schema')}
              searchPlaceholder={t('Search schemas...')}
              emptyMessage={t('No schemas found')}
              disabled={schemasLoading && schemaItems.length === 0}
              onSearchChange={setSchemaSearch}
              isFetching={schemasFetching}
              hasNextPage={hasMoreSchemas ?? false}
              isFetchingNextPage={schemasFetchingNextPage}
              onLoadMore={() => fetchNextSchemaPage()}
              listFooter={
                schemasTotal > schemas.length
                  ? `Showing ${schemas.length.toLocaleString()} of ${schemasTotal.toLocaleString()} schemas`
                  : undefined
              }
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-table" className="text-[12px] font-medium">
              {t('Table')}
            </Label>
            <SearchableSelect
              value={value.table}
              onValueChange={handleTableChange}
              items={tableItems}
              placeholder={
                !value.schema
                  ? t('Select a schema first')
                  : tablesLoading
                    ? t('Loading tables…')
                    : t('Select table')
              }
              searchPlaceholder={t('Search tables...')}
              emptyMessage={t('No tables found')}
              disabled={!value.schema || (tablesLoading && tableItems.length === 0)}
              onSearchChange={setTableSearch}
              isFetching={tablesFetching}
              hasNextPage={hasMoreTables ?? false}
              isFetchingNextPage={tablesFetchingNextPage}
              onLoadMore={() => fetchNextTablePage()}
              listFooter={
                tablesTotal > tables.length
                  ? `Showing ${tables.length.toLocaleString()} of ${tablesTotal.toLocaleString()} tables`
                  : undefined
              }
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="foreign-key-column" className="text-[12px] font-medium">
              {t('Column')}
            </Label>
            <SearchableSelect
              value={value.column}
              onValueChange={handleColumnChange}
              items={columnItems}
              placeholder={
                !value.table
                  ? t('Select a table first')
                  : columnsLoading
                    ? t('Loading columns…')
                    : t('Select column')
              }
              searchPlaceholder={t('Search columns...')}
              emptyMessage={t('No columns found')}
              disabled={!value.table || (columnsLoading && columnItems.length === 0)}
            />
          </div>
          {selectedReference ? (
            <p className="text-[11px] text-muted-foreground">
              References{' '}
              <code className="font-mono text-[11px] text-foreground">
                {formatMysqlForeignKeyReference(selectedReference)}
              </code>
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
