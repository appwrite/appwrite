import { isMysqlTruthyFlag } from '@/lib/mysql-rls'
import {
  buildMysqlSingleRequestDdlSql,
  prefixMysqlSqlComment,
  quoteMysqlStringLiteral,
} from '@/lib/mysql-sql'

export type MysqlRoleRow = {
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

export type MysqlRoleFormState = {
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

export const MYSQL_ROLE_NAME_REGEX = /^[A-Za-z_][A-Za-z0-9_]*$/

const MYSQL_BUILTIN_PROTECTED_ROLES = new Set([
  'PUBLIC',
  'mysql',
  'rdsadmin',
  'rds_superuser',
  'cloudsqlsuperuser',
  'azure_superuser',
  'supabase_admin',
  'supabase_auth_admin',
  'supabase_storage_admin',
])

export function isMysqlRoleFlag(
  value: boolean | string | number | null | undefined,
): boolean {
  if (typeof value === 'number') return value !== 0
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase()
    if (['1', 'y', 'yes'].includes(normalized)) return true
  }
  return isMysqlTruthyFlag(
    typeof value === 'boolean' || typeof value === 'string' || value == null
      ? value
      : undefined,
  )
}

export function isMysqlBuiltinRole(row: MysqlRoleRow): boolean {
  if (MYSQL_BUILTIN_PROTECTED_ROLES.has(row.role_name)) return true
  if (row.role_name === 'root' || row.role_name === 'mysql.sys') return true
  if (row.role_name.startsWith('mysql.')) return true
  return false
}

export function isMysqlProtectedRole(row: MysqlRoleRow): boolean {
  if (isMysqlBuiltinRole(row)) return true
  if (isMysqlRoleFlag(row.is_superuser)) return true
  return false
}

export function canUpdateMysqlRole(row: MysqlRoleRow): boolean {
  return !isMysqlBuiltinRole(row)
}

export function parseMysqlRoleMembership(
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

export function formatMysqlRoleMembership(
  memberOf: string[] | string | null | undefined,
): string {
  const parsed = parseMysqlRoleMembership(memberOf)
  if (parsed.length === 0) return ''
  return parsed.join(', ')
}

export function formatMysqlRoleConnectionLimit(
  value: number | string | null | undefined,
): string | null {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  if (!Number.isFinite(parsed)) return String(value)
  if (parsed === -1) return 'Unlimited'
  return String(parsed)
}

export function createDefaultMysqlRoleFormState(): MysqlRoleFormState {
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

export function mapMysqlRoleRowToFormState(
  row: MysqlRoleRow,
): MysqlRoleFormState {
  const connectionLimit = formatMysqlRoleConnectionLimit(row.connection_limit)
  const expiry = formatValidUntilForInput(row.valid_until)

  return {
    roleName: row.role_name,
    password: '',
    canLogin: isMysqlRoleFlag(row.can_login),
    isSuperuser: isMysqlRoleFlag(row.is_superuser),
    canCreateRole: isMysqlRoleFlag(row.can_create_role),
    canCreateDb: isMysqlRoleFlag(row.can_create_db),
    canReplicate: isMysqlRoleFlag(row.can_replicate),
    inherit: isMysqlRoleFlag(row.inherit),
    bypassRls: isMysqlRoleFlag(row.bypass_rls),
    unlimitedConnections: connectionLimit === 'Unlimited' || connectionLimit === null,
    connectionLimit:
      connectionLimit && connectionLimit !== 'Unlimited' ? connectionLimit : '',
    noExpiry: expiry.noExpiry,
    validUntil: expiry.validUntil,
    memberOf: parseMysqlRoleMembership(row.member_of),
  }
}

export function validateMysqlRoleFormState(
  formState: MysqlRoleFormState,
  options: { isEdit: boolean },
): string | null {
  const roleName = formState.roleName.trim()

  if (!options.isEdit) {
    if (!roleName) return 'Role name is required'
    if (!MYSQL_ROLE_NAME_REGEX.test(roleName)) {
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

function quoteMysqlUserAccount(userName: string, host = '%'): string {
  return `${quoteMysqlStringLiteral(userName)}@${quoteMysqlStringLiteral(host)}`
}

function mysqlMaxUserConnectionsClause(
  formState: MysqlRoleFormState,
): string {
  if (formState.unlimitedConnections) {
    return 'WITH MAX_USER_CONNECTIONS 0'
  }
  const limit = Number.parseInt(formState.connectionLimit.trim(), 10)
  return `WITH MAX_USER_CONNECTIONS ${limit}`
}

function mysqlAccountLockClause(formState: MysqlRoleFormState): string {
  return formState.canLogin ? 'ACCOUNT UNLOCK' : 'ACCOUNT LOCK'
}

function buildMysqlRolePrivilegeStatements(
  account: string,
  formState: MysqlRoleFormState,
): string[] {
  // Only GRANT. REVOKE of a privilege the account never had fails the whole
  // multi-statement request on MySQL.
  const statements: string[] = []
  if (formState.canCreateDb) {
    statements.push(`GRANT CREATE ON *.* TO ${account}`)
  }
  if (formState.canCreateRole) {
    statements.push(`GRANT CREATE USER ON *.* TO ${account}`)
  }
  if (formState.canReplicate) {
    statements.push(
      `GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO ${account}`,
    )
  }
  if (formState.isSuperuser) {
    statements.push(`GRANT ALL ON *.* TO ${account} WITH GRANT OPTION`)
  }
  return statements
}

function buildMysqlRoleMembershipStatements(
  account: string,
  previousMembers: string[],
  nextMembers: string[],
  inherit: boolean,
): string[] {
  const statements: string[] = []
  const previous = new Set(previousMembers)
  const next = new Set(nextMembers)

  for (const member of next) {
    if (!previous.has(member)) {
      statements.push(`GRANT ${quoteMysqlUserAccount(member)} TO ${account}`)
    }
  }
  for (const member of previous) {
    if (!next.has(member)) {
      statements.push(`REVOKE ${quoteMysqlUserAccount(member)} FROM ${account}`)
    }
  }

  if (next.size > 0) {
    statements.push(
      inherit
        ? `ALTER USER ${account} DEFAULT ROLE ALL`
        : `ALTER USER ${account} DEFAULT ROLE NONE`,
    )
  } else if (previous.size > 0) {
    statements.push(`ALTER USER ${account} DEFAULT ROLE NONE`)
  }

  return statements
}

export function buildMysqlListRolesSql(): string {
  return prefixMysqlSqlComment(
    `
SELECT
  u.User AS role_name,
  MAX(IF(u.account_locked = 'N', 1, 0)) AS can_login,
  MAX(IF(u.Create_user_priv = 'Y', 1, 0)) AS can_create_role,
  MAX(IF(u.Create_priv = 'Y', 1, 0)) AS can_create_db,
  MAX(IF(u.Super_priv = 'Y', 1, 0)) AS is_superuser,
  MAX(IF(u.Repl_slave_priv = 'Y' OR u.Repl_client_priv = 'Y', 1, 0)) AS can_replicate,
  1 AS inherit,
  0 AS bypass_rls,
  CASE
    WHEN MAX(u.max_user_connections) = 0 THEN -1
    ELSE MAX(u.max_user_connections)
  END AS connection_limit,
  NULL AS valid_until,
  NULL AS member_of
FROM mysql.user u
WHERE u.User <> ''
  AND u.User NOT LIKE 'mysql.%'
GROUP BY u.User
ORDER BY u.User ASC
`.trim(),
    'List MySQL users',
  )
}

/** Fallback when mysql.user is not readable. */
export function buildMysqlListRolesFallbackSql(): string {
  return prefixMysqlSqlComment(
    `
SELECT
  REPLACE(SUBSTRING_INDEX(GRANTEE, '@', 1), '''', '') AS role_name,
  1 AS can_login,
  MAX(PRIVILEGE_TYPE = 'CREATE USER') AS can_create_role,
  MAX(PRIVILEGE_TYPE = 'CREATE') AS can_create_db,
  MAX(PRIVILEGE_TYPE = 'SUPER') AS is_superuser,
  MAX(PRIVILEGE_TYPE IN ('REPLICATION SLAVE', 'REPLICATION CLIENT')) AS can_replicate,
  1 AS inherit,
  0 AS bypass_rls,
  NULL AS connection_limit,
  NULL AS valid_until,
  NULL AS member_of
FROM information_schema.USER_PRIVILEGES
GROUP BY GRANTEE
ORDER BY role_name ASC
`.trim(),
    'List MySQL users from information_schema',
  )
}

export function buildMysqlCreateRoleSql(
  formState: MysqlRoleFormState,
): string {
  const roleName = formState.roleName.trim()
  const password = formState.password.trim()
  const account = quoteMysqlUserAccount(roleName)
  const identified = password
    ? ` IDENTIFIED BY ${quoteMysqlStringLiteral(password)}`
    : ''
  const statements = [
    `CREATE USER ${account}${identified} ${mysqlMaxUserConnectionsClause(formState)} ${mysqlAccountLockClause(formState)}`,
    ...buildMysqlRolePrivilegeStatements(account, formState),
    ...buildMysqlRoleMembershipStatements(
      account,
      [],
      formState.memberOf,
      formState.inherit,
    ),
  ]

  return buildMysqlSingleRequestDdlSql(
    statements,
    `Create MySQL user ${roleName}`,
  )
}

export function buildMysqlUpdateRoleSql(
  formState: MysqlRoleFormState,
  previousMembers: string[],
): string {
  const roleName = formState.roleName.trim()
  const password = formState.password.trim()
  const account = quoteMysqlUserAccount(roleName)
  const statements = [
    `ALTER USER ${account} ${mysqlMaxUserConnectionsClause(formState)} ${mysqlAccountLockClause(formState)}`,
  ]

  if (password) {
    statements.push(
      `ALTER USER ${account} IDENTIFIED BY ${quoteMysqlStringLiteral(password)}`,
    )
  }

  statements.push(
    ...buildMysqlRolePrivilegeStatements(account, formState),
    ...buildMysqlRoleMembershipStatements(
      account,
      previousMembers,
      formState.memberOf,
      formState.inherit,
    ),
  )

  return buildMysqlSingleRequestDdlSql(
    statements,
    `Update MySQL user ${roleName}`,
  )
}

export function buildMysqlDropRoleSql(roleName: string): string {
  return prefixMysqlSqlComment(
    `DROP USER ${quoteMysqlUserAccount(roleName.trim())}`,
    `Drop MySQL user ${roleName}`,
  )
}
