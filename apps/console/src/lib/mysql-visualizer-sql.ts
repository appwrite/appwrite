import {
  prefixMysqlSqlComment,
  quoteMysqlStringLiteral,
} from '@/lib/mysql-sql'

/** Relations loaded per visualizer batch (tables/views, then their columns). */
export const MYSQL_VISUALIZER_RELATIONS_BATCH_SIZE = 25

export type MysqlVisualizerColumnRow = {
  table_schema: string
  table_name: string
  table_type: string
  column_name: string
  data_type: string
  udt_name: string
  is_nullable: string
  ordinal_position: number | string
  is_primary_key: boolean | string
}

export type MysqlVisualizerForeignKeyRow = {
  constraint_name: string
  source_schema: string
  source_table: string
  source_column: string
  target_schema: string
  target_table: string
  target_column: string
}

export type MysqlVisualizerRelationRef = {
  schema: string
  table: string
}

function quoteMysqlRelationPair(schema: string, table: string): string {
  return `(${quoteMysqlStringLiteral(schema)}, ${quoteMysqlStringLiteral(table)})`
}

/**
 * Load columns for a batch of tables/views in one schema.
 */
export function buildMysqlVisualizerColumnsBatchSql(
  schema: string,
  tableNames: string[],
): string {
  const trimmedNames = tableNames.map((name) => name.trim()).filter(Boolean)
  if (trimmedNames.length === 0) {
    return prefixMysqlSqlComment(
      'SELECT NULL AS table_schema WHERE FALSE',
      'List visualizer columns (empty batch)',
    )
  }

  const schemaLit = quoteMysqlStringLiteral(schema.trim())
  const namesList = trimmedNames
    .map((name) => quoteMysqlStringLiteral(name))
    .join(', ')

  return prefixMysqlSqlComment(
    `
SELECT
  c.TABLE_SCHEMA AS table_schema,
  c.TABLE_NAME AS table_name,
  t.TABLE_TYPE AS table_type,
  c.COLUMN_NAME AS column_name,
  c.DATA_TYPE AS data_type,
  COALESCE(c.COLUMN_TYPE, c.DATA_TYPE) AS udt_name,
  c.IS_NULLABLE AS is_nullable,
  c.ORDINAL_POSITION AS ordinal_position,
  CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_primary_key
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
  AND t.TABLE_NAME = c.TABLE_NAME
LEFT JOIN (
  SELECT kcu.TABLE_SCHEMA, kcu.TABLE_NAME, kcu.COLUMN_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
    AND tc.TABLE_SCHEMA = ${schemaLit}
) pk
  ON pk.TABLE_SCHEMA = c.TABLE_SCHEMA
  AND pk.TABLE_NAME = c.TABLE_NAME
  AND pk.COLUMN_NAME = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = ${schemaLit}
  AND c.TABLE_NAME IN (${namesList})
  AND t.TABLE_TYPE IN ('BASE TABLE', 'VIEW')
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
`.trim(),
    'List visualizer columns batch',
  )
}

/** Load columns for specific relations that may span multiple schemas (FK targets). */
export function buildMysqlVisualizerExternalColumnsSql(
  relations: MysqlVisualizerRelationRef[],
): string {
  const pairs = relations
    .map(({ schema, table }) => ({
      schema: schema.trim(),
      table: table.trim(),
    }))
    .filter(({ schema, table }) => schema && table)

  if (pairs.length === 0) {
    return prefixMysqlSqlComment(
      'SELECT NULL AS table_schema WHERE FALSE',
      'List visualizer external columns (empty)',
    )
  }

  const pairList = pairs.map(({ schema, table }) =>
    quoteMysqlRelationPair(schema, table),
  )

  return prefixMysqlSqlComment(
    `
SELECT
  c.TABLE_SCHEMA AS table_schema,
  c.TABLE_NAME AS table_name,
  t.TABLE_TYPE AS table_type,
  c.COLUMN_NAME AS column_name,
  c.DATA_TYPE AS data_type,
  COALESCE(c.COLUMN_TYPE, c.DATA_TYPE) AS udt_name,
  c.IS_NULLABLE AS is_nullable,
  c.ORDINAL_POSITION AS ordinal_position,
  CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_primary_key
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
  AND t.TABLE_NAME = c.TABLE_NAME
LEFT JOIN (
  SELECT kcu.TABLE_SCHEMA, kcu.TABLE_NAME, kcu.COLUMN_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
) pk
  ON pk.TABLE_SCHEMA = c.TABLE_SCHEMA
  AND pk.TABLE_NAME = c.TABLE_NAME
  AND pk.COLUMN_NAME = c.COLUMN_NAME
WHERE (c.TABLE_SCHEMA, c.TABLE_NAME) IN (${pairList.join(', ')})
  AND t.TABLE_TYPE IN ('BASE TABLE', 'VIEW')
ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.ORDINAL_POSITION
`.trim(),
    'List visualizer external columns',
  )
}

/** All foreign-key relationships where the source table is in the given schema. */
export function buildMysqlVisualizerForeignKeysSql(schema: string): string {
  const schemaLit = quoteMysqlStringLiteral(schema.trim())

  return prefixMysqlSqlComment(
    `
SELECT
  kcu.CONSTRAINT_NAME AS constraint_name,
  kcu.TABLE_SCHEMA AS source_schema,
  kcu.TABLE_NAME AS source_table,
  kcu.COLUMN_NAME AS source_column,
  kcu.REFERENCED_TABLE_SCHEMA AS target_schema,
  kcu.REFERENCED_TABLE_NAME AS target_table,
  kcu.REFERENCED_COLUMN_NAME AS target_column
FROM information_schema.KEY_COLUMN_USAGE kcu
JOIN information_schema.TABLE_CONSTRAINTS tc
  ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
  AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
  AND tc.TABLE_NAME = kcu.TABLE_NAME
WHERE tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
  AND kcu.TABLE_SCHEMA = ${schemaLit}
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
`.trim(),
    'List visualizer foreign keys',
  )
}
