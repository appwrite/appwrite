import {
  parsePostgresTableId,
  quotePostgresIdentifier,
} from '@/lib/postgres-database-routes'
import { prefixPostgresSqlComment, quotePostgresStringLiteral } from '@/lib/postgres-sql'

export type PostgresPolicyCommand =
  | 'ALL'
  | 'SELECT'
  | 'INSERT'
  | 'UPDATE'
  | 'DELETE'

export type PostgresPolicyPermissive = 'PERMISSIVE' | 'RESTRICTIVE'

export type PostgresTableRlsRow = {
  row_security_enabled: boolean | string
  force_row_security: boolean | string
}

export type PostgresTablePolicyRow = {
  policyname: string
  permissive: string
  roles: string[] | string | null
  cmd: string
  qual: string | null
  with_check: string | null
}

export type PostgresPolicyFormState = {
  name: string
  command: PostgresPolicyCommand
  permissive: PostgresPolicyPermissive
  roles: string
  usingExpression: string
  withCheckExpression: string
}

export function isPostgresTruthyFlag(
  value: boolean | string | null | undefined,
): boolean {
  return value === true || value === 'true' || value === 't'
}

export function parsePostgresPolicyRoles(
  roles: string[] | string | null | undefined,
): string[] {
  if (Array.isArray(roles)) {
    return roles.map((role) => String(role).trim()).filter(Boolean)
  }
  if (typeof roles !== 'string' || !roles.trim()) return []

  const trimmed = roles.trim()
  if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
    const inner = trimmed.slice(1, -1).trim()
    if (!inner) return []
    return inner
      .split(',')
      .map((role) => role.trim().replace(/^"(.*)"$/, '$1'))
      .filter(Boolean)
  }

  return [trimmed]
}

export function formatPostgresPolicyRoles(
  roles: string[] | string | null | undefined,
): string {
  const parsed = parsePostgresPolicyRoles(roles)
  if (parsed.length === 0) return 'PUBLIC'
  return parsed.join(', ')
}

export function createDefaultPostgresPolicyFormState(): PostgresPolicyFormState {
  return {
    name: '',
    command: 'ALL',
    permissive: 'PERMISSIVE',
    roles: 'public',
    usingExpression: '',
    withCheckExpression: '',
  }
}

export function mapPostgresPolicyRowToFormState(
  policy: PostgresTablePolicyRow,
): PostgresPolicyFormState {
  const roles = parsePostgresPolicyRoles(policy.roles)
  return {
    name: policy.policyname,
    command: normalizePostgresPolicyCommand(policy.cmd),
    permissive:
      policy.permissive?.toUpperCase() === 'RESTRICTIVE'
        ? 'RESTRICTIVE'
        : 'PERMISSIVE',
    roles: roles.length > 0 ? roles.join(', ') : 'public',
    usingExpression: policy.qual?.trim() ?? '',
    withCheckExpression: policy.with_check?.trim() ?? '',
  }
}

export function normalizePostgresPolicyCommand(
  command: string | null | undefined,
): PostgresPolicyCommand {
  const normalized = command?.trim().toUpperCase()
  if (
    normalized === 'ALL' ||
    normalized === 'SELECT' ||
    normalized === 'INSERT' ||
    normalized === 'UPDATE' ||
    normalized === 'DELETE'
  ) {
    return normalized
  }
  return 'ALL'
}

function qualifiedPostgresTable(tableId: string): string {
  const { schema, table } = parsePostgresTableId(tableId)
  return `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
}

function formatPostgresPolicyRolesClause(rolesInput: string): string {
  const roles = rolesInput
    .split(',')
    .map((role) => role.trim())
    .filter(Boolean)

  if (roles.length === 0) return 'PUBLIC'

  return roles
    .map((role) => {
      const normalized = role.toLowerCase()
      if (
        normalized === 'public' ||
        normalized === 'current_user' ||
        normalized === 'current_role'
      ) {
        return normalized.toUpperCase()
      }
      return quotePostgresIdentifier(role)
    })
    .join(', ')
}

export function buildPostgresTableRlsStatusSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
SELECT
  c.relrowsecurity AS row_security_enabled,
  c.relforcerowsecurity AS force_row_security
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = ${schemaLit}
  AND c.relname = ${tableLit}
`.trim(),
    'Load table RLS status',
  )
}

export function buildPostgresTablePoliciesSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
SELECT
  policyname,
  permissive,
  roles,
  cmd,
  qual,
  with_check
FROM pg_policies
WHERE schemaname = ${schemaLit}
  AND tablename = ${tableLit}
ORDER BY policyname
`.trim(),
    'List table policies',
  )
}

export function buildPostgresEnableRlsSql(tableId: string): string {
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualifiedPostgresTable(tableId)} ENABLE ROW LEVEL SECURITY`,
    'Enable row level security',
  )
}

export function buildPostgresDisableRlsSql(tableId: string): string {
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualifiedPostgresTable(tableId)} DISABLE ROW LEVEL SECURITY`,
    'Disable row level security',
  )
}

export function buildPostgresForceRlsSql(tableId: string): string {
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualifiedPostgresTable(tableId)} FORCE ROW LEVEL SECURITY`,
    'Force row level security',
  )
}

export function buildPostgresNoForceRlsSql(tableId: string): string {
  return prefixPostgresSqlComment(
    `ALTER TABLE ${qualifiedPostgresTable(tableId)} NO FORCE ROW LEVEL SECURITY`,
    'Disable forced row level security',
  )
}

export function buildPostgresCreatePolicySql(
  tableId: string,
  form: PostgresPolicyFormState,
): string {
  const policyName = form.name.trim()
  if (!policyName) {
    throw new Error('Policy name is required')
  }

  const parts = [
    `CREATE POLICY ${quotePostgresIdentifier(policyName)}`,
    `ON ${qualifiedPostgresTable(tableId)}`,
    `AS ${form.permissive}`,
    `FOR ${form.command}`,
    `TO ${formatPostgresPolicyRolesClause(form.roles)}`,
  ]

  const usingExpression = form.usingExpression.trim()
  if (usingExpression) {
    parts.push(`USING (${usingExpression})`)
  }

  const withCheckExpression = form.withCheckExpression.trim()
  if (withCheckExpression) {
    parts.push(`WITH CHECK (${withCheckExpression})`)
  }

  return prefixPostgresSqlComment(parts.join('\n'), 'Create table policy')
}

export function buildPostgresAlterPolicySql(
  tableId: string,
  policyName: string,
  form: PostgresPolicyFormState,
): string {
  const trimmedName = policyName.trim()
  if (!trimmedName) {
    throw new Error('Policy name is required')
  }

  const parts = [
    `ALTER POLICY ${quotePostgresIdentifier(trimmedName)}`,
    `ON ${qualifiedPostgresTable(tableId)}`,
    `TO ${formatPostgresPolicyRolesClause(form.roles)}`,
  ]

  const usingExpression = form.usingExpression.trim()
  if (usingExpression) {
    parts.push(`USING (${usingExpression})`)
  }

  const withCheckExpression = form.withCheckExpression.trim()
  if (withCheckExpression) {
    parts.push(`WITH CHECK (${withCheckExpression})`)
  }

  return prefixPostgresSqlComment(parts.join('\n'), 'Update table policy')
}

export function buildPostgresDropPolicySql(
  tableId: string,
  policyName: string,
): string {
  const trimmedName = policyName.trim()
  if (!trimmedName) {
    throw new Error('Policy name is required')
  }

  return prefixPostgresSqlComment(
    `DROP POLICY ${quotePostgresIdentifier(trimmedName)} ON ${qualifiedPostgresTable(tableId)}`,
    'Delete table policy',
  )
}

export function validatePostgresPolicyFormState(
  form: PostgresPolicyFormState,
  _options?: { isEdit?: boolean },
): string | null {
  if (!form.name.trim()) {
    return 'Policy name is required'
  }

  return null
}
