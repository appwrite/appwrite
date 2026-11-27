import type { Page, Route } from '@playwright/test'
import {
  ARTIFACT_CHUNK_SIZE,
  DISTRIBUTION_IDS,
  PROJECT,
  appsFixtures,
  artifactsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  test,
  type DistributionRequest,
} from './fixtures/distribution'

const [SHOP] = appsFixtures()
const ARTIFACTS_PATH = distributionPath(`/${SHOP.$id}/artifacts`)
const API_PATH = `/distribution/apps/${SHOP.$id}/artifacts`
const UPLOAD_SIZE = 11_534_336
const UPLOAD_NAME = 'shop-1.5.0.aab'

function row(page: Page, artifactId: string) {
  return page.getByTestId(`artifact-${artifactId}`)
}

async function openRowMenu(page: Page, artifactId: string) {
  await row(page, artifactId)
    .getByRole('button', { name: /^Actions for/ })
    .click()
}

function binary(name: string, size: number) {
  return {
    name,
    mimeType: 'application/octet-stream',
    buffer: Buffer.alloc(size),
  }
}

function failWith(route: Route, status: number, type: string, message: string) {
  return route.fulfill({
    status,
    headers: {
      'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
      'access-control-allow-credentials': 'true',
      'content-type': 'application/json',
    },
    body: JSON.stringify({ message, code: status, type, version: '2.0.0' }),
  })
}

function isUpload(request: DistributionRequest) {
  return request.method === 'POST' && request.path === API_PATH
}

function isListing(request: DistributionRequest) {
  return request.method === 'GET' && request.path === API_PATH
}

function uploadedSize(request: DistributionRequest) {
  return (request.body.file as { size: number }).size
}

async function chooseUpload(page: Page, name: string, size: number) {
  await page.getByRole('button', { name: 'Upload artifact' }).click()
  const dialog = page.getByRole('dialog')
  await page
    .locator('#distribution-artifact-file')
    .setInputFiles(binary(name, size))
  await expect(dialog).toContainText(name)
  await dialog.getByRole('button', { name: 'Upload', exact: true }).click()
  await expect(dialog).toBeHidden()
}

function uploadCard(page: Page, name: string) {
  return page.locator('[data-testid^="artifact-upload-"]', { hasText: name })
}

test.describe('distribution artifacts', () => {
  test('lists artifacts with status, source and version', async ({ page }) => {
    await mockDistributionApi(page)

    await page.goto(ARTIFACTS_PATH)

    const ios = row(page, DISTRIBUTION_IDS.artifacts.shopIos)
    await expect(ios).toContainText('Runner.ipa')
    await expect(ios).toContainText('com.acme.shop')
    await expect(ios).toContainText('iOS')
    await expect(ios).toContainText('1.4.0')
    await expect(ios).toContainText('(42)')
    await expect(ios).toContainText('Ready')
    await expect(ios).toContainText('Signed')
    await expect(ios.getByRole('link', { name: 'Build' })).toHaveAttribute(
      'href',
      distributionPath(
        `/${SHOP.$id}/builds/${DISTRIBUTION_IDS.builds.shopReady}`,
      ),
    )

    const android = row(page, DISTRIBUTION_IDS.artifacts.shopAndroid)
    await expect(android).toContainText('(17)')
    await expect(android).toContainText('Upload')

    const processing = row(page, DISTRIBUTION_IDS.artifacts.processing)
    await expect(processing).toContainText('Processing')

    const invalid = row(page, DISTRIBUTION_IDS.artifacts.invalid)
    await expect(invalid).toContainText('Invalid')
    await expect(invalid).toContainText(
      'No target of the app ships the bundle ID com.acme.unknown.',
    )
    await expect(invalid).toContainText('Unsigned')

    await expect(
      page.getByRole('button', { name: 'Upload artifact' }),
    ).toBeEnabled()
    await screenshot(page, 'artifacts-list.png')

    await row(page, DISTRIBUTION_IDS.artifacts.shopIos).click({
      button: 'right',
    })
    const contextMenu = page.getByRole('menu')
    await expect(
      contextMenu.getByRole('menuitem', { name: 'Download' }),
    ).toBeVisible()
    await expect(
      contextMenu.getByRole('menuitem', { name: 'Copy' }),
    ).toBeVisible()
    await expect(
      contextMenu.getByRole('menuitem', { name: 'Delete' }),
    ).toBeVisible()
  })

  test('uploads an AAB in exact 5 MiB chunks through the progress panel', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    let release = () => {}
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    await page.route(`**/v1${API_PATH}`, async (route) => {
      const range = route.request().headers()['content-range'] ?? ''
      if (
        route.request().method() === 'POST' &&
        range.startsWith(`bytes ${2 * ARTIFACT_CHUNK_SIZE}-`)
      ) {
        await held
      }
      await route.fallback()
    })

    await page.goto(ARTIFACTS_PATH)
    await mock.realtime.connected()
    await page.getByRole('button', { name: 'Upload artifact' }).click()

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByRole('heading', { name: 'Upload artifact' }),
    ).toBeVisible()
    await expect(dialog).toContainText('com.acme.shop')
    await page
      .locator('#distribution-artifact-file')
      .setInputFiles(binary(UPLOAD_NAME, UPLOAD_SIZE))
    await expect(dialog).toContainText(UPLOAD_NAME)
    await expect(dialog).toContainText('11 MB')
    await screenshot(page, 'artifact-upload.png')
    await dialog.getByRole('button', { name: 'Upload', exact: true }).click()
    await expect(dialog).toBeHidden()

    const card = page.locator('[data-testid^="artifact-upload-"]')
    await expect(card).toHaveAttribute('data-status', 'uploading')
    await expect(card).toContainText(UPLOAD_NAME)
    await expect(card).toContainText('91%')
    await expect(card).toContainText(/\b10\.\d{2} MB \/ 11\.00 MB\b/)
    await expect(
      card.getByRole('button', { name: 'Cancel upload' }),
    ).toBeVisible()
    const partial = mock.store.artifacts.find(
      (artifact) => artifact.name === UPLOAD_NAME,
    )
    expect(partial).toBeDefined()
    await expect(row(page, partial!.$id)).toContainText('Uploading')
    const listingsBeforeCompletion = mock.requests.filter(isListing).length
    await card.evaluate((element) =>
      Promise.all(
        element
          .getAnimations({ subtree: true })
          .filter((animation) => animation instanceof CSSTransition)
          .map((animation) => animation.finished),
      ),
    )
    await screenshot(page, 'artifact-upload-panel.png')

    release()
    await expect(card).toHaveAttribute('data-status', 'completed')
    await expect(card).toContainText('100%')
    await expect(card.getByRole('button', { name: 'Dismiss' })).toBeVisible()
    await expect
      .poll(() => mock.requests.filter(isListing).length)
      .toBeGreaterThan(listingsBeforeCompletion)
    await expect(
      card.getByRole('link', { name: 'View artifacts' }),
    ).toHaveAttribute('href', ARTIFACTS_PATH)

    const uploads = mock.requests.filter(isUpload)
    expect(uploads).toHaveLength(3)
    expect(uploads.map(uploadedSize).sort((a, b) => a - b)).toEqual([
      1_048_576, 5_242_880, 5_242_880,
    ])
    expect(
      new Set(uploads.map((request) => request.headers['content-range'])),
    ).toEqual(
      new Set([
        `bytes 0-5242879/${UPLOAD_SIZE}`,
        `bytes 5242880-10485759/${UPLOAD_SIZE}`,
        `bytes 10485760-11534335/${UPLOAD_SIZE}`,
      ]),
    )
    const first = uploads.filter((request) => !request.headers['x-appwrite-id'])
    expect(first).toHaveLength(1)
    expect(first[0].headers['content-range']).toMatch(/^bytes 0-/)
    const created = mock.store.artifacts.find(
      (artifact) => artifact.name === UPLOAD_NAME,
    )
    expect(created).toBeDefined()
    const rest = uploads.filter((request) => request !== first[0])
    expect(rest.map((request) => request.headers['x-appwrite-id'])).toEqual([
      created!.$id,
      created!.$id,
    ])

    const uploaded = row(page, created!.$id)
    await expect(uploaded).toContainText(UPLOAD_NAME)
    await expect(uploaded).toContainText('Processing')

    mock.change('artifacts', created!.$id, {
      status: 'ready',
      platform: 'android',
      targetId: DISTRIBUTION_IDS.targets.shopAndroid,
      identifier: 'com.acme.shop',
      versionName: '1.5.0',
      versionCode: 18,
      buildNumber: '18',
      signed: true,
    })
    await expect(uploaded).toContainText('Ready')
    await expect(uploaded).toContainText('(18)')

    await card.getByRole('button', { name: 'Dismiss' }).click()
    await expect(card).toBeHidden()
  })

  test('cancels an upload so its remaining chunks are never sent', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    let releaseFirst = () => {}
    const heldFirst = new Promise<void>((resolve) => {
      releaseFirst = resolve
    })
    let releaseSmall = () => {}
    const heldSmall = new Promise<void>((resolve) => {
      releaseSmall = resolve
    })
    const smallName = 'Runner-small.ipa'
    const smallSize = 1_048_576
    await page.route(`**/v1${API_PATH}`, async (route) => {
      const request = route.request()
      const range = request.headers()['content-range'] ?? ''
      if (request.method() === 'POST' && range.startsWith('bytes 0-')) {
        await heldFirst
      }
      if (request.method() === 'POST' && !range) await heldSmall
      await route.fallback()
    })

    await page.goto(ARTIFACTS_PATH)
    await mock.realtime.connected()
    await chooseUpload(page, UPLOAD_NAME, UPLOAD_SIZE)

    const card = uploadCard(page, UPLOAD_NAME)
    await expect(card).toHaveAttribute('data-status', 'uploading')
    await card.getByRole('button', { name: 'Cancel upload' }).click()
    await expect(card).toBeHidden()

    releaseFirst()
    await expect
      .poll(() =>
        mock.store.artifacts.some((artifact) => artifact.name === UPLOAD_NAME),
      )
      .toBe(true)

    await chooseUpload(page, smallName, smallSize)
    const small = uploadCard(page, smallName)
    await expect(small).toHaveAttribute('data-status', 'uploading')
    await expect(small).toContainText('Uploading…')
    await expect(small).not.toContainText('%')
    await expect(
      small.getByRole('progressbar', { name: smallName }),
    ).toHaveAttribute('aria-busy', 'true')
    releaseSmall()
    await expect(small).toHaveAttribute('data-status', 'completed')
    await expect(small).toContainText('100%')

    const cancelled = mock.store.artifacts.find(
      (artifact) => artifact.name === UPLOAD_NAME,
    )
    expect(cancelled).toMatchObject({ status: 'uploading', chunksUploaded: 1 })
    expect(
      mock.requests
        .filter(isUpload)
        .map((request) => request.headers['content-range']),
    ).toEqual([`bytes 0-5242879/${UPLOAD_SIZE}`, undefined])
  })

  test('retries an upload the server turned away', async ({ page }) => {
    const mock = await mockDistributionApi(page)
    const message =
      'Rate limit for the current endpoint has been exceeded. Please try again after some time.'
    let busy = true
    await page.route(`**/v1${API_PATH}`, async (route) => {
      const range = route.request().headers()['content-range'] ?? ''
      if (
        busy &&
        route.request().method() === 'POST' &&
        range.startsWith(`bytes ${ARTIFACT_CHUNK_SIZE}-`)
      ) {
        busy = false
        return failWith(route, 429, 'general_rate_limit_exceeded', message)
      }
      return route.fallback()
    })

    await page.goto(ARTIFACTS_PATH)
    await mock.realtime.connected()
    await chooseUpload(page, UPLOAD_NAME, UPLOAD_SIZE)

    const card = uploadCard(page, UPLOAD_NAME)
    await expect(card).toHaveAttribute('data-status', 'failed')
    await expect(card).toContainText(message)
    await expect(card.getByRole('button', { name: 'Dismiss' })).toBeVisible()
    await card.getByRole('button', { name: 'Retry' }).click()
    await expect(card).toHaveAttribute('data-status', 'completed')
    await expect(card).toContainText('100%')

    const created = mock.store.artifacts.filter(
      (artifact) => artifact.name === UPLOAD_NAME,
    )
    expect(created).toHaveLength(1)
    const [completed] = created
    expect(completed).toMatchObject({ status: 'processing', chunksUploaded: 3 })
    const [start, ...chunks] = mock.requests.filter(isUpload)
    expect(start.headers['x-appwrite-id']).toBeUndefined()
    expect(start.headers['content-range']).toBe(
      `bytes 0-5242879/${UPLOAD_SIZE}`,
    )
    expect(chunks.map((request) => request.headers['x-appwrite-id'])).toEqual(
      chunks.map(() => completed.$id),
    )
    expect(
      chunks.map((request) => request.headers['content-range']).sort(),
    ).toEqual([
      `bytes 0-5242879/${UPLOAD_SIZE}`,
      `bytes 10485760-11534335/${UPLOAD_SIZE}`,
      `bytes 10485760-11534335/${UPLOAD_SIZE}`,
      `bytes 5242880-10485759/${UPLOAD_SIZE}`,
    ])
  })

  test('rejects a file that is not an IPA or AAB', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(ARTIFACTS_PATH)
    await page.getByRole('button', { name: 'Upload artifact' }).click()
    const dialog = page.getByRole('dialog')
    await page
      .locator('#distribution-artifact-file')
      .setInputFiles(binary('release-notes.txt', 128))

    await expect(dialog.getByRole('alert')).toHaveText(
      'Choose an .ipa or .aab file.',
    )
    await expect(
      dialog.getByRole('button', { name: 'Upload', exact: true }),
    ).toBeDisabled()
    expect(
      requests.filter(
        (request) => request.method === 'POST' && request.path === API_PATH,
      ),
    ).toHaveLength(0)
  })

  test('downloads a ready artifact through the admin URL', async ({ page }) => {
    await mockDistributionApi(page)
    await page.addInitScript(() => {
      const opened: string[] = []
      Object.assign(window, { openedUrls: opened })
      window.open = (url?: string | URL) => {
        opened.push(String(url))
        return null
      }
    })

    await page.goto(ARTIFACTS_PATH)

    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.processing)
    await expect(
      page.getByRole('menuitem', { name: 'Download' }),
    ).toBeDisabled()
    await page.keyboard.press('Escape')

    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.shopIos)
    await page.getByRole('menuitem', { name: 'Download' }).click()

    const opened = await page.evaluate(
      () => (window as unknown as { openedUrls: string[] }).openedUrls,
    )
    expect(opened).toHaveLength(1)
    const url = new URL(opened[0])
    expect(url.pathname).toBe(
      `/v1${API_PATH}/${DISTRIBUTION_IDS.artifacts.shopIos}/download`,
    )
    expect(url.searchParams.get('project')).toBe(PROJECT.$id)
    expect(url.searchParams.get('mode')).toBe('admin')
  })

  test('deletes an artifact after confirmation', async ({ page }) => {
    const mock = await mockDistributionApi(page)

    await page.goto(ARTIFACTS_PATH)
    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.invalid)
    await page.getByRole('menuitem', { name: 'Delete' }).click()

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByRole('heading', { name: 'Delete artifact' }),
    ).toBeVisible()
    await expect(dialog).toContainText('Unknown.ipa')
    await dialog.getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog).toBeHidden()
    expect(mock.requests.some((request) => request.method === 'DELETE')).toBe(
      false,
    )

    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.invalid)
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await dialog.getByRole('button', { name: 'Delete' }).click()

    await expect(row(page, DISTRIBUTION_IDS.artifacts.invalid)).toBeHidden()
    await expect(page.getByText('Artifact deleted')).toBeVisible()
    expect(
      mock.requests.filter((request) => request.method === 'DELETE'),
    ).toEqual([
      expect.objectContaining({
        path: `${API_PATH}/${DISTRIBUTION_IDS.artifacts.invalid}`,
      }),
    ])
  })

  test('shows the server message when the artifact is still in use', async ({
    page,
  }) => {
    const mock = await mockDistributionApi(page)
    const message =
      'The artifact has a submission in progress, in review or rolling out. Cancel it or wait for it to finish before deleting the artifact.'
    await page.route(
      `**/v1${API_PATH}/${DISTRIBUTION_IDS.artifacts.shopAndroid}`,
      (route) =>
        route.request().method() === 'DELETE'
          ? failWith(route, 409, 'distribution_artifact_in_use', message)
          : route.fallback(),
    )

    await page.goto(ARTIFACTS_PATH)
    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.shopAndroid)
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()

    await expect(page.getByText(message)).toBeVisible()
    await expect(
      row(page, DISTRIBUTION_IDS.artifacts.shopAndroid),
    ).toBeVisible()
    expect(mock.store.artifacts).toHaveLength(4)
  })

  test('shows an empty state when the app has no artifacts', async ({
    page,
  }) => {
    await mockDistributionApi(page, { seed: { artifacts: [] } })

    await page.goto(ARTIFACTS_PATH)

    await expect(page.getByText('No artifacts yet')).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Upload artifact' }),
    ).toBeEnabled()
  })

  test('returns to the last page when its only artifact is deleted', async ({
    page,
  }) => {
    const fixtures = artifactsFixtures()
    const invalid = fixtures.find(
      (artifact) => artifact.$id === DISTRIBUTION_IDS.artifacts.invalid,
    )!
    const extra = Array.from({ length: 7 }, (_, index) => ({
      ...invalid,
      $id: `artifact${String(100 + index).padStart(15, '0')}`,
      name: `Extra-${index}.ipa`,
    }))
    await mockDistributionApi(page, {
      seed: { artifacts: [...fixtures, ...extra] },
    })
    const rows = page.locator('tbody tr[data-testid^="artifact-"]')

    await page.goto(ARTIFACTS_PATH)
    await expect(rows).toHaveCount(10)
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await expect(rows).toHaveCount(1)
    const last = (await rows.getAttribute('data-testid'))!.slice(
      'artifact-'.length,
    )

    await openRowMenu(page, last)
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()

    await expect(rows).toHaveCount(10)
    await expect(row(page, last)).toBeHidden()
    await expect(page.getByText('No artifacts yet')).toBeHidden()
  })

  test('keeps writes disabled for a read-only role', async ({ page }) => {
    await mockDistributionApi(page, { role: 'readonly' })

    await page.goto(ARTIFACTS_PATH)

    const upload = page.getByRole('button', { name: 'Upload artifact' })
    await expect(upload).toBeDisabled()
    await upload.locator('..').hover()
    await expect(page.getByRole('tooltip')).toContainText(
      "You don't have permission to perform this action.",
    )

    await openRowMenu(page, DISTRIBUTION_IDS.artifacts.shopIos)
    await expect(page.getByRole('menuitem', { name: 'Download' })).toBeEnabled()
    const remove = page.getByRole('menuitem', { name: 'Delete' })
    await expect(remove).toBeDisabled()
    await expect(remove).toHaveAttribute(
      'title',
      "You don't have permission to perform this action.",
    )
  })
})
