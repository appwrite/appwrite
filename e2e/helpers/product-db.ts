import { expect, type Page } from '@playwright/test'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'
import { waitForFullscreenLoaderHidden } from './fullscreen-loader'
import {
  escapeRegExp,
  fillWizardDatabaseName,
  openCreateDatabaseWizard,
  selectFirstEnabledSpecification,
  selectServerlessSpecificationIfPresent,
  selectWizardDatabaseType,
  uniqueSuffix,
  type WizardDatabaseType,
} from './wizard'

export type ProductDbKind = 'tablesdb' | 'documentsdb' | 'vectorsdb'

export type CreatedProductDatabase = {
  kind: ProductDbKind
  databaseId: string
  databaseName: string
}

const PRODUCT: Record<
  ProductDbKind,
  {
    wizardType: WizardDatabaseType
    label: string
    usesDedicatedCompute: boolean
    createPath: RegExp
    containerWord: 'table' | 'collection'
    recordWord: 'row' | 'document'
    schemaWord: 'column' | 'attribute'
  }
> = {
  tablesdb: {
    wizardType: 'TablesDB',
    label: 'TablesDB',
    usesDedicatedCompute: false,
    createPath: /\/(?:v1\/)?tablesdb\/?$/i,
    containerWord: 'table',
    recordWord: 'row',
    schemaWord: 'column',
  },
  documentsdb: {
    wizardType: 'DocumentsDB',
    label: 'DocumentsDB',
    usesDedicatedCompute: true,
    createPath: /\/(?:v1\/)?documentsdb\/?$/i,
    containerWord: 'collection',
    recordWord: 'document',
    schemaWord: 'attribute',
  },
  vectorsdb: {
    wizardType: 'VectorsDB',
    label: 'VectorsDB',
    usesDedicatedCompute: true,
    createPath: /\/(?:v1\/)?vectorsdb\/?$/i,
    containerWord: 'collection',
    recordWord: 'document',
    schemaWord: 'attribute',
  },
}

export const PRODUCT_PROVISION_TIMEOUT_MS = 10 * 60_000

export function productDatabasePath(
  kind: ProductDbKind,
  projectId: string,
  databaseId: string,
  suffix = '',
): string {
  const base = `/projects/${projectId}/databases/${kind}/${databaseId}`
  return suffix ? `${base}${suffix.startsWith('/') ? suffix : `/${suffix}`}` : base
}

export function productContainerPath(
  kind: ProductDbKind,
  projectId: string,
  databaseId: string,
  containerId: string,
  tab: string,
): string {
  const segment = kind === 'tablesdb' ? 'tables' : 'collections'
  return productDatabasePath(
    kind,
    projectId,
    databaseId,
    `/${segment}/${containerId}/${tab}`,
  )
}

/**
 * Create a TablesDB / DocumentsDB / VectorsDB database via the wizard.
 * TablesDB uses serverless compute (fast). DocumentsDB and VectorsDB provision
 * dedicated compute and can take several minutes.
 */
export async function createProductDatabaseViaWizard(
  page: Page,
  projectId: string,
  kind: ProductDbKind,
  options?: { namePrefix?: string },
): Promise<CreatedProductDatabase> {
  await enableDatabaseFeatureFlags(page)
  const config = PRODUCT[kind]
  const databaseName =
    `${options?.namePrefix ?? `e2e-${kind}`}-${uniqueSuffix()}`.slice(0, 128)

  await openCreateDatabaseWizard(page, projectId)
  await selectWizardDatabaseType(page, config.wizardType)
  await expect(page.getByText(config.label).first()).toBeVisible({
    timeout: 15_000,
  })
  await fillWizardDatabaseName(page, databaseName)

  if (kind === 'tablesdb') {
    await selectServerlessSpecificationIfPresent(page)
  } else {
    await selectFirstEnabledSpecification(page)
  }

  const createButton = page.getByRole('button', { name: 'Create database' })
  await expect(createButton).toBeEnabled({ timeout: 30_000 })

  const createResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        return (
          response.request().method() === 'POST' &&
          config.createPath.test(url.pathname) &&
          response.status() < 500 &&
          !url.pathname.includes('/executions')
        )
      } catch {
        return false
      }
    },
    { timeout: 120_000 },
  )

  await createButton.click()
  const createResponse = await createResponsePromise
  if (!createResponse.ok()) {
    throw new Error(
      `${config.label} create failed: ${createResponse.status()} ${await createResponse.text()}`,
    )
  }
  const body = (await createResponse.json()) as { $id?: string }
  const databaseId = body.$id
  if (!databaseId) {
    throw new Error(
      `${config.label} create response missing $id: ${JSON.stringify(body).slice(0, 500)}`,
    )
  }

  await expect(page).toHaveURL(
    new RegExp(`/projects/${projectId}/databases/${kind}/${databaseId}`),
    {
      timeout: config.usesDedicatedCompute
        ? PRODUCT_PROVISION_TIMEOUT_MS
        : 120_000,
    },
  )
  await waitForFullscreenLoaderHidden(page, 120_000)
  await expect(page.getByText(/Database not found/i)).toHaveCount(0)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  await expect(
    page.getByText(databaseName, { exact: false }).first(),
  ).toBeVisible({ timeout: 60_000 })

  return { kind, databaseId, databaseName }
}

export async function expectProductTabRenders(
  page: Page,
  kind: ProductDbKind,
  projectId: string,
  databaseId: string,
  tabPath: string,
  options?: {
    ready?: () => ReturnType<Page['locator']>
    timeout?: number
  },
): Promise<void> {
  const timeout = options?.timeout ?? 90_000
  const path = productDatabasePath(kind, projectId, databaseId, tabPath)
  await page.goto(path, { waitUntil: 'domcontentloaded', timeout })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(new RegExp(tabPath.replace(/\//g, '\\/')), {
    timeout,
  })
  await waitForFullscreenLoaderHidden(page, timeout)
  await expect(page.getByText(/Database not found/i)).toHaveCount(0)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  if (options?.ready) {
    await expect(options.ready()).toBeVisible({ timeout })
  }
}

export async function gotoProductDatabase(
  page: Page,
  kind: ProductDbKind,
  projectId: string,
  databaseId: string,
  suffix = '',
): Promise<void> {
  await page.goto(productDatabasePath(kind, projectId, databaseId, suffix), {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await acceptCookieBannerIfPresent(page)
  await waitForFullscreenLoaderHidden(page, 120_000)
}

export async function createProductContainerViaUi(
  page: Page,
  kind: ProductDbKind,
  name: string,
  options?: { embeddingModelSearch?: string },
): Promise<string> {
  const config = PRODUCT[kind]
  const createLabel =
    config.containerWord === 'collection' ? 'Create collection' : 'Create table'
  const headingLabel = createLabel
  const createdToast = new RegExp(`${escapeRegExp(name)} has been created`)

  const createButton = page.getByRole('button', { name: createLabel }).first()
  await expect(createButton).toBeEnabled({ timeout: 60_000 })
  await createButton.click()

  await expect(page.getByRole('heading', { name: headingLabel })).toBeVisible({
    timeout: 30_000,
  })
  await page.locator('#name').fill(name)
  await expect(page.locator('[data-fullscreen-loader]')).toHaveCount(0, {
    timeout: 30_000,
  })

  if (kind === 'vectorsdb') {
    const modelSearch = options?.embeddingModelSearch ?? 'all-minilm'
    const modelTrigger = page.locator('#embedding-model')
    await expect(modelTrigger).toBeVisible({ timeout: 15_000 })
    await modelTrigger.click()
    const search = page.getByPlaceholder('Search embedding models...')
    await expect(search).toBeVisible({ timeout: 10_000 })
    await search.fill(modelSearch)
    const option = page
      .locator('[cmdk-item], [data-slot="command-item"]')
      .filter({ hasText: new RegExp(escapeRegExp(modelSearch), 'i') })
      .first()
    await expect(option).toBeVisible({ timeout: 10_000 })
    await option.click()
  }

  const createResponsePromise = page.waitForResponse(
    (response) => {
      try {
        const url = new URL(response.url())
        const method = response.request().method()
        if (method !== 'POST' || response.status() >= 500) return false
        return (
          url.pathname.includes('/tables') ||
          url.pathname.includes('/collections')
        )
      } catch {
        return false
      }
    },
    { timeout: 90_000 },
  )

  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Create', exact: true })
    .click()
  const response = await createResponsePromise
  if (!response.ok()) {
    throw new Error(
      `${createLabel} failed: ${response.status()} ${await response.text()}`,
    )
  }
  const body = (await response.json()) as { $id?: string }
  const containerId = body.$id
  if (!containerId) {
    throw new Error(
      `${createLabel} response missing $id: ${JSON.stringify(body).slice(0, 500)}`,
    )
  }

  await expect(page.getByText(createdToast)).toBeVisible({ timeout: 60_000 })
  return containerId
}

export async function gotoProductContainerTab(
  page: Page,
  kind: ProductDbKind,
  projectId: string,
  databaseId: string,
  containerId: string,
  tab: string,
): Promise<void> {
  await page.goto(
    productContainerPath(kind, projectId, databaseId, containerId, tab),
    { waitUntil: 'domcontentloaded', timeout: 60_000 },
  )
  await acceptCookieBannerIfPresent(page)
  await waitForFullscreenLoaderHidden(page, 90_000)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
}

async function openSelectAndChoose(
  page: Page,
  trigger: ReturnType<Page['locator']>,
  optionName: string,
): Promise<void> {
  await expect(trigger).toBeVisible({ timeout: 15_000 })
  await trigger.click()
  const option = page.getByRole('option', { name: optionName, exact: true })
  await expect(option).toBeVisible({ timeout: 10_000 })
  await option.click()
}

export async function addTablesDbColumnViaUi(
  page: Page,
  options: {
    key: string
    typeLabel: string
    enumElements?: string[]
  },
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create column' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(
    page.getByRole('heading', { name: 'Create Column' }),
  ).toBeVisible({ timeout: 15_000 })
  await page.locator('#column-key').fill(options.key)
  await openSelectAndChoose(page, page.locator('#column-type'), options.typeLabel)

  if (options.typeLabel === 'Enum') {
    const elements = options.enumElements?.length
      ? options.enumElements
      : ['active', 'archived']
    const first = page.getByPlaceholder('Add elements here').first()
    await expect(first).toBeVisible({ timeout: 10_000 })
    await first.fill(elements[0])
    for (const extra of elements.slice(1)) {
      const add = page.getByRole('button', { name: /^Add$/ }).first()
      if (await add.isVisible().catch(() => false)) {
        await add.click()
      }
      const inputs = page.getByPlaceholder('Add elements here')
      await inputs.last().fill(extra)
    }
  }

  await page.getByRole('button', { name: 'Create Column' }).click()
  await expect(page.getByText('Column created successfully')).toBeVisible({
    timeout: 60_000,
  })
}

export async function addTablesDbIndexViaUi(
  page: Page,
  options: {
    key: string
    typeLabel: 'Key' | 'Unique' | 'Fulltext' | 'Spatial'
    column: string
  },
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create index' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(page.getByRole('heading', { name: 'Create Index' })).toBeVisible({
    timeout: 15_000,
  })
  await page.locator('#index-key').fill(options.key)

  const typeTrigger = page.locator('#index-type')
  await expect(typeTrigger).toBeVisible({ timeout: 10_000 })
  await typeTrigger.click()
  const typeOption = page
    .locator('[cmdk-item], [data-slot="command-item"]')
    .filter({ hasText: new RegExp(`^${options.typeLabel}$`) })
    .first()
  await expect(typeOption).toBeVisible({ timeout: 10_000 })
  await typeOption.click()

  const columnTrigger = page.getByRole('combobox').filter({
    hasText: /Select column|Select/i,
  }).first()
  await openSelectAndChoose(page, columnTrigger, options.column)

  await page.getByRole('button', { name: 'Create Index' }).click()
  await expect(page.getByText('Index created successfully')).toBeVisible({
    timeout: 60_000,
  })
}

export async function addTablesDbRelationshipColumnViaUi(
  page: Page,
  options: {
    key: string
    relatedTableName: string
    relationshipType:
      | 'One to one'
      | 'One to many'
      | 'Many to one'
      | 'Many to many'
  },
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create column' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(
    page.getByRole('heading', { name: 'Create Column' }),
  ).toBeVisible({ timeout: 15_000 })
  await openSelectAndChoose(page, page.locator('#column-type'), 'Relationship')

  const relatedTrigger = page.locator('#related-table')
  await expect(relatedTrigger).toBeVisible({ timeout: 15_000 })
  await relatedTrigger.click()
  const relatedOption = page
    .getByRole('option')
    .filter({ hasText: options.relatedTableName })
    .first()
  await expect(relatedOption).toBeVisible({ timeout: 10_000 })
  await relatedOption.click()

  const keyInput = page.locator('#column-key-relationship')
  await expect(keyInput).toBeVisible({ timeout: 10_000 })
  await keyInput.fill(options.key)
  await openSelectAndChoose(
    page,
    page.locator('#relationship-type'),
    options.relationshipType,
  )

  await page.getByRole('button', { name: 'Create Column' }).click()
  await expect(page.getByText('Column created successfully')).toBeVisible({
    timeout: 60_000,
  })
}

export async function addCollectionIndexViaUi(
  page: Page,
  options: {
    key: string
    typeLabel: 'Key' | 'Unique' | 'Fulltext' | 'Spatial'
    attribute: string
  },
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create index' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(page.getByRole('heading', { name: 'Create Index' })).toBeVisible({
    timeout: 15_000,
  })
  await page.locator('#index-key').fill(options.key)

  const typeTrigger = page.locator('#index-type')
  await expect(typeTrigger).toBeVisible({ timeout: 10_000 })
  await typeTrigger.click()
  const typeOption = page
    .locator('[cmdk-item], [data-slot="command-item"]')
    .filter({ hasText: new RegExp(`^${options.typeLabel}$`) })
    .first()
  await expect(typeOption).toBeVisible({ timeout: 10_000 })
  await typeOption.click()

  const attributeTrigger = page.getByRole('combobox').first()
  await expect(attributeTrigger).toBeVisible({ timeout: 10_000 })
  await attributeTrigger.click()
  const custom = page.getByRole('option', { name: 'Custom attribute' })
  if (await custom.isVisible().catch(() => false)) {
    await custom.click()
    const nameInput = page.getByPlaceholder('e.g. email, score, tags')
    await expect(nameInput).toBeVisible({ timeout: 10_000 })
    await nameInput.fill(options.attribute)
  } else {
    const option = page.getByRole('option', {
      name: options.attribute,
      exact: true,
    })
    await expect(option).toBeVisible({ timeout: 10_000 })
    await option.click()
  }

  await page.getByRole('button', { name: 'Create Index' }).click()
  await expect(page.getByText('Index created successfully')).toBeVisible({
    timeout: 60_000,
  })
}

export async function createTablesDbRowViaUi(
  page: Page,
): Promise<void> {
  const createButton = page.getByRole('button', { name: 'Create row' }).first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()
  const submit = page.getByRole('button', { name: 'Create row' }).last()
  await expect(submit).toBeVisible({ timeout: 15_000 })
  await submit.click()
  await expect(
    page
      .getByText('Row created successfully')
      .or(page.getByText('Row created')),
  ).toBeVisible({ timeout: 60_000 })
}

export async function createCollectionDocumentViaUi(
  page: Page,
  json: Record<string, unknown>,
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create document' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  const editor = page.locator('textarea, .monaco-editor').last()
  await expect(editor.first()).toBeVisible({ timeout: 15_000 })
  const payload = JSON.stringify(json, null, 2)
  const textarea = page.locator('textarea').last()
  if (await textarea.isVisible().catch(() => false)) {
    await textarea.fill(payload)
  } else {
    await page.evaluate((nextJson) => {
      const monacoApi = (
        window as unknown as {
          monaco?: {
            editor?: {
              getEditors?: () => Array<{ setValue: (value: string) => void }>
            }
          }
        }
      ).monaco
      const editors = monacoApi?.editor?.getEditors?.() ?? []
      if (editors[editors.length - 1]) {
        editors[editors.length - 1].setValue(nextJson)
      }
    }, payload)
  }

  await page.getByRole('button', { name: 'Create document' }).last().click()
  await expect(page.getByText('Document created successfully')).toBeVisible({
    timeout: 60_000,
  })
}
