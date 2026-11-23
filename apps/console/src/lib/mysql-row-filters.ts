import type { MysqlTableColumnRow } from '@/lib/mysql-sql'
import {
  getMysqlColumnEditMeta,
  getMysqlInlineFieldType,
} from '@/lib/mysql-row-edits'
import type { FilterColumn, FilterColumnType } from '@/lib/table-filters/types'
import {
  buildMysqlFilterWhereClause,
  buildMysqlTextSearchWhereClause,
  combineMysqlWhereClauses,
} from '@/lib/table-filters/sql/mysql'

export {
  buildMysqlFilterWhereClause,
  buildMysqlTextSearchWhereClause,
  combineMysqlWhereClauses,
}

function mapMysqlTypeToFilterType(
  column: MysqlTableColumnRow,
): FilterColumnType {
  const meta = getMysqlColumnEditMeta(column)
  const fieldType = getMysqlInlineFieldType(meta)

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

export function mysqlRowsFilterColumnsFromTableColumns(
  columns: MysqlTableColumnRow[],
): FilterColumn[] {
  return columns.map((column) => {
    const type = mapMysqlTypeToFilterType(column)
    return {
      id: column.column_name,
      title: column.column_name,
      type,
      optional: column.is_nullable === 'YES',
    }
  })
}

export function buildMysqlRowsListWhereClause(
  filterKeys: import('@/lib/table-filters/types').CompactFilterKey[] | undefined,
  search: string | undefined,
  columns: MysqlTableColumnRow[],
): string | undefined {
  const filterWhere = filterKeys?.length
    ? buildMysqlFilterWhereClause(filterKeys)
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
    ? buildMysqlTextSearchWhereClause(search, textColumns)
    : undefined

  return combineMysqlWhereClauses(filterWhere, searchWhere)
}
