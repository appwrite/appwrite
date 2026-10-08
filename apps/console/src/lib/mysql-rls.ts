import { prefixMysqlSqlComment, quoteMysqlStringLiteral } from '@/lib/mysql-sql'

export type MysqlPolicyCommand =
  | 'ALL'
  | 'SELECT'
  | 'INSERT'
  | 'UPDATE'
  | 'DELETE'

export type MysqlPolicyPermissive = 'PERMISSIVE' | 'RESTRICTIVE'

export type MysqlTableRlsRow = {
  row_security_enabled: boolean | string
  force_row_security: boolean | string
}

export type MysqlTablePolicyRow = {
  policyname: string
  permissive: string
  roles: string[] | string | null
  cmd: string
  qual: string | null
  with_check: string | null
}

export type MysqlPolicyFormState = {
  name: string
  command: MysqlPolicyCommand
  permissive: MysqlPolicyPermissive
  roles: string
  usingExpression: string
  withCheckExpression: string
}

export function isMysqlTruthyFlag(
  value: boolean | string | null | undefined,
): boolean {
  return value === true || value === 'true' || value === 't'
}

function readMysqlPolicyField(
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

function readMysqlPolicyText(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value.trim()
  return String(value).trim()
}

export function normalizeMysqlPolicyRoleName(role: string): string {
  const trimmed = role.trim()
  const lower = trimmed.toLowerCase()
  if (lower === 'public') return 'public'
  if (lower === 'current_user') return 'CURRENT_USER'
  if (lower === 'current_role') return 'CURRENT_ROLE'
  return trimmed
}

export function parseMysqlPolicyRoles(
  roles: string[] | string | null | undefined,
): string[] {
  if (roles === null || roles === undefined) return []

  if (Array.isArray(roles)) {
    return roles
      .map((role) => normalizeMysqlPolicyRoleName(String(role)))
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
        normalizeMysqlPolicyRoleName(role.trim().replace(/^"(.*)"$/, '$1')),
      )
      .filter(Boolean)
  }

  if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
    try {
      const parsed: unknown = JSON.parse(trimmed)
      if (Array.isArray(parsed)) {
        return parsed
          .map((role) => normalizeMysqlPolicyRoleName(String(role)))
          .filter(Boolean)
      }
    } catch {
      // Fall through to comma-separated / single-value parsing.
    }
  }

  if (trimmed.includes(',')) {
    return trimmed
      .split(',')
      .map((role) => normalizeMysqlPolicyRoleName(role))
      .filter(Boolean)
  }

  return [normalizeMysqlPolicyRoleName(trimmed)]
}

export function normalizeMysqlTablePolicyRow(
  row: MysqlTablePolicyRow | Record<string, unknown>,
): MysqlTablePolicyRow {
  const source = row as Record<string, unknown>
  const rolesValue = readMysqlPolicyField(source, 'roles')
  const parsedRoles = parseMysqlPolicyRoles(
    rolesValue as string[] | string | null | undefined,
  )

  return {
    policyname: readMysqlPolicyText(
      readMysqlPolicyField(source, 'policyname', 'policy_name'),
    ),
    permissive: readMysqlPolicyText(
      readMysqlPolicyField(source, 'permissive'),
    ),
    roles: parsedRoles.length > 0 ? parsedRoles : null,
    cmd: readMysqlPolicyText(readMysqlPolicyField(source, 'cmd', 'command')),
    qual: readMysqlPolicyText(readMysqlPolicyField(source, 'qual', 'using')) || null,
    with_check:
      readMysqlPolicyText(
        readMysqlPolicyField(source, 'with_check', 'withcheck', 'with check'),
      ) || null,
  }
}

export function formatMysqlPolicyRoles(
  roles: string[] | string | null | undefined,
): string {
  const parsed = parseMysqlPolicyRoles(roles)
  if (parsed.length === 0) return 'PUBLIC'
  return parsed.join(', ')
}

export function createDefaultMysqlPolicyFormState(): MysqlPolicyFormState {
  return {
    name: '',
    command: 'ALL',
    permissive: 'PERMISSIVE',
    roles: '',
    usingExpression: '',
    withCheckExpression: '',
  }
}

export function parseMysqlPolicyFormRoles(rolesInput: string): string[] {
  return parseMysqlPolicyRoles(rolesInput)
}

export function formatMysqlPolicyFormRoles(roles: string[]): string {
  return roles.join(', ')
}

function isMysqlPolicyPublicRole(role: string): boolean {
  return role.trim().toLowerCase() === 'public'
}

/** Named roles and PUBLIC are mutually exclusive in MySQL policies. */
export function addMysqlPolicyFormRole(
  selectedRoles: readonly string[],
  roleName: string,
): string[] {
  const trimmed = roleName.trim()
  if (!trimmed) return [...selectedRoles]

  const normalized = normalizeMysqlPolicyRoleName(trimmed)

  if (isMysqlPolicyPublicRole(normalized)) {
    return ['public']
  }

  const withoutPublic = selectedRoles.filter(
    (role) => !isMysqlPolicyPublicRole(role),
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

export function removeMysqlPolicyFormRole(
  selectedRoles: readonly string[],
  roleName: string,
): string[] {
  const key = roleName.trim().toLowerCase()
  return selectedRoles.filter((role) => role.trim().toLowerCase() !== key)
}

function resolveMysqlPolicyRolesForSql(rolesInput: string): string[] {
  const roles = parseMysqlPolicyRoles(rolesInput)
  if (roles.length === 0) return []

  const hasNamedRole = roles.some(
    (role) =>
      !isMysqlPolicyPublicRole(role) &&
      role !== 'CURRENT_USER' &&
      role !== 'CURRENT_ROLE',
  )

  if (!hasNamedRole) return roles

  return roles.filter((role) => !isMysqlPolicyPublicRole(role))
}

export function mapMysqlPolicyRowToFormState(
  policy: MysqlTablePolicyRow | Record<string, unknown>,
): MysqlPolicyFormState {
  const normalized = normalizeMysqlTablePolicyRow(policy)
  const roles = parseMysqlPolicyRoles(normalized.roles)
  return {
    name: normalized.policyname,
    command: normalizeMysqlPolicyCommand(normalized.cmd),
    permissive: normalizeMysqlPolicyPermissive(normalized.permissive),
    roles: roles.length > 0 ? roles.join(', ') : '',
    usingExpression: normalized.qual ?? '',
    withCheckExpression: normalized.with_check ?? '',
  }
}

export function normalizeMysqlPolicyPermissive(
  permissive: string | null | undefined,
): MysqlPolicyPermissive {
  const normalized = String(permissive ?? 'PERMISSIVE').trim().toUpperCase()
  return normalized === 'RESTRICTIVE' ? 'RESTRICTIVE' : 'PERMISSIVE'
}

export function normalizeMysqlPolicyCommand(
  command: string | null | undefined,
): MysqlPolicyCommand {
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

export function buildMysqlTableRlsStatusSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  // MySQL has no RLS; return a stub row so the UI stays disabled.
  return prefixMysqlSqlComment(
    `
SELECT
  FALSE AS row_security_enabled,
  FALSE AS force_row_security
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = ${schemaLit}
  AND TABLE_NAME = ${tableLit}
LIMIT 1
`.trim(),
    'Load table RLS status (unsupported on MySQL)',
  )
}

export function buildMysqlTablePoliciesSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  NULL AS policyname,
  NULL AS permissive,
  NULL AS roles,
  NULL AS cmd,
  NULL AS qual,
  NULL AS with_check
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = ${schemaLit}
  AND TABLE_NAME = ${tableLit}
  AND FALSE
`.trim(),
    'List table policies (unsupported on MySQL)',
  )
}

export function buildMysqlEnableRlsSql(_tableId: string): string {
  throw new Error('Row level security is not supported on MySQL.')
}

export function buildMysqlDisableRlsSql(_tableId: string): string {
  throw new Error('Row level security is not supported on MySQL.')
}

export function buildMysqlForceRlsSql(_tableId: string): string {
  throw new Error('Row level security is not supported on MySQL.')
}

export function buildMysqlNoForceRlsSql(_tableId: string): string {
  throw new Error('Row level security is not supported on MySQL.')
}

export function buildMysqlCreatePolicySql(
  _tableId: string,
  _form: MysqlPolicyFormState,
): string {
  throw new Error('Row level security policies are not supported on MySQL.')
}

export function buildMysqlAlterPolicySql(
  _tableId: string,
  _policyName: string,
  _form: MysqlPolicyFormState,
): string {
  throw new Error('Row level security policies are not supported on MySQL.')
}

export function buildMysqlDropPolicySql(
  _tableId: string,
  _policyName: string,
): string {
  throw new Error('Row level security policies are not supported on MySQL.')
}

export function validateMysqlPolicyFormState(
  form: MysqlPolicyFormState,
  _options?: { isEdit?: boolean },
): string | null {
  if (!form.name.trim()) {
    return 'Policy name is required'
  }

  return null
}
