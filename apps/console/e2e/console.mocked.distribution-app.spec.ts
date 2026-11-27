import type { Page } from '@playwright/test'
import {
  appsFixtures,
  checkFixture,
  DISTRIBUTION_IDS,
  distributionPath,
  expect,
  mockDistributionApi,
  OWNER_SCOPES,
  screenshot,
  screenshotElement,
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

    await expect(page).toHaveURL(
      new RegExp(`${distributionPath(`/${SHOP.$id}/overview`)}$`),
    )
    await expect(page.getByText('Acme Shop', { exact: true })).toBeVisible()
    for (const tab of [
      'Overview',
      'Builds',
      'Releases',
      'Artifacts',
      'Targets',
      'Settings',
      'Variables',
    ]) {
      await expect(
        page.getByRole('tab', { name: tab, exact: true }),
      ).toBeVisible()
    }
    await expect(
      page.getByRole('link', { name: 'Back to apps' }),
    ).toHaveAttribute('href', distributionPath())

    await page.getByRole('tab', { name: 'Targets', exact: true }).click()
    await expect(page).toHaveURL(new RegExp(`${targetsPath(SHOP.$id)}$`))

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

test.describe('distribution app overview', () => {
  const IOS = DISTRIBUTION_IDS.targets.shopIos
  const ANDROID = DISTRIBUTION_IDS.targets.shopAndroid
  const SUBMISSION_SCOPES = [
    'distribution.submissions.read',
    'distribution.submissions.write',
  ]

  const overviewPath = (appId: string) => distributionPath(`/${appId}/overview`)

  const androidBlocked = checkFixture([
    {
      code: 'store_app_missing',
      severity: 'error',
      platform: 'android',
      field: 'identifier',
      message: 'Google Play has no app with the package name com.acme.shop.',
      remediation:
        'Create the app in Play Console with this package name, then run the checks again.',
      manualActionRequired: false,
    },
    {
      code: 'privacy_labels_missing',
      severity: 'warning',
      platform: 'android',
      field: '',
      message: 'The data safety form of the app is incomplete.',
      remediation: 'Complete the data safety form in Play Console.',
      manualActionRequired: true,
    },
  ])

  const checkRequests = (
    requests: { method: string; path: string; body: Record<string, unknown> }[],
    targetId: string,
  ) =>
    requests.filter(
      (request) =>
        request.method === 'POST' &&
        request.path === `/distribution/apps/${SHOP.$id}/checks` &&
        request.body.targetId === targetId,
    )

  test('shows readiness, latest build and latest submission per target', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page, {
      seed: { checks: { [`${ANDROID}:internal`]: androidBlocked } },
    })

    await page.goto(overviewPath(SHOP.$id))

    const ios = page.getByTestId('overview-ios-card')
    await expect(ios.getByText('com.acme.shop', { exact: true })).toBeVisible()
    await expect(ios.getByText('Acme App Store Connect')).toBeVisible()
    await expect(ios.getByText('Acme Distribution 2026')).toBeVisible()
    await expect(ios.getByText('TestFlight (internal)')).toBeVisible()
    await expect(
      page.getByTestId('overview-ios-readiness').getByText('Ready to submit'),
    ).toBeVisible()
    await expect(ios.getByRole('link', { name: 'View build' })).toHaveAttribute(
      'href',
      distributionPath(
        `/${SHOP.$id}/builds/${DISTRIBUTION_IDS.builds.shopReady}`,
      ),
    )
    await expect(ios.getByText('1.4.0 (42)')).toBeVisible()
    await expect(ios.getByText('Latest submission')).toBeVisible()
    await expect(ios.getByText('Approved', { exact: true })).toBeVisible()
    await expect(
      ios.getByRole('link', { name: 'View release' }),
    ).toHaveAttribute(
      'href',
      distributionPath(
        `/${SHOP.$id}/releases/${DISTRIBUTION_IDS.releases.active}`,
      ),
    )

    const android = page.getByTestId('overview-android-card')
    const readiness = page.getByTestId('overview-android-readiness')
    await expect(readiness.getByText('Not ready to submit')).toBeVisible()
    await expect(
      readiness.getByText(
        'Create the app in Play Console with this package name, then run the checks again.',
      ),
    ).toBeVisible()
    await expect(readiness.getByText('Blocker', { exact: true })).toBeVisible()
    await expect(readiness.getByText('Warning', { exact: true })).toBeVisible()
    await expect(readiness.getByText('Manual action required')).toBeVisible()
    await expect(android.getByText('Upload key')).toBeVisible()
    await expect(android.getByText('Building', { exact: true })).toBeVisible()
    await expect(android.getByText('In review', { exact: true })).toBeVisible()
    await expect(
      android.getByRole('link', { name: 'View build' }),
    ).toHaveAttribute(
      'href',
      distributionPath(
        `/${SHOP.$id}/builds/${DISTRIBUTION_IDS.builds.shopActive}`,
      ),
    )

    const tasks = page.getByTestId('overview-store-tasks')
    await expect(
      tasks.getByTestId('overview-store-tasks-ios').getByRole('link', {
        name: 'Open App Store Connect',
      }),
    ).toHaveAttribute('href', 'https://appstoreconnect.apple.com/apps')
    await expect(
      tasks.getByText('Complete the data safety form.'),
    ).toBeVisible()

    expect(checkRequests(requests, IOS)).toHaveLength(1)
    expect(checkRequests(requests, IOS)[0].body.track).toBe('testflight')
    expect(checkRequests(requests, ANDROID)).toHaveLength(1)

    await screenshotWholePage(page, 'overview.png')
    await screenshotElement(android, 'overview-blocked.png')

    await ios.getByRole('link', { name: 'View build' }).click()
    await expect(page).toHaveURL(
      new RegExp(
        `${distributionPath(`/${SHOP.$id}/builds/${DISTRIBUTION_IDS.builds.shopReady}`)}$`,
      ),
    )
  })

  test('runs the checks again on request', async ({ page }) => {
    const { requests, store } = await mockDistributionApi(page, {
      seed: { checks: { [`${ANDROID}:internal`]: androidBlocked } },
    })
    await page.goto(overviewPath(SHOP.$id))

    const readiness = page.getByTestId('overview-android-readiness')
    await expect(readiness.getByText('Not ready to submit')).toBeVisible()
    expect(checkRequests(requests, ANDROID)).toHaveLength(1)

    let release: () => void = () => {}
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    await page.route('**/v1/distribution/apps/*/checks', async (route) => {
      await held
      await route.fallback()
    })
    store.checks[`${ANDROID}:internal`] = checkFixture()

    const rerun = page
      .getByTestId('overview-android-card')
      .getByRole('button', { name: 'Run checks again' })
    await rerun.click()
    await expect(readiness.getByText('Checking…')).toBeVisible()
    await expect(rerun).toBeDisabled()
    release()

    await expect(readiness.getByText('Ready to submit')).toBeVisible()
    await expect(rerun).toBeEnabled()
    expect(checkRequests(requests, ANDROID)).toHaveLength(2)
    expect(checkRequests(requests, IOS)).toHaveLength(1)
  })

  test('links to the targets when the app has no targets', async ({ page }) => {
    const { requests } = await mockDistributionApi(page, {
      seed: { targets: [] },
    })
    await page.goto(overviewPath(SHOP.$id))

    await expect(page.getByText('No targets yet')).toBeVisible()
    const link = page.getByRole('link', { name: 'Go to targets' })
    await expect(link).toHaveAttribute('href', targetsPath(SHOP.$id))
    await expect(page.getByTestId('overview-store-tasks')).toHaveCount(0)
    await screenshotWholePage(page, 'overview-empty.png')
    expect(
      requests.filter((request) => request.path.endsWith('/checks')),
    ).toHaveLength(0)

    await link.click()
    await expect(page).toHaveURL(new RegExp(`${targetsPath(SHOP.$id)}$`))
  })

  test('hides the latest submission from a role without submission access', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page, {
      scopes: OWNER_SCOPES.filter(
        (scope) => !SUBMISSION_SCOPES.includes(scope),
      ),
    })
    await page.goto(overviewPath(SHOP.$id))

    const ios = page.getByTestId('overview-ios-card')
    const android = page.getByTestId('overview-android-card')
    await expect(page.getByRole('tab', { name: 'Settings' })).toBeVisible()
    await expect(
      page.getByTestId('overview-ios-readiness').getByText('Ready to submit'),
    ).toBeVisible()
    await expect(ios.getByRole('link', { name: 'View build' })).toBeVisible()
    await expect(
      android.getByRole('link', { name: 'View build' }),
    ).toBeVisible()
    expect(
      requests.filter(
        (request) =>
          request.method === 'GET' && request.path.endsWith('/builds'),
      ).length,
    ).toBeGreaterThanOrEqual(2)
    await expect(page.getByText('Latest submission')).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'View release' })).toHaveCount(
      0,
    )
    expect(
      requests.filter(
        (request) =>
          request.method === 'GET' && request.path.includes('/submissions'),
      ),
    ).toHaveLength(0)
  })
})
