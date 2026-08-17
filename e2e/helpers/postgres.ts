/**
 * PostgreSQL-specific wrappers around the shared native-database e2e helpers.
 */
import type { Page } from '@playwright/test'
import {
  addNativeColumnViaUi,
  addNativeIndexViaUi,
  createNativeDatabaseViaWizard,
  createNativeEnumViaUi,
  createNativeTableViaUi,
  expectNativeExecutionCell,
  expectNativeQueryResult,
  expectNativeSidebarTable,
  expectNativeTabRenders,
  nativeDatabasePath,
  nativeExecutionRows,
  NATIVE_PROVISION_TIMEOUT_MS,
  openNativeSqlEditor,
  quoteIdent,
  renameNativeDatabase,
  runNativeSql,
  selectNativeSchema,
  typeNativeSql,
  type CreatedNativeDatabase,
  type NativeExecutionPayload,
} from './native-db'

export const POSTGRES_PROVISION_TIMEOUT_MS = NATIVE_PROVISION_TIMEOUT_MS
export type CreatedPostgresDatabase = CreatedNativeDatabase
export type PostgresExecutionPayload = NativeExecutionPayload

const ENGINE = 'postgres' as const

export { expectNativeExecutionCell as expectPostgresExecutionCell }
export { expectNativeQueryResult as expectPostgresQueryResult }
export { expectNativeSidebarTable as expectPostgresSidebarTable }
export { nativeExecutionRows as postgresExecutionRows }
export { quoteIdent }
export { selectNativeSchema as selectPostgresSchema }
export { typeNativeSql as typePostgresSql }
export { createNativeTableViaUi as createPostgresTableViaUi }
export { addNativeIndexViaUi as addPostgresIndexViaUi }
export { createNativeEnumViaUi as createPostgresEnumViaUi }

export function postgresDatabasePath(
  projectId: string,
  databaseId: string,
  suffix = '',
): string {
  return nativeDatabasePath(ENGINE, projectId, databaseId, suffix)
}

export async function createPostgresDatabaseViaWizard(
  page: Page,
  projectId: string,
  options?: { namePrefix?: string },
): Promise<CreatedPostgresDatabase> {
  return createNativeDatabaseViaWizard(page, projectId, ENGINE, options)
}

export async function expectPostgresTabRenders(
  page: Page,
  projectId: string,
  databaseId: string,
  tabPath: string,
  options?: {
    ready?: () => ReturnType<Page['locator']>
    timeout?: number
  },
): Promise<void> {
  return expectNativeTabRenders(
    page,
    ENGINE,
    projectId,
    databaseId,
    tabPath,
    options,
  )
}

export async function runPostgresSql(
  page: Page,
): Promise<NativeExecutionPayload> {
  return runNativeSql(page, ENGINE)
}

export async function openPostgresSqlEditor(
  page: Page,
  projectId: string,
  databaseId: string,
): Promise<void> {
  return openNativeSqlEditor(page, ENGINE, projectId, databaseId)
}

export async function addPostgresColumnViaUi(
  page: Page,
  options: { name: string; typeSearch: string; unique?: boolean },
): Promise<void> {
  return addNativeColumnViaUi(page, ENGINE, options)
}

export async function renamePostgresDatabase(
  page: Page,
  projectId: string,
  database: CreatedPostgresDatabase,
): Promise<string> {
  return renameNativeDatabase(page, ENGINE, projectId, database)
}

export function postgresTablePath(
  projectId: string,
  databaseId: string,
  schema: string,
  table: string,
  tab: 'rows' | 'columns' | 'indexes' | 'settings' | 'security',
): string {
  const encoded = encodeURIComponent(`${schema}.${table}`)
  return nativeDatabasePath(
    ENGINE,
    projectId,
    databaseId,
    `/tables/${encoded}/${tab}`,
  )
}
