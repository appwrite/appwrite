import { quoteMysqlIdentifier } from '@/lib/mysql-database-routes'
import {
  prefixMysqlSqlComment,
  quoteMysqlStringLiteral,
} from '@/lib/mysql-sql'

export { buildMysqlSingleRequestDdlSql } from '@/lib/mysql-sql'

/**
 * MySQL has no CREATE TYPE for enums. Enums are inline column types.
 * These builders throw so the UI surfaces a clear unsupported message.
 */
export function buildMysqlCreateEnumSql(
  _schema: string,
  _enumName: string,
  _values: string[],
  _options?: { comment?: string },
): string {
  throw new Error(
    'Standalone enum types are not supported on MySQL. Define ENUM(...) on a column instead.',
  )
}

export function buildMysqlAddEnumValueSql(
  _schema: string,
  _enumName: string,
  _value: string,
  _options?: { before?: string; after?: string },
): string {
  throw new Error(
    'Changing ENUM values requires ALTER TABLE ... MODIFY COLUMN on MySQL.',
  )
}

export function buildMysqlRenameEnumValueSql(
  _schema: string,
  _enumName: string,
  _fromValue: string,
  _toValue: string,
): string {
  throw new Error(
    'Renaming ENUM values requires ALTER TABLE ... MODIFY COLUMN on MySQL.',
  )
}

export function buildMysqlRenameEnumTypeSql(
  _schema: string,
  _enumName: string,
  _nextName: string,
): string {
  throw new Error(
    'Standalone enum types are not supported on MySQL.',
  )
}

export function buildMysqlDropEnumSql(_schema: string, _enumName: string): string {
  throw new Error(
    'Standalone enum types are not supported on MySQL. Drop or alter the column instead.',
  )
}

export function buildMysqlEnumCommentSql(
  _schema: string,
  _enumName: string,
  _comment: string | null,
): string {
  throw new Error(
    'Standalone enum type comments are not supported on MySQL.',
  )
}

/** Helper for building an inline ENUM column type fragment. */
export function buildMysqlInlineEnumTypeSql(values: string[]): string {
  const literals = values.map((value) => quoteMysqlStringLiteral(value)).join(', ')
  return `ENUM(${literals})`
}

/** Helper for altering a column to a new ENUM definition. */
export function buildMysqlModifyEnumColumnSql(
  schema: string,
  table: string,
  columnName: string,
  values: string[],
  options?: { nullable?: boolean; comment?: string },
): string {
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  const parts = [
    `ALTER TABLE ${qualified}`,
    `MODIFY COLUMN ${quoteMysqlIdentifier(columnName)} ${buildMysqlInlineEnumTypeSql(values)}`,
  ]
  if (options?.nullable === false) {
    parts.push('NOT NULL')
  }
  if (options?.comment?.trim()) {
    parts.push(`COMMENT ${quoteMysqlStringLiteral(options.comment.trim())}`)
  }
  return prefixMysqlSqlComment(parts.join(' '), 'Modify enum column')
}
