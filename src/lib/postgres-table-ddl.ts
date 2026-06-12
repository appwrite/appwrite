import {
  parsePostgresTableId,
  quotePostgresIdentifier,
} from '@/lib/postgres-database-routes'
import type { PostgresIndexAlgorithm } from '@/lib/postgres-index-metadata'
import { quotePostgresStringLiteral } from '@/lib/postgres-sql'

export function buildPostgresAddColumnSql(
  tableId: string,
  columnName: string,
  dataType: string,
  options?: { nullable?: boolean; defaultValue?: string },
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const parts = [
    `ALTER TABLE ${qualified}`,
    `ADD COLUMN ${quotePostgresIdentifier(columnName)} ${dataType}`,
  ]
  if (options?.nullable === false) {
    parts.push('NOT NULL')
  }
  if (options?.defaultValue?.trim()) {
    parts.push(`DEFAULT ${options.defaultValue.trim()}`)
  }
  return parts.join(' ')
}

export function buildPostgresDropColumnSql(
  tableId: string,
  columnName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `ALTER TABLE ${qualified} DROP COLUMN ${quotePostgresIdentifier(columnName)}`
}

export function buildPostgresRenameColumnSql(
  tableId: string,
  columnName: string,
  newName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `ALTER TABLE ${qualified} RENAME COLUMN ${quotePostgresIdentifier(columnName)} TO ${quotePostgresIdentifier(newName)}`
}

export function buildPostgresAlterColumnTypeSql(
  tableId: string,
  columnName: string,
  dataType: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `ALTER TABLE ${qualified} ALTER COLUMN ${quotePostgresIdentifier(columnName)} TYPE ${dataType}`
}

export function buildPostgresAlterColumnNullableSql(
  tableId: string,
  columnName: string,
  nullable: boolean,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const action = nullable ? 'DROP NOT NULL' : 'SET NOT NULL'
  return `ALTER TABLE ${qualified} ALTER COLUMN ${quotePostgresIdentifier(columnName)} ${action}`
}

export function buildPostgresCreateIndexSql(
  tableId: string,
  indexName: string,
  columnNames: string[],
  options?: {
    unique?: boolean
    algorithm?: PostgresIndexAlgorithm | string
    condition?: string
    includeColumns?: string[]
  },
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const columns = columnNames
    .map((name) => quotePostgresIdentifier(name))
    .join(', ')
  const uniqueKeyword = options?.unique ? 'UNIQUE ' : ''
  const algorithm = (options?.algorithm ?? 'btree').trim().toLowerCase()
  const includeColumns = (options?.includeColumns ?? []).filter(Boolean)
  const includeClause =
    includeColumns.length > 0
      ? ` INCLUDE (${includeColumns.map((name) => quotePostgresIdentifier(name)).join(', ')})`
      : ''
  const condition = options?.condition?.trim()
  const whereClause = condition ? ` WHERE (${condition})` : ''

  return `CREATE ${uniqueKeyword}INDEX ${quotePostgresIdentifier(indexName)} ON ${qualified} USING ${algorithm} (${columns})${includeClause}${whereClause}`
}

export function buildPostgresIndexCommentSql(
  schema: string,
  indexName: string,
  comment: string | null,
): string {
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(indexName)}`
  if (!comment?.trim()) {
    return `COMMENT ON INDEX ${qualified} IS NULL`
  }
  return `COMMENT ON INDEX ${qualified} IS ${quotePostgresStringLiteral(comment.trim())}`
}

export function buildPostgresDropIndexSql(schema: string, indexName: string): string {
  return `DROP INDEX ${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(indexName)}`
}

export function buildPostgresRenameTableSql(
  tableId: string,
  newTableName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `ALTER TABLE ${qualified} RENAME TO ${quotePostgresIdentifier(newTableName)}`
}

export function buildPostgresColumnCommentSql(
  tableId: string,
  columnName: string,
  comment: string | null,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const column = quotePostgresIdentifier(columnName)
  if (!comment?.trim()) {
    return `COMMENT ON COLUMN ${qualified}.${column} IS NULL`
  }
  return `COMMENT ON COLUMN ${qualified}.${column} IS ${quotePostgresStringLiteral(comment.trim())}`
}

export function buildPostgresDropConstraintSql(
  tableId: string,
  constraintName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `ALTER TABLE ${qualified} DROP CONSTRAINT ${quotePostgresIdentifier(constraintName)}`
}

export function buildPostgresAddCheckConstraintSql(
  tableId: string,
  constraintName: string,
  expression: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const trimmed = expression.trim()
  return `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} CHECK (${trimmed})`
}

export function buildPostgresAddForeignKeySql(
  tableId: string,
  columnName: string,
  constraintName: string,
  reference: { schema: string; table: string; column: string },
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const refQualified = `${quotePostgresIdentifier(reference.schema)}.${quotePostgresIdentifier(reference.table)}`
  return `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} FOREIGN KEY (${quotePostgresIdentifier(columnName)}) REFERENCES ${refQualified} (${quotePostgresIdentifier(reference.column)})`
}

export function buildPostgresTableCommentSql(
  tableId: string,
  comment: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `COMMENT ON TABLE ${qualified} IS ${quotePostgresStringLiteral(comment)}`
}

export function buildPostgresDropTableSql(tableId: string): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `DROP TABLE ${qualified}`
}

export function formatPostgresColumnType(row: {
  data_type: string
  character_maximum_length?: number | string | null
  numeric_precision?: number | string | null
  numeric_scale?: number | string | null
}): string {
  const base = row.data_type
  const charLen = row.character_maximum_length
  if (charLen != null && charLen !== '') {
    return `${base}(${charLen})`
  }
  const precision = row.numeric_precision
  const scale = row.numeric_scale
  if (precision != null && precision !== '' && scale != null && scale !== '') {
    return `${base}(${precision},${scale})`
  }
  if (precision != null && precision !== '') {
    return `${base}(${precision})`
  }
  return base
}

export function formatPostgresBytes(bytes: number | string | null | undefined): string {
  const value = typeof bytes === 'string' ? Number.parseInt(bytes, 10) : bytes
  if (value == null || !Number.isFinite(value)) return '-'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`
  return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`
}
