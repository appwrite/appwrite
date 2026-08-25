import {
  parseMysqlTableId,
  quoteMysqlIdentifier,
} from '@/lib/mysql-database-routes'
import type { MysqlIndexAlgorithm } from '@/lib/mysql-index-metadata'
import { quoteMysqlStringLiteral, prefixMysqlSqlComment } from '@/lib/mysql-sql'

export function buildMysqlAddColumnSql(
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
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const parts = [
    `ALTER TABLE ${qualified}`,
    `ADD COLUMN ${quoteMysqlIdentifier(columnName)} ${dataType}`,
  ]
  if (options?.nullable === false && !options?.primaryKey) {
    parts.push('NOT NULL')
  }
  if (options?.defaultValue?.trim()) {
    parts.push(`DEFAULT ${options.defaultValue.trim()}`)
  }
  if (options?.primaryKey) {
    parts.push('PRIMARY KEY')
  } else if (options?.unique) {
    parts.push('UNIQUE')
  }
  return prefixMysqlSqlComment(parts.join(' '), 'Add table column')
}

export function buildMysqlAlterColumnDefaultSql(
  tableId: string,
  columnName: string,
  defaultValue: string | null,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const column = quoteMysqlIdentifier(columnName)
  if (!defaultValue?.trim()) {
    return prefixMysqlSqlComment(
      `ALTER TABLE ${qualified} ALTER COLUMN ${column} DROP DEFAULT`,
      'Drop column default',
    )
  }
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ALTER COLUMN ${column} SET DEFAULT ${defaultValue.trim()}`,
    'Set column default',
  )
}

export function buildMysqlAddPrimaryKeySql(
  tableId: string,
  columnName: string,
  constraintName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quoteMysqlIdentifier(constraintName)} PRIMARY KEY (${quoteMysqlIdentifier(columnName)})`,
    'Add primary key',
  )
}

export function buildMysqlAddUniqueConstraintSql(
  tableId: string,
  columnName: string,
  constraintName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quoteMysqlIdentifier(constraintName)} UNIQUE (${quoteMysqlIdentifier(columnName)})`,
    'Add unique constraint',
  )
}

export function buildMysqlDropColumnSql(
  tableId: string,
  columnName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} DROP COLUMN ${quoteMysqlIdentifier(columnName)}`,
    'Drop table column',
  )
}

export function buildMysqlRenameColumnSql(
  tableId: string,
  columnName: string,
  newName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} RENAME COLUMN ${quoteMysqlIdentifier(columnName)} TO ${quoteMysqlIdentifier(newName)}`,
    'Rename table column',
  )
}

export function buildMysqlAlterColumnTypeSql(
  tableId: string,
  columnName: string,
  dataType: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} MODIFY COLUMN ${quoteMysqlIdentifier(columnName)} ${dataType}`,
    'Change column type',
  )
}

export function buildMysqlAlterColumnNullableSql(
  tableId: string,
  columnName: string,
  nullable: boolean,
  dataType: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const nullClause = nullable ? 'NULL' : 'NOT NULL'
  // MySQL MODIFY requires the full column definition; callers pass the current type.
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} MODIFY COLUMN ${quoteMysqlIdentifier(columnName)} ${dataType} ${nullClause}`,
    'Set column nullable',
  )
}

export function buildMysqlCreateIndexSql(
  tableId: string,
  indexName: string,
  columnNames: string[],
  options?: {
    unique?: boolean
    algorithm?: MysqlIndexAlgorithm | string
    condition?: string
    includeColumns?: string[]
  },
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const columns = columnNames
    .map((name) => quoteMysqlIdentifier(name))
    .join(', ')
  const uniqueKeyword = options?.unique ? 'UNIQUE ' : ''
  const algorithm = (options?.algorithm ?? 'btree').trim().toLowerCase()
  const usingClause =
    algorithm && algorithm !== 'btree'
      ? ` USING ${algorithm.toUpperCase()}`
      : ' USING BTREE'

  return prefixMysqlSqlComment(
    `CREATE ${uniqueKeyword}INDEX ${quoteMysqlIdentifier(indexName)} ON ${qualified} (${columns})${usingClause}`,
    'Create table index',
  )
}

export function buildMysqlIndexCommentSql(
  tableId: string,
  indexName: string,
  comment: string | null,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const literal = quoteMysqlStringLiteral(comment?.trim() ?? '')
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ALTER INDEX ${quoteMysqlIdentifier(indexName)} COMMENT ${literal}`,
    'Set index comment',
  )
}

export function buildMysqlDropIndexSql(
  tableId: string,
  indexName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `DROP INDEX ${quoteMysqlIdentifier(indexName)} ON ${qualified}`,
    'Drop table index',
  )
}

export function buildMysqlRenameTableSql(
  tableId: string,
  newTableName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `RENAME TABLE ${qualified} TO ${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(newTableName)}`,
    'Rename table',
  )
}

export function buildMysqlColumnCommentSql(
  tableId: string,
  columnName: string,
  comment: string | null,
  dataType: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const column = quoteMysqlIdentifier(columnName)
  const literal = quoteMysqlStringLiteral(comment?.trim() ?? '')
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} MODIFY COLUMN ${column} ${dataType} COMMENT ${literal}`,
    'Set column comment',
  )
}

export function buildMysqlDropConstraintSql(
  tableId: string,
  constraintName: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} DROP CONSTRAINT ${quoteMysqlIdentifier(constraintName)}`,
    'Drop table constraint',
  )
}

export function buildMysqlAddCheckConstraintSql(
  tableId: string,
  constraintName: string,
  expression: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const trimmed = expression.trim()
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quoteMysqlIdentifier(constraintName)} CHECK (${trimmed})`,
    'Add check constraint',
  )
}

export function buildMysqlAddForeignKeySql(
  tableId: string,
  columnName: string,
  constraintName: string,
  reference: { schema: string; table: string; column: string },
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const refQualified = `${quoteMysqlIdentifier(reference.schema)}.${quoteMysqlIdentifier(reference.table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} ADD CONSTRAINT ${quoteMysqlIdentifier(constraintName)} FOREIGN KEY (${quoteMysqlIdentifier(columnName)}) REFERENCES ${refQualified} (${quoteMysqlIdentifier(reference.column)})`,
    'Add foreign key',
  )
}

export function buildMysqlTableCommentSql(
  tableId: string,
  comment: string,
): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `ALTER TABLE ${qualified} COMMENT = ${quoteMysqlStringLiteral(comment)}`,
    'Set table comment',
  )
}

export function buildMysqlDropTableSql(tableId: string): string {
  const { schema, table } = parseMysqlTableId(tableId)
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(`DROP TABLE ${qualified}`, 'Drop table')
}

export function buildMysqlCreateSchemaSql(schemaName: string): string {
  return prefixMysqlSqlComment(
    `CREATE DATABASE ${quoteMysqlIdentifier(schemaName)}`,
    'Create database schema',
  )
}

export function buildMysqlDropSchemaSql(schemaName: string): string {
  return prefixMysqlSqlComment(
    `DROP DATABASE ${quoteMysqlIdentifier(schemaName)}`,
    'Drop database schema',
  )
}

export function formatMysqlColumnType(row: {
  data_type: string
  udt_name?: string
  character_maximum_length?: number | string | null
  numeric_precision?: number | string | null
  numeric_scale?: number | string | null
}): string {
  const dataType = row.data_type?.toLowerCase() ?? ''
  if (dataType === 'enum' && row.udt_name?.trim()) {
    return row.udt_name
  }
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

export function formatMysqlBytes(bytes: number | string | null | undefined): string {
  const value = typeof bytes === 'string' ? Number.parseInt(bytes, 10) : bytes
  if (value == null || !Number.isFinite(value)) return '-'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`
  return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`
}
