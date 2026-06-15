import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import {
  quotePostgresStringLiteral,
  escapePostgresLikePattern,
} from '@/lib/postgres-sql'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import {
  getPostgresColumnEditMeta,
  getPostgresInlineFieldType,
} from '@/lib/postgres-row-edits'
import type { CompactFilterKey, FilterColumn, FilterColumnType } from '@/lib/table-filters/types'

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

function quoteFilterValue(value: string | number | boolean): string {
  if (typeof value === 'boolean') return value ? 'TRUE' : 'FALSE'
  if (typeof value === 'number') return String(value)
  return quotePostgresStringLiteral(String(value))
}

function likePattern(value: string, mode: 'contains' | 'starts' | 'ends'): string {
  const escaped = escapePostgresLikePattern(value)
  if (mode === 'starts') return `${escaped}%`
  if (mode === 'ends') return `%${escaped}`
  return `%${escaped}%`
}

function buildSingleFilterCondition(
  key: CompactFilterKey,
  column?: PostgresTableColumnRow,
): string | undefined {
  const columnName = quotePostgresIdentifier(key.c)
  const value = key.v

  switch (key.o) {
    case 'equal':
      if (value === null || value === undefined) return `${columnName} IS NULL`
      return `${columnName} = ${quoteFilterValue(value as string | number | boolean)}`
    case 'notEqual':
      if (value === null || value === undefined) return `${columnName} IS NOT NULL`
      return `${columnName} <> ${quoteFilterValue(value as string | number | boolean)}`
    case 'startsWith':
      return `${columnName}::text ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'starts'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'notStartsWith':
      return `${columnName}::text NOT ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'starts'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'endsWith':
      return `${columnName}::text ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'ends'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'notEndsWith':
      return `${columnName}::text NOT ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'ends'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'contains':
      return `${columnName}::text ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'contains'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'notContains':
      return `${columnName}::text NOT ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'contains'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'search':
      return `${columnName}::text ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'contains'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'notSearch':
      return `${columnName}::text NOT ILIKE ${quotePostgresStringLiteral(likePattern(String(value ?? ''), 'contains'))} ESCAPE ${quotePostgresStringLiteral('\\')}`
    case 'regex':
      return `${columnName}::text ~ ${quotePostgresStringLiteral(String(value ?? ''))}`
    case 'greaterThan':
      return `${columnName} > ${quoteFilterValue(value as string | number | boolean)}`
    case 'greaterThanEqual':
      return `${columnName} >= ${quoteFilterValue(value as string | number | boolean)}`
    case 'lessThan':
      return `${columnName} < ${quoteFilterValue(value as string | number | boolean)}`
    case 'lessThanEqual':
      return `${columnName} <= ${quoteFilterValue(value as string | number | boolean)}`
    case 'between': {
      const values = Array.isArray(value) ? value : []
      if (values.length < 2) return undefined
      return `${columnName} BETWEEN ${quoteFilterValue(values[0] as string | number | boolean)} AND ${quoteFilterValue(values[1] as string | number | boolean)}`
    }
    case 'notBetween': {
      const values = Array.isArray(value) ? value : []
      if (values.length < 2) return undefined
      return `${columnName} NOT BETWEEN ${quoteFilterValue(values[0] as string | number | boolean)} AND ${quoteFilterValue(values[1] as string | number | boolean)}`
    }
    case 'isNull':
      return `${columnName} IS NULL`
    case 'isNotNull':
      return `${columnName} IS NOT NULL`
    case 'exists':
      return `${columnName} IS NOT NULL`
    case 'notExists':
      return `${columnName} IS NULL`
    default:
      if (column && value != null) {
        return `${columnName} = ${quoteFilterValue(value as string | number | boolean)}`
      }
      return undefined
  }
}

export function buildPostgresFilterWhereClause(
  filterKeys: CompactFilterKey[],
  columns: PostgresTableColumnRow[],
): string | undefined {
  const columnByName = new Map(
    columns.map((column) => [column.column_name, column]),
  )
  const conditions = filterKeys
    .map((key) =>
      buildSingleFilterCondition(key, columnByName.get(key.c)),
    )
    .filter((condition): condition is string => Boolean(condition))

  if (conditions.length === 0) return undefined
  return conditions.map((condition) => `(${condition})`).join(' AND ')
}
