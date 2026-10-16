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

function readPostgresPolicyField(
  row: Record<string, unknown>,
  ...keys: string[]
): unknown {
  for (const key of keys) {
    if (key in row) return row[key]
  }
  const lowerEntries = Object.entries(row).map(
    ([entryKey, value]) => [entryKey.toLowerCase(), value] as const,
  )
  for (const key of keys) {
    const match = lowerEntries.find(([entryKey]) => entryKey === key.toLowerCase())
    if (match) return match[1]
  }
  return undefined
}

function readPostgresPolicyText(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value.trim()
  return String(value).trim()
}

export function normalizePostgresPolicyRoleName(role: string): string {
  const trimmed = role.trim()
  const lower = trimmed.toLowerCase()
  if (lower === 'public') return 'public'
  if (lower === 'current_user') return 'CURRENT_USER'
  if (lower === 'current_role') return 'CURRENT_ROLE'
  return trimmed
}

export function parsePostgresPolicyRoles(
  roles: string[] | string | null | undefined,
): string[] {
  if (roles === null || roles === undefined) return []

  if (Array.isArray(roles)) {
    return roles
      .map((role) => normalizePostgresPolicyRoleName(String(role)))
      .filter(Boolean)
  }
  if (typeof roles !== 'string' || !roles.trim()) return []

  const trimmed = roles.trim()
  if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
    const inner = trimmed.slice(1, -1).trim()
    if (!inner) return []
    return inner
      .split(',')
      .map((role) =>
        normalizePostgresPolicyRoleName(role.trim().replace(/^"(.*)"$/, '$1')),
      )
      .filter(Boolean)
  }

  if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
    try {
      const parsed: unknown = JSON.parse(trimmed)
      if (Array.isArray(parsed)) {
        return parsed
          .map((role) => normalizePostgresPolicyRoleName(String(role)))
          .filter(Boolean)
      }
    } catch {
      // Fall through to comma-separated / single-value parsing.
    }
  }

  if (trimmed.includes(',')) {
    return trimmed
      .split(',')
      .map((role) => normalizePostgresPolicyRoleName(role))
      .filter(Boolean)
  }

  return [normalizePostgresPolicyRoleName(trimmed)]
}

export function normalizePostgresTablePolicyRow(
  row: PostgresTablePolicyRow | Record<string, unknown>,
): PostgresTablePolicyRow {
  const source = row as Record<string, unknown>
  const rolesValue = readPostgresPolicyField(source, 'roles')
  const parsedRoles = parsePostgresPolicyRoles(
    rolesValue as string[] | string | null | undefined,
  )

  return {
    policyname: readPostgresPolicyText(
      readPostgresPolicyField(source, 'policyname', 'policy_name'),
    ),
    permissive: readPostgresPolicyText(
      readPostgresPolicyField(source, 'permissive'),
    ),
    roles: parsedRoles.length > 0 ? parsedRoles : null,
    cmd: readPostgresPolicyText(readPostgresPolicyField(source, 'cmd', 'command')),
    qual: readPostgresPolicyText(readPostgresPolicyField(source, 'qual', 'using')) || null,
    with_check:
      readPostgresPolicyText(
        readPostgresPolicyField(source, 'with_check', 'withcheck', 'with check'),
      ) || null,
  }
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
    roles: '',
    usingExpression: '',
    withCheckExpression: '',
  }
}

export function parsePostgresPolicyFormRoles(rolesInput: string): string[] {
  return parsePostgresPolicyRoles(rolesInput)
}

export function formatPostgresPolicyFormRoles(roles: string[]): string {
  return roles.join(', ')
}

function isPostgresPolicyPublicRole(role: string): boolean {
  return role.trim().toLowerCase() === 'public'
}

/** Named roles and PUBLIC are mutually exclusive in PostgreSQL policies. */
export function addPostgresPolicyFormRole(
  selectedRoles: readonly string[],
  roleName: string,
): string[] {
  const trimmed = roleName.trim()
  if (!trimmed) return [...selectedRoles]

  const normalized = normalizePostgresPolicyRoleName(trimmed)

  if (isPostgresPolicyPublicRole(normalized)) {
    return ['public']
  }

  const withoutPublic = selectedRoles.filter(
    (role) => !isPostgresPolicyPublicRole(role),
  )
  if (
    withoutPublic.some(
      (role) => role.trim().toLowerCase() === normalized.toLowerCase(),
    )
  ) {
    return withoutPublic
  }

  return [...withoutPublic, normalized]
}

export function removePostgresPolicyFormRole(
  selectedRoles: readonly string[],
  roleName: string,
): string[] {
  const key = roleName.trim().toLowerCase()
  return selectedRoles.filter((role) => role.trim().toLowerCase() !== key)
}

function resolvePostgresPolicyRolesForSql(rolesInput: string): string[] {
  const roles = parsePostgresPolicyRoles(rolesInput)
  if (roles.length === 0) return []

  const hasNamedRole = roles.some(
    (role) =>
      !isPostgresPolicyPublicRole(role) &&
      role !== 'CURRENT_USER' &&
      role !== 'CURRENT_ROLE',
  )

  if (!hasNamedRole) return roles

  return roles.filter((role) => !isPostgresPolicyPublicRole(role))
}

export function mapPostgresPolicyRowToFormState(
  policy: PostgresTablePolicyRow | Record<string, unknown>,
): PostgresPolicyFormState {
  const normalized = normalizePostgresTablePolicyRow(policy)
  const roles = parsePostgresPolicyRoles(normalized.roles)
  return {
    name: normalized.policyname,
    command: normalizePostgresPolicyCommand(normalized.cmd),
    permissive: normalizePostgresPolicyPermissive(normalized.permissive),
    roles: roles.length > 0 ? roles.join(', ') : '',
    usingExpression: normalized.qual ?? '',
    withCheckExpression: normalized.with_check ?? '',
  }
}

export function normalizePostgresPolicyPermissive(
  permissive: string | null | undefined,
): PostgresPolicyPermissive {
  const normalized = String(permissive ?? 'PERMISSIVE').trim().toUpperCase()
  return normalized === 'RESTRICTIVE' ? 'RESTRICTIVE' : 'PERMISSIVE'
}

export function normalizePostgresPolicyCommand(
  command: string | null | undefined,
): PostgresPolicyCommand {
  const normalized = String(command ?? 'ALL').trim().toUpperCase()
  if (normalized === '*' || normalized === 'ALL') return 'ALL'
  if (
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
  const roles = resolvePostgresPolicyRolesForSql(rolesInput)

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
