import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import {
  getPostgresColumnEditMeta,
  getPostgresInlineFieldType,
} from '@/lib/postgres-row-edits'
import type { FilterColumn, FilterColumnType } from '@/lib/table-filters/types'
import {
  buildPostgresFilterWhereClause,
  buildPostgresTextSearchWhereClause,
  combinePostgresWhereClauses,
} from '@/lib/table-filters/sql/postgres'

export {
  buildPostgresFilterWhereClause,
  buildPostgresTextSearchWhereClause,
  combinePostgresWhereClauses,
}

function mapPostgresTypeToFilterType(
  column: PostgresTableColumnRow,
): FilterColumnType {
  const meta = getPostgresColumnEditMeta(column)
  const fieldType = getPostgresInlineFieldType(meta)

  switch (fieldType) {
    case 'boolean':
      return 'boolean'
    case 'integer':
    case 'bigint':
      return fieldType
    case 'double':
      return 'double'
    case 'datetime':
      return 'datetime'
    default:
      return 'string'
  }
}

export function postgresRowsFilterColumnsFromTableColumns(
  columns: PostgresTableColumnRow[],
): FilterColumn[] {
  return columns.map((column) => {
    const type = mapPostgresTypeToFilterType(column)
    return {
      id: column.column_name,
      title: column.column_name,
      type,
      optional: column.is_nullable === 'YES',
    }
  })
}

export function buildPostgresRowsListWhereClause(
  filterKeys: import('@/lib/table-filters/types').CompactFilterKey[] | undefined,
  search: string | undefined,
  columns: PostgresTableColumnRow[],
): string | undefined {
  const filterWhere = filterKeys?.length
    ? buildPostgresFilterWhereClause(filterKeys)
    : undefined

  const textColumns = columns
    .filter((column) => {
      const type = column.data_type.toLowerCase()
      return (
        type.includes('char') ||
        type.includes('text') ||
        type === 'uuid' ||
        type.includes('json')
      )
    })
    .map((column) => column.column_name)

  const searchWhere = search?.trim()
    ? buildPostgresTextSearchWhereClause(search, textColumns)
    : undefined

  return combinePostgresWhereClauses(filterWhere, searchWhere)
}
