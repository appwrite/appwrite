import type { DedicatedDatabaseCredentials } from '@/lib/databases/dedicated-engine'
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import {
  buildMysqlDirectConnectionString,
  maskMysqlConnectionStringPassword,
} from '@/lib/mysql-connection-string'

export type MysqlConnectSnippetTab = 'env' | 'prisma' | 'drizzle' | 'mysql'

export type MysqlConnectTab = 'details' | 'dsn' | MysqlConnectSnippetTab

export type MysqlConnectionEndpointInfo = {
  sslLabel: string
  poolerEnabled: boolean
  poolerMode?: string
  pooledHost: string
  pooledPort: number
  directHost: string
  directPort: number
  showDirectEndpoint: boolean
}

export type MysqlConnectSnippetContext = {
  credentials: DedicatedDatabaseCredentials
  endpointInfo: MysqlConnectionEndpointInfo
}

export const MYSQL_CONNECT_TABS: {
  id: MysqlConnectTab
  label: string
  language?: CodeBlockLanguage
}[] = [
  { id: 'details', label: 'Details' },
  { id: 'dsn', label: 'DSN' },
  { id: 'env', label: '.env', language: 'env' },
  { id: 'prisma', label: 'Prisma', language: 'plaintext' },
  { id: 'drizzle', label: 'Drizzle', language: 'typescript' },
  { id: 'mysql', label: 'mysql', language: 'bash' },
]

export const MYSQL_CONNECT_SNIPPET_TABS = MYSQL_CONNECT_TABS.filter(
  (tab): tab is {
    id: MysqlConnectSnippetTab
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

function getDirectConnectionString(
  context: MysqlConnectSnippetContext,
): string | null {
  if (!context.endpointInfo.showDirectEndpoint) return null
  return buildMysqlDirectConnectionString(context.credentials)
}

function buildEnvVariableLine(
  key: string,
  connectionString: string,
  password?: string,
  options?: { maskPassword?: boolean },
) {
  const url = options?.maskPassword
    ? maskMysqlConnectionStringPassword(connectionString, password)
    : connectionString

  return `${key}="${escapeForEnv(url)}"`
}

function buildEnvFileSnippet(
  context: MysqlConnectSnippetContext,
  options?: { maskPassword?: boolean },
) {
  const { credentials } = context
  const directConnectionString = getDirectConnectionString(context)
  const lines = [
    buildEnvVariableLine(
      'DATABASE_URL',
      credentials.connectionString,
      credentials.password,
      options,
    ),
  ]

  if (directConnectionString) {
    lines.push(
      buildEnvVariableLine(
        'DIRECT_URL',
        directConnectionString,
        credentials.password,
        options,
      ),
    )
  }

  return lines.join('\n')
}

function buildEnvSectionComment(endpointInfo: MysqlConnectionEndpointInfo) {
  if (endpointInfo.showDirectEndpoint) {
    return '# Use DATABASE_URL for the app (pooled). Use DIRECT_URL for migrations.'
  }

  if (endpointInfo.poolerEnabled) {
    return '# Add to your .env file.'
  }

  return '# Add to your .env file.'
}

function buildMysqlPrismaSnippet(context: MysqlConnectSnippetContext) {
  const { endpointInfo } = context
  const directConnectionString = getDirectConnectionString(context)
  const datasourceLines = [
    'datasource db {',
    '  provider  = "mysql"',
    '  url       = env("DATABASE_URL")',
  ]

  if (directConnectionString) {
    datasourceLines.push('  directUrl = env("DIRECT_URL")')
  }

  datasourceLines.push('}')

  return [
    '# .env',
    buildEnvSectionComment(endpointInfo),
    '',
    buildEnvFileSnippet(context),
    '',
    '# schema.prisma',
    '',
    'generator client {',
    '  provider = "prisma-client-js"',
    '}',
    '',
    ...datasourceLines,
  ].join('\n')
}

function buildMysqlDrizzleDbSnippet() {
  return [
    'import { drizzle } from "drizzle-orm/mysql2"',
    'import mysql from "mysql2/promise"',
    '',
    'const pool = mysql.createPool(process.env.DATABASE_URL!)',
    '',
    'export const db = drizzle(pool)',
  ].join('\n')
}

function buildMysqlDrizzleConfigSnippet(
  directConnectionString: string | null,
) {
  const migrationUrl = directConnectionString
    ? '    url: process.env.DIRECT_URL ?? process.env.DATABASE_URL!,'
    : '    url: process.env.DATABASE_URL!,'

  return [
    'import { defineConfig } from "drizzle-kit"',
    '',
    'export default defineConfig({',
    '  schema: "./src/db/schema.ts",',
    '  out: "./drizzle",',
    '  dialect: "mysql",',
    '  dbCredentials: {',
    migrationUrl,
    '  },',
    '})',
  ].join('\n')
}

export type MysqlDrizzleSnippetPart = 'env' | 'db' | 'config'

export function buildMysqlDrizzleSnippetParts(
  context: MysqlConnectSnippetContext,
) {
  const directConnectionString = getDirectConnectionString(context)

  return {
    env: [
      buildEnvSectionComment(context.endpointInfo),
      '',
      buildEnvFileSnippet(context),
    ].join('\n'),
    db: buildMysqlDrizzleDbSnippet(),
    config: buildMysqlDrizzleConfigSnippet(directConnectionString),
  }
}

export function buildMysqlDrizzleSnippetPartDisplayCode(
  part: MysqlDrizzleSnippetPart,
  context: MysqlConnectSnippetContext,
) {
  const parts = buildMysqlDrizzleSnippetParts(context)

  if (part === 'env') {
    return [
      buildEnvSectionComment(context.endpointInfo),
      '',
      buildEnvFileSnippet(context, { maskPassword: true }),
    ].join('\n')
  }

  return parts[part]
}

function buildMysqlDrizzleSnippet(context: MysqlConnectSnippetContext) {
  const parts = buildMysqlDrizzleSnippetParts(context)

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

function buildMysqlCliUriSnippet(context: MysqlConnectSnippetContext) {
  const { credentials, endpointInfo } = context
  const databaseName = credentials.database || credentials.tcpDatabase
  const lines = [
    '# Connect with the mysql CLI (prompts for password)',
  ]

  if (endpointInfo.poolerEnabled) {
    lines.push('# Uses the pooler host and port shown in Details.')
  }

  lines.push(
    `mysql -h ${credentials.host} -P ${String(credentials.port)} -u ${credentials.username} -p -D ${databaseName}`,
  )

  return lines.join('\n')
}

function buildMysqlCliFlagsSnippet(context: MysqlConnectSnippetContext) {
  const { credentials, endpointInfo } = context
  const databaseName = credentials.database || credentials.tcpDatabase
  const lines = [
    '# Connect with individual flags (prompts for password)',
  ]

  if (endpointInfo.poolerEnabled) {
    lines.push('# Uses the pooler host and port shown in Details.')
  }

  lines.push(
    `mysql -h ${credentials.host} -P ${String(credentials.port)} -u ${credentials.username} -p -D ${databaseName}`,
  )

  if (endpointInfo.showDirectEndpoint) {
    const directDatabase = credentials.tcpDatabase || credentials.database
    lines.push('')
    lines.push('# Direct TCP connection (bypass pooler)')
    lines.push(
      `mysql -h ${credentials.tcpHost || credentials.host} -P ${String(credentials.tcpPort || credentials.port)} -u ${credentials.username} -p -D ${directDatabase}`,
    )
  }

  return lines.join('\n')
}

function buildMysqlCliCommandsSnippet() {
  return [
    '# Useful mysql commands after you connect',
    'SHOW TABLES;              # List tables in the current database',
    'DESCRIBE table_name;      # Describe a table',
    'SHOW DATABASES;           # List databases',
    'USE dbname;               # Switch to a different database',
    'EXIT;                     # Quit mysql',
  ].join('\n')
}

export type MysqlCliSnippetPart = 'uri' | 'flags' | 'commands'

export function buildMysqlCliSnippetParts(
  context: MysqlConnectSnippetContext,
) {
  return {
    uri: buildMysqlCliUriSnippet(context),
    flags: buildMysqlCliFlagsSnippet(context),
    commands: buildMysqlCliCommandsSnippet(),
  }
}

export function buildMysqlCliSnippetPartDisplayCode(
  part: MysqlCliSnippetPart,
  context: MysqlConnectSnippetContext,
) {
  const parts = buildMysqlCliSnippetParts(context)

  if (part === 'uri') {
    return maskSnippetConnectionStrings(parts.uri, context)
  }

  return parts[part]
}

function buildMysqlCliSnippet(context: MysqlConnectSnippetContext) {
  const parts = buildMysqlCliSnippetParts(context)

  return [parts.uri, '', parts.flags, '', parts.commands].join('\n')
}

/** @deprecated Use buildMysqlCliSnippetParts */
export type MysqlPsqlSnippetPart = MysqlCliSnippetPart
/** @deprecated Use buildMysqlCliSnippetParts */
export const buildMysqlPsqlSnippetParts = buildMysqlCliSnippetParts
/** @deprecated Use buildMysqlCliSnippetPartDisplayCode */
export const buildMysqlPsqlSnippetPartDisplayCode =
  buildMysqlCliSnippetPartDisplayCode

export function buildMysqlEnvSnippet(
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

export function buildMysqlConnectSnippets(context: MysqlConnectSnippetContext) {
  return {
    env: buildEnvFileSnippet(context),
    prisma: buildMysqlPrismaSnippet(context),
    drizzle: buildMysqlDrizzleSnippet(context),
    mysql: buildMysqlCliSnippet(context),
  } satisfies Record<MysqlConnectSnippetTab, string>
}

function maskSnippetConnectionStrings(
  snippet: string,
  context: MysqlConnectSnippetContext,
) {
  const { credentials } = context
  const { connectionString, password } = credentials
  let result = snippet

  const maskValue = (value: string) => {
    const masked = maskMysqlConnectionStringPassword(value, password)
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

  const directConnectionString = getDirectConnectionString(context)
  if (directConnectionString && directConnectionString !== connectionString) {
    maskValue(directConnectionString)
  }

  return result
}

export function buildMysqlConnectSnippetDisplayCode(
  tab: MysqlConnectSnippetTab,
  context: MysqlConnectSnippetContext,
) {
  const snippet = buildMysqlConnectSnippets(context)[tab]

  if (tab === 'env') {
    return buildEnvFileSnippet(context, { maskPassword: true })
  }

  if (tab === 'prisma') {
    return maskSnippetConnectionStrings(snippet, context)
  }

  return snippet
}

export function buildMysqlConnectionEndpointInfo(
  credentials: DedicatedDatabaseCredentials,
  pooler: Models.DedicatedDatabasePooler | null | undefined,
): MysqlConnectionEndpointInfo {
  const pooledHost = credentials.host
  const pooledPort =
    pooler?.enabled === true && pooler.port ? pooler.port : credentials.port
  const directHost = credentials.tcpHost || credentials.host
  const directPort = credentials.tcpPort || credentials.port
  const poolerEnabled = pooler?.enabled === true
  const showDirectEndpoint =
    poolerEnabled &&
    (directHost !== pooledHost || directPort !== pooledPort)

  return {
    sslLabel: credentials.ssl ? 'Required' : 'Not required',
    poolerEnabled,
    poolerMode: pooler?.mode || undefined,
    pooledHost,
    pooledPort,
    directHost,
    directPort,
    showDirectEndpoint,
  }
}

export function buildMysqlCopyAllText(
  credentials: DedicatedDatabaseCredentials,
  endpointInfo: MysqlConnectionEndpointInfo,
): string {
  const databaseName = credentials.database || credentials.tcpDatabase
  const lines = [
    `Host: ${credentials.host}`,
    `Port: ${String(credentials.port)}`,
    `Username: ${credentials.username}`,
    `Password: ${credentials.password}`,
    ...(databaseName ? [`Database: ${databaseName}`] : []),
    `SSL: ${endpointInfo.sslLabel}`,
  ]

  if (endpointInfo.poolerEnabled) {
    lines.push(
      `Pooler: ${endpointInfo.pooledHost}:${String(endpointInfo.pooledPort)}`,
    )
    if (endpointInfo.poolerMode) {
      lines.push(`Pool mode: ${endpointInfo.poolerMode}`)
    }
  }

  if (endpointInfo.showDirectEndpoint) {
    lines.push(
      `Direct TCP: ${endpointInfo.directHost}:${String(endpointInfo.directPort)}`,
    )
  }

  if (credentials.connectionString) {
    lines.push(`DSN: ${credentials.connectionString}`)
  }

  return lines.join('\n')
}
