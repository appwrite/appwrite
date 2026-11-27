import type { Locator, Page } from '@playwright/test'
import type {
  DistributionArtifact,
  DistributionRelease,
  DistributionSubmission,
} from '@/lib/appwrite/distribution'
import {
  DISTRIBUTION_IDS,
  artifactsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  releasesFixtures,
  screenshot,
  test,
  type DistributionMock,
  type DistributionRequest,
} from './fixtures/distribution'

const SHOP = DISTRIBUTION_IDS.apps.shop
const { releases, submissions, targets, artifacts, builds } = DISTRIBUTION_IDS

function releasesPath(releaseId?: string): string {
  return distributionPath(
    `/${SHOP}/releases${releaseId ? `/${releaseId}` : ''}`,
  )
}

function submissionPath(submissionId: string, suffix: string = ''): string {
  return `/distribution/apps/${SHOP}/submissions/${submissionId}${suffix}`
}

function findRequest(
  requests: DistributionRequest[],
  method: string,
  path: string,
): DistributionRequest | undefined {
  return requests.find(
    (request) => request.method === method && request.path === path,
  )
}

async function screenshotWholePage(page: Page, name: string) {
  const viewport = page.viewportSize()
  await page.setViewportSize({ width: viewport?.width ?? 1920, height: 2400 })
  await screenshot(page, name)
  if (viewport) await page.setViewportSize(viewport)
}

function corsHeaders(origin: string | undefined): Record<string, string> {
  return {
    'access-control-allow-origin': origin ?? '*',
    'access-control-allow-credentials': 'true',
  }
}

function storeSubmission(
  mock: DistributionMock,
  submissionId: string,
): DistributionSubmission {
  const found = mock.store.submissions.find(
    (submission) => submission.$id === submissionId,
  )
  if (!found) throw new Error(`No submission has the ID ${submissionId}.`)
  return found
}

function storeArtifact(
  mock: DistributionMock,
  artifactId: string,
): DistributionArtifact {
  const found = mock.store.artifacts.find(
    (artifact) => artifact.$id === artifactId,
  )
  if (!found) throw new Error(`No artifact has the ID ${artifactId}.`)
  return found
}

async function expectTooltip(button: Locator, text: string) {
  const page = button.page()
  const tooltip = page.getByRole('tooltip', { name: text })
  await expect(button).toBeDisabled()
  await button.locator('..').hover()
  await expect(tooltip).toBeAttached()
  await page.mouse.move(0, 0)
  await page.keyboard.press('Escape')
  await expect(tooltip).toHaveCount(0)
}

function submissionRow(page: Page, submissionId: string): Locator {
  return page.getByTestId(`submission-${submissionId}`)
}

async function choose(page: Page, trigger: string, option: string | RegExp) {
  await page.locator(trigger).click()
  await page.getByRole('option', { name: option }).click()
  await expect(page.getByRole('listbox')).toBeHidden()
}

async function requestControl(
  page: Page,
  submissionId: string,
  trigger: string,
  confirm: string,
) {
  await submissionRow(page, submissionId)
    .getByRole('button', { name: trigger })
    .click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: confirm, exact: true }).click()
  await expect(dialog).toBeHidden()
}

test.describe('distribution releases', () => {
  test('lists releases and searches by version', async ({ page }) => {
    await mockDistributionApi(page)
    await page.goto(releasesPath())

    const rows = page.locator('[data-testid^="release-release"]')
    await expect(rows).toHaveCount(4)
    await expect(rows.nth(0)).toContainText('1.5.0')
    await expect(rows.nth(0)).toContainText('Draft')
    await expect(page.getByTestId(`release-${releases.active}`)).toContainText(
      'Active',
    )
    await expect(
      page.getByTestId(`release-${releases.published}`),
    ).toContainText('Published')
    await expect(
      page.getByTestId(`release-${releases.attention}`),
    ).toContainText('Needs attention')
    await screenshot(page, 'releases-list.png')

    await page.getByRole('textbox', { name: 'Search releases' }).fill('1.3')
    await expect(rows).toHaveCount(1)
    await expect(rows.first()).toContainText('1.3.0')

    await page.getByRole('textbox', { name: 'Search releases' }).fill('9.9')
    await expect(rows).toHaveCount(0)

    await page.getByRole('textbox', { name: 'Search releases' }).fill('')
    await expect(rows).toHaveCount(4)
    await page.getByTestId(`release-${releases.published}`).click()
    await expect(page).toHaveURL(
      new RegExp(`${releasesPath(releases.published)}$`),
    )
  })

  test('creates a release with notes per locale', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath())

    await page.getByRole('button', { name: 'Create release' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: 'Create' })).toBeDisabled()
    await dialog.getByLabel('Version').fill('2.0.0')
    await expect(dialog.getByLabel('Locale').first()).toHaveValue('en-US')
    await dialog
      .getByLabel('Notes', { exact: true })
      .first()
      .fill('A brand new home screen.')
    await dialog.getByRole('button', { name: 'Add locale' }).click()
    await dialog
      .getByLabel('Notes', { exact: true })
      .nth(1)
      .fill('Ein neuer Startbildschirm.')
    await expect(
      dialog.getByText('Enter a locale for every note.'),
    ).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Create' })).toBeDisabled()
    await dialog.getByLabel('Locale').nth(1).fill('de-DE')
    await screenshot(page, 'release-create.png')
    await dialog.getByRole('button', { name: 'Create' }).click()

    await expect(page).toHaveURL(new RegExp(`${releasesPath()}/[^/]+$`))
    const create = findRequest(
      requests,
      'POST',
      `/distribution/apps/${SHOP}/releases`,
    )
    expect(create?.body).toEqual({
      releaseId: expect.any(String),
      version: '2.0.0',
      notes: {
        'en-US': 'A brand new home screen.',
        'de-DE': 'Ein neuer Startbildschirm.',
      },
    })
    await expect(page).toHaveURL(
      new RegExp(`${releasesPath(String(create?.body.releaseId))}$`),
    )
    const summary = page.getByTestId('release-summary')
    await expect(summary).toContainText('2.0.0')
    await expect(summary).toContainText('Draft')
    await expect(summary).toContainText('Ein neuer Startbildschirm.')
  })

  test('updates only the changed release fields', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.active))

    await page
      .getByTestId('release-summary')
      .getByRole('button', { name: 'Update' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: 'Update' })).toBeDisabled()
    await dialog.getByLabel('Version').fill('1.4.1')
    await dialog.getByRole('button', { name: 'Update' }).click()
    await expect(dialog).toBeHidden()

    const update = findRequest(
      requests,
      'PATCH',
      `/distribution/apps/${SHOP}/releases/${releases.active}`,
    )
    expect(update?.body).toEqual({ version: '1.4.1' })
    await expect(page.getByTestId('release-summary')).toContainText('1.4.1')
  })

  test('shows the release with its builds and per-target submissions', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    await page.goto(releasesPath(releases.active))

    const summary = page.getByTestId('release-summary')
    await expect(summary).toContainText('1.4.0')
    await expect(summary).toContainText('Active')
    await expect(summary).toContainText(
      'Faster checkout and a redesigned cart.',
    )

    const build = page.getByTestId(`release-build-${builds.shopReady}`)
    await expect(build).toContainText('Ready')
    await expect(build.getByRole('link')).toHaveAttribute(
      'href',
      distributionPath(`/${SHOP}/builds/${builds.shopReady}`),
    )
    await expect(
      build.getByRole('button', { name: 'Submit build' }),
    ).toBeEnabled()

    const ios = page.getByTestId(`target-submissions-${targets.shopIos}`)
    await expect(ios).toContainText('com.acme.shop')
    const testflight = submissionRow(page, submissions.testflight)
    await expect(testflight).toContainText('TestFlight (internal)')
    await expect(testflight.getByTestId('submission-status')).toHaveText(
      'Published',
    )
    await expect(testflight).toContainText('IN_BETA_TESTING')
    await expect(testflight).toContainText('Settled')
    await expect(testflight.getByRole('button')).toHaveCount(0)

    const approved = submissionRow(page, submissions.approved)
    await expect(approved.getByTestId('submission-status')).toHaveText(
      'Approved',
    )
    await expect(approved).toContainText('Manual')
    await expect(
      approved.getByRole('button', { name: 'Release to the store' }),
    ).toBeEnabled()
    await expect(
      approved.getByRole('button', { name: 'Cancel submission' }),
    ).toBeEnabled()

    const reviewing = submissionRow(page, submissions.reviewing)
    await expect(reviewing.getByTestId('submission-status')).toHaveText(
      'In review',
    )
    await expect(reviewing).toContainText('100%')
    await expect(
      reviewing.getByRole('button', { name: 'Update rollout' }),
    ).toHaveCount(0)
    await expect(page.getByTestId('release-rollback-note')).toContainText(
      'Store releases cannot be rolled back.',
    )
    await screenshotWholePage(page, 'release-detail.png')
  })

  test('pauses and resumes a staged rollout', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    const { requests } = mock
    await page.goto(releasesPath(releases.published))
    await mock.realtime.connected()

    const row = submissionRow(page, submissions.production)
    await expect(row.getByTestId('submission-status')).toHaveText('Published')
    await expect(row).toContainText('20%')

    await requestControl(
      page,
      submissions.production,
      'Pause rollout',
      'Pause rollout',
    )
    expect(
      findRequest(
        requests,
        'PATCH',
        submissionPath(submissions.production, '/status'),
      )?.body,
    ).toEqual({ status: 'halted' })
    await expect(row.getByTestId('submission-status')).toHaveText('Halted')
    await expect(row.getByTestId('submission-change-requested')).toHaveCount(0)

    await requestControl(
      page,
      submissions.production,
      'Resume rollout',
      'Resume rollout',
    )
    const statuses = requests.filter(
      (request) =>
        request.method === 'PATCH' &&
        request.path === submissionPath(submissions.production, '/status'),
    )
    expect(statuses.map((request) => request.body)).toEqual([
      { status: 'halted' },
      { status: 'published' },
    ])
    await expect(row.getByTestId('submission-status')).toHaveText('Published')
    await expect(
      row.getByRole('button', { name: 'Pause rollout' }),
    ).toBeVisible()
  })

  test('shows the change as requested until realtime confirms it', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.route(
      `**/v1${submissionPath(submissions.production, '/status')}`,
      async (route) => {
        if (route.request().method() !== 'PATCH') return route.fallback()
        const unchanged = mock.store.submissions.find(
          (submission) => submission.$id === submissions.production,
        )
        await route.fulfill({
          status: 200,
          headers: corsHeaders(route.request().headers()['origin']),
          json: unchanged,
        })
      },
    )
    await page.goto(releasesPath(releases.published))
    await mock.realtime.connected()
    const row = submissionRow(page, submissions.production)

    await requestControl(
      page,
      submissions.production,
      'Pause rollout',
      'Pause rollout',
    )
    await expect(row.getByTestId('submission-change-requested')).toBeVisible()
    await expect(row.getByTestId('submission-status')).toHaveText('Published')
    await expect(
      row.getByRole('button', { name: 'Pause rollout' }),
    ).toBeDisabled()

    mock.change('submissions', submissions.production, { status: 'halted' })
    await expect(row.getByTestId('submission-status')).toHaveText('Halted')
    await expect(row.getByTestId('submission-change-requested')).toHaveCount(0)
  })

  test('releases an approved manual submission', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    const { requests } = mock
    await page.goto(releasesPath(releases.active))
    await mock.realtime.connected()

    await requestControl(
      page,
      submissions.approved,
      'Release to the store',
      'Release',
    )
    expect(
      findRequest(
        requests,
        'PATCH',
        submissionPath(submissions.approved, '/status'),
      )?.body,
    ).toEqual({ status: 'published' })
    const row = submissionRow(page, submissions.approved)
    await expect(row.getByTestId('submission-status')).toHaveText('Published')
    await expect(
      row.getByRole('button', { name: 'Release to the store' }),
    ).toHaveCount(0)
  })

  test('cancels a submission in review', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    const { requests } = mock
    await page.goto(releasesPath(releases.active))
    await mock.realtime.connected()

    await requestControl(
      page,
      submissions.reviewing,
      'Cancel submission',
      'Cancel submission',
    )
    expect(
      findRequest(
        requests,
        'PATCH',
        submissionPath(submissions.reviewing, '/status'),
      )?.body,
    ).toEqual({ status: 'canceled' })
    const row = submissionRow(page, submissions.reviewing)
    await expect(row.getByTestId('submission-status')).toHaveText('Canceled')
    await expect(row.getByRole('button')).toHaveCount(0)
  })

  test('updates the staged rollout percentage', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.published))
    const row = submissionRow(page, submissions.production)

    await row.getByRole('button', { name: 'Update rollout' }).click()
    const dialog = page.getByRole('dialog')
    const percent = dialog.getByLabel('Rollout percentage')
    await expect(percent).toHaveValue('20')
    await expect(dialog.getByRole('button', { name: 'Update' })).toBeDisabled()
    await percent.fill('150')
    await expect(dialog.getByRole('button', { name: 'Update' })).toBeDisabled()
    await percent.fill('50')
    await dialog.getByRole('button', { name: 'Update' }).click()
    await expect(dialog).toBeHidden()

    expect(
      findRequest(requests, 'PATCH', submissionPath(submissions.production))
        ?.body,
    ).toEqual({ rollout: 0.5 })
    await expect(row).toContainText('50%')
  })

  test('shows the server error when the rollout change is refused', async ({
    page,
  }) => {
    await mockDistributionApi(page)
    const refusal = 'At least one parameter to update is required.'
    await page.route(
      `**/v1${submissionPath(submissions.production)}`,
      async (route) => {
        if (route.request().method() !== 'PATCH') return route.fallback()
        await route.fulfill({
          status: 400,
          headers: corsHeaders(route.request().headers()['origin']),
          json: {
            message: refusal,
            code: 400,
            type: 'general_argument_invalid',
            version: '2.0.0',
          },
        })
      },
    )
    await page.goto(releasesPath(releases.published))

    await submissionRow(page, submissions.production)
      .getByRole('button', { name: 'Update rollout' })
      .click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Rollout percentage').fill('40')
    await dialog.getByRole('button', { name: 'Update' }).click()
    await expect(dialog.getByTestId('update-rollout-error')).toHaveText(refusal)
    await expect(dialog).toBeVisible()
  })

  test('submits a release to an Apple target', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.active))

    await page
      .getByTestId(`target-submissions-${targets.shopIos}`)
      .getByRole('button', { name: 'Submit release' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('heading')).toHaveText(
      'Submit to the App Store',
    )
    await expect(page.locator('#create-submission-artifact')).toContainText(
      'Runner.ipa',
    )
    await expect(page.locator('#create-submission-track')).toHaveText(
      'TestFlight (internal)',
    )
    await expect(dialog.getByLabel('Release automatically')).toBeChecked()
    await expect(dialog.getByLabel('Rollout percentage')).toHaveCount(0)
    await choose(page, '#create-submission-track', 'Production')
    await dialog.getByLabel('Release automatically').click()
    await screenshot(page, 'release-submission.png')
    await dialog.getByRole('button', { name: 'Submit' }).click()
    await expect(dialog).toBeHidden()

    expect(
      findRequest(requests, 'POST', `/distribution/apps/${SHOP}/submissions`)
        ?.body,
    ).toEqual({
      releaseId: releases.active,
      targetId: targets.shopIos,
      artifactId: artifacts.shopIos,
      track: 'production',
      automatic: false,
    })
    await expect(
      page
        .getByTestId(`target-submissions-${targets.shopIos}`)
        .getByTestId('submission-status')
        .filter({ hasText: 'Waiting' }),
    ).toHaveCount(1)
  })

  test('submits a release to a Google target with a staged rollout', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.draft))

    await page
      .getByTestId(`target-submissions-${targets.shopAndroid}`)
      .getByRole('button', { name: 'Submit release' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('heading')).toHaveText(
      'Submit to Google Play',
    )
    await expect(page.locator('#create-submission-track')).toHaveText(
      'Internal testing',
    )
    await expect(dialog.getByLabel('Release automatically')).toHaveCount(0)
    await dialog.getByLabel('Rollout percentage').fill('10')
    await dialog.getByRole('button', { name: 'Submit' }).click()
    await expect(dialog).toBeHidden()

    expect(
      findRequest(requests, 'POST', `/distribution/apps/${SHOP}/submissions`)
        ?.body,
    ).toEqual({
      releaseId: releases.draft,
      targetId: targets.shopAndroid,
      artifactId: artifacts.shopAndroid,
      track: 'internal',
      rollout: 0.1,
    })
  })

  test('submits a ready build of the release', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.active))

    await page
      .getByTestId(`release-build-${builds.shopReady}`)
      .getByRole('button', { name: 'Submit build' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(page.locator('#create-submission-artifact')).toContainText(
      'Runner.ipa',
    )
    await dialog.getByRole('button', { name: 'Submit' }).click()
    await expect(dialog).toBeHidden()

    expect(
      findRequest(requests, 'POST', `/distribution/apps/${SHOP}/submissions`)
        ?.body,
    ).toEqual({
      releaseId: releases.active,
      targetId: targets.shopIos,
      artifactId: artifacts.shopIos,
      track: 'testflight',
      automatic: true,
    })
  })

  test('explains why a release in use cannot be deleted', async ({ page }) => {
    await mockDistributionApi(page)
    await page.goto(releasesPath())

    await page
      .getByTestId(`release-${releases.active}`)
      .getByRole('button', { name: /Actions for/ })
      .click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()
    await expect(
      page.getByText(
        'The release has a submission in progress. Cancel it or wait for it to finish first.',
      ),
    ).toBeVisible()
    await expect(page.getByTestId(`release-${releases.active}`)).toBeVisible()
  })

  test('deletes a draft release from its page', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)
    await page.goto(releasesPath(releases.draft))

    await page
      .getByTestId('release-summary')
      .getByRole('button', { name: 'Delete' })
      .click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()

    await expect(page).toHaveURL(new RegExp(`${releasesPath()}$`))
    expect(
      findRequest(
        requests,
        'DELETE',
        `/distribution/apps/${SHOP}/releases/${releases.draft}`,
      ),
    ).toBeDefined()
    await expect(page.locator('[data-testid^="release-release"]')).toHaveCount(
      3,
    )
  })

  test('disables writes for a read-only role', async ({ page }) => {
    await mockDistributionApi(page, { role: 'readonly' })
    await page.goto(releasesPath())

    await expectTooltip(
      page.getByRole('button', { name: 'Create release' }),
      "You don't have permission to create releases.",
    )
    await page
      .getByTestId(`release-${releases.active}`)
      .getByRole('button', { name: /Actions for/ })
      .click()
    for (const name of ['Update', 'Delete']) {
      const item = page.getByRole('menuitem', { name })
      await expect(item).toBeDisabled()
      await expect(item).toHaveAttribute(
        'title',
        "You don't have permission to update releases.",
      )
    }
    await page.keyboard.press('Escape')

    await page.goto(releasesPath(releases.published))
    const summary = page.getByTestId('release-summary')
    for (const name of ['Update', 'Delete']) {
      await expectTooltip(
        summary.getByRole('button', { name }),
        "You don't have permission to update releases.",
      )
    }
    const submissionsReason = "You don't have permission to manage submissions."
    await expectTooltip(
      page
        .getByTestId(`target-submissions-${targets.shopAndroid}`)
        .getByRole('button', { name: 'Submit release' }),
      submissionsReason,
    )
    const row = submissionRow(page, submissions.production)
    await expectTooltip(
      row.getByRole('button', { name: 'Pause rollout' }),
      submissionsReason,
    )
    await expectTooltip(
      row.getByRole('button', { name: 'Update rollout' }),
      submissionsReason,
    )
  })

  test('hides submissions without the submissions read scope', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page, {
      scopes: ['projects.read', 'distribution.read', 'distribution.write'],
    })
    await page.goto(releasesPath(releases.active))

    await expect(page.getByTestId('release-submissions-hidden')).toBeVisible()
    await expect(
      page.locator('[data-testid^="target-submissions-"]'),
    ).toHaveCount(0)
    await expectTooltip(
      page
        .getByTestId(`release-build-${builds.shopReady}`)
        .getByRole('button', { name: 'Submit build' }),
      "You don't have permission to manage submissions.",
    )
    await expect(
      page
        .getByTestId('release-summary')
        .getByRole('button', { name: 'Update' }),
    ).toBeEnabled()
    await expect
      .poll(() =>
        findRequest(
          requests,
          'GET',
          `/distribution/apps/${SHOP}/artifacts/${artifacts.shopIos}`,
        ),
      )
      .toBeDefined()
    await page.waitForLoadState('networkidle')
    expect(
      requests.filter((request) => request.path.includes('/submissions')),
    ).toEqual([])
  })

  test('warns when the store applies another rollout to a new submission', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.route(
      `**/v1/distribution/apps/${SHOP}/submissions`,
      async (route) => {
        if (route.request().method() !== 'POST') return route.fallback()
        const body = route.request().postDataJSON() as Record<string, string>
        await route.fulfill({
          status: 201,
          headers: corsHeaders(route.request().headers()['origin']),
          json: {
            ...storeSubmission(mock, submissions.reviewing),
            $id: 'submission0000000000099',
            releaseId: body.releaseId,
            targetId: body.targetId,
            artifactId: body.artifactId,
            track: body.track,
            rollout: 1,
            status: 'waiting',
            step: '',
          },
        })
      },
    )
    await page.goto(releasesPath(releases.draft))

    await page
      .getByTestId(`target-submissions-${targets.shopAndroid}`)
      .getByRole('button', { name: 'Submit release' })
      .click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText(
      'If the store applies a different percentage, you are warned after submitting.',
    )
    await dialog.getByLabel('Rollout percentage').fill('10')
    await dialog.getByRole('button', { name: 'Submit' }).click()
    await expect(dialog).toBeHidden()

    await expect(
      page.getByText(
        'The store applied a 100% rollout instead of the requested 10%.',
      ),
    ).toBeVisible()
  })

  test('warns when the store applies another rollout to an update', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    await page.route(
      `**/v1${submissionPath(submissions.production)}`,
      async (route) => {
        if (route.request().method() !== 'PATCH') return route.fallback()
        await route.fulfill({
          status: 200,
          headers: corsHeaders(route.request().headers()['origin']),
          json: {
            ...storeSubmission(mock, submissions.production),
            rollout: 1,
          },
        })
      },
    )
    await page.goto(releasesPath(releases.published))

    await submissionRow(page, submissions.production)
      .getByRole('button', { name: 'Update rollout' })
      .click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Rollout percentage').fill('50')
    await dialog.getByRole('button', { name: 'Update' }).click()
    await expect(dialog).toBeHidden()

    await expect(
      page.getByText(
        'The store applied a 100% rollout instead of the requested 50%.',
      ),
    ).toBeVisible()
  })

  test('waits for the build artifact before the build can be submitted', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    storeArtifact(mock, artifacts.shopIos).status = 'processing'
    await page.goto(releasesPath(releases.active))
    await mock.realtime.connected()

    const submit = page
      .getByTestId(`release-build-${builds.shopReady}`)
      .getByRole('button', { name: 'Submit build' })
    await expectTooltip(submit, "This build's artifact is still processing.")

    mock.change('artifacts', artifacts.shopIos, { status: 'ready' })
    await expect(submit).toBeEnabled()
  })

  test('does not swap the build artifact for another one', async ({ page }) => {
    const shopIos = artifactsFixtures().find(
      (artifact) => artifact.$id === artifacts.shopIos,
    )!
    const previous: DistributionArtifact = {
      ...shopIos,
      $id: 'artifact000000000000099',
      $createdAt: new Date(Date.now() - 3 * 86_400_000).toISOString(),
      source: 'upload',
      buildId: '',
      name: 'Runner-41.ipa',
      buildNumber: '41',
    }
    const mock = await mockDistributionApi(page, {
      seed: { artifacts: [...artifactsFixtures(), previous] },
    })
    const { requests } = mock
    await page.goto(releasesPath(releases.active))

    const submit = page
      .getByTestId(`release-build-${builds.shopReady}`)
      .getByRole('button', { name: 'Submit build' })
    await expect(submit).toBeEnabled()
    storeArtifact(mock, artifacts.shopIos).status = 'processing'
    await submit.click()

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByTestId('create-submission-artifact-unavailable'),
    ).toBeVisible()
    await expect(page.locator('#create-submission-artifact')).toHaveText(
      'Select an artifact',
    )
    await expect(dialog.getByRole('button', { name: 'Submit' })).toBeDisabled()

    await choose(page, '#create-submission-artifact', /Runner-41\.ipa/)
    await expect(
      dialog.getByTestId('create-submission-artifact-unavailable'),
    ).toHaveCount(0)
    await dialog.getByRole('button', { name: 'Submit' }).click()
    await expect(dialog).toBeHidden()
    expect(
      findRequest(requests, 'POST', `/distribution/apps/${SHOP}/submissions`)
        ?.body,
    ).toMatchObject({ artifactId: previous.$id })
  })

  test('offers no cancel once Google Play released the version', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    storeSubmission(mock, submissions.reviewing).step = 'released'
    await page.goto(releasesPath(releases.active))

    const row = submissionRow(page, submissions.reviewing)
    await expect(row.getByTestId('submission-status')).toHaveText('In review')
    await expect(row).toContainText('Released')
    await expect(row.getByRole('button')).toHaveCount(0)
    await expect(
      submissionRow(page, submissions.approved).getByRole('button', {
        name: 'Cancel submission',
      }),
    ).toBeEnabled()
  })

  test('returns to the last page when the current page empties', async ({
    page,
  }) => {
    const drafts: DistributionRelease[] = Array.from(
      { length: 7 },
      (_, index) => {
        const createdAt = new Date(
          Date.now() - (60 + index) * 86_400_000,
        ).toISOString()
        return {
          $id: `release00000000000001${index}`,
          $createdAt: createdAt,
          $updatedAt: createdAt,
          appId: SHOP,
          version: `0.${index + 1}.0`,
          notes: {},
          status: 'draft',
        }
      },
    )
    await mockDistributionApi(page, {
      seed: { releases: [...releasesFixtures(), ...drafts] },
    })
    await page.goto(releasesPath())

    const rows = page.locator('[data-testid^="release-release"]')
    await expect(rows).toHaveCount(10)
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await expect(rows).toHaveCount(1)
    const oldest = drafts[drafts.length - 1]
    await expect(rows.first()).toContainText(oldest.version)

    await rows
      .first()
      .getByRole('button', { name: /Actions for/ })
      .click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()

    await expect(rows).toHaveCount(10)
    await expect(rows.first()).toContainText('1.5.0')
    await expect(
      page.getByRole('button', { name: 'Go to previous page' }),
    ).toBeDisabled()
    await expect(page.getByText('No releases yet')).toHaveCount(0)
  })

  test('shows the release in Japanese', async ({ page }) => {
    await mockDistributionApi(page, { language: 'ja' })
    await page.goto(releasesPath(releases.active))

    await expect(page.getByText('このリリースのビルド')).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'ストアにリリース' }),
    ).toBeVisible()
    await screenshotWholePage(page, 'release-detail-ja.png')
  })
})
