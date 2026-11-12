import {
  parseMysqlTableId,
  quoteMysqlIdentifier,
} from '@/lib/mysql-database-routes'
import type { MysqlTableColumnRow } from '@/lib/mysql-sql'
import {
  isMysqlPrimaryKeyColumn,
  prefixMysqlSqlComment,
  quoteMysqlStringLiteral,
} from '@/lib/mysql-sql'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export function formatMysqlSqlLiteral(value: RowCellValue): string {
  if (value === null || value === undefined) return 'NULL'
  if (typeof value === 'boolean') return value ? 'TRUE' : 'FALSE'
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new Error('Invalid numeric value.')
    return String(value)
  }
  if (typeof value === 'bigint') return String(value)
  if (typeof value === 'object') {
    return `CAST(${quoteMysqlStringLiteral(JSON.stringify(value))} AS JSON)`
  }
  return quoteMysqlStringLiteral(String(value))
}

/** Kept for API compatibility; MySQL has no ctid and this column is never selected. */
export const MYSQL_ROW_CTID_COLUMN = '__ctid__'

export type MysqlRowIdentity = {
  primaryKeyValues?: Record<string, RowCellValue>
  ctid?: string
}

export type MysqlRowsListOptions = {
  search?: string
  whereClause?: string
  orderByClause?: string
  limit: number
  offset: number
  includeCtid?: boolean
}

function qualifiedTable(schema: string, table: string): string {
  return `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
}

export function getMysqlPrimaryKeyColumns(
  columns: MysqlTableColumnRow[],
): MysqlTableColumnRow[] {
  return columns.filter(isMysqlPrimaryKeyColumn)
}

export function buildMysqlRowIdentityFromRow(
  row: Record<string, unknown>,
  columns: MysqlTableColumnRow[],
): MysqlRowIdentity {
  const pkColumns = getMysqlPrimaryKeyColumns(columns)
  if (pkColumns.length > 0) {
    const primaryKeyValues: Record<string, RowCellValue> = {}
    for (const column of pkColumns) {
      primaryKeyValues[column.column_name] = row[column.column_name] as RowCellValue
    }
    return { primaryKeyValues }
  }

  return {}
}

export function getMysqlRowKey(
  row: Record<string, unknown>,
  columns: MysqlTableColumnRow[],
): string {
  const identity = buildMysqlRowIdentityFromRow(row, columns)
  if (identity.primaryKeyValues) {
    return JSON.stringify(identity.primaryKeyValues)
  }
  return JSON.stringify(row)
}

function buildMysqlRowWhereClause(identity: MysqlRowIdentity): string {
  const conditions: string[] = []

  if (identity.primaryKeyValues) {
    for (const [column, value] of Object.entries(identity.primaryKeyValues)) {
      if (value === null || value === undefined) {
        conditions.push(`${quoteMysqlIdentifier(column)} IS NULL`)
      } else {
        conditions.push(
          `${quoteMysqlIdentifier(column)} = ${formatMysqlSqlLiteral(value)}`,
        )
      }
    }
  }

  if (conditions.length === 0) {
    throw new Error(
      'Cannot identify row for update or delete. MySQL row edits require a primary key.',
    )
  }

  return conditions.join(' AND ')
}

function appendWhereAndOrder(
  baseSql: string,
  options: Pick<
    MysqlRowsListOptions,
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

export function buildMysqlSelectRowsSql(
  tableId: string,
  options: MysqlRowsListOptions,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  // includeCtid is ignored: MySQL has no ctid.
  const base = `SELECT * FROM ${qualified}`
  return prefixMysqlSqlComment(appendWhereAndOrder(base, options), 'Select filtered rows')
}

export function buildMysqlCountRowsSql(
  tableId: string,
  whereClause?: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  let sql = `SELECT COUNT(*) AS total FROM ${qualified}`
  if (whereClause?.trim()) {
    sql += `\nWHERE ${whereClause.trim()}`
  }
  return prefixMysqlSqlComment(sql, 'Count filtered rows')
}

export function buildMysqlUpdateRowSql(
  tableId: string,
  identity: MysqlRowIdentity,
  changes: Record<string, RowCellValue>,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const assignments = Object.entries(changes).map(([column, value]) => {
    if (value === null || value === undefined) {
      return `${quoteMysqlIdentifier(column)} = NULL`
    }
    return `${quoteMysqlIdentifier(column)} = ${formatMysqlSqlLiteral(value)}`
  })

  if (assignments.length === 0) {
    throw new Error('No changes to update.')
  }

  const whereClause = buildMysqlRowWhereClause(identity)
  return prefixMysqlSqlComment(
    `UPDATE ${qualified}\nSET ${assignments.join(', ')}\nWHERE ${whereClause}`,
    'Update table row',
  )
}

/** Columns whose INSERT default comes from AUTO_INCREMENT. */
export function isMysqlSequenceBackedColumn(
  column: MysqlTableColumnRow,
): boolean {
  if (column.serial_sequence?.trim()) return true
  const identity = String(column.is_identity ?? '').toUpperCase()
  if (identity === 'YES' || identity === 'TRUE' || identity === 'T') {
    return true
  }
  const generation = String(column.identity_generation ?? '').toUpperCase()
  if (generation === 'ALWAYS' || generation === 'BY DEFAULT') {
    return true
  }
  const defaultValue = column.column_default?.toLowerCase() ?? ''
  return defaultValue.includes('auto_increment')
}

/** Resolve a sequence name (unused on MySQL; kept for API compatibility). */
export function getMysqlColumnSequenceName(
  column: MysqlTableColumnRow,
): string | null {
  const fromMeta = column.serial_sequence?.trim()
  if (fromMeta) return fromMeta
  return null
}

/**
 * AUTO_INCREMENT needs no sequence sync on MySQL.
 * Returns null so callers skip the sync step.
 */
export function buildSyncMysqlSerialSequencesSql(
  _tableId: string,
  _columns: MysqlTableColumnRow[],
): string | null {
  return null
}

export function isMysqlDuplicatePrimaryKeyError(error: unknown): boolean {
  const normalized = (getErrorMessage(error) ?? String(error)).toLowerCase()
  if (
    normalized.includes('duplicate entry') ||
    normalized.includes('duplicate key')
  ) {
    return (
      normalized.includes('primary') ||
      normalized.includes('unique') ||
      normalized.includes('1062')
    )
  }
  return false
}

export function buildMysqlInsertRowSql(
  tableId: string,
  values: Record<string, RowCellValue>,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const entries = Object.entries(values).filter(
    ([, value]) => value !== undefined,
  )

  if (entries.length === 0) {
    return prefixMysqlSqlComment(
      `INSERT INTO ${qualified} () VALUES ()`,
      'Insert table row',
    )
  }

  const columnNames = entries.map(([column]) => quoteMysqlIdentifier(column))
  const valueLiterals = entries.map(([, value]) =>
    value === null ? 'NULL' : formatMysqlSqlLiteral(value),
  )

  return prefixMysqlSqlComment(
    `INSERT INTO ${qualified} (${columnNames.join(', ')})\nVALUES (${valueLiterals.join(', ')})`,
    'Insert table row',
  )
}

export function buildMysqlDeleteRowSql(
  tableId: string,
  identity: MysqlRowIdentity,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const whereClause = buildMysqlRowWhereClause(identity)
  return prefixMysqlSqlComment(
    `DELETE FROM ${qualified}\nWHERE ${whereClause}`,
    'Delete table row',
  )
}

export {
  buildMysqlTextSearchWhereClause as buildMysqlSearchWhereClause,
  combineMysqlWhereClauses,
} from '@/lib/table-filters/sql/mysql'
