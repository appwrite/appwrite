import type { Locator, Page, Route } from '@playwright/test'
import {
  DISTRIBUTION_IDS,
  appsFixtures,
  checkFixture,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  targetsFixtures,
  test,
  type DistributionMock,
} from './fixtures/distribution'

const [SHOP, REWARDS] = appsFixtures()
const {
  targets: TARGETS,
  artifacts: ARTIFACTS,
  releases: RELEASES,
} = DISTRIBUTION_IDS

const ANDROID_BLOCKED = checkFixture([
  {
    code: 'store_app_missing',
    severity: 'error',
    platform: 'android',
    field: 'identifier',
    message: 'Google Play has no app with the package com.acme.shop.',
    remediation: 'Create the app in Play Console, then run the checks again.',
    manualActionRequired: true,
  },
])

const IOS_BLOCKED = checkFixture([
  {
    code: 'store_testers_missing',
    severity: 'error',
    platform: 'ios',
    field: 'track',
    message: 'TestFlight has no external testing group for this app.',
    remediation: 'Create an external group in App Store Connect.',
    manualActionRequired: true,
  },
])

const IOS_WARNING = checkFixture([
  {
    code: 'store_review_in_progress',
    severity: 'warning',
    platform: 'ios',
    field: '',
    message: 'Another version of the app is in review.',
    remediation: 'The new build waits until the current review finishes.',
    manualActionRequired: false,
  },
])

async function openPublish(page: Page, appId: string) {
  await page.goto(distributionPath(`/${appId}/targets`))
  await page.getByRole('button', { name: 'Publish', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Publish app' })
  await expect(dialog).toBeVisible()
  return dialog
}

function posts(mock: DistributionMock): string[] {
  return mock.requests
    .filter((request) => request.method === 'POST')
    .map((request) => request.path.split('/').at(-1) ?? '')
}

function bodyOf(mock: DistributionMock, resource: string) {
  return mock.requests.filter(
    (request) =>
      request.method === 'POST' && request.path.endsWith(`/${resource}`),
  )
}

function releasePath(appId: string, releaseId: string): RegExp {
  return new RegExp(`${distributionPath(`/${appId}/releases/${releaseId}`)}$`)
}

function fail(route: Route, code: number, type: string, message: string) {
  return route.fulfill({
    status: code,
    headers: {
      'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
      'access-control-allow-credentials': 'true',
      'content-type': 'application/json',
    },
    body: JSON.stringify({ message, code, type, version: '2.0.0' }),
  })
}

const TRACK_HINT =
  'The checks use the track shown for each target. Choose the track for an artifact in the Source step.'

async function chooseArtifact(page: Page, source: Locator, track: string) {
  await source.getByRole('radio', { name: 'Use an artifact' }).click()
  await source.getByRole('combobox', { name: 'Track' }).click()
  await page.getByRole('option', { name: track, exact: true }).click()
}

test.describe('distribution publish flow', () => {
  test('blocks on check errors until the blocked target is deselected', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page, {
      seed: {
        checks: {
          [`${TARGETS.shopAndroid}:internal`]: ANDROID_BLOCKED,
          [`${TARGETS.shopIos}:testflight`]: IOS_WARNING,
        },
      },
    })
    const dialog = await openPublish(page, SHOP.$id)
    const ios = dialog.getByTestId('publish-target-ios')
    const android = dialog.getByTestId('publish-target-android')
    const next = dialog.getByRole('button', { name: 'Continue' })

    await expect(ios.getByRole('checkbox')).toBeChecked()
    await expect(android.getByRole('checkbox')).toBeChecked()
    await expect(dialog.getByTestId('publish-target-ios-track')).toContainText(
      'TestFlight (internal)',
    )
    await expect(
      dialog.getByTestId('publish-target-android-track'),
    ).toContainText('Internal testing')
    await expect(dialog.getByRole('combobox')).toHaveCount(0)
    await expect(dialog.getByRole('switch')).toHaveCount(0)
    await expect(dialog.getByText(TRACK_HINT)).toBeVisible()
    await expect(next).toBeDisabled()

    await dialog.getByRole('button', { name: 'Run checks' }).click()

    await expect(
      android.getByText(
        'Google Play has no app with the package com.acme.shop.',
      ),
    ).toBeVisible()
    await expect(
      android.getByText(
        'Create the app in Play Console, then run the checks again.',
      ),
    ).toBeVisible()
    await expect(android.getByText('Blocker')).toBeVisible()
    await expect(android.getByText('Manual action required')).toBeVisible()
    await expect(
      ios.getByText('Another version of the app is in review.'),
    ).toBeVisible()
    await expect(ios.getByText('Warning', { exact: true })).toBeVisible()
    await expect(
      dialog.getByText(
        'Resolve the blockers or deselect those targets to continue.',
      ),
    ).toBeVisible()
    await expect(next).toBeDisabled()
    expect(bodyOf(mock, 'checks').map((request) => request.body)).toEqual(
      expect.arrayContaining([
        { targetId: TARGETS.shopIos, track: 'testflight' },
        { targetId: TARGETS.shopAndroid, track: 'internal' },
      ]),
    )
    await screenshot(page, 'publish-checks.png')

    await android.getByRole('checkbox').click()
    await expect(next).toBeEnabled()
    await next.click()
    await expect(
      dialog.getByRole('radio', { name: 'Create a release' }),
    ).toBeChecked()
  })

  test('creates a release, submits an artifact and starts a build', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })

    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await expect(dialog.getByText('Ready to submit')).toHaveCount(2)
    await next.click()

    await expect(next).toBeDisabled()
    await dialog.locator('#publish-release-version').fill('1.6.0')
    await dialog.locator('#publish-release-notes').fill('Faster checkout.')
    await screenshot(page, 'publish-release.png')
    await next.click()

    const ios = dialog.getByTestId('publish-source-ios')
    const android = dialog.getByTestId('publish-source-android')
    await expect(
      ios.getByRole('radio', { name: 'Build from main' }),
    ).toBeChecked()
    await android.getByRole('radio', { name: 'Use an artifact' }).click()
    await expect(
      android.getByRole('combobox', { name: 'Artifact' }),
    ).toContainText('1.3.0 (17)')
    await expect(android.getByRole('combobox', { name: 'Track' })).toHaveText(
      'Internal testing',
    )
    await screenshot(page, 'publish-sources.png')
    await next.click()

    const review = dialog.getByTestId('publish-review-ios')
    await expect(review.getByText('Build from main')).toBeVisible()
    await expect(
      review.getByText(
        'Submit this build from the release page when it is ready.',
      ),
    ).toBeVisible()
    await expect(
      dialog
        .getByTestId('publish-review-android')
        .getByText('Internal testing'),
    ).toBeVisible()
    await expect(dialog.getByText('1.6.0')).toBeVisible()
    await screenshot(page, 'publish-review.png')

    await dialog.getByRole('button', { name: 'Publish', exact: true }).click()

    await expect
      .poll(() => posts(mock))
      .toEqual([
        'checks',
        'checks',
        'checks',
        'checks',
        'releases',
        'submissions',
        'builds',
      ])
    const created = mock.store.releases.find((item) => item.version === '1.6.0')
    expect(created).toBeDefined()
    expect(bodyOf(mock, 'releases')[0].body).toMatchObject({
      version: '1.6.0',
      notes: { 'en-US': 'Faster checkout.' },
    })
    expect(bodyOf(mock, 'submissions')[0].body).toEqual({
      releaseId: created!.$id,
      targetId: TARGETS.shopAndroid,
      artifactId: ARTIFACTS.shopAndroid,
      track: 'internal',
    })
    expect(bodyOf(mock, 'builds')[0].body).toEqual({
      targetId: TARGETS.shopIos,
      releaseId: created!.$id,
      type: 'branch',
      reference: 'main',
    })
    await expect(page).toHaveURL(releasePath(SHOP.$id, created!.$id))
    await expect(page.getByText('Publishing started')).toBeVisible()
    await expect(dialog).toBeHidden()
    await screenshot(page, 'publish-done.png')
  })

  test('submits an Apple artifact to an open release with the track and automatic value chosen in the Source step', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })

    await dialog
      .getByTestId('publish-target-android')
      .getByRole('checkbox')
      .click()
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await next.click()

    await dialog.getByRole('radio', { name: 'Add to an open release' }).click()
    await dialog.locator('#publish-release-select').click()
    await page.getByRole('option', { name: /1\.4\.0/ }).click()
    await next.click()

    const source = dialog.getByTestId('publish-source-ios')
    await expect(source.getByRole('combobox', { name: 'Track' })).toHaveCount(0)
    await chooseArtifact(page, source, 'TestFlight (external)')
    const automatic = source.getByRole('switch', {
      name: 'Release automatically after approval',
    })
    await expect(automatic).toBeChecked()
    await automatic.click()
    await expect(automatic).not.toBeChecked()
    await next.click()

    const review = dialog.getByTestId('publish-review-ios')
    await expect(review.getByText('TestFlight (external)')).toBeVisible()
    await expect(review.getByText('Wait for a manual release')).toBeVisible()
    await dialog.getByRole('button', { name: 'Publish', exact: true }).click()

    await expect(page).toHaveURL(releasePath(SHOP.$id, RELEASES.active))
    expect(posts(mock)).toEqual(['checks', 'checks', 'submissions'])
    expect(bodyOf(mock, 'checks').map((request) => request.body)).toEqual([
      { targetId: TARGETS.shopIos, track: 'testflight' },
      { targetId: TARGETS.shopIos, track: 'testflight-external' },
    ])
    expect(bodyOf(mock, 'submissions')[0].body).toEqual({
      releaseId: RELEASES.active,
      targetId: TARGETS.shopIos,
      artifactId: ARTIFACTS.shopIos,
      track: 'testflight-external',
      automatic: false,
    })
  })

  test('re-checks an artifact against the track chosen in the Source step', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page, {
      seed: {
        checks: { [`${TARGETS.shopIos}:testflight-external`]: IOS_BLOCKED },
      },
    })
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })
    const ios = dialog.getByTestId('publish-target-ios')

    await dialog
      .getByTestId('publish-target-android')
      .getByRole('checkbox')
      .click()
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await expect(ios.getByText('Ready to submit')).toBeVisible()
    await next.click()
    await dialog.locator('#publish-release-version').fill('1.6.5')
    await next.click()
    await chooseArtifact(
      page,
      dialog.getByTestId('publish-source-ios'),
      'TestFlight (external)',
    )
    await next.click()
    await dialog.getByRole('button', { name: 'Publish', exact: true }).click()

    await expect(
      ios.getByText('TestFlight has no external testing group for this app.'),
    ).toBeVisible()
    await expect(dialog.getByTestId('publish-target-ios-track')).toContainText(
      'TestFlight (external)',
    )
    await expect(next).toBeDisabled()
    expect(posts(mock)).toEqual(['checks', 'checks'])
    expect(
      mock.store.releases.find((item) => item.version === '1.6.5'),
    ).toBeUndefined()
  })

  test('shows a per-target error that Retry fixes', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    let attempts = 0
    await page.route('**/v1/distribution/apps/*/submissions', async (route) => {
      if (route.request().method() !== 'POST') return route.fallback()
      attempts += 1
      if (attempts > 1) return route.fallback()
      return fail(
        route,
        400,
        'distribution_version_invalid',
        'Version code 17 is not higher than the latest upload on Google Play.',
      )
    })
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })

    await dialog.getByTestId('publish-target-ios').getByRole('checkbox').click()
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await next.click()
    await dialog.locator('#publish-release-version').fill('1.6.1')
    await next.click()
    await dialog
      .getByTestId('publish-source-android')
      .getByRole('radio', { name: 'Use an artifact' })
      .click()
    await next.click()
    await dialog.getByRole('button', { name: 'Publish', exact: true }).click()

    const android = dialog.getByTestId('publish-review-android')
    await expect(
      android.getByText(
        'Version code 17 is not higher than the latest upload on Google Play.',
      ),
    ).toBeVisible()
    await expect(
      dialog.getByRole('button', { name: 'Publish', exact: true }),
    ).toBeDisabled()
    await expect(dialog.getByRole('button', { name: 'Back' })).toBeDisabled()

    await android.getByRole('button', { name: 'Retry' }).click()

    const created = mock.store.releases.find((item) => item.version === '1.6.1')
    await expect(page).toHaveURL(releasePath(SHOP.$id, created!.$id))
    expect(attempts).toBe(2)
    expect(posts(mock)).toEqual([
      'checks',
      'checks',
      'releases',
      'checks',
      'submissions',
    ])
  })

  test('creates the release again with the same release ID', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    const releaseIds: unknown[] = []
    await page.route('**/v1/distribution/apps/*/releases', async (route) => {
      if (route.request().method() !== 'POST') return route.fallback()
      releaseIds.push(route.request().postDataJSON().releaseId)
      if (releaseIds.length > 1) return route.fallback()
      return fail(route, 503, 'general_server_error', 'Server error.')
    })
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })
    const publish = dialog.getByRole('button', { name: 'Publish', exact: true })

    await dialog.getByTestId('publish-target-ios').getByRole('checkbox').click()
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await next.click()
    await dialog.locator('#publish-release-version').fill('1.6.3')
    await next.click()
    await dialog
      .getByTestId('publish-source-android')
      .getByRole('radio', { name: 'Use an artifact' })
      .click()
    await next.click()
    await publish.click()

    await expect(dialog.getByRole('alert')).toBeVisible()
    await expect(publish).toBeEnabled()
    await publish.click()

    await expect(page).toHaveURL(releasePath(SHOP.$id, String(releaseIds[0])))
    expect(releaseIds).toHaveLength(2)
    expect(releaseIds[1]).toBe(releaseIds[0])
    expect(
      mock.store.releases.find((item) => item.version === '1.6.3')?.$id,
    ).toBe(releaseIds[0])
  })

  test('returns to the checks when a blocker appears before publishing', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    const dialog = await openPublish(page, SHOP.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })
    const android = dialog.getByTestId('publish-target-android')

    await dialog.getByTestId('publish-target-ios').getByRole('checkbox').click()
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await expect(android.getByText('Ready to submit')).toBeVisible()
    await next.click()
    await dialog.locator('#publish-release-version').fill('1.6.4')
    await next.click()
    await dialog
      .getByTestId('publish-source-android')
      .getByRole('radio', { name: 'Use an artifact' })
      .click()
    await next.click()

    mock.store.checks[`${TARGETS.shopAndroid}:internal`] = ANDROID_BLOCKED
    await dialog.getByRole('button', { name: 'Publish', exact: true }).click()

    await expect(
      android.getByText(
        'Google Play has no app with the package com.acme.shop.',
      ),
    ).toBeVisible()
    await expect(
      page.getByText(
        'The checks changed since you ran them. Review the targets to publish.',
      ),
    ).toBeVisible()
    await expect(next).toBeDisabled()
    expect(posts(mock)).toEqual(['checks', 'checks'])
    expect(
      mock.store.releases.find((item) => item.version === '1.6.4'),
    ).toBeUndefined()
  })

  test('checks a build-sourced target against its stored track', async ({
    page,
  }) => {
    const [ios, android] = targetsFixtures(SHOP.$id)
    const mock = await mockDistributionApi(page, {
      seed: {
        targets: [
          { ...ios, track: 'beta' },
          { ...android, track: 'beta' },
        ],
      },
    })
    const dialog = await openPublish(page, SHOP.$id)

    await expect(dialog.getByTestId('publish-target-ios-track')).toContainText(
      'TestFlight (internal)',
    )
    await expect(
      dialog.getByTestId('publish-target-android-track'),
    ).toContainText('Open testing')
    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await expect(dialog.getByText('Ready to submit')).toHaveCount(2)

    expect(bodyOf(mock, 'checks').map((request) => request.body)).toEqual(
      expect.arrayContaining([
        { targetId: TARGETS.shopIos, track: 'testflight' },
        { targetId: TARGETS.shopAndroid, track: 'beta' },
      ]),
    )
  })

  test('disables Publish without permission', async ({ page }) => {
    await mockDistributionApi(page, { role: 'readonly' })
    await page.goto(distributionPath(`/${SHOP.$id}/targets`))
    const publish = page.getByRole('button', { name: 'Publish', exact: true })
    await expect(publish).toBeDisabled()
    await publish.locator('..').hover()
    await expect(
      page.getByRole('tooltip', {
        name: "You don't have permission to publish this app.",
      }),
    ).toBeVisible()
  })

  test('disables Publish when the app has no targets', async ({ page }) => {
    await mockDistributionApi(page, { seed: { targets: [] } })
    await page.goto(distributionPath(`/${SHOP.$id}/targets`))
    const publish = page.getByRole('button', { name: 'Publish', exact: true })
    await expect(publish).toBeDisabled()
    await publish.locator('..').hover()
    await expect(
      page.getByRole('tooltip', { name: 'Add a target to publish this app.' }),
    ).toBeVisible()
  })

  test('explains a disabled Publish when the targets fail to load', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.route('**/v1/distribution/apps/*/targets*', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback()
      return fail(route, 503, 'general_server_error', 'Server error.')
    })
    await page.goto(distributionPath(`/${SHOP.$id}/builds`))
    const publish = page.getByRole('button', { name: 'Publish', exact: true })
    await expect(publish).toBeDisabled()
    await publish.locator('..').hover()
    await expect(
      page.getByRole('tooltip', {
        name: 'Could not load the targets of this app.',
      }),
    ).toBeVisible()
    await expect(
      page.getByRole('tooltip', { name: 'Add a target to publish this app.' }),
    ).toHaveCount(0)
  })

  test('disables the build source when the app has no repository', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    const dialog = await openPublish(page, REWARDS.$id)
    const next = dialog.getByRole('button', { name: 'Continue' })

    await dialog.getByRole('button', { name: 'Run checks' }).click()
    await next.click()
    await dialog.locator('#publish-release-version').fill('2.0.0')
    await next.click()

    const ios = dialog.getByTestId('publish-source-ios')
    const build = ios.getByRole('radio', { name: 'Build from Git' })
    await expect(build).toBeDisabled()
    await expect(
      ios.getByRole('radio', { name: 'Use an artifact' }),
    ).toBeChecked()
    await expect(
      ios.getByText(
        'No ready artifacts for this target. Upload one from the Artifacts tab.',
      ),
    ).toBeVisible()
    await expect(next).toBeDisabled()
    await ios.getByText('Build from Git').hover()
    await expect(
      page.getByRole('tooltip', {
        name: 'Connect a repository in the app settings to build from Git.',
      }),
    ).toBeVisible()
  })
})
