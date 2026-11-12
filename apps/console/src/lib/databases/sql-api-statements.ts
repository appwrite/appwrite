/**
 * Console SQL API statement allow-list.
 *
 * The SQL API sidecar defaults to read/write DML only (SELECT, INSERT, UPDATE,
 * DELETE). DDL/DCL types (CREATE, ALTER, DROP, TRUNCATE, GRANT, REVOKE) are
 * opt-in per database. The console SQL editor and schema UI need those types,
 * so we enable the full allow-list before running console SQL.
 */
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'

export const SQL_API_ALLOWED_STATEMENT_TYPES = [
  'SELECT',
  'INSERT',
  'UPDATE',
  'DELETE',
  'CREATE',
  'ALTER',
  'DROP',
  'TRUNCATE',
  'GRANT',
  'REVOKE',
] as const

export type SqlApiStatementType =
  (typeof SQL_API_ALLOWED_STATEMENT_TYPES)[number]

export const CONSOLE_SQL_API_ALLOWED_STATEMENTS: string[] = [
  ...SQL_API_ALLOWED_STATEMENT_TYPES,
]

function normalizedStatementSet(
  statements: string[] | null | undefined,
): Set<string> {
  return new Set(
    (statements ?? [])
      .map((statement) => statement.trim().toUpperCase())
      .filter(Boolean),
  )
}

/** True when the database already allows every statement the console issues. */
export function hasConsoleSqlApiStatements(
  database:
    | { sqlApiAllowedStatements?: string[] | null }
    | null
    | undefined,
): boolean {
  const allowed = normalizedStatementSet(database?.sqlApiAllowedStatements)
  if (allowed.size === 0) return false
  return CONSOLE_SQL_API_ALLOWED_STATEMENTS.every((type) => allowed.has(type))
}

function errorMessage(error: unknown): string {
  if (typeof error === 'string') return error
  if (typeof error !== 'object' || error === null) return String(error ?? '')
  const record = error as { message?: unknown; response?: unknown }
  return [record.message, record.response]
    .map((value) => (value == null ? '' : String(value)))
    .filter(Boolean)
    .join(' ')
}

/**
 * True when the SQL API rejected DDL because the statement type is not opted
 * in, or executed it inside a read-only transaction (the PostgreSQL symptom
 * of the same default).
 */
export function isSqlApiDdlBlockedError(error: unknown): boolean {
  const message = errorMessage(error)
  if (/read-only transaction/i.test(message)) return true
  if (/cannot execute \w+ in a read-only/i.test(message)) return true
  if (
    /statement type/i.test(message) &&
    /not allowed|allow-?list|allowlist/i.test(message)
  ) {
    return true
  }
  return false
}

export type EnsureConsoleSqlApiStatementsResult = {
  database: Models.DedicatedDatabase | null
  updated: boolean
}

/**
 * Opt the database into the console SQL allow-list when DDL/DCL is missing.
 * Returns `updated: true` only when a PATCH was sent.
 */
export async function ensureConsoleSqlApiStatements(
  projectId: string,
  databaseId: string,
  engine: string,
  database?: Models.DedicatedDatabase | null,
): Promise<EnsureConsoleSqlApiStatementsResult> {
  const service = dedicatedEngineService(sdk.forProject(projectId), engine)
  const current =
    database ??
    (await service.get({ databaseId }).catch(() => null))

  if (!current) {
    return { database: null, updated: false }
  }
  if (hasConsoleSqlApiStatements(current)) {
    return { database: current, updated: false }
  }

  const updated = await service.update({
    databaseId,
    sqlApiEnabled: true,
    sqlApiAllowedStatements: CONSOLE_SQL_API_ALLOWED_STATEMENTS,
  })
  return { database: updated, updated: true }
}
