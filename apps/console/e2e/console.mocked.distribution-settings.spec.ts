import type { Page } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import {
  NOW,
  appsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  test,
  type DistributionRequest,
} from './fixtures/distribution'

const [shop, rewards, fieldNotes] = appsFixtures()

const TERMINAL_BAR_HEIGHT = 64

const PROJECT_VARIABLE: Models.Variable = {
  $id: 'projectvariable0001',
  $createdAt: NOW,
  $updatedAt: NOW,
  key: 'API_ENDPOINT',
  description: '',
  value: 'https://api.acme.dev',
  secret: false,
  resourceType: 'project',
  resourceId: '',
}

async function mockProjectVariables(page: Page) {
  await page.route('**/v1/project/variables*', (route) => {
    const request = route.request()
    if (request.method() !== 'GET') return route.fallback()
    return route.fulfill({
      status: 200,
      headers: {
        'access-control-allow-origin': request.headers()['origin'] ?? '*',
        'access-control-allow-credentials': 'true',
        'content-type': 'application/json',
      },
      body: JSON.stringify({ total: 1, variables: [PROJECT_VARIABLE] }),
    })
  })
}

async function captureFullPage(page: Page, name: string) {
  const height = await page.evaluate(() =>
    Math.max(
      window.innerHeight,
      ...Array.from(document.querySelectorAll('main, main *'))
        .filter((element) => element.scrollHeight > element.clientHeight)
        .map(
          (element) =>
            element.getBoundingClientRect().top + element.scrollHeight,
        ),
    ),
  )
  const viewport = page.viewportSize()
  if (viewport && height > viewport.height) {
    await page.setViewportSize({
      width: viewport.width,
      height: Math.ceil(height) + TERMINAL_BAR_HEIGHT,
    })
  }
  await screenshot(page, name)
}

function lastPatch(
  requests: DistributionRequest[],
  appId: string,
): Record<string, unknown> | undefined {
  return requests
    .filter(
      (request) =>
        request.method === 'PATCH' &&
        request.path === `/distribution/apps/${appId}`,
    )
    .at(-1)?.body
}

const REPOSITORY_FIELDS = [
  'installationId',
  'providerRepositoryId',
  'providerBranch',
  'providerRootDirectory',
  'providerBranches',
  'providerPaths',
  'providerTags',
  'providerSilentMode',
  'providerComments',
  'providerDeployments',
  'providerStatuses',
]

async function ignoreRepositoryFields(page: Page) {
  await page.route(
    (url) => {
      const [version, service, resource, ...rest] = url.pathname
        .split('/')
        .filter(Boolean)
      return (
        version === 'v1' &&
        service === 'distribution' &&
        resource === 'apps' &&
        rest.length <= 1
      )
    },
    async (route) => {
      const request = route.request()
      if (request.method() !== 'POST' && request.method() !== 'PATCH') {
        return route.fallback()
      }
      const body = request.postDataJSON() as Record<string, unknown>
      const accepted = Object.fromEntries(
        Object.entries(body).filter(
          ([key]) => !REPOSITORY_FIELDS.includes(key),
        ),
      )
      return route.fallback({ postData: JSON.stringify(accepted) })
    },
  )
}

function card(page: Page, title: string) {
  return page
    .locator('div.rounded-xl')
    .filter({ has: page.getByRole('heading', { name: title, exact: true }) })
}

test.describe('Distribution app settings', () => {
  test('shows the connected repository and saves sparse updates', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(distributionPath(`/${shop.$id}/settings`))

    await expect(page).toHaveTitle(/^Settings · Distribution · /)
    const repository = card(page, 'Repository')
    await expect(repository.getByText('acme/shop-app')).toBeVisible()
    await expect(card(page, 'Git automation')).toBeVisible()
    await expect(card(page, 'Triggers').getByText('release/*')).toBeVisible()
    await expect(card(page, 'Triggers').getByText('v*')).toBeVisible()
    await captureFullPage(page, 'distribution-settings-connected.png')

    const name = card(page, 'Name')
    await name.getByRole('textbox', { name: 'Name' }).fill('Acme Store')
    await name.getByRole('button', { name: 'Update' }).click()
    await expect(page.getByText('App name updated')).toBeVisible()
    expect(lastPatch(requests, shop.$id)).toEqual({ name: 'Acme Store' })

    const triggers = card(page, 'Triggers')
    const tags = triggers.locator('#distribution-providerTags')
    await tags.fill('release-*')
    await tags.press('Enter')
    await triggers.getByRole('button', { name: 'Update' }).click()
    await expect(page.getByText('Triggers updated.')).toBeVisible()
    expect(lastPatch(requests, shop.$id)).toEqual({
      providerTags: ['v*', 'release-*'],
    })

    const automation = card(page, 'Git automation')
    await automation.getByRole('switch', { name: 'Build on push' }).click()
    await automation.getByRole('button', { name: 'Update' }).click()
    await expect(
      page.getByText('Repository settings updated successfully'),
    ).toBeVisible()
    expect(lastPatch(requests, shop.$id)).toEqual({
      providerDeployments: false,
    })
  })

  test('clears silent mode when automation is saved', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(distributionPath(`/${fieldNotes.$id}/settings`))

    const automation = card(page, 'Git automation')
    const statuses = automation.getByRole('switch', {
      name: 'Report commit status',
    })
    await expect(statuses).not.toBeChecked()
    await statuses.click()
    await automation.getByRole('button', { name: 'Update' }).click()
    await expect(
      page.getByText('Repository settings updated successfully'),
    ).toBeVisible()
    expect(lastPatch(requests, fieldNotes.$id)).toEqual({
      providerSilentMode: false,
      providerDeployments: true,
      providerStatuses: true,
      providerComments: false,
    })
  })

  test('connects a repository to an app without one', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(distributionPath(`/${rewards.$id}/settings`))

    const repository = card(page, 'Repository')
    const row = repository.getByRole('button', { name: /acme\/rewards-mobile/ })
    await expect(row).toBeVisible()
    await expect(card(page, 'Git automation')).toHaveCount(0)
    await expect(card(page, 'Triggers')).toHaveCount(0)
    await captureFullPage(page, 'distribution-settings-no-repository.png')

    await row.click()
    await repository
      .getByRole('button', { name: 'Connect', exact: true })
      .last()
      .click()
    await expect(
      page.getByText('Repository connected successfully'),
    ).toBeVisible()
    expect(lastPatch(requests, rewards.$id)).toEqual({
      installationId: 'installation0001',
      providerRepositoryId: '1003',
      providerBranch: 'main',
      providerSilentMode: false,
    })
    await expect(repository.getByText('acme/rewards-mobile')).toBeVisible()
    await expect(card(page, 'Git automation')).toBeVisible()
  })

  test('reports a repository connection the server did not save', async ({
    page,
  }) => {
    const { store } = await mockDistributionApi(page)
    await ignoreRepositoryFields(page)
    await page.goto(distributionPath(`/${rewards.$id}/settings`))

    const repository = card(page, 'Repository')
    await repository
      .getByRole('button', { name: /acme\/rewards-mobile/ })
      .click()
    await repository
      .getByRole('button', { name: 'Connect', exact: true })
      .last()
      .click()
    await expect(page.getByText('Failed to connect repository')).toBeVisible()
    await expect(
      page.getByText('The server did not save these changes.'),
    ).toBeVisible()
    await expect(
      page.getByText('Repository connected successfully'),
    ).toHaveCount(0)
    await expect(card(page, 'Git automation')).toHaveCount(0)
    expect(
      store.apps.find((app) => app.$id === rewards.$id)?.installationId,
    ).toBe('')
    await screenshot(page, 'distribution-settings-repository-not-saved.png')
  })

  test('reports triggers and automation the server did not save', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await ignoreRepositoryFields(page)
    await page.goto(distributionPath(`/${shop.$id}/settings`))

    const triggers = card(page, 'Triggers')
    const tags = triggers.locator('#distribution-providerTags')
    await tags.fill('release-*')
    await tags.press('Enter')
    await triggers.getByRole('button', { name: 'Update' }).click()
    await expect(page.getByText('Failed to update triggers')).toBeVisible()
    await expect(page.getByText('Triggers updated.')).toHaveCount(0)

    const automation = card(page, 'Git automation')
    await automation.getByRole('switch', { name: 'Build on push' }).click()
    await automation.getByRole('button', { name: 'Update' }).click()
    await expect(
      page.getByText('Failed to update repository settings'),
    ).toBeVisible()
    await expect(
      page.getByText('Repository settings updated successfully'),
    ).toHaveCount(0)
  })

  test('disconnects the repository', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(distributionPath(`/${shop.$id}/settings`))

    await card(page, 'Repository')
      .getByRole('button', { name: 'Disconnect' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('Disconnect repository')).toBeVisible()
    await dialog.getByRole('button', { name: 'Disconnect' }).click()
    await expect(
      page.getByText('Repository disconnected successfully'),
    ).toBeVisible()
    expect(lastPatch(requests, shop.$id)).toEqual({
      installationId: '',
      providerRepositoryId: '',
      providerBranch: '',
      providerRootDirectory: '',
    })
    await expect(card(page, 'Git automation')).toHaveCount(0)
  })

  test('deletes the app after confirmation', async ({ page }) => {
    const { requests, store } = await mockDistributionApi(page)
    await page.goto(distributionPath(`/${shop.$id}/settings`))

    await card(page, 'Delete app')
      .getByRole('button', { name: 'Delete app' })
      .click()
    const dialog = page.getByRole('dialog')
    const confirm = dialog.getByRole('button', { name: 'Delete' })
    await expect(confirm).toBeDisabled()
    await dialog.getByRole('textbox').fill(shop.name)
    await expect(confirm).toBeEnabled()
    await screenshot(page, 'distribution-settings-delete.png')

    await confirm.click()
    await expect(page).toHaveURL(new RegExp(`${distributionPath()}$`))
    expect(
      requests.some(
        (request) =>
          request.method === 'DELETE' &&
          request.path === `/distribution/apps/${shop.$id}`,
      ),
    ).toBe(true)
    expect(store.apps.map((app) => app.$id)).not.toContain(shop.$id)
  })

  test('lists variables including secrets', async ({ page }) => {
    await mockDistributionApi(page)
    await mockProjectVariables(page)
    await page.goto(distributionPath(`/${shop.$id}/variables`))

    await expect(page).toHaveTitle(/^Variables · Distribution · /)
    await expect(page.getByText('FLUTTER_BUILD_MODE')).toBeVisible()
    await expect(page.getByText('SENTRY_DSN')).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Same name as a project variable' }),
    ).toBeVisible()
    await screenshot(page, 'distribution-variables.png')
  })

  test('redirects a read-only role away from settings and variables', async ({
    page,
  }) => {
    await mockDistributionApi(page, { role: 'readonly' })

    await page.goto(distributionPath(`/${shop.$id}/settings`))
    await expect(page).toHaveURL(
      new RegExp(`${distributionPath(`/${shop.$id}/targets`)}$`),
    )
    await screenshot(page, 'distribution-settings-readonly-redirect.png')

    await page.goto(distributionPath(`/${shop.$id}/variables`))
    await expect(page).toHaveURL(
      new RegExp(`${distributionPath(`/${shop.$id}/targets`)}$`),
    )
  })
})
