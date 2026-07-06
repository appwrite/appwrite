import {
  parsePostgresTableId,
  quotePostgresIdentifier,
} from '@/lib/postgres-database-routes'
import type { PostgresIndexAlgorithm } from '@/lib/postgres-index-metadata'
import { quotePostgresStringLiteral, prefixPostgresSqlComment } from '@/lib/postgres-sql'

export function buildPostgresAddColumnSql(
  tableId: string,
  columnName: string,
  dataType: string,
  options?: {
    nullable?: boolean
    defaultValue?: string
    primaryKey?: boolean
    unique?: boolean
  },
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const parts = [
    `ALTER TABLE ${qualified}`,
    `ADD COLUMN ${quotePostgresIdentifier(columnName)} ${dataType}`,
  ]
  if (options?.primaryKey) {
    parts.push('PRIMARY KEY')
  } else if (options?.unique) {
    parts.push('UNIQUE')
  }
  if (options?.nullable === false && !options?.primaryKey) {
    parts.push('NOT NULL')
  }
  if (options?.defaultValue?.trim()) {
    parts.push(`DEFAULT ${options.defaultValue.trim()}`)
  }
  return prefixPostgresSqlComment(parts.join(' '), 'Add table column')
}

export function buildPostgresAlterColumnDefaultSql(
  tableId: string,
  columnName: string,
  defaultValue: string | null,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const column = quotePostgresIdentifier(columnName)
  if (!defaultValue?.trim()) {
    return prefixPostgresSqlComment(
      `ALTER TABLE ${qualified} ALTER COLUMN ${column} DROP DEFAULT`,
      'Drop column default',
    )
  }
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ALTER COLUMN ${column} SET DEFAULT ${defaultValue.trim()}`,
    'Set column default',
  )
}

export function buildPostgresAddPrimaryKeySql(
  tableId: string,
  columnName: string,
  constraintName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} PRIMARY KEY (${quotePostgresIdentifier(columnName)})`,
    'Add primary key',
  )
}

export function buildPostgresAddUniqueConstraintSql(
  tableId: string,
  columnName: string,
  constraintName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} UNIQUE (${quotePostgresIdentifier(columnName)})`,
    'Add unique constraint',
  )
}

export function buildPostgresDropColumnSql(
  tableId: string,
  columnName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} DROP COLUMN ${quotePostgresIdentifier(columnName)}`,
    'Drop table column',
  )
}

export function buildPostgresRenameColumnSql(
  tableId: string,
  columnName: string,
  newName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} RENAME COLUMN ${quotePostgresIdentifier(columnName)} TO ${quotePostgresIdentifier(newName)}`,
    'Rename table column',
  )
}

export function buildPostgresAlterColumnTypeSql(
  tableId: string,
  columnName: string,
  dataType: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ALTER COLUMN ${quotePostgresIdentifier(columnName)} TYPE ${dataType}`,
    'Change column type',
  )
}

export function buildPostgresAlterColumnNullableSql(
  tableId: string,
  columnName: string,
  nullable: boolean,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const action = nullable ? 'DROP NOT NULL' : 'SET NOT NULL'
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ALTER COLUMN ${quotePostgresIdentifier(columnName)} ${action}`,
    'Set column nullable',
  )
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

  return prefixPostgresSqlComment(
    `CREATE ${uniqueKeyword}INDEX ${quotePostgresIdentifier(indexName)} ON ${qualified} USING ${algorithm} (${columns})${includeClause}${whereClause}`,
    'Create table index',
  )
}

export function buildPostgresIndexCommentSql(
  schema: string,
  indexName: string,
  comment: string | null,
): string {
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(indexName)}`
  if (!comment?.trim()) {
    return prefixPostgresSqlComment(
      `COMMENT ON INDEX ${qualified} IS NULL`,
      'Set index comment',
    )
  }
  return prefixPostgresSqlComment(
    `COMMENT ON INDEX ${qualified} IS ${quotePostgresStringLiteral(comment.trim())}`,
    'Set index comment',
  )
}

export function buildPostgresDropIndexSql(schema: string, indexName: string): string {
  return prefixPostgresSqlComment(
    `DROP INDEX ${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(indexName)}`,
    'Drop table index',
  )
}

export function buildPostgresRenameTableSql(
  tableId: string,
  newTableName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} RENAME TO ${quotePostgresIdentifier(newTableName)}`,
    'Rename table',
  )
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
    return prefixPostgresSqlComment(
      `COMMENT ON COLUMN ${qualified}.${column} IS NULL`,
      'Set column comment',
    )
  }
  return prefixPostgresSqlComment(
    `COMMENT ON COLUMN ${qualified}.${column} IS ${quotePostgresStringLiteral(comment.trim())}`,
    'Set column comment',
  )
}

export function buildPostgresDropConstraintSql(
  tableId: string,
  constraintName: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} DROP CONSTRAINT ${quotePostgresIdentifier(constraintName)}`,
    'Drop table constraint',
  )
}

export function buildPostgresAddCheckConstraintSql(
  tableId: string,
  constraintName: string,
  expression: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  const trimmed = expression.trim()
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} CHECK (${trimmed})`,
    'Add check constraint',
  )
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
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quotePostgresIdentifier(constraintName)} FOREIGN KEY (${quotePostgresIdentifier(columnName)}) REFERENCES ${refQualified} (${quotePostgresIdentifier(reference.column)})`,
    'Add foreign key',
  )
}

export function buildPostgresTableCommentSql(
  tableId: string,
  comment: string,
): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(
    `COMMENT ON TABLE ${qualified} IS ${quotePostgresStringLiteral(comment)}`,
    'Set table comment',
  )
}

export function buildPostgresDropTableSql(tableId: string): string {
  const { schema, table } = parsePostgresTableId(tableId)
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return prefixPostgresSqlComment(`DROP TABLE ${qualified}`, 'Drop table')
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
