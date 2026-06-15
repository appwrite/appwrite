import {
  parsePostgresTableId,
  quotePostgresIdentifier,
} from '@/lib/postgres-database-routes'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import { quotePostgresStringLiteral } from '@/lib/postgres-sql'
import { isPostgresPrimaryKeyColumn } from '@/lib/postgres-sql'
import type { RowCellValue } from '@/lib/database-row-inline-edits'

export function formatPostgresSqlLiteral(value: RowCellValue): string {
  if (value === null || value === undefined) return 'NULL'
  if (typeof value === 'boolean') return value ? 'TRUE' : 'FALSE'
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new Error('Invalid numeric value.')
    return String(value)
  }
  if (typeof value === 'bigint') return String(value)
  if (typeof value === 'object') {
    return `${quotePostgresStringLiteral(JSON.stringify(value))}::jsonb`
  }
  return quotePostgresStringLiteral(String(value))
}

/** System column included in row selects for tables without a primary key. */
export const POSTGRES_ROW_CTID_COLUMN = '__ctid__'

export type PostgresRowIdentity = {
  primaryKeyValues?: Record<string, RowCellValue>
  ctid?: string
}

export type PostgresRowsListOptions = {
  search?: string
  whereClause?: string
  orderByClause?: string
  limit: number
  offset: number
}

function qualifiedTable(schema: string, table: string): string {
  return `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
}

export function getPostgresPrimaryKeyColumns(
  columns: PostgresTableColumnRow[],
): PostgresTableColumnRow[] {
  return columns.filter(isPostgresPrimaryKeyColumn)
}

export function buildPostgresRowIdentityFromRow(
  row: Record<string, unknown>,
  columns: PostgresTableColumnRow[],
): PostgresRowIdentity {
  const pkColumns = getPostgresPrimaryKeyColumns(columns)
  if (pkColumns.length > 0) {
    const primaryKeyValues: Record<string, RowCellValue> = {}
    for (const column of pkColumns) {
      primaryKeyValues[column.column_name] = row[column.column_name] as RowCellValue
    }
    return { primaryKeyValues }
  }

  const ctid = row[POSTGRES_ROW_CTID_COLUMN]
  return {
    ctid: ctid != null ? String(ctid) : undefined,
  }
}

export function getPostgresRowKey(
  row: Record<string, unknown>,
  columns: PostgresTableColumnRow[],
): string {
  const identity = buildPostgresRowIdentityFromRow(row, columns)
  if (identity.primaryKeyValues) {
    return JSON.stringify(identity.primaryKeyValues)
  }
  if (identity.ctid) {
    return `ctid:${identity.ctid}`
  }
  return JSON.stringify(row)
}

function buildPostgresRowWhereClause(
  identity: PostgresRowIdentity,
): string {
  const conditions: string[] = []

  if (identity.primaryKeyValues) {
    for (const [column, value] of Object.entries(identity.primaryKeyValues)) {
      if (value === null || value === undefined) {
        conditions.push(`${quotePostgresIdentifier(column)} IS NULL`)
      } else {
        conditions.push(
          `${quotePostgresIdentifier(column)} = ${formatPostgresSqlLiteral(value)}`,
        )
      }
    }
  } else if (identity.ctid) {
    conditions.push(`ctid = ${quotePostgresStringLiteral(identity.ctid)}::tid`)
  }

  if (conditions.length === 0) {
    throw new Error('Cannot identify row for update or delete.')
  }

  return conditions.join(' AND ')
}

function appendWhereAndOrder(
  baseSql: string,
  options: Pick<
    PostgresRowsListOptions,
    'whereClause' | 'orderByClause' | 'limit' | 'offset'
  >,
): string {
  let sql = baseSql
  if (options.whereClause?.trim()) {
    sql += `\nWHERE ${options.whereClause.trim()}`
  }
  if (options.orderByClause?.trim()) {
    sql += `\nORDER BY ${options.orderByClause.trim()}`
  }
  sql += `\nLIMIT ${Math.max(1, Math.floor(options.limit))}`
  sql += `\nOFFSET ${Math.max(0, Math.floor(options.offset))}`
  return sql
}

export function buildPostgresSelectRowsSql(
  tableId: string,
  options: PostgresRowsListOptions,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const base = `SELECT *, ctid::text AS ${quotePostgresIdentifier(POSTGRES_ROW_CTID_COLUMN)} FROM ${qualified}`
  return appendWhereAndOrder(base, options)
}

export function buildPostgresCountRowsSql(
  tableId: string,
  whereClause?: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  let sql = `SELECT COUNT(*) AS total FROM ${qualified}`
  if (whereClause?.trim()) {
    sql += `\nWHERE ${whereClause.trim()}`
  }
  return sql
}

export function buildPostgresUpdateRowSql(
  tableId: string,
  identity: PostgresRowIdentity,
  changes: Record<string, RowCellValue>,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const assignments = Object.entries(changes).map(([column, value]) => {
    if (value === null || value === undefined) {
      return `${quotePostgresIdentifier(column)} = NULL`
    }
    return `${quotePostgresIdentifier(column)} = ${formatPostgresSqlLiteral(value)}`
  })

  if (assignments.length === 0) {
    throw new Error('No changes to update.')
  }

  const whereClause = buildPostgresRowWhereClause(identity)
  return `UPDATE ${qualified}\nSET ${assignments.join(', ')}\nWHERE ${whereClause}`
}

export function buildPostgresInsertRowSql(
  tableId: string,
  values: Record<string, RowCellValue>,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const entries = Object.entries(values).filter(
    ([, value]) => value !== undefined,
  )

  if (entries.length === 0) {
    throw new Error('No values to insert.')
  }

  const columnNames = entries.map(([column]) => quotePostgresIdentifier(column))
  const valueLiterals = entries.map(([, value]) =>
    value === null ? 'NULL' : formatPostgresSqlLiteral(value),
  )

  return `INSERT INTO ${qualified} (${columnNames.join(', ')})\nVALUES (${valueLiterals.join(', ')})`
}

export function buildPostgresDeleteRowSql(
  tableId: string,
  identity: PostgresRowIdentity,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const whereClause = buildPostgresRowWhereClause(identity)
  return `DELETE FROM ${qualified}\nWHERE ${whereClause}`
}

export {
  buildPostgresTextSearchWhereClause as buildPostgresSearchWhereClause,
  combinePostgresWhereClauses,
} from '@/lib/table-filters/sql/postgres'
