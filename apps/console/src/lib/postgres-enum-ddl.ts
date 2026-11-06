import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import {
  prefixPostgresSqlComment,
  quotePostgresStringLiteral,
} from '@/lib/postgres-sql'

function qualifiedEnumType(schema: string, enumName: string): string {
  return `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(enumName)}`
}

export function buildPostgresCreateEnumSql(
  schema: string,
  enumName: string,
  values: string[],
  options?: { comment?: string },
): string[] {
  const qualified = qualifiedEnumType(schema, enumName)
  const literals = values.map((value) => quotePostgresStringLiteral(value)).join(', ')
  const statements = [
    prefixPostgresSqlComment(
      `CREATE TYPE ${qualified} AS ENUM (${literals})`,
      'Create enum type',
    ),
  ]
  const comment = options?.comment?.trim()
  if (comment) {
    statements.push(buildPostgresEnumCommentSql(schema, enumName, comment))
  }
  return statements
}

export function buildPostgresAddEnumValueSql(
  schema: string,
  enumName: string,
  value: string,
  options?: { before?: string; after?: string },
): string {
  const qualified = qualifiedEnumType(schema, enumName)
  const literal = quotePostgresStringLiteral(value)
  let sql = `ALTER TYPE ${qualified} ADD VALUE ${literal}`

  if (options?.before) {
    sql += ` BEFORE ${quotePostgresStringLiteral(options.before)}`
  } else if (options?.after) {
    sql += ` AFTER ${quotePostgresStringLiteral(options.after)}`
  }

  return prefixPostgresSqlComment(sql, 'Add enum value')
}

export function buildPostgresRenameEnumValueSql(
  schema: string,
  enumName: string,
  fromValue: string,
  toValue: string,
): string {
  return prefixPostgresSqlComment(
    `ALTER TYPE ${qualifiedEnumType(schema, enumName)} RENAME VALUE ${quotePostgresStringLiteral(fromValue)} TO ${quotePostgresStringLiteral(toValue)}`,
    'Rename enum value',
  )
}

export function buildPostgresRenameEnumTypeSql(
  schema: string,
  enumName: string,
  nextName: string,
): string {
  return prefixPostgresSqlComment(
    `ALTER TYPE ${qualifiedEnumType(schema, enumName)} RENAME TO ${quotePostgresIdentifier(nextName)}`,
    'Rename enum type',
  )
}

export function buildPostgresDropEnumSql(schema: string, enumName: string): string {
  return prefixPostgresSqlComment(
    `DROP TYPE ${qualifiedEnumType(schema, enumName)}`,
    'Drop enum type',
  )
}

export function buildPostgresEnumCommentSql(
  schema: string,
  enumName: string,
  comment: string | null,
): string {
  const qualified = qualifiedEnumType(schema, enumName)
  if (!comment?.trim()) {
    return prefixPostgresSqlComment(
      `COMMENT ON TYPE ${qualified} IS NULL`,
      'Clear enum comment',
    )
  }
  return prefixPostgresSqlComment(
    `COMMENT ON TYPE ${qualified} IS ${quotePostgresStringLiteral(comment.trim())}`,
    'Set enum comment',
  )
}
