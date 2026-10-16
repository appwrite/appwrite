/**
 * Postgres SQL emission for the shared filter UI (CompactFilterKey + FILTER_OPERATORS).
 * Mirrors operator keys in `operators.ts` → `buildFilterQueryString` for Appwrite Query.
 * Add new operators in one place (FILTER_OPERATORS) and extend both emitters together.
 */

import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import {
  escapePostgresLikePattern,
  quotePostgresStringLiteral,
} from '@/lib/postgres-sql'
import type { CompactFilterKey, FilterColumnType } from '../types'

function quoteSqlValue(value: string | number | boolean): string {
  if (typeof value === 'boolean') return value ? 'TRUE' : 'FALSE'
  if (typeof value === 'number') return String(value)
  return quotePostgresStringLiteral(String(value))
}

function likePattern(
  value: string,
  mode: 'contains' | 'starts' | 'ends',
): string {
  const escaped = escapePostgresLikePattern(value)
  if (mode === 'starts') return `${escaped}%`
  if (mode === 'ends') return `%${escaped}`
  return `%${escaped}%`
}

function ilikeCondition(
  columnId: string,
  pattern: string,
  negate = false,
): string {
  const column = quotePostgresIdentifier(columnId)
  const patternLit = quotePostgresStringLiteral(pattern)
  const escapeLit = quotePostgresStringLiteral('\\')
  return negate
    ? `${column}::text NOT ILIKE ${patternLit} ESCAPE ${escapeLit}`
    : `${column}::text ILIKE ${patternLit} ESCAPE ${escapeLit}`
}

function parseBetweenValue(
  value: string | number | string[] | boolean | null | undefined,
): [string, string] | null {
  const s =
    typeof value === 'string'
      ? value
      : Array.isArray(value)
        ? value.join(',')
        : String(value ?? '')
  const parts = s
    .split(',')
    .map((p) => p.trim())
    .filter(Boolean)
  if (parts.length >= 2) return [parts[0], parts[1]]
  return null
}

/**
 * Build one SQL predicate from the same operator keys used by FiltersPopover / buildFilterQueryString.
 */
export function buildPostgresFilterSqlCondition(
  operatorKey: string,
  columnId: string,
  value: string | number | string[] | boolean | null | undefined,
  _columnType?: FilterColumnType,
): string | undefined {
  const column = quotePostgresIdentifier(columnId)
  const safeVal = value ?? ''

  switch (operatorKey) {
    case 'equal':
      if (value === null || value === undefined) return `${column} IS NULL`
      return `${column} = ${quoteSqlValue(value as string | number | boolean)}`
    case 'notEqual':
      if (value === null || value === undefined) return `${column} IS NOT NULL`
      return `${column} <> ${quoteSqlValue(value as string | number | boolean)}`
    case 'startsWith':
      return ilikeCondition(columnId, likePattern(String(safeVal), 'starts'))
    case 'notStartsWith':
      return ilikeCondition(
        columnId,
        likePattern(String(safeVal), 'starts'),
        true,
      )
    case 'endsWith':
      return ilikeCondition(columnId, likePattern(String(safeVal), 'ends'))
    case 'notEndsWith':
      return ilikeCondition(
        columnId,
        likePattern(String(safeVal), 'ends'),
        true,
      )
    case 'contains':
    case 'search':
      return ilikeCondition(columnId, likePattern(String(safeVal), 'contains'))
    case 'notContains':
    case 'notSearch':
      return ilikeCondition(
        columnId,
        likePattern(String(safeVal), 'contains'),
        true,
      )
    case 'regex':
      return `${column}::text ~ ${quotePostgresStringLiteral(String(safeVal))}`
    case 'greaterThan':
      return `${column} > ${quoteSqlValue(safeVal as string | number | boolean)}`
    case 'greaterThanEqual':
      return `${column} >= ${quoteSqlValue(safeVal as string | number | boolean)}`
    case 'lessThan':
      return `${column} < ${quoteSqlValue(safeVal as string | number | boolean)}`
    case 'lessThanEqual':
      return `${column} <= ${quoteSqlValue(safeVal as string | number | boolean)}`
    case 'between': {
      const pair = parseBetweenValue(value)
      if (!pair) return undefined
      return `${column} BETWEEN ${quoteSqlValue(pair[0])} AND ${quoteSqlValue(pair[1])}`
    }
    case 'notBetween': {
      const pair = parseBetweenValue(value)
      if (!pair) return undefined
      return `${column} NOT BETWEEN ${quoteSqlValue(pair[0])} AND ${quoteSqlValue(pair[1])}`
    }
    case 'isNull':
      return `${column} IS NULL`
    case 'isNotNull':
      return `${column} IS NOT NULL`
    case 'exists':
      return `${column} IS NOT NULL`
    case 'notExists':
      return `${column} IS NULL`
    default:
      if (value == null) return undefined
      return `${column} = ${quoteSqlValue(value as string | number | boolean)}`
  }
}

export function buildPostgresFilterWhereClause(
  filterKeys: CompactFilterKey[],
): string | undefined {
  const conditions = filterKeys
    .map((key) => buildPostgresFilterSqlCondition(key.o, key.c, key.v))
    .filter((condition): condition is string => Boolean(condition))

  if (conditions.length === 0) return undefined
  return conditions.map((condition) => `(${condition})`).join(' AND ')
}

export function buildPostgresTextSearchWhereClause(
  search: string,
  textColumnNames: string[],
): string | undefined {
  const trimmed = search.trim()
  if (!trimmed || textColumnNames.length === 0) return undefined

  const pattern = likePattern(trimmed, 'contains')
  const patternLit = quotePostgresStringLiteral(pattern)
  const escapeLit = quotePostgresStringLiteral('\\')
  const conditions = textColumnNames.map(
    (column) =>
      `${quotePostgresIdentifier(column)}::text ILIKE ${patternLit} ESCAPE ${escapeLit}`,
  )
  return `(${conditions.join(' OR ')})`
}

export function combinePostgresWhereClauses(
  ...clauses: Array<string | undefined | null>
): string | undefined {
  const parts = clauses
    .map((clause) => clause?.trim())
    .filter((clause): clause is string => Boolean(clause))
  if (parts.length === 0) return undefined
  return parts.map((part) => `(${part})`).join(' AND ')
}
