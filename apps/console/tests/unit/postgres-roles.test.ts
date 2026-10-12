import { describe, expect, test } from 'bun:test'
import {
  buildPostgresCreateRoleSql,
  buildPostgresUpdateRoleSql,
  createDefaultPostgresRoleFormState,
  mapPostgresRoleRowToFormState,
  type PostgresRoleFormState,
  type PostgresRoleRow,
} from '@/lib/postgres-roles'
import { runPostgresDdlStatementsUntilFailure } from '@/lib/postgres-sql'

const PRIVILEGED_KEYWORDS = /\b(NO)?(SUPERUSER|REPLICATION|BYPASSRLS)\b/

function withoutComment(sql: string): string {
  return sql
    .split('\n')
    .filter((line) => !line.startsWith('--'))
    .join('\n')
}

function roleRow(overrides: Partial<PostgresRoleRow> = {}): PostgresRoleRow {
  return {
    role_name: 'r',
    can_login: true,
    can_create_role: false,
    can_create_db: false,
    is_superuser: false,
    can_replicate: false,
    inherit: true,
    bypass_rls: false,
    connection_limit: -1,
    valid_until: null,
    member_of: [],
    ...overrides,
  }
}

function update(
  row: PostgresRoleRow,
  patch: Partial<PostgresRoleFormState>,
): string[] {
  const previous = mapPostgresRoleRowToFormState(row)
  return buildPostgresUpdateRoleSql(previous, { ...previous, ...patch }).map(
    withoutComment,
  )
}

function create(patch: Partial<PostgresRoleFormState>): string {
  return withoutComment(
    buildPostgresCreateRoleSql({
      ...createDefaultPostgresRoleFormState(),
      roleName: 'x',
      ...patch,
    }),
  )
}

describe('buildPostgresUpdateRoleSql', () => {
  test('a password-only change emits only the password', () => {
    const statements = update(roleRow(), { password: 'n3w pa$$ ' })

    expect(statements).toEqual([`ALTER ROLE "r" WITH PASSWORD 'n3w pa$$ '`])
    expect(statements[0]).not.toMatch(PRIVILEGED_KEYWORDS)
  })

  test('escapes quotes in the password', () => {
    expect(update(roleRow(), { password: "it's" })).toEqual([
      `ALTER ROLE "r" WITH PASSWORD 'it''s'`,
    ])
  })

  test('an unchanged role emits no statements', () => {
    expect(update(roleRow(), {})).toEqual([])
    expect(
      update(
        roleRow({
          can_login: 'f',
          is_superuser: 't',
          can_replicate: 't',
          bypass_rls: 't',
          connection_limit: '5',
          valid_until: '2030-01-01 00:00:00+00',
          member_of: '{app_reader,app_writer}',
        }),
        {},
      ),
    ).toEqual([])
  })

  test('a single toggle emits a single clause', () => {
    expect(update(roleRow(), { canCreateDb: true })).toEqual([
      'ALTER ROLE "r" WITH CREATEDB',
    ])
    expect(update(roleRow({ inherit: true }), { inherit: false })).toEqual([
      'ALTER ROLE "r" WITH NOINHERIT',
    ])
    expect(
      update(roleRow(), { unlimitedConnections: false, connectionLimit: '3' }),
    ).toEqual(['ALTER ROLE "r" WITH CONNECTION LIMIT 3'])
  })

  test('a whitespace-only password is not sent', () => {
    expect(update(roleRow(), { password: '   ' })).toEqual([])
  })

  test('never sends a password when login is off', () => {
    expect(
      update(roleRow({ can_login: false }), { password: 'secret' }),
    ).toEqual([])
    expect(update(roleRow(), { canLogin: false, password: 'secret' })).toEqual([
      'ALTER ROLE "r" WITH NOLOGIN',
    ])
  })

  test('writes VALID UNTIL as UTC', () => {
    expect(
      update(roleRow(), {
        noExpiry: false,
        validUntil: '2030-06-01T12:30:00+02:00',
      }),
    ).toEqual([`ALTER ROLE "r" WITH VALID UNTIL '2030-06-01T10:30:00.000Z'`])
    expect(
      update(roleRow({ valid_until: '2030-06-01 10:30:00+00' }), {
        noExpiry: true,
      }),
    ).toEqual([`ALTER ROLE "r" WITH VALID UNTIL 'infinity'`])
  })

  test('compares VALID UNTIL by instant', () => {
    expect(
      update(roleRow({ valid_until: '2030-06-01 10:30:00+00' }), {
        validUntil: '2030-06-01T12:30:00+02:00',
      }),
    ).toEqual([])
  })

  test('diffs memberships with GRANT and REVOKE', () => {
    expect(
      update(roleRow({ member_of: ['app_reader'] }), {
        memberOf: ['app_writer'],
      }),
    ).toEqual(['GRANT "app_writer" TO "r"', 'REVOKE "app_reader" FROM "r"'])
  })
})

describe('buildPostgresCreateRoleSql', () => {
  test('Postgres defaults emit a bare CREATE ROLE', () => {
    expect(create({})).toBe('CREATE ROLE "x"')
  })

  test('emits only attributes that differ from the defaults', () => {
    const sql = create({
      canLogin: true,
      password: 'secret',
      canCreateDb: true,
    })

    expect(sql).toBe(`CREATE ROLE "x" WITH LOGIN CREATEDB PASSWORD 'secret'`)
    expect(sql).not.toMatch(PRIVILEGED_KEYWORDS)
  })

  test('never sends a password under NOLOGIN', () => {
    expect(create({ password: 'secret' })).toBe('CREATE ROLE "x"')
  })

  test('writes VALID UNTIL as UTC and keeps memberships', () => {
    expect(
      create({
        noExpiry: false,
        validUntil: '2030-06-01T12:30:00+02:00',
        memberOf: ['app_reader'],
      }),
    ).toBe(
      `CREATE ROLE "x" WITH VALID UNTIL '2030-06-01T10:30:00.000Z' IN ROLE "app_reader"`,
    )
  })
})

describe('runPostgresDdlStatementsUntilFailure', () => {
  test('runs every statement and reports no failure', async () => {
    const ran: string[] = []
    const failure = await runPostgresDdlStatementsUntilFailure(
      async (sql) => ran.push(sql),
      ['ALTER ROLE "r" WITH CREATEDB;', 'GRANT "a" TO "r"'],
    )

    expect(failure).toBeNull()
    expect(ran).toEqual(['ALTER ROLE "r" WITH CREATEDB', 'GRANT "a" TO "r"'])
  })

  test('stops at the first failure and counts the statements already applied', async () => {
    const error = new Error('permission denied to grant role "b"')
    const ran: string[] = []
    const failure = await runPostgresDdlStatementsUntilFailure(
      async (sql) => {
        if (sql.includes('"b"')) throw error
        ran.push(sql)
      },
      ['ALTER ROLE "r" WITH CREATEDB', 'GRANT "b" TO "r"', 'GRANT "c" TO "r"'],
    )

    expect(failure).toEqual({ applied: 1, error })
    expect(ran).toEqual(['ALTER ROLE "r" WITH CREATEDB'])
  })

  test('reports nothing applied when the first statement fails', async () => {
    const error = new Error('permission denied to alter role')
    const failure = await runPostgresDdlStatementsUntilFailure(async () => {
      throw error
    }, ['ALTER ROLE "r" WITH CREATEDB', 'GRANT "a" TO "r"'])

    expect(failure).toEqual({ applied: 0, error })
  })
})
