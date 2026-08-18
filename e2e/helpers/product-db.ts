import { expect, type Page } from '@playwright/test'
import { enableDatabaseFeatureFlags } from './feature-flags'
import { acceptCookieBannerIfPresent } from './cookie-banner'
import { waitForFullscreenLoaderHidden } from './fullscreen-loader'
import {
  chooseCommandItem,
  clickInPage,
  expectToast,
  openCreatedDatabase,
  openSelectAndChoose,
  waitForDedicatedDatabaseReady,
} from './ui'
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

  await clickInPage(createButton)
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

  const targetPath = productDatabasePath(kind, projectId, databaseId)
  const targetPattern = new RegExp(
    `/projects/${projectId}/databases/${kind}/${databaseId}`,
  )
  const provisionTimeout = config.usesDedicatedCompute
    ? PRODUCT_PROVISION_TIMEOUT_MS
    : 120_000
  await openCreatedDatabase(page, targetPath, targetPattern, provisionTimeout, {
    waitUntilReady: config.usesDedicatedCompute,
  })
  await expect(page).toHaveURL(targetPattern, { timeout: 30_000 })
  await waitForFullscreenLoaderHidden(page, 30_000)
  await expect(page.getByText(/Database not found/i)).toHaveCount(0)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
  await expect(
    page.getByRole('heading', { name: 'Error', exact: true }),
  ).toHaveCount(0)
  await expect(
    page.getByText(databaseName, { exact: true }).first(),
  ).toBeVisible({ timeout: 30_000 })

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
  const timeout =
    options?.timeout ?? (PRODUCT[kind].usesDedicatedCompute ? 60_000 : 30_000)
  const path = productDatabasePath(kind, projectId, databaseId, tabPath)
  await page.goto(path, { waitUntil: 'domcontentloaded', timeout })
  await acceptCookieBannerIfPresent(page)
  await expect(page).toHaveURL(new RegExp(tabPath.replace(/\//g, '\\/')), {
    timeout,
  })
  await waitForFullscreenLoaderHidden(page, timeout)
  if (PRODUCT[kind].usesDedicatedCompute) {
    await waitForDedicatedDatabaseReady(page, PRODUCT_PROVISION_TIMEOUT_MS)
  }
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
    timeout: 45_000,
  })
  await acceptCookieBannerIfPresent(page)
  await waitForFullscreenLoaderHidden(page, 30_000)
  if (PRODUCT[kind].usesDedicatedCompute) {
    await waitForDedicatedDatabaseReady(page, PRODUCT_PROVISION_TIMEOUT_MS)
  }
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
    await chooseCommandItem(
      page,
      page.locator('#embedding-model'),
      'Search embedding models...',
      modelSearch,
      new RegExp(escapeRegExp(modelSearch), 'i'),
    )
    await expect(page.locator('#embedding-model')).toContainText(
      new RegExp(escapeRegExp(modelSearch), 'i'),
    )
    // Re-apply the name after the combobox so a cmdk fill cannot clobber it.
    await page.locator('#name').fill(name)
  }

  const dialog = page.getByRole('dialog')
  const submit = dialog.getByRole('button', { name: 'Create', exact: true })
  await expect(page.locator('#name')).toHaveValue(name)
  await expect(submit).toBeEnabled({ timeout: 30_000 })

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

  await clickInPage(submit)
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

  await expectToast(page, createdToast)
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
    { waitUntil: 'domcontentloaded', timeout: 45_000 },
  )
  await acceptCookieBannerIfPresent(page)
  await waitForFullscreenLoaderHidden(page, 30_000)
  await expect(page.getByText(/trim is not a function/i)).toHaveCount(0)
}

export async function addTablesDbColumnViaUi(
  page: Page,
  options: {
    key: string
    typeLabel: string
    enumElements?: string[]
    required?: boolean
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

  if (options.required) {
    const requiredBox = page.locator('#spatial-required, #column-required')
    await expect(requiredBox).toBeVisible({ timeout: 10_000 })
    await requiredBox.check()
  }

  await page.getByRole('button', { name: 'Create Column' }).click()
  await expectToast(page, 'Column created successfully')
  await expect(page.getByRole('heading', { name: 'Create Column' })).toHaveCount(
    0,
    { timeout: 15_000 },
  )
  await waitForProductSchemaAvailable(page, options.key)
}

async function waitForProductSchemaAvailable(
  page: Page,
  key: string,
): Promise<void> {
  const row = page
    .locator('tr, [role="row"]')
    .filter({ hasText: new RegExp(`(?:^|\\s)${escapeRegExp(key)}(?:\\s|$)`) })
    .first()
  const attached = await row
    .waitFor({ state: 'visible', timeout: 8_000 })
    .then(() => true)
    .catch(() => false)
  if (!attached) return
  await expect
    .poll(
      async () =>
        !(await row
          .getByText('Processing', { exact: true })
          .isVisible()
          .catch(() => false)),
      { timeout: 30_000, intervals: [400, 800, 1_200] },
    )
    .toBe(true)
}

function isSchemaNotReadyIndexError(status: number, body: string): boolean {
  return (
    status === 400 &&
    (body.includes('column_not_available') ||
      body.includes('attribute_not_available') ||
      body.includes('not yet available'))
  )
}

async function submitCreateIndexForm(page: Page): Promise<void> {
  const submit = page.getByRole('button', { name: 'Create Index' })
  await expect(submit).toBeEnabled({ timeout: 15_000 })
  const deadline = Date.now() + 60_000
  let lastError = 'Create index did not reach the API'

  while (Date.now() < deadline) {
    const createResponsePromise = page.waitForResponse(
      (response) => {
        try {
          const url = new URL(response.url())
          return (
            response.request().method() === 'POST' &&
            url.pathname.includes('/indexes')
          )
        } catch {
          return false
        }
      },
      { timeout: 30_000 },
    )
    await clickInPage(submit)
    const createResponse = await createResponsePromise.catch(() => null)
    if (!createResponse) {
      throw new Error(lastError)
    }
    if (createResponse.ok()) {
      await expectToast(page, 'Index created successfully')
      await expect(
        page.getByRole('heading', { name: 'Create Index' }),
      ).toHaveCount(0, { timeout: 15_000 })
      return
    }
    lastError = `Create index failed: ${createResponse.status()} ${await createResponse.text()}`
    if (!isSchemaNotReadyIndexError(createResponse.status(), lastError)) {
      throw new Error(lastError)
    }
    await page.waitForTimeout(1_500)
    await expect(submit).toBeEnabled({ timeout: 15_000 })
  }

  throw new Error(lastError)
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

  await chooseCommandItem(
    page,
    page.locator('#index-type'),
    'Search index types...',
    options.typeLabel,
    new RegExp(`^${options.typeLabel}$`),
  )
  await expect(page.locator('#index-type')).toContainText(
    new RegExp(`^${options.typeLabel}`),
  )

  const columnTrigger = page
    .getByRole('combobox')
    .filter({ hasText: /Select column/i })
  await expect(columnTrigger).toBeVisible({ timeout: 10_000 })
  await openSelectAndChoose(page, columnTrigger, options.column)

  const keyInput = page.locator('#index-key')
  await keyInput.fill(options.key)
  await expect(keyInput).toHaveValue(options.key)

  await submitCreateIndexForm(page)
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
  await openSelectAndChoose(page, relatedTrigger, options.relatedTableName)

  const keyInput = page.locator('#column-key-relationship')
  await expect(keyInput).toBeVisible({ timeout: 10_000 })
  await keyInput.fill(options.key)
  await openSelectAndChoose(
    page,
    page.locator('#relationship-type'),
    options.relationshipType,
  )
  await openSelectAndChoose(
    page,
    page.locator('#on-delete'),
    'Set NULL - set row ID as NULL in all related rows',
  )

  await clickInPage(page.getByRole('button', { name: 'Create Column' }))
  await expectToast(page, 'Column created successfully')
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

  await chooseCommandItem(
    page,
    page.locator('#index-type'),
    'Search index types...',
    options.typeLabel,
    new RegExp(`^${options.typeLabel}$`),
  )
  await expect(page.locator('#index-type')).toContainText(
    new RegExp(`^${options.typeLabel}`),
  )

  const attributeTrigger = page.getByRole('combobox').filter({
    hasText: /Select attribute/i,
  })
  await expect(attributeTrigger).toBeVisible({ timeout: 10_000 })
  await attributeTrigger.click({ force: true })

  const attributeSearch = page.getByPlaceholder('Search attributes...')
  await expect(attributeSearch).toBeVisible({ timeout: 10_000 })
  await attributeSearch.fill(options.attribute)

  const existingAttribute = page
    .locator('[cmdk-item], [data-slot="command-item"]')
    .filter({
      has: page.getByText(new RegExp(`^${escapeRegExp(options.attribute)}$`)),
    })
    .first()
  const customAttribute = page.getByRole('button', { name: 'Custom attribute' })
  await expect(customAttribute).toBeVisible({ timeout: 10_000 })

  if (await existingAttribute.isVisible().catch(() => false)) {
    await existingAttribute.click({ force: true })
  } else {
    await clickInPage(customAttribute)
    const nameInput = page.getByPlaceholder('e.g. email, score, tags')
    await expect(nameInput).toBeVisible({ timeout: 10_000 })
    await nameInput.fill(options.attribute)
  }

  const keyInput = page.locator('#index-key')
  await keyInput.fill(options.key)
  await expect(keyInput).toHaveValue(options.key)

  await submitCreateIndexForm(page)
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
  await expectToast(page, /Row created successfully|Row created/)
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
  await expectToast(page, 'Document created successfully')
}

export async function createVectorsDbDocumentViaUi(
  page: Page,
  options: { text: string; dimensions?: number },
): Promise<void> {
  const createButton = page
    .getByRole('button', { name: 'Create document' })
    .first()
  await expect(createButton).toBeVisible({ timeout: 30_000 })
  await createButton.click()

  await expect(page.getByRole('dialog', { name: 'Create document' })).toBeVisible(
    { timeout: 15_000 },
  )
  await page.getByRole('radio', { name: 'Vector' }).click()

  const dimensions = options.dimensions ?? 384
  const vectorInput = page.getByRole('textbox', { name: /Vector values/i })
  await expect(vectorInput).toBeVisible({ timeout: 10_000 })
  await vectorInput.fill(
    JSON.stringify(Array.from({ length: dimensions }, () => 0)),
  )

  const submit = page
    .getByRole('dialog')
    .getByRole('button', { name: 'Create document' })
  await expect(submit).toBeEnabled({ timeout: 15_000 })
  await submit.click({ force: true })
  await expectToast(
    page,
    /Row created successfully|Document created successfully/,
    30_000,
  )
}
