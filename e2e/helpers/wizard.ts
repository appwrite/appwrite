import { expect, type Page } from '@playwright/test'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'

export type WizardDatabaseType =
  | 'TablesDB'
  | 'DocumentsDB'
  | 'VectorsDB'
  | 'Postgres'
  | 'MySQL'

const WIZARD_TYPE_DESCRIPTIONS: Record<WizardDatabaseType, string> = {
  TablesDB:
    'Relational-style database with tables, columns, and indexes. Ideal for structured data and complex queries.',
  DocumentsDB:
    'Document-based storage with flexible schemas. Store JSON documents and query with filters and full-text search.',
  VectorsDB:
    'Vector database for embeddings and similarity search. Ideal for semantic search and AI.',
  Postgres:
    'A dedicated PostgreSQL database for relational workloads, SQL tooling, and portable schemas.',
  MySQL:
    'A dedicated MySQL database for common relational workloads and existing MySQL applications.',
}

export function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

export function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** Open the create-database wizard and select a product / engine card. */
export async function openCreateDatabaseWizard(
  page: Page,
  projectId: string,
): Promise<void> {
  await enableDatabaseFeatureFlags(page)
  await page.goto(`/projects/${projectId}/databases/create`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(/\/databases\/create/, { timeout: 60_000 })
  await expect(
    page.getByRole('heading', { name: 'Choose database type' }),
  ).toBeVisible({ timeout: 60_000 })
}

/**
 * Playwright's pointer hit-test fights the fullscreen WizardLayout chrome, so
 * scroll the card into the scrollport and invoke click() in the page.
 */
export async function selectWizardDatabaseType(
  page: Page,
  dbType: WizardDatabaseType,
): Promise<void> {
  const card = page
    .locator('button:not([disabled])')
    .filter({ hasText: WIZARD_TYPE_DESCRIPTIONS[dbType] })
    .first()
  await expect(card).toBeVisible({ timeout: 30_000 })
  await card.evaluate((el: HTMLButtonElement) => {
    el.scrollIntoView({ block: 'center', inline: 'nearest' })
    el.click()
  })
}

/** Click the first enabled specification row (skips locked Upgrade rows). */
export async function selectFirstEnabledSpecification(
  page: Page,
): Promise<void> {
  const specRows = page.locator('table tbody tr').filter({
    hasNot: page.getByText('Upgrade', { exact: true }),
  })
  await expect(specRows.first()).toBeVisible({ timeout: 90_000 })
  await specRows.first().click()
}

/** Prefer the serverless TablesDB tier when the spec table includes it. */
export async function selectServerlessSpecificationIfPresent(
  page: Page,
): Promise<void> {
  const serverlessRow = page.locator('table tbody tr').filter({
    hasText: /Serverless|Pay as you go/i,
  })
  if (await serverlessRow.first().isVisible({ timeout: 15_000 }).catch(() => false)) {
    await serverlessRow.first().click()
    return
  }
  await selectFirstEnabledSpecification(page)
}

export async function fillWizardDatabaseName(
  page: Page,
  databaseName: string,
): Promise<void> {
  await expect(page.locator('#db-name')).toBeVisible({ timeout: 30_000 })
  await page.locator('#db-name').fill(databaseName)
}
