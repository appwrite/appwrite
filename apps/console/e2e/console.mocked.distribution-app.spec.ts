import type { Page } from '@playwright/test'
import {
  appsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  targetsFixtures,
  test,
} from './fixtures/distribution'

const [SHOP, , FIELD_NOTES] = appsFixtures()

function targetsPath(appId: string): string {
  return distributionPath(`/${appId}/targets`)
}

async function screenshotWholePage(page: Page, name: string) {
  const viewport = page.viewportSize()
  await page.setViewportSize({ width: viewport?.width ?? 1920, height: 2800 })
  await screenshot(page, name)
  if (viewport) await page.setViewportSize(viewport)
}

async function choose(page: Page, trigger: string, option: string | RegExp) {
  await page.locator(trigger).click()
  await page.getByRole('option', { name: option }).click()
  await expect(page.getByRole('listbox')).toBeHidden()
}

test.describe('distribution app shell and targets', () => {
  test('shows the app shell with a card per platform', async ({ page }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath(`/${SHOP.$id}`))

    await expect(page).toHaveURL(new RegExp(`${targetsPath(SHOP.$id)}$`))
    await expect(page.getByText('Acme Shop', { exact: true })).toBeVisible()
    for (const tab of ['Targets', 'Settings', 'Variables']) {
      await expect(
        page.getByRole('tab', { name: tab, exact: true }),
      ).toBeVisible()
    }
    await expect(
      page.getByRole('link', { name: 'Back to apps' }),
    ).toHaveAttribute('href', distributionPath())

    const ios = page.getByTestId('target-ios-card')
    await expect(ios.getByLabel('Bundle ID')).toHaveValue('com.acme.shop')
    await expect(ios.getByLabel('App Store app ID')).toHaveValue('6740000001')
    await expect(ios.getByLabel('App Store app ID')).toBeDisabled()
    await expect(page.locator('#target-ios-connection')).toHaveText(
      /Acme App Store Connect/,
    )
    await expect(page.locator('#target-ios-signing-identity')).toHaveText(
      /Acme Distribution 2026/,
    )
    await expect(page.locator('#target-ios-track')).toHaveText(
      'TestFlight (internal)',
    )
    await expect(ios.getByLabel('Scheme')).toHaveValue('Runner')
    await expect(ios.getByLabel('Automatic submission')).toBeChecked()
    await expect(
      ios.getByRole('link', { name: 'Manage connections' }),
    ).toHaveAttribute('href', distributionPath('/connections'))
    await expect(
      ios.getByRole('link', { name: 'Manage signing' }),
    ).toHaveAttribute('href', distributionPath('/signing'))

    const android = page.getByTestId('target-android-card')
    await expect(android.getByLabel('Package name')).toHaveValue(
      'com.acme.shop',
    )
    await expect(android.getByLabel('App Store app ID')).toHaveCount(0)
    await expect(android.getByLabel('Flavor')).toHaveValue('production')
    await expect(android.getByLabel('Gradle task')).toHaveValue(
      'bundleProductionRelease',
    )
    await expect(page.getByTestId('target-ios-missing')).toHaveCount(0)
    await expect(page.getByTestId('target-android-missing')).toHaveCount(0)
    await expect(ios.getByRole('button', { name: 'Update' })).toBeDisabled()

    await screenshotWholePage(page, 'app-targets.png')
  })

  test('limits the connection and signing options to the platform', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.goto(targetsPath(SHOP.$id))

    await page.locator('#target-android-connection').click()
    await expect(page.getByRole('option')).toHaveText([
      'None',
      'Acme Google Play',
      /Legacy Play account/,
    ])
    await page.keyboard.press('Escape')

    await page.locator('#target-ios-signing-identity').click()
    await expect(page.getByRole('option')).toHaveText([
      'None',
      'Acme Distribution 2026',
      /Managed by Appwrite/,
    ])
    await page.keyboard.press('Escape')
  })

  test('sends only the changed target fields', async ({ page }) => {
    const { requests, store } = await mockDistributionApi(page)
    await page.goto(targetsPath(SHOP.$id))
    const android = page.getByTestId('target-android-card')
    const [target] = targetsFixtures(SHOP.$id, ['android'])

    await choose(page, '#target-android-track', 'Open testing')
    await android.getByLabel('Gradle task').fill('bundleRelease')
    await android.getByLabel('Build timeout (seconds)').fill('2400')
    await android.getByRole('button', { name: 'Update' }).click()

    await expect(page.getByText('Target updated')).toBeVisible()
    const update = requests.find(
      (request) =>
        request.method === 'PATCH' &&
        request.path === `/distribution/apps/${SHOP.$id}/targets/${target.$id}`,
    )
    expect(update?.body).toEqual({
      track: 'beta',
      timeout: 2400,
      configuration: { flavor: 'production', gradleTask: 'bundleRelease' },
    })
    expect(
      store.targets.find((candidate) => candidate.$id === target.$id)?.track,
    ).toBe('beta')
    await expect(android.getByRole('button', { name: 'Update' })).toBeDisabled()
  })

  test('edits a target whose stored identifier the console would reject', async ({
    page,
  }) => {
    const [stored] = targetsFixtures(SHOP.$id, ['android'])
    const target = { ...stored, identifier: 'com.acme-shop' }
    const { requests } = await mockDistributionApi(page, {
      seed: { targets: [target, ...targetsFixtures(SHOP.$id, ['ios'])] },
    })
    await page.goto(targetsPath(SHOP.$id))
    const android = page.getByTestId('target-android-card')
    const identifier = android.getByLabel('Package name')

    await expect(identifier).toHaveValue('com.acme-shop')
    await expect(identifier).toHaveAttribute('aria-invalid', 'false')
    await android.getByLabel('Gradle task').fill('bundleRelease')
    await android.getByRole('button', { name: 'Update' }).click()

    await expect(page.getByText('Target updated')).toBeVisible()
    const update = requests.find(
      (request) =>
        request.method === 'PATCH' &&
        request.path === `/distribution/apps/${SHOP.$id}/targets/${target.$id}`,
    )
    expect(update?.body).toEqual({
      configuration: { flavor: 'production', gradleTask: 'bundleRelease' },
    })

    await identifier.fill('com.acme-store')
    await expect(identifier).toHaveAttribute('aria-invalid', 'true')
    await expect(android.getByRole('button', { name: 'Update' })).toBeDisabled()
  })

  test('rejects an invalid store identifier', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(targetsPath(SHOP.$id))
    const android = page.getByTestId('target-android-card')
    const identifier = android.getByLabel('Package name')

    await identifier.fill('com.acme-shop')
    await expect(identifier).toHaveAttribute('aria-invalid', 'true')
    await expect(
      android.getByText(
        'At least two parts separated by periods, each starting with a letter, as in your Gradle applicationId.',
      ),
    ).toBeVisible()
    await expect(android.getByRole('button', { name: 'Update' })).toBeDisabled()
    await screenshotWholePage(page, 'app-target-invalid-identifier.png')

    await identifier.fill('com.acme.shop_beta')
    await expect(identifier).toHaveAttribute('aria-invalid', 'false')
    await expect(android.getByRole('button', { name: 'Update' })).toBeEnabled()
    expect(requests.some((request) => request.method === 'PATCH')).toBe(false)
  })

  test('adds the missing platform target', async ({ page }) => {
    const { requests } = await mockDistributionApi(page, {
      seed: { targets: targetsFixtures(SHOP.$id, ['android']) },
    })
    await page.goto(targetsPath(SHOP.$id))

    const missing = page.getByTestId('target-ios-missing')
    await expect(missing).toContainText('No iOS target')
    await expect(page.getByTestId('target-android-card')).toBeVisible()
    await expect(page.getByTestId('target-android-missing')).toHaveCount(0)
    await screenshotWholePage(page, 'app-missing-target.png')

    await missing.getByRole('button', { name: 'Add target' }).click()
    const dialog = page.getByRole('dialog', { name: 'Add target' })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByLabel('Platform')).toBeDisabled()
    await expect(dialog.getByLabel('Track')).toHaveText('TestFlight (internal)')
    const add = dialog.getByRole('button', { name: 'Add', exact: true })
    await dialog.getByLabel('Bundle ID').fill('com..acme')
    await expect(dialog.getByLabel('Bundle ID')).toHaveAttribute(
      'aria-invalid',
      'true',
    )
    await expect(add).toBeDisabled()
    await dialog.getByLabel('Bundle ID').fill('com.acme.shop')
    await expect(add).toBeEnabled()
    await choose(page, '#create-target-connection', 'Acme App Store Connect')
    await choose(page, '#create-target-signing-identity', /Managed by Appwrite/)
    await screenshot(page, 'app-add-target.png')

    await add.click()

    await expect(page.getByText('Target added')).toBeVisible()
    await expect(dialog).toBeHidden()
    await expect(
      page.getByTestId('target-ios-card').getByLabel('Bundle ID'),
    ).toHaveValue('com.acme.shop')
    await expect(page.getByTestId('target-ios-missing')).toHaveCount(0)
    const create = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === `/distribution/apps/${SHOP.$id}/targets`,
    )
    expect(create?.body).toMatchObject({
      platform: 'ios',
      identifier: 'com.acme.shop',
      connectionId: 'connection0000000000001',
      signingIdentityId: 'signing000000000000002',
      track: 'testflight',
    })
    expect(Object.keys(create?.body ?? {}).sort()).toEqual([
      'connectionId',
      'identifier',
      'platform',
      'signingIdentityId',
      'targetId',
      'track',
    ])
  })

  test('offers only the platforms a native framework builds', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.goto(targetsPath(FIELD_NOTES.$id))

    await expect(page.getByTestId('target-android-card')).toBeVisible()
    await expect(page.getByTestId('target-ios-missing')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Add target' })).toHaveCount(
      0,
    )
    await screenshotWholePage(page, 'app-native-android.png')
  })

  test('offers an Android target only for a native Android app without targets', async ({
    page,
  }) => {
    await mockDistributionApi(page, { seed: { targets: [] } })
    await page.goto(targetsPath(FIELD_NOTES.$id))

    await expect(page.getByTestId('target-android-missing')).toBeVisible()
    await expect(page.getByTestId('target-ios-missing')).toHaveCount(0)
    await page
      .getByTestId('target-android-missing')
      .getByRole('button', { name: 'Add target' })
      .click()
    const dialog = page.getByRole('dialog', { name: 'Add target' })
    await expect(dialog.getByLabel('Platform')).toBeDisabled()
    await expect(dialog.getByLabel('Platform')).toHaveText('Android')
    await expect(dialog.getByLabel('Package name')).toBeVisible()
  })

  test('shows both platforms as missing when the app has no targets', async ({
    page,
  }) => {
    await mockDistributionApi(page, { seed: { targets: [] } })
    await page.goto(targetsPath(SHOP.$id))

    await expect(page.getByTestId('target-ios-missing')).toBeVisible()
    await expect(page.getByTestId('target-android-missing')).toBeVisible()
    await page
      .getByTestId('target-android-missing')
      .getByRole('button', { name: 'Add target' })
      .click()
    const dialog = page.getByRole('dialog', { name: 'Add target' })
    await expect(dialog.getByLabel('Platform')).toBeEnabled()
    await expect(dialog.getByLabel('Package name')).toBeVisible()
    await expect(dialog.getByLabel('Track')).toHaveText('Internal testing')
  })

  test('shows not found only when the app is missing', async ({ page }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath('/app0000000000000missing/settings'))
    await expect(page.getByText('App not found')).toBeVisible()
    await expect(page.getByText('Could not load this app')).toHaveCount(0)

    await page.route(
      (url) => url.pathname.endsWith(`/distribution/apps/${SHOP.$id}`),
      (route) => {
        const request = route.request()
        if (request.method() !== 'GET') return route.fallback()
        return route.fulfill({
          status: 500,
          headers: {
            'access-control-allow-origin': request.headers()['origin'] ?? '*',
            'access-control-allow-credentials': 'true',
            'content-type': 'application/json',
          },
          body: JSON.stringify({
            message: 'The distribution service is unavailable.',
            code: 500,
            type: 'general_server_error',
            version: '2.0.0',
          }),
        })
      },
    )
    await page.goto(targetsPath(SHOP.$id))
    await expect(page.getByText('Could not load this app')).toBeVisible()
    await expect(page.getByText('App not found')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
    await screenshot(page, 'app-load-error.png')
  })

  test('removes a target after confirmation', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(targetsPath(SHOP.$id))

    await page
      .getByTestId('target-android-card')
      .getByRole('button', { name: 'Remove target' })
      .click()
    await page
      .getByRole('dialog', { name: 'Remove target' })
      .getByRole('button', { name: 'Remove', exact: true })
      .click()

    await expect(page.getByText('Target removed')).toBeVisible()
    await expect(page.getByTestId('target-android-missing')).toBeVisible()
    await expect(page.getByTestId('target-android-card')).toHaveCount(0)
    expect(
      requests.some(
        (request) =>
          request.method === 'DELETE' &&
          request.path.startsWith(`/distribution/apps/${SHOP.$id}/targets/`),
      ),
    ).toBe(true)
  })

  test('keeps a read-only role out of target changes and settings', async ({
    page,
  }) => {
    await mockDistributionApi(page, {
      role: 'readonly',
      seed: {
        targets: targetsFixtures(SHOP.$id, ['ios']),
      },
    })
    await page.goto(targetsPath(SHOP.$id))

    await expect(
      page.getByRole('tab', { name: 'Targets', exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('tab', { name: 'Settings', exact: true }),
    ).toHaveCount(0)
    await expect(
      page.getByRole('tab', { name: 'Variables', exact: true }),
    ).toHaveCount(0)

    const ios = page.getByTestId('target-ios-card')
    await expect(ios.getByLabel('Bundle ID')).toBeDisabled()
    await expect(ios.getByLabel('Install command')).toBeDisabled()
    await expect(ios.getByLabel('Automatic submission')).toBeDisabled()
    await expect(page.locator('#target-ios-connection')).toBeDisabled()
    await expect(ios.getByRole('button', { name: 'Update' })).toBeDisabled()
    await expect(
      ios.getByRole('button', { name: 'Remove target' }),
    ).toBeDisabled()
    await expect(
      page
        .getByTestId('target-android-missing')
        .getByRole('button', { name: 'Add target' }),
    ).toBeDisabled()
    await screenshotWholePage(page, 'app-readonly.png')

    await page.goto(distributionPath(`/${SHOP.$id}/settings`))
    await expect(page).toHaveURL(new RegExp(`${targetsPath(SHOP.$id)}$`))
  })
})
