/**
 * MySQL-specific wrappers around the shared native-database e2e helpers.
 */
import { expect, type Locator, type Page } from '@playwright/test'
import { skipCommunitySupportWizardIfPresent } from './cookie-banner'
import { clickInPage, expectToast } from './ui'
import { uniqueSuffix } from './wizard'
import {
  addNativeColumnViaUi,
  addNativeIndexViaUi,
  createNativeDatabaseViaWizard,
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
  options: {
    name: string
    typeSearch: string
    unique?: boolean
    enumValues?: string[]
  },
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

export function mysqlRoleName(prefix = 'e2e_role'): string {
  return `${prefix}_${uniqueSuffix()}`
}

export async function openMysqlRolesPage(
  page: Page,
  projectId: string,
  databaseId: string,
): Promise<void> {
  await expectMysqlTabRenders(page, projectId, databaseId, '/roles', {
    ready: () =>
      page
        .getByRole('button', { name: /Create role/i })
        .or(page.getByText(/Create roles|No roles/i))
        .first(),
  })
  await skipCommunitySupportWizardIfPresent(page)
}

async function openMysqlRoleRowMenu(page: Page, row: Locator): Promise<void> {
  await skipCommunitySupportWizardIfPresent(page)
  const trigger = row.getByRole('button', { name: 'Actions' })
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  // Radix menus listen for pointer events; a raw DOM click does not open them.
  await trigger.click({ force: true })
  await expect(page.getByRole('menu')).toBeVisible({ timeout: 10_000 })
}

export function mysqlRoleRow(page: Page, roleName: string): Locator {
  return page
    .locator('tbody tr')
    .filter({ has: page.getByText(roleName, { exact: true }) })
}

async function filterMysqlRoles(page: Page, roleName: string): Promise<void> {
  const search = page.getByPlaceholder('Search roles...')
  await expect(search).toBeVisible({ timeout: 15_000 })
  await search.fill(roleName)
}

async function setMysqlRoleConnectionLimit(
  page: Page,
  limit: number,
): Promise<void> {
  const unlimited = page.getByRole('switch', { name: 'Unlimited connections' })
  await expect(unlimited).toBeVisible({ timeout: 15_000 })
  if (await unlimited.isChecked()) {
    await unlimited.click()
  }
  const input = page.locator('#role-connection-limit')
  await expect(input).toBeVisible({ timeout: 10_000 })
  await input.fill(String(limit))
}

export async function createMysqlRoleViaUi(
  page: Page,
  options: { name: string; connectionLimit?: number },
): Promise<void> {
  await clickInPage(page.getByRole('button', { name: 'Create role' }).first())
  await expect(page.getByRole('heading', { name: 'Create role' })).toBeVisible({
    timeout: 15_000,
  })
  await page.locator('#role-name').fill(options.name)
  if (options.connectionLimit != null) {
    await setMysqlRoleConnectionLimit(page, options.connectionLimit)
  }
  await clickInPage(
    page.locator('form').getByRole('button', { name: 'Create', exact: true }),
  )
  await expectToast(page, 'Role created')
  await expect(page.getByRole('heading', { name: 'Create role' })).toHaveCount(
    0,
    { timeout: 15_000 },
  )
  await filterMysqlRoles(page, options.name)
  await expect(mysqlRoleRow(page, options.name)).toBeVisible({ timeout: 30_000 })
}

export async function updateMysqlRoleViaUi(
  page: Page,
  options: { name: string; connectionLimit: number },
): Promise<void> {
  await filterMysqlRoles(page, options.name)
  const row = mysqlRoleRow(page, options.name)
  await expect(row).toBeVisible({ timeout: 15_000 })
  await openMysqlRoleRowMenu(page, row)
  await page.getByRole('menuitem', { name: 'Update' }).click({ force: true })
  await expect(page.getByRole('heading', { name: 'Update role' })).toBeVisible({
    timeout: 15_000,
  })
  await setMysqlRoleConnectionLimit(page, options.connectionLimit)
  await clickInPage(
    page.locator('form').getByRole('button', { name: 'Update', exact: true }),
  )
  await expectToast(page, 'Role updated')
  await expect(page.getByRole('heading', { name: 'Update role' })).toHaveCount(
    0,
    { timeout: 15_000 },
  )
  await filterMysqlRoles(page, options.name)
  await expect(mysqlRoleRow(page, options.name)).toContainText(
    String(options.connectionLimit),
    { timeout: 30_000 },
  )
}

export async function deleteMysqlRoleViaUi(
  page: Page,
  roleName: string,
): Promise<void> {
  await filterMysqlRoles(page, roleName)
  const row = mysqlRoleRow(page, roleName)
  await expect(row).toBeVisible({ timeout: 15_000 })
  await openMysqlRoleRowMenu(page, row)
  await page.getByRole('menuitem', { name: 'Delete' }).click({ force: true })
  const dialog = page.getByRole('dialog')
  await expect(
    dialog.getByRole('heading', { name: 'Delete role' }),
  ).toBeVisible({ timeout: 15_000 })
  await clickInPage(dialog.getByRole('button', { name: 'Delete', exact: true }))
  await expectToast(page, 'Role deleted')
  await expect(mysqlRoleRow(page, roleName)).toHaveCount(0, { timeout: 30_000 })
}
