/**
 * MySQL-specific wrappers around the shared native-database e2e helpers.
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
  waitForNativeDatabaseShell,
  waitForNativeSqlEditor,
  renameNativeDatabase,
  runNativeSql,
  selectNativeSchema,
  typeNativeSql,
  type CreatedNativeDatabase,
  type NativeExecutionPayload,
} from './native-db'

export const MYSQL_PROVISION_TIMEOUT_MS = NATIVE_PROVISION_TIMEOUT_MS
export type CreatedMysqlDatabase = CreatedNativeDatabase
export type MysqlExecutionPayload = NativeExecutionPayload

const ENGINE = 'mysql' as const

export { expectNativeExecutionCell as expectMysqlExecutionCell }
export { expectNativeQueryResult as expectMysqlQueryResult }
export { expectNativeSidebarTable as expectMysqlSidebarTable }
export { nativeExecutionRows as mysqlExecutionRows }
export { quoteIdent }
export { selectNativeSchema as selectMysqlSchema }
export { typeNativeSql as typeMysqlSql }
export { waitForNativeDatabaseShell as waitForMysqlDatabaseShell }
export { waitForNativeSqlEditor as waitForMysqlSqlEditor }
export { createNativeTableViaUi as createMysqlTableViaUi }
export { addNativeIndexViaUi as addMysqlIndexViaUi }
export { createNativeEnumViaUi as createMysqlEnumViaUi }

export function mysqlDatabasePath(
  projectId: string,
  databaseId: string,
  suffix = '',
): string {
  return nativeDatabasePath(ENGINE, projectId, databaseId, suffix)
}

export async function createMysqlDatabaseViaWizard(
  page: Page,
  projectId: string,
  options?: { namePrefix?: string },
): Promise<CreatedMysqlDatabase> {
  return createNativeDatabaseViaWizard(page, projectId, ENGINE, options)
}

export async function expectMysqlTabRenders(
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

export async function runMysqlSql(page: Page): Promise<NativeExecutionPayload> {
  return runNativeSql(page, ENGINE)
}

export async function openMysqlSqlEditor(
  page: Page,
  projectId: string,
  databaseId: string,
): Promise<void> {
  return openNativeSqlEditor(page, ENGINE, projectId, databaseId)
}

export async function addMysqlColumnViaUi(
  page: Page,
  options: { name: string; typeSearch: string; unique?: boolean },
): Promise<void> {
  return addNativeColumnViaUi(page, ENGINE, options)
}

export async function renameMysqlDatabase(
  page: Page,
  projectId: string,
  database: CreatedMysqlDatabase,
): Promise<string> {
  return renameNativeDatabase(page, ENGINE, projectId, database)
}

export function mysqlTablePath(
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
