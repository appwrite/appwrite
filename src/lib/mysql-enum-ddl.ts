import { quoteMysqlStringLiteral } from '@/lib/mysql-sql'

/** Inline ENUM column type, e.g. ENUM('open', 'closed'). */
export function buildMysqlInlineEnumTypeSql(values: string[]): string {
  const literals = values.map((value) => quoteMysqlStringLiteral(value)).join(', ')
  return `ENUM(${literals})`
}
