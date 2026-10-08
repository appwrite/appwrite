import {
  prefixPostgresSqlComment,
  quotePostgresStringLiteral,
} from '@/lib/postgres-sql'

/** Relations loaded per visualizer batch (tables/views, then their columns). */
export const POSTGRES_VISUALIZER_RELATIONS_BATCH_SIZE = 25

export type PostgresVisualizerColumnRow = {
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

export type PostgresVisualizerForeignKeyRow = {
  constraint_name: string
  source_schema: string
  source_table: string
  source_column: string
  target_schema: string
  target_table: string
  target_column: string
}

export type PostgresVisualizerRelationRef = {
  schema: string
  table: string
}

function quotePostgresRelationPair(schema: string, table: string): string {
  return `(${quotePostgresStringLiteral(schema)}, ${quotePostgresStringLiteral(table)})`
}

/**
 * Load columns for a batch of tables/views in one schema.
 * Uses pg_catalog only (no information_schema) for better performance on large schemas.
 */
export function buildPostgresVisualizerColumnsBatchSql(
  schema: string,
  tableNames: string[],
): string {
  const trimmedNames = tableNames.map((name) => name.trim()).filter(Boolean)
  if (trimmedNames.length === 0) {
    return prefixPostgresSqlComment(
      'SELECT NULL::text AS table_schema LIMIT 0',
      'List visualizer columns (empty batch)',
    )
  }

  const schemaLit = quotePostgresStringLiteral(schema.trim())
  const namesList = trimmedNames
    .map((name) => quotePostgresStringLiteral(name))
    .join(', ')

  return prefixPostgresSqlComment(
    `
WITH relations AS (
  SELECT
    c.oid AS rel_oid,
    n.nspname AS table_schema,
    c.relname AS table_name,
    CASE c.relkind
      WHEN 'r' THEN 'BASE TABLE'
      WHEN 'v' THEN 'VIEW'
      ELSE c.relkind::text
    END AS table_type
  FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = ${schemaLit}
    AND c.relname IN (${namesList})
    AND c.relkind IN ('r', 'v')
)
SELECT
  r.table_schema,
  r.table_name,
  r.table_type,
  a.attname AS column_name,
  format_type(a.atttypid, a.atttypmod) AS data_type,
  t.typname AS udt_name,
  CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
  a.attnum AS ordinal_position,
  EXISTS (
    SELECT 1
    FROM pg_catalog.pg_index i
    WHERE i.indrelid = r.rel_oid
      AND i.indisprimary
      AND a.attnum = ANY (i.indkey)
  ) AS is_primary_key
FROM relations r
JOIN pg_catalog.pg_attribute a ON a.attrelid = r.rel_oid
JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
WHERE a.attnum > 0
  AND NOT a.attisdropped
ORDER BY r.table_name, a.attnum
`.trim(),
    'List visualizer columns batch',
  )
}

/** Load columns for specific relations that may span multiple schemas (FK targets). */
export function buildPostgresVisualizerExternalColumnsSql(
  relations: PostgresVisualizerRelationRef[],
): string {
  const pairs = relations
    .map(({ schema, table }) => ({
      schema: schema.trim(),
      table: table.trim(),
    }))
    .filter(({ schema, table }) => schema && table)

  if (pairs.length === 0) {
    return prefixPostgresSqlComment(
      'SELECT NULL::text AS table_schema LIMIT 0',
      'List visualizer external columns (empty)',
    )
  }

  const pairList = pairs.map(({ schema, table }) =>
    quotePostgresRelationPair(schema, table),
  )

  return prefixPostgresSqlComment(
    `
WITH relations AS (
  SELECT
    c.oid AS rel_oid,
    n.nspname AS table_schema,
    c.relname AS table_name,
    CASE c.relkind
      WHEN 'r' THEN 'BASE TABLE'
      WHEN 'v' THEN 'VIEW'
      ELSE c.relkind::text
    END AS table_type
  FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
  WHERE (n.nspname, c.relname) IN (${pairList.join(', ')})
    AND c.relkind IN ('r', 'v')
)
SELECT
  r.table_schema,
  r.table_name,
  r.table_type,
  a.attname AS column_name,
  format_type(a.atttypid, a.atttypmod) AS data_type,
  t.typname AS udt_name,
  CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
  a.attnum AS ordinal_position,
  EXISTS (
    SELECT 1
    FROM pg_catalog.pg_index i
    WHERE i.indrelid = r.rel_oid
      AND i.indisprimary
      AND a.attnum = ANY (i.indkey)
  ) AS is_primary_key
FROM relations r
JOIN pg_catalog.pg_attribute a ON a.attrelid = r.rel_oid
JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
WHERE a.attnum > 0
  AND NOT a.attisdropped
ORDER BY r.table_schema, r.table_name, a.attnum
`.trim(),
    'List visualizer external columns',
  )
}

/** All foreign-key relationships where the source table is in the given schema. */
export function buildPostgresVisualizerForeignKeysSql(schema: string): string {
  const schemaLit = quotePostgresStringLiteral(schema.trim())

  return prefixPostgresSqlComment(
    `
SELECT
  con.conname AS constraint_name,
  src_ns.nspname AS source_schema,
  src.relname AS source_table,
  src_att.attname AS source_column,
  ref_ns.nspname AS target_schema,
  ref.relname AS target_table,
  ref_att.attname AS target_column
FROM pg_catalog.pg_constraint con
JOIN pg_catalog.pg_class src ON src.oid = con.conrelid
JOIN pg_catalog.pg_namespace src_ns ON src_ns.oid = src.relnamespace
JOIN pg_catalog.pg_class ref ON ref.oid = con.confrelid
JOIN pg_catalog.pg_namespace ref_ns ON ref_ns.oid = ref.relnamespace
JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS sk(attnum, ord) ON true
JOIN LATERAL unnest(con.confkey) WITH ORDINALITY AS rk(attnum, ord) ON sk.ord = rk.ord
JOIN pg_catalog.pg_attribute src_att
  ON src_att.attrelid = src.oid
  AND src_att.attnum = sk.attnum
  AND NOT src_att.attisdropped
JOIN pg_catalog.pg_attribute ref_att
  ON ref_att.attrelid = ref.oid
  AND ref_att.attnum = rk.attnum
  AND NOT ref_att.attisdropped
WHERE con.contype = 'f'
  AND src_ns.nspname = ${schemaLit}
  AND src_ns.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND src_ns.nspname NOT LIKE 'pg_temp_%'
  AND src_ns.nspname NOT LIKE 'pg_toast_temp_%'
ORDER BY src.relname, con.conname, sk.ord
`.trim(),
    'List visualizer foreign keys',
  )
}
