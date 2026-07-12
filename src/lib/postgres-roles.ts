import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import { buildPostgresSingleRequestDdlSql } from '@/lib/postgres-sql'
import { isPostgresTruthyFlag } from '@/lib/postgres-rls'
import { prefixPostgresSqlComment, quotePostgresStringLiteral } from '@/lib/postgres-sql'

export type PostgresRoleRow = {
  role_name: string
  can_login: boolean | string
  can_create_role: boolean | string
  can_create_db: boolean | string
  is_superuser: boolean | string
  can_replicate: boolean | string
  inherit: boolean | string
  bypass_rls: boolean | string
  connection_limit: number | string | null
  valid_until: string | null
  member_of: string[] | string | null
}

export type PostgresRoleFormState = {
  roleName: string
  password: string
  canLogin: boolean
  isSuperuser: boolean
  canCreateRole: boolean
  canCreateDb: boolean
  canReplicate: boolean
  inherit: boolean
  bypassRls: boolean
  unlimitedConnections: boolean
  connectionLimit: string
  noExpiry: boolean
  validUntil: string
  memberOf: string[]
}

export const POSTGRES_ROLE_NAME_REGEX = /^[A-Za-z_][A-Za-z0-9_]*$/

const POSTGRES_BUILTIN_PROTECTED_ROLES = new Set([
  'PUBLIC',
  'postgres',
  'rdsadmin',
  'rds_superuser',
  'cloudsqlsuperuser',
  'azure_superuser',
  'supabase_admin',
  'supabase_auth_admin',
  'supabase_storage_admin',
])

export function isPostgresRoleFlag(
  value: boolean | string | null | undefined,
): boolean {
  return isPostgresTruthyFlag(value)
}

export function isPostgresBuiltinRole(row: PostgresRoleRow): boolean {
  if (POSTGRES_BUILTIN_PROTECTED_ROLES.has(row.role_name)) return true
  if (row.role_name.startsWith('pg_')) return true
  return false
}

export function isPostgresProtectedRole(row: PostgresRoleRow): boolean {
  if (isPostgresBuiltinRole(row)) return true
  if (isPostgresRoleFlag(row.is_superuser)) return true
  return false
}

export function canUpdatePostgresRole(row: PostgresRoleRow): boolean {
  return !isPostgresBuiltinRole(row)
}

export function parsePostgresRoleMembership(
  memberOf: string[] | string | null | undefined,
): string[] {
  if (Array.isArray(memberOf)) {
    return memberOf.map((role) => String(role).trim()).filter(Boolean)
  }
  if (typeof memberOf !== 'string' || !memberOf.trim()) return []

  const trimmed = memberOf.trim()
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

export function formatPostgresRoleMembership(
  memberOf: string[] | string | null | undefined,
): string {
  const parsed = parsePostgresRoleMembership(memberOf)
  if (parsed.length === 0) return ''
  return parsed.join(', ')
}

export function formatPostgresRoleConnectionLimit(
  value: number | string | null | undefined,
): string | null {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  if (!Number.isFinite(parsed)) return String(value)
  if (parsed === -1) return 'Unlimited'
  return String(parsed)
}

export function createDefaultPostgresRoleFormState(): PostgresRoleFormState {
  return {
    roleName: '',
    password: '',
    canLogin: false,
    isSuperuser: false,
    canCreateRole: false,
    canCreateDb: false,
    canReplicate: false,
    inherit: true,
    bypassRls: false,
    unlimitedConnections: true,
    connectionLimit: '',
    noExpiry: true,
    validUntil: '',
    memberOf: [],
  }
}

function formatValidUntilForInput(value: string | null | undefined): {
  noExpiry: boolean
  validUntil: string
} {
  if (!value || value === 'infinity') {
    return { noExpiry: true, validUntil: '' }
  }

  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) {
    return { noExpiry: true, validUntil: '' }
  }

  return { noExpiry: false, validUntil: parsed.toISOString() }
}

export function mapPostgresRoleRowToFormState(
  row: PostgresRoleRow,
): PostgresRoleFormState {
  const connectionLimit = formatPostgresRoleConnectionLimit(row.connection_limit)
  const expiry = formatValidUntilForInput(row.valid_until)

  return {
    roleName: row.role_name,
    password: '',
    canLogin: isPostgresRoleFlag(row.can_login),
    isSuperuser: isPostgresRoleFlag(row.is_superuser),
    canCreateRole: isPostgresRoleFlag(row.can_create_role),
    canCreateDb: isPostgresRoleFlag(row.can_create_db),
    canReplicate: isPostgresRoleFlag(row.can_replicate),
    inherit: isPostgresRoleFlag(row.inherit),
    bypassRls: isPostgresRoleFlag(row.bypass_rls),
    unlimitedConnections: connectionLimit === 'Unlimited' || connectionLimit === null,
    connectionLimit:
      connectionLimit && connectionLimit !== 'Unlimited' ? connectionLimit : '',
    noExpiry: expiry.noExpiry,
    validUntil: expiry.validUntil,
    memberOf: parsePostgresRoleMembership(row.member_of),
  }
}

export function validatePostgresRoleFormState(
  formState: PostgresRoleFormState,
  options: { isEdit: boolean },
): string | null {
  const roleName = formState.roleName.trim()

  if (!options.isEdit) {
    if (!roleName) return 'Role name is required'
    if (!POSTGRES_ROLE_NAME_REGEX.test(roleName)) {
      return 'Role name must use letters, numbers, and underscores only.'
    }
  }

  if (formState.canLogin && !options.isEdit && !formState.password.trim()) {
    return 'Password is required when login is enabled.'
  }

  if (!formState.unlimitedConnections) {
    const limit = Number.parseInt(formState.connectionLimit.trim(), 10)
    if (!Number.isFinite(limit) || limit < 0) {
      return 'Connection limit must be a non-negative number.'
    }
  }

  if (!formState.noExpiry && !formState.validUntil.trim()) {
    return 'Valid until is required when expiry is enabled.'
  }

  return null
}

function quotePostgresRoleNames(roleNames: string[]): string {
  return roleNames.map((role) => quotePostgresIdentifier(role)).join(', ')
}

function buildPostgresRoleAttributeClauses(
  formState: PostgresRoleFormState,
  options: { includePassword: boolean },
): string[] {
  const clauses: string[] = [
    formState.canLogin ? 'LOGIN' : 'NOLOGIN',
    formState.isSuperuser ? 'SUPERUSER' : 'NOSUPERUSER',
    formState.canCreateRole ? 'CREATEROLE' : 'NOCREATEROLE',
    formState.canCreateDb ? 'CREATEDB' : 'NOCREATEDB',
    formState.canReplicate ? 'REPLICATION' : 'NOREPLICATION',
    formState.inherit ? 'INHERIT' : 'NOINHERIT',
    formState.bypassRls ? 'BYPASSRLS' : 'NOBYPASSRLS',
  ]

  if (options.includePassword && formState.password.trim()) {
    clauses.push(`PASSWORD ${quotePostgresStringLiteral(formState.password.trim())}`)
  }

  if (formState.unlimitedConnections) {
    clauses.push('CONNECTION LIMIT -1')
  } else {
    const limit = Number.parseInt(formState.connectionLimit.trim(), 10)
    clauses.push(`CONNECTION LIMIT ${limit}`)
  }

  if (formState.noExpiry) {
    clauses.push("VALID UNTIL 'infinity'")
  } else {
    const parsed = new Date(formState.validUntil.trim())
    const pad = (n: number) => String(n).padStart(2, '0')
    const normalized = Number.isNaN(parsed.getTime())
      ? formState.validUntil.trim().replace('T', ' ')
      : `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}:${pad(parsed.getSeconds())}`
    clauses.push(`VALID UNTIL ${quotePostgresStringLiteral(normalized)}`)
  }

  return clauses
}

export function buildPostgresListRolesSql(): string {
  return prefixPostgresSqlComment(
    `
SELECT
  r.rolname AS role_name,
  r.rolcanlogin AS can_login,
  r.rolcreaterole AS can_create_role,
  r.rolcreatedb AS can_create_db,
  r.rolsuper AS is_superuser,
  r.rolreplication AS can_replicate,
  r.rolinherit AS inherit,
  r.rolbypassrls AS bypass_rls,
  r.rolconnlimit AS connection_limit,
  r.rolvaliduntil AS valid_until,
  COALESCE(
    array_agg(DISTINCT parent.rolname ORDER BY parent.rolname)
      FILTER (WHERE parent.rolname IS NOT NULL),
    '{}'::name[]
  ) AS member_of
FROM pg_catalog.pg_roles r
LEFT JOIN pg_catalog.pg_auth_members membership
  ON membership.member = r.oid
LEFT JOIN pg_catalog.pg_roles parent
  ON parent.oid = membership.roleid
WHERE r.rolname !~ '^pg_'
GROUP BY
  r.rolname,
  r.rolcanlogin,
  r.rolcreaterole,
  r.rolcreatedb,
  r.rolsuper,
  r.rolreplication,
  r.rolinherit,
  r.rolbypassrls,
  r.rolconnlimit,
  r.rolvaliduntil
ORDER BY r.rolname ASC
`.trim(),
    'List PostgreSQL roles (excluding pg_* system roles)',
  )
}

export function buildPostgresCreateRoleSql(
  formState: PostgresRoleFormState,
): string {
  const roleName = formState.roleName.trim()
  const clauses = buildPostgresRoleAttributeClauses(formState, {
    includePassword: true,
  })

  if (formState.memberOf.length > 0) {
    clauses.push(`IN ROLE ${quotePostgresRoleNames(formState.memberOf)}`)
  }

  const withClause = clauses.length > 0 ? ` WITH ${clauses.join(' ')}` : ''

  return prefixPostgresSqlComment(
    `CREATE ROLE ${quotePostgresIdentifier(roleName)}${withClause}`,
    `Create PostgreSQL role ${roleName}`,
  )
}

function buildPostgresRoleMembershipChangeSql(
  roleName: string,
  previousMembers: string[],
  nextMembers: string[],
): string[] {
  const previous = new Set(previousMembers)
  const next = new Set(nextMembers)
  const statements: string[] = []

  for (const member of next) {
    if (!previous.has(member)) {
      statements.push(
        `GRANT ${quotePostgresIdentifier(member)} TO ${quotePostgresIdentifier(roleName)}`,
      )
    }
  }

  for (const member of previous) {
    if (!next.has(member)) {
      statements.push(
        `REVOKE ${quotePostgresIdentifier(member)} FROM ${quotePostgresIdentifier(roleName)}`,
      )
    }
  }

  return statements
}

export function buildPostgresUpdateRoleSql(
  formState: PostgresRoleFormState,
  previousMembers: string[],
): string {
  const roleName = formState.roleName.trim()
  const statements = [
    `ALTER ROLE ${quotePostgresIdentifier(roleName)} WITH ${buildPostgresRoleAttributeClauses(formState, {
      includePassword: Boolean(formState.password.trim()),
    }).join(' ')}`,
    ...buildPostgresRoleMembershipChangeSql(
      roleName,
      previousMembers,
      formState.memberOf,
    ),
  ]

  return buildPostgresSingleRequestDdlSql(
    statements,
    `Update PostgreSQL role ${roleName}`,
  )
}

export function buildPostgresDropRoleSql(roleName: string): string {
  return prefixPostgresSqlComment(
    `DROP ROLE ${quotePostgresIdentifier(roleName)}`,
    `Drop PostgreSQL role ${roleName}`,
  )
}
