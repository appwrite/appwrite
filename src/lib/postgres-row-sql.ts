import {
  parsePostgresTableId,
  quotePostgresIdentifier,
} from '@/lib/postgres-database-routes'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import {
  isPostgresPrimaryKeyColumn,
  prefixPostgresSqlComment,
  quotePostgresStringLiteral,
} from '@/lib/postgres-sql'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import { getErrorMessage } from '@/lib/utils/error-formatting'

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
  includeCtid?: boolean
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
  const includeCtid = options.includeCtid !== false
  const base = includeCtid
    ? `SELECT *, ctid::text AS ${quotePostgresIdentifier(POSTGRES_ROW_CTID_COLUMN)} FROM ${qualified}`
    : `SELECT * FROM ${qualified}`
  return prefixPostgresSqlComment(appendWhereAndOrder(base, options), 'Select filtered rows')
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
  return prefixPostgresSqlComment(sql, 'Count filtered rows')
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
  return prefixPostgresSqlComment(
    `UPDATE ${qualified}\nSET ${assignments.join(', ')}\nWHERE ${whereClause}`,
    'Update table row',
  )
}

/** Columns whose INSERT default comes from a serial/identity sequence. */
export function isPostgresSequenceBackedColumn(
  column: PostgresTableColumnRow,
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
  return defaultValue.includes('nextval(')
}

/** Resolve the sequence name for a serial/identity column. */
export function getPostgresColumnSequenceName(
  column: PostgresTableColumnRow,
): string | null {
  const fromMeta = column.serial_sequence?.trim()
  if (fromMeta) return fromMeta

  const defaultValue = column.column_default ?? ''
  // nextval('schema.seq'::regclass) / nextval('seq'::text)
  const match = defaultValue.match(/nextval\s*\(\s*'((?:[^']|'')+)'/i)
  if (match) return match[1].replace(/''/g, "'")
  return null
}

/**
 * Advance serial/identity sequences past MAX(column) so the next DEFAULT
 * nextval() does not collide with existing primary keys (e.g. after an
 * explicit ID insert left the sequence behind).
 *
 * Uses a DO block (not SELECT) so the SQL API does not wrap it as a read
 * query, which can skip or roll back setval().
 */
export function buildSyncPostgresSerialSequencesSql(
  tableId: string,
  columns: PostgresTableColumnRow[],
): string | null {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const qualifiedLiteral = quotePostgresStringLiteral(`${schema}.${table}`)
  const blocks: string[] = []

  for (const column of columns) {
    if (!isPostgresSequenceBackedColumn(column)) continue
    const columnName = quotePostgresIdentifier(column.column_name)
    const columnNameLiteral = quotePostgresStringLiteral(column.column_name)
    const sequenceName = getPostgresColumnSequenceName(column)
    // Prefer an explicit sequence name (metadata or nextval default). Fall back
    // to pg_get_serial_sequence when the sequence is owned by the column.
    const sequenceExpr = sequenceName
      ? `${quotePostgresStringLiteral(sequenceName)}::regclass`
      : `pg_get_serial_sequence(${qualifiedLiteral}, ${columnNameLiteral})::regclass`

    // is_called=false when the table is empty so the next nextval() returns 1;
    // is_called=true when rows exist so the next value is MAX+1.
    blocks.push(`
seq := ${sequenceExpr};
IF seq IS NOT NULL THEN
  SELECT MAX(${columnName}) INTO max_id FROM ${qualified};
  IF max_id IS NULL THEN
    PERFORM setval(seq, 1, false);
  ELSE
    PERFORM setval(seq, max_id, true);
  END IF;
END IF;`.trim())
  }

  if (blocks.length === 0) return null

  return prefixPostgresSqlComment(
    `DO $sync$
DECLARE
  seq regclass;
  max_id bigint;
BEGIN
  ${blocks.join('\n  ')}
END
$sync$`,
    'Sync serial sequences',
  )
}

export function isPostgresDuplicatePrimaryKeyError(error: unknown): boolean {
  const normalized = (getErrorMessage(error) ?? String(error)).toLowerCase()
  if (!normalized.includes('duplicate key')) return false
  return (
    normalized.includes('pkey') ||
    normalized.includes('unique constraint') ||
    normalized.includes('primary key')
  )
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
    return prefixPostgresSqlComment(
      `INSERT INTO ${qualified} DEFAULT VALUES`,
      'Insert table row',
    )
  }

  const columnNames = entries.map(([column]) => quotePostgresIdentifier(column))
  const valueLiterals = entries.map(([, value]) =>
    value === null ? 'NULL' : formatPostgresSqlLiteral(value),
  )

  return prefixPostgresSqlComment(
    `INSERT INTO ${qualified} (${columnNames.join(', ')})\nVALUES (${valueLiterals.join(', ')})`,
    'Insert table row',
  )
}

export function buildPostgresDeleteRowSql(
  tableId: string,
  identity: PostgresRowIdentity,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = qualifiedTable(schema, table)
  const whereClause = buildPostgresRowWhereClause(identity)
  return prefixPostgresSqlComment(
    `DELETE FROM ${qualified}\nWHERE ${whereClause}`,
    'Delete table row',
  )
}

export {
  buildPostgresTextSearchWhereClause as buildPostgresSearchWhereClause,
  combinePostgresWhereClauses,
} from '@/lib/table-filters/sql/postgres'
