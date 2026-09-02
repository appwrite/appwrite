import type { DedicatedDatabaseCredentials } from '@/lib/databases/dedicated-engine'
import type { Models } from '@appwrite.io/console'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { maskPostgresConnectionStringPassword } from '@/lib/postgres-connection-string'

export type PostgresConnectSnippetTab = 'env' | 'prisma' | 'drizzle' | 'psql'

export type PostgresConnectTab = 'details' | 'dsn' | PostgresConnectSnippetTab

export type PostgresConnectionEndpointInfo = {
  sslLabel: string
  poolerEnabled: boolean
  poolerMode?: string
  pooledHost: string
  pooledPort: number
}

export type PostgresConnectSnippetContext = {
  credentials: DedicatedDatabaseCredentials
  endpointInfo: PostgresConnectionEndpointInfo
}

export const POSTGRES_CONNECT_TABS: {
  id: PostgresConnectTab
  label: string
  language?: CodeBlockLanguage
}[] = [
  { id: 'details', label: 'Details' },
  { id: 'dsn', label: 'DSN' },
  { id: 'env', label: '.env', language: 'env' },
  { id: 'prisma', label: 'Prisma', language: 'plaintext' },
  { id: 'drizzle', label: 'Drizzle', language: 'typescript' },
  { id: 'psql', label: 'psql', language: 'bash' },
]

export const POSTGRES_CONNECT_SNIPPET_TABS = POSTGRES_CONNECT_TABS.filter(
  (tab): tab is {
    id: PostgresConnectSnippetTab
    label: string
    language: CodeBlockLanguage
  } => tab.id !== 'details' && tab.id !== 'dsn' && tab.language != null,
)

function escapeForEnv(value: string) {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
}

function escapeForShell(value: string) {
  return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
}

/** Connection string pointed at the single pooler (or primary) endpoint. */
export function getPostgresPooledConnectionString(
  context: PostgresConnectSnippetContext,
): string {
  const { credentials, endpointInfo } = context
  const { connectionString } = credentials
  if (!connectionString) return ''

  if (
    endpointInfo.pooledHost === credentials.host &&
    endpointInfo.pooledPort === credentials.port
  ) {
    return connectionString
  }

  try {
    const parsed = new URL(connectionString)
    parsed.hostname = endpointInfo.pooledHost
    parsed.port = String(endpointInfo.pooledPort)
    return parsed.toString()
  } catch {
    return connectionString
  }
}

function buildEnvVariableLine(
  key: string,
  connectionString: string,
  password?: string,
  options?: { maskPassword?: boolean },
) {
  const url = options?.maskPassword
    ? maskPostgresConnectionStringPassword(connectionString, password)
    : connectionString

  return `${key}="${escapeForEnv(url)}"`
}

function buildEnvFileSnippet(
  context: PostgresConnectSnippetContext,
  options?: { maskPassword?: boolean },
) {
  const { credentials } = context
  const connectionString = getPostgresPooledConnectionString(context)

  return buildEnvVariableLine(
    'DATABASE_URL',
    connectionString,
    credentials.password,
    options,
  )
}

function buildEnvSectionComment() {
  return '# Add to your .env file.'
}

function buildPostgresPrismaSnippet(context: PostgresConnectSnippetContext) {
  return [
    '# .env',
    buildEnvSectionComment(),
    '',
    buildEnvFileSnippet(context),
    '',
    '# schema.prisma',
    '',
    'generator client {',
    '  provider = "prisma-client-js"',
    '}',
    '',
    'datasource db {',
    '  provider = "postgresql"',
    '  url      = env("DATABASE_URL")',
    '}',
  ].join('\n')
}

function buildPostgresDrizzleDbSnippet() {
  return [
    'import { drizzle } from "drizzle-orm/node-postgres"',
    'import { Pool } from "pg"',
    '',
    'const pool = new Pool({',
    '  connectionString: process.env.DATABASE_URL,',
    '})',
    '',
    'export const db = drizzle({ client: pool })',
  ].join('\n')
}

function buildPostgresDrizzleConfigSnippet() {
  return [
    'import { defineConfig } from "drizzle-kit"',
    '',
    'export default defineConfig({',
    '  schema: "./src/db/schema.ts",',
    '  out: "./drizzle",',
    '  dialect: "postgresql",',
    '  dbCredentials: {',
    '    url: process.env.DATABASE_URL!,',
    '  },',
    '})',
  ].join('\n')
}

export type PostgresDrizzleSnippetPart = 'env' | 'db' | 'config'

export function buildPostgresDrizzleSnippetParts(
  context: PostgresConnectSnippetContext,
) {
  return {
    env: [buildEnvSectionComment(), '', buildEnvFileSnippet(context)].join(
      '\n',
    ),
    db: buildPostgresDrizzleDbSnippet(),
    config: buildPostgresDrizzleConfigSnippet(),
  }
}

export function buildPostgresDrizzleSnippetPartDisplayCode(
  part: PostgresDrizzleSnippetPart,
  context: PostgresConnectSnippetContext,
) {
  const parts = buildPostgresDrizzleSnippetParts(context)

  if (part === 'env') {
    return [
      buildEnvSectionComment(),
      '',
      buildEnvFileSnippet(context, { maskPassword: true }),
    ].join('\n')
  }

  return parts[part]
}

function buildPostgresDrizzleSnippet(context: PostgresConnectSnippetContext) {
  const parts = buildPostgresDrizzleSnippetParts(context)

  return [
    '# .env',
    parts.env,
    '',
    '// src/db/index.ts',
    parts.db,
    '',
    '// drizzle.config.ts',
    parts.config,
  ].join('\n')
}

function buildPostgresPsqlUriSnippet(context: PostgresConnectSnippetContext) {
  const { endpointInfo } = context
  const connectionString = getPostgresPooledConnectionString(context)
  const lines = ['# Connect with a connection string (recommended)']

  if (endpointInfo.poolerEnabled) {
    lines.push('# Uses the pooler endpoint for interactive sessions.')
  }

  lines.push(`psql "${escapeForShell(connectionString)}"`)

  return lines.join('\n')
}

function buildPostgresPsqlFlagsSnippet(context: PostgresConnectSnippetContext) {
  const { credentials, endpointInfo } = context
  const databaseName = credentials.database || credentials.tcpDatabase
  const lines = [
    '# Connect with individual flags (prompts for password)',
  ]

  if (credentials.ssl) {
    lines.push('export PGSSLMODE=require')
  }

  if (endpointInfo.poolerEnabled) {
    lines.push('# Uses the pooler host and port shown in Details.')
  }

  lines.push(
    `psql -h ${endpointInfo.pooledHost} -p ${String(endpointInfo.pooledPort)} -U ${credentials.username} -d ${databaseName}`,
  )

  return lines.join('\n')
}

function buildPostgresPsqlCommandsSnippet() {
  return [
    '# Useful psql commands after you connect',
    '\\dt          # List tables in the current schema',
    '\\d name      # Describe a table, view, or sequence',
    '\\dn          # List schemas',
    '\\l           # List databases',
    '\\c dbname    # Connect to a different database',
    '\\q           # Quit psql',
  ].join('\n')
}

export type PostgresPsqlSnippetPart = 'uri' | 'flags' | 'commands'

export function buildPostgresPsqlSnippetParts(
  context: PostgresConnectSnippetContext,
) {
  return {
    uri: buildPostgresPsqlUriSnippet(context),
    flags: buildPostgresPsqlFlagsSnippet(context),
    commands: buildPostgresPsqlCommandsSnippet(),
  }
}

export function buildPostgresPsqlSnippetPartDisplayCode(
  part: PostgresPsqlSnippetPart,
  context: PostgresConnectSnippetContext,
) {
  const parts = buildPostgresPsqlSnippetParts(context)

  if (part === 'uri') {
    return maskSnippetConnectionStrings(parts.uri, context)
  }

  return parts[part]
}

function buildPostgresPsqlSnippet(context: PostgresConnectSnippetContext) {
  const parts = buildPostgresPsqlSnippetParts(context)

  return [parts.uri, '', parts.flags, '', parts.commands].join('\n')
}

export function buildPostgresEnvSnippet(
  connectionString: string,
  password?: string,
  options?: { maskPassword?: boolean },
) {
  return buildEnvVariableLine(
    'DATABASE_URL',
    connectionString,
    password,
    options,
  )
}

export function buildPostgresConnectSnippets(context: PostgresConnectSnippetContext) {
  return {
    env: buildEnvFileSnippet(context),
    prisma: buildPostgresPrismaSnippet(context),
    drizzle: buildPostgresDrizzleSnippet(context),
    psql: buildPostgresPsqlSnippet(context),
  } satisfies Record<PostgresConnectSnippetTab, string>
}

function maskSnippetConnectionStrings(
  snippet: string,
  context: PostgresConnectSnippetContext,
) {
  const { credentials } = context
  const connectionString = getPostgresPooledConnectionString(context)
  const { password } = credentials
  let result = snippet

  const maskValue = (value: string) => {
    const masked = maskPostgresConnectionStringPassword(value, password)
    result = result.replaceAll(value, masked)

    const escaped = escapeForEnv(value)
    const maskedEscaped = escapeForEnv(masked)
    if (escaped !== value) {
      result = result.replaceAll(escaped, maskedEscaped)
    }

    const shellEscaped = escapeForShell(value)
    const shellMasked = escapeForShell(masked)
    if (shellEscaped !== value) {
      result = result.replaceAll(shellEscaped, shellMasked)
    }
  }

  if (connectionString) {
    maskValue(connectionString)
  }

  if (
    credentials.connectionString &&
    credentials.connectionString !== connectionString
  ) {
    maskValue(credentials.connectionString)
  }

  return result
}

export function buildPostgresConnectSnippetDisplayCode(
  tab: PostgresConnectSnippetTab,
  context: PostgresConnectSnippetContext,
) {
  const snippet = buildPostgresConnectSnippets(context)[tab]

  if (tab === 'env') {
    return buildEnvFileSnippet(context, { maskPassword: true })
  }

  if (tab === 'prisma') {
    return maskSnippetConnectionStrings(snippet, context)
  }

  return snippet
}

export function buildPostgresConnectionEndpointInfo(
  credentials: DedicatedDatabaseCredentials,
  pooler: Models.DedicatedDatabasePooler | null | undefined,
): PostgresConnectionEndpointInfo {
  const poolerEnabled = pooler?.enabled === true
  const pooledHost = credentials.host
  const pooledPort =
    poolerEnabled && pooler.port ? pooler.port : credentials.port

  return {
    sslLabel: credentials.ssl ? 'Required' : 'Not required',
    poolerEnabled,
    poolerMode: pooler?.mode || undefined,
    pooledHost,
    pooledPort,
  }
}

export function buildPostgresCopyAllText(
  credentials: DedicatedDatabaseCredentials,
  endpointInfo: PostgresConnectionEndpointInfo,
): string {
  const databaseName = credentials.database || credentials.tcpDatabase
  const connectionString = getPostgresPooledConnectionString({
    credentials,
    endpointInfo,
  })
  const lines = [
    `Host: ${endpointInfo.pooledHost}`,
    `Port: ${String(endpointInfo.pooledPort)}`,
    `Username: ${credentials.username}`,
    `Password: ${credentials.password}`,
    ...(databaseName ? [`Database: ${databaseName}`] : []),
    `SSL: ${endpointInfo.sslLabel}`,
  ]

  if (endpointInfo.poolerEnabled && endpointInfo.poolerMode) {
    lines.push(`Pool mode: ${endpointInfo.poolerMode}`)
  }

  if (connectionString) {
    lines.push(`DSN: ${connectionString}`)
  }

  return lines.join('\n')
}
