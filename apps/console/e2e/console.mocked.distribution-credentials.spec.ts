import type { Page, Route } from '@playwright/test'
import type { DistributionConnection } from '@/lib/appwrite/distribution'
import {
  connectionsFixtures,
  distributionPath,
  expect,
  mockDistributionApi,
  screenshot,
  signingDownloadFixture,
  signingFixtures,
  test,
} from './fixtures/distribution'

const PRIVATE_KEY =
  '-----BEGIN PRIVATE KEY-----\nMIGTAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBHkwdwIBAQQg\n-----END PRIVATE KEY-----'

const SERVICE_ACCOUNT_SENT = {
  project_id: 'acme-release',
  client_email: 'release@acme-release.iam.gserviceaccount.com',
  private_key: PRIVATE_KEY,
}

const SERVICE_ACCOUNT = {
  type: 'service_account',
  private_key_id: 'a1b2c3d4e5f6a7b8c9d0',
  client_id: '104857600000000000000',
  token_uri: 'https://oauth2.googleapis.com/token',
  ...SERVICE_ACCOUNT_SENT,
}

const CERTIFICATE = Buffer.from([0x30, 0x82, 0x0a, 0x01, 0xff, 0x00, 0x7f])
const PROFILE = Buffer.from('<?xml version="1.0"?><plist>profile</plist>')
const KEYSTORE = Buffer.from([0xfe, 0xed, 0xfe, 0xed, 0x00, 0x02])

function rowActions(page: Page, name: string) {
  return page.getByRole('button', { name: `Actions for ${name}` })
}

async function openCreateMenu(page: Page, option: string) {
  await page.getByTestId('credential-create-menu').first().click()
  await page.getByRole('menuitem', { name: option }).click()
  await expect(page.getByRole('menu')).toBeHidden()
}

async function failWith(
  route: Route,
  status: number,
  type: string,
  message: string,
) {
  await route.fulfill({
    status,
    headers: {
      'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
      'access-control-allow-credentials': 'true',
      'content-type': 'application/json',
    },
    body: JSON.stringify({ message, code: status, type, version: '2.0.0' }),
  })
}

async function capture(page: Page, name: string) {
  await page.waitForFunction(() =>
    document
      .getAnimations()
      .filter(
        (animation) =>
          animation.effect?.getTiming().iterations !== Number.POSITIVE_INFINITY,
      )
      .every((animation) => animation.playState !== 'running'),
  )
  await screenshot(page, name)
}

test.describe('Distribution connections', () => {
  test('lists connections and explains a connection still in use', async ({
    page,
  }) => {
    const { store } = await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))

    const apple = page.getByTestId('connection-connection0000000000001')
    await expect(apple).toContainText('Acme App Store Connect')
    await expect(apple).toContainText('App Store Connect')
    await expect(apple).toContainText('Acme Inc. (A1B2C3D4E5)')
    await expect(apple).toContainText('2X9R4HXF34')
    const invalid = page.getByTestId('connection-connection0000000000003')
    await expect(invalid).toContainText('Invalid')
    await expect(invalid).toContainText('no access to the Google Play Console')
    await capture(page, 'connections-list.png')

    await rowActions(page, 'Acme App Store Connect').click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()
    await expect(
      page.getByText('An app target still uses this connection.', {
        exact: false,
      }),
    ).toBeVisible()
    expect(store.connections).toHaveLength(3)

    await rowActions(page, 'Legacy Play account').click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Delete' })
      .click()
    await expect(invalid).toBeHidden()
    expect(store.connections.map((connection) => connection.name)).toEqual([
      'Acme App Store Connect',
      'Acme Google Play',
    ])
  })

  test('connects App Store Connect with a team key', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))
    await openCreateMenu(page, 'App Store Connect')

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByText('The Account Holder must first request API access.'),
    ).toBeVisible()
    await expect(
      dialog.getByRole('link', { name: 'Open App Store Connect' }),
    ).toHaveAttribute(
      'href',
      'https://appstoreconnect.apple.com/access/integrations/api',
    )
    await expect(
      dialog.getByText('Give the key the App Manager role.'),
    ).toBeVisible()
    await expect(
      dialog.getByText('It never asks for your Apple ID or password.', {
        exact: false,
      }),
    ).toBeVisible()
    await capture(page, 'apple-wizard-key.png')

    await dialog.getByRole('button', { name: 'Next' }).click()
    await capture(page, 'apple-wizard-details.png')

    await dialog.getByLabel('Issuer ID').fill('not-an-issuer')
    await dialog.getByLabel('Key ID').fill('abc')
    await dialog.getByRole('button', { name: 'Connect' }).click()
    await expect(
      dialog.getByText('An issuer ID looks like', { exact: false }),
    ).toBeVisible()
    await expect(
      dialog.getByText('A key ID is 10 uppercase letters', { exact: false }),
    ).toBeVisible()
    await expect(dialog.getByText('Add the .p8 private key.')).toBeVisible()
    await capture(page, 'apple-wizard-validation.png')
    expect(
      requests.filter((request) => request.method === 'POST'),
    ).toHaveLength(0)

    await dialog
      .getByLabel('Issuer ID')
      .fill('57246542-96fe-1a63-e053-0824d011072a')
    await dialog.getByLabel('Key ID').fill('7RQ9P2LK8M')
    await dialog.getByRole('tab', { name: 'Paste contents' }).click()
    await dialog.locator('#apple-connection-private-key').fill(PRIVATE_KEY)
    await dialog.getByRole('button', { name: 'Connect' }).click()

    await expect(dialog).toBeHidden()
    await expect(page.getByText('App Store Connect connected')).toBeVisible()
    const created = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/connections/apple',
    )
    expect(created?.body).toMatchObject({
      name: 'App Store Connect',
      issuerId: '57246542-96fe-1a63-e053-0824d011072a',
      keyId: '7RQ9P2LK8M',
      privateKey: PRIVATE_KEY,
    })
    expect(Object.keys(created?.body ?? {}).sort()).toEqual([
      'connectionId',
      'issuerId',
      'keyId',
      'name',
      'privateKey',
    ])
    await expect(page.getByText('7RQ9P2LK8M')).toBeVisible()
  })

  test('connects Google Play with a service account key file', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))
    await openCreateMenu(page, 'Google Play')

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByText('Enable the Google Play Android Developer API.'),
    ).toBeVisible()
    await capture(page, 'google-wizard-account.png')

    await dialog.getByRole('button', { name: 'Next' }).click()
    await expect(
      dialog.getByText('Invite the service account email.'),
    ).toBeVisible()
    await capture(page, 'google-wizard-invite.png')

    await dialog.getByRole('button', { name: 'Next' }).click()
    await dialog.getByRole('tab', { name: 'Paste contents' }).click()
    await dialog
      .locator('#google-connection-key')
      .fill('{"type": "service_account"}')
    await dialog.getByRole('button', { name: 'Connect' }).click()
    await expect(
      dialog.getByText('The key needs client_email and private_key.', {
        exact: false,
      }),
    ).toBeVisible()
    await capture(page, 'google-wizard-validation.png')

    await dialog.getByRole('tab', { name: 'Upload file' }).click()
    await dialog.locator('#google-connection-key-file').setInputFiles({
      name: 'acme-release-key.json',
      mimeType: 'application/json',
      buffer: Buffer.from(JSON.stringify(SERVICE_ACCOUNT)),
    })
    await expect(dialog.getByText('acme-release-key.json')).toBeVisible()
    await expect(
      dialog.getByText(`Service account: ${SERVICE_ACCOUNT.client_email}`),
    ).toBeVisible()
    await capture(page, 'google-wizard-key.png')
    await dialog.getByRole('button', { name: 'Connect' }).click()

    await expect(dialog).toBeHidden()
    const created = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/connections/google',
    )
    expect(created?.body).toEqual({
      connectionId: expect.any(String),
      name: 'Google Play',
      serviceAccount: SERVICE_ACCOUNT_SENT,
    })
    await expect(page.getByText(SERVICE_ACCOUNT.client_email)).toBeVisible()
  })
  test('replaces the key of an invalid Google Play connection', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))
    await rowActions(page, 'Legacy Play account').click()
    await page.getByRole('menuitem', { name: 'Update' }).click()
    await expect(page.getByRole('menu')).toBeHidden()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('Last check failed:')).toBeVisible()
    await dialog.getByLabel('Name').fill('Play publisher')
    await dialog.locator('#update-connection-credentials-file').setInputFiles({
      name: 'publisher.json',
      mimeType: 'application/json',
      buffer: Buffer.from(JSON.stringify(SERVICE_ACCOUNT)),
    })
    await capture(page, 'connection-update.png')
    await dialog.getByRole('button', { name: 'Update' }).click()

    await expect(dialog).toBeHidden()
    const updated = requests.find((request) => request.method === 'PATCH')
    expect(updated?.path).toBe(
      '/distribution/connections/google/connection0000000000003',
    )
    expect(updated?.body).toEqual({
      name: 'Play publisher',
      serviceAccount: SERVICE_ACCOUNT_SENT,
    })
    await expect(
      page.getByTestId('connection-connection0000000000003'),
    ).toContainText('Active')
  })
})

test.describe('Distribution code signing', () => {
  test('lists signing identities with their expiry state', async ({ page }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath('/signing'))

    const expiring = page.getByTestId('signing-signing000000000000002')
    await expect(expiring).toContainText('Managed by Appwrite')
    await expect(expiring).toContainText('Expiring')
    await expect(expiring).toContainText('com.acme.fieldnotes')
    const expired = page.getByTestId('signing-signing000000000000004')
    await expect(expired).toContainText('Expired')
    await expect(expired).toContainText('Imported')
    const imported = page.getByTestId('signing-signing000000000000001')
    await expect(imported).toContainText('com.acme.shop')
    await expect(imported).toContainText('com.acme.rewards')
    await capture(page, 'signing-list.png')
  })

  test('imports an iOS certificate with its profiles', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(distributionPath('/signing'))
    await openCreateMenu(page, 'Import iOS certificate')

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Import' }).click()
    await expect(dialog.getByText('Add the .p12 certificate.')).toBeVisible()
    await expect(
      dialog.getByText('Add at least one App Store provisioning profile.'),
    ).toBeVisible()

    await dialog.getByLabel('Name').fill('Acme Distribution 2027')
    await dialog.locator('#ios-signing-certificate-file').setInputFiles({
      name: 'distribution.p12',
      mimeType: 'application/x-pkcs12',
      buffer: CERTIFICATE,
    })
    await dialog.getByLabel('Certificate password').fill('p12-secret')
    await dialog.locator('#ios-signing-profiles-file').setInputFiles([
      {
        name: 'Acme_Shop.mobileprovision',
        mimeType: 'application/octet-stream',
        buffer: PROFILE,
      },
      {
        name: 'Acme_Shop_Widget.mobileprovision',
        mimeType: 'application/octet-stream',
        buffer: PROFILE,
      },
    ])
    await expect(
      dialog.getByText('Acme_Shop_Widget.mobileprovision'),
    ).toBeVisible()
    await capture(page, 'signing-import-ios.png')
    await dialog.getByRole('button', { name: 'Import' }).click()

    await expect(dialog).toBeHidden()
    const created = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/signing-identities/ios',
    )
    expect(created?.body).toMatchObject({
      name: 'Acme Distribution 2027',
      certificate: CERTIFICATE.toString('base64'),
      password: 'p12-secret',
      profiles: [PROFILE.toString('base64'), PROFILE.toString('base64')],
    })
    await expect(page.getByText('Acme Distribution 2027')).toBeVisible()
  })

  test('imports an Android keystore', async ({ page }) => {
    const { requests } = await mockDistributionApi(page)

    await page.goto(distributionPath('/signing'))
    await openCreateMenu(page, 'Import Android keystore')

    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Name').fill('Play upload key')
    await dialog.locator('#android-signing-keystore-file').setInputFiles({
      name: 'upload.jks',
      mimeType: 'application/octet-stream',
      buffer: KEYSTORE,
    })
    await dialog.getByLabel('Keystore password').fill('store-secret')
    await dialog.getByLabel('Key alias').fill('upload')
    await capture(page, 'signing-import-android.png')
    await dialog.getByRole('button', { name: 'Import' }).click()

    await expect(dialog).toBeHidden()
    const created = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/signing-identities/android',
    )
    expect(created?.body).toMatchObject({
      name: 'Play upload key',
      keystore: KEYSTORE.toString('base64'),
      storePassword: 'store-secret',
      keyAlias: 'upload',
      keyPassword: 'store-secret',
    })
  })

  test('generates managed identities for Android and iOS', async ({ page }) => {
    const { requests, store } = await mockDistributionApi(page)

    await page.goto(distributionPath('/signing'))
    await openCreateMenu(page, 'Generate with Appwrite')

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByText('RSA 2048 upload key', { exact: false }),
    ).toBeVisible()
    await capture(page, 'signing-generate-android.png')
    await dialog.getByRole('button', { name: 'Generate' }).click()
    await expect(dialog).toBeHidden()
    const android = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/signing-identities/android',
    )
    expect(android?.body).toMatchObject({ name: 'Upload key' })
    expect(android?.body).not.toHaveProperty('keystore')

    await openCreateMenu(page, 'Generate with Appwrite')
    await dialog.getByRole('radio', { name: 'iOS' }).click()
    await expect(
      dialog.getByLabel('App Store Connect connection'),
    ).toContainText('Acme App Store Connect')
    await dialog.getByRole('button', { name: 'Generate' }).click()
    await expect(dialog.getByText('Add at least one bundle ID.')).toBeVisible()
    await dialog.getByLabel('Bundle IDs').fill('com.acme.shop')
    await dialog.getByLabel('Bundle IDs').press('Enter')
    await dialog.getByLabel('Bundle IDs').fill('com.acme.shop.widget')
    await dialog.getByLabel('Bundle IDs').press('Enter')
    await capture(page, 'signing-generate-ios.png')
    await dialog.getByRole('button', { name: 'Generate' }).click()

    await expect(dialog).toBeHidden()
    const ios = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/signing-identities/ios',
    )
    expect(ios?.body).toMatchObject({
      name: 'Managed by Appwrite',
      connectionId: store.connections[0].$id,
      identifiers: ['com.acme.shop', 'com.acme.shop.widget'],
    })
    expect(ios?.body).not.toHaveProperty('certificate')
  })

  test('downloads signing files only after an explicit request', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)
    const downloads = () =>
      requests.filter((request) => request.path.endsWith('/download'))

    await page.goto(distributionPath('/signing'))
    await rowActions(page, 'Upload key').click()
    await page.getByRole('menuitem', { name: 'Download' }).click()
    await expect(page.getByRole('menu')).toBeHidden()

    const dialog = page.getByRole('dialog')
    await expect(
      dialog.getByText('Every download is recorded in the audit log.'),
    ).toBeVisible()
    expect(downloads()).toHaveLength(0)

    await dialog.getByRole('button', { name: 'Show signing files' }).click()
    await expect(dialog.getByText('keystore.p12')).toBeVisible()
    expect(downloads()).toHaveLength(1)
    await expect(
      dialog.getByRole('definition').filter({ hasText: 'upload' }),
    ).toBeVisible()
    await expect(dialog.getByText('Key alias')).toBeVisible()
    await expect(dialog.getByText('Keystore password')).toBeVisible()
    await expect(dialog.getByText('Key password')).toBeVisible()
    await expect(dialog.getByText('upload-certificate.pem')).toHaveCount(0)
    await expect(
      dialog.getByText('Request upload key reset', { exact: false }),
    ).toHaveCount(0)
    await expect(
      dialog.getByRole('button', { name: 'Save all files' }),
    ).toHaveCount(0)
    await capture(page, 'signing-download.png')

    const download = page.waitForEvent('download')
    await dialog.getByRole('button', { name: 'Save keystore.p12' }).click()
    expect((await download).suggestedFilename()).toBe('keystore.p12')

    await dialog.getByRole('button', { name: 'Close' }).first().click()
    await expect(dialog).toBeHidden()
    await rowActions(page, 'Upload key').click()
    await page.getByRole('menuitem', { name: 'Download' }).click()
    await expect(page.getByRole('menu')).toBeHidden()
    await expect(
      page.getByRole('dialog').getByText('keystore-password-0001'),
    ).toHaveCount(0)
    expect(downloads()).toHaveLength(1)
  })

  test('explains the upload key reset when the certificate is included', async ({
    page,
  }) => {
    const uploadKey = signingFixtures().find(
      (identity) => identity.name === 'Upload key',
    )!
    await mockDistributionApi(page, {
      seed: {
        downloads: {
          [uploadKey.$id]: signingDownloadFixture(uploadKey, {
            uploadCertificate: true,
          }),
        },
      },
    })

    await page.goto(distributionPath('/signing'))
    await rowActions(page, 'Upload key').click()
    await page.getByRole('menuitem', { name: 'Download' }).click()
    await expect(page.getByRole('menu')).toBeHidden()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Show signing files' }).click()
    await expect(
      dialog.getByText('upload-certificate.pem', { exact: true }),
    ).toBeVisible()
    await expect(
      dialog.getByText('Request upload key reset', { exact: false }),
    ).toBeVisible()
    await capture(page, 'signing-download-upload-certificate.png')

    const download = page.waitForEvent('download')
    await dialog
      .getByRole('button', { name: 'Save upload-certificate.pem' })
      .click()
    expect((await download).suggestedFilename()).toBe('upload-certificate.pem')
  })

  test('shows the certificate password of an iOS identity without an alias', async ({
    page,
  }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath('/signing'))
    await rowActions(page, 'Acme Distribution 2026').click()
    await page.getByRole('menuitem', { name: 'Download' }).click()
    await expect(page.getByRole('menu')).toBeHidden()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Show signing files' }).click()
    await expect(dialog.getByText('certificate.p12')).toBeVisible()
    await expect(
      dialog.getByText('com.acme.shop.mobileprovision'),
    ).toBeVisible()
    await expect(dialog.getByText('Certificate password')).toBeVisible()
    await expect(dialog.getByText('Key alias')).toHaveCount(0)
  })

  test('reports a signing file that cannot be decoded', async ({ page }) => {
    const uploadKey = signingFixtures().find(
      (identity) => identity.name === 'Upload key',
    )!
    await mockDistributionApi(page, {
      seed: {
        downloads: {
          [uploadKey.$id]: {
            files: [{ name: 'keystore.p12', content: '%%%' }],
            passwords: {},
            alias: '',
          },
        },
      },
    })

    await page.goto(distributionPath('/signing'))
    await rowActions(page, 'Upload key').click()
    await page.getByRole('menuitem', { name: 'Download' }).click()
    await expect(page.getByRole('menu')).toBeHidden()

    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Show signing files' }).click()
    await dialog.getByRole('button', { name: 'Save keystore.p12' }).click()
    await expect(page.getByText('The file could not be saved.')).toBeVisible()
  })
})

test.describe('Distribution credentials for a read-only member', () => {
  test('keeps every write control disabled', async ({ page }) => {
    const { requests } = await mockDistributionApi(page, { role: 'readonly' })

    await page.goto(distributionPath('/connections'))
    await expect(
      page.getByTestId('connection-connection0000000000001'),
    ).toBeVisible()
    await expect(page.getByTestId('credential-create-menu')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    await rowActions(page, 'Acme App Store Connect').click()
    await expect(page.getByRole('menuitem', { name: 'Update' })).toBeDisabled()
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toBeDisabled()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('menu')).toBeHidden()
    await capture(page, 'readonly-connections.png')

    await page.getByRole('tab', { name: 'Code signing' }).click()
    await expect(
      page.getByTestId('signing-signing000000000000001'),
    ).toBeVisible()
    await expect(page.getByTestId('credential-create-menu')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    await rowActions(page, 'Upload key').click()
    await expect(
      page.getByRole('menuitem', { name: 'Download' }),
    ).toBeDisabled()
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toBeDisabled()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('menu')).toBeHidden()
    await page.getByTestId('credential-create-menu').locator('..').hover()
    await expect(page.getByRole('tooltip')).toContainText(
      "You don't have permission to manage signing identities.",
    )
    await capture(page, 'readonly-signing.png')

    expect(requests.filter((request) => request.method !== 'GET')).toHaveLength(
      0,
    )
  })
})

test.describe('Distribution credentials in Hebrew', () => {
  test('renders the connections list and Apple wizard right to left', async ({
    page,
  }) => {
    await mockDistributionApi(page, { language: 'he' })

    await page.goto(distributionPath('/connections'))
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect(page.getByText('בדיקה אחרונה')).toBeVisible()
    await expect(
      page.getByText('2X9R4HXF34', { exact: true }).first(),
    ).toHaveAttribute('dir', 'ltr')
    await capture(page, 'he-connections-list.png')

    await page.getByTestId('credential-create-menu').click()
    await page.getByRole('menuitem', { name: 'App Store Connect' }).click()
    await expect(page.getByRole('menu')).toBeHidden()
    await expect(
      page.getByText('בעל החשבון (Account Holder) צריך קודם לבקש גישה ל-API.'),
    ).toBeVisible()
    await capture(page, 'he-apple-wizard-key.png')

    await page.getByRole('button', { name: 'הבא' }).click()
    await expect(page.locator('#apple-connection-key')).toHaveAttribute(
      'dir',
      'ltr',
    )
    await expect(page.locator('#apple-connection-issuer')).toHaveAttribute(
      'dir',
      'ltr',
    )
    await capture(page, 'he-apple-wizard-details.png')
  })

  test('keeps fingerprints left to right in the signing list', async ({
    page,
  }) => {
    await mockDistributionApi(page, { language: 'he' })
    const [signingIdentity] = signingFixtures()

    await page.goto(distributionPath('/signing'))
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    const fingerprint = page
      .getByTestId(`signing-${signingIdentity.$id}`)
      .getByText('SHA-256', { exact: false })
    await expect(fingerprint).toHaveAttribute('dir', 'ltr')
    await capture(page, 'he-signing-list.png')
  })
})

test.describe('Distribution credentials when the server refuses', () => {
  test('keeps the generate dialog usable after a server error', async ({
    page,
  }) => {
    const { requests } = await mockDistributionApi(page)
    const refusal = 'Param "keystore" is not optional.'
    const generateAndroid = (url: URL) =>
      url.pathname === '/v1/distribution/signing-identities/android'
    await page.route(generateAndroid, async (route) => {
      if (route.request().method() !== 'POST') return route.fallback()
      await failWith(route, 400, 'general_argument_invalid', refusal)
    })

    await page.goto(distributionPath('/signing'))
    await openCreateMenu(page, 'Generate with Appwrite')
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Generate' }).click()

    await expect(dialog.getByRole('alert')).toHaveText(refusal)
    await expect(dialog).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Generate' })).toBeEnabled()
    await expect(dialog.getByLabel('Name')).toBeEditable()
    await capture(page, 'signing-generate-refused.png')

    await page.unroute(generateAndroid)
    await dialog.getByRole('button', { name: 'Generate' }).click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText('Signing identity generated')).toBeVisible()
    expect(
      requests.filter(
        (request) =>
          request.method === 'POST' &&
          request.path === '/distribution/signing-identities/android',
      ),
    ).toHaveLength(1)
  })

  test('shows an error instead of the first-run state when a page fails', async ({
    page,
  }) => {
    const [connection] = connectionsFixtures()
    const [signingIdentity] = signingFixtures()
    const copies = <T extends { $id: string; name: string }>(item: T) =>
      Array.from({ length: 12 }, (_, index) => ({
        ...item,
        $id: `${item.$id.slice(0, -2)}${String(index).padStart(2, '0')}`,
        name: `${item.name} ${index + 1}`,
      }))
    await mockDistributionApi(page, {
      seed: {
        connections: copies(connection),
        signingIdentities: copies(signingIdentity),
      },
    })
    const lists = (url: URL) =>
      url.pathname === '/v1/distribution/connections' ||
      url.pathname === '/v1/distribution/signing-identities'
    const refuseLists = () =>
      page.route(lists, async (route) => {
        if (route.request().method() !== 'GET') return route.fallback()
        await failWith(
          route,
          500,
          'general_unknown',
          'The distribution service is unavailable.',
        )
      })

    await page.goto(distributionPath('/connections'))
    await expect(
      page.getByText(`${connection.name} 1`, { exact: true }),
    ).toBeVisible()
    await refuseLists()
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await expect(page.getByText('Failed to load connections')).toBeVisible()
    await expect(page.getByText('Connect a store account')).toBeHidden()
    await capture(page, 'connections-load-failed.png')

    await page.unroute(lists)
    await page.getByRole('button', { name: 'Try again' }).click()
    await expect(
      page.getByText(`${connection.name} 11`, { exact: true }),
    ).toBeVisible()

    await page.getByRole('tab', { name: 'Code signing' }).click()
    await expect(
      page.getByText(`${signingIdentity.name} 1`, { exact: true }),
    ).toBeVisible()
    await refuseLists()
    await page.getByRole('button', { name: 'Go to next page' }).click()
    await expect(
      page.getByText('Failed to load signing identities'),
    ).toBeVisible()
    await expect(page.getByText('No signing identities')).toBeHidden()
  })
})

test.describe('Distribution credentials copy', () => {
  test('names the credential in its delete confirmation', async ({ page }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))
    await rowActions(page, 'Legacy Play account').click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await expect(page.getByRole('dialog')).toContainText(
      'Delete Legacy Play account? Appwrite stops using its credentials immediately.',
    )
    await expect(
      page.getByRole('dialog').locator('strong', {
        hasText: 'Legacy Play account',
      }),
    ).toBeVisible()
    await page.keyboard.press('Escape')

    await page.getByRole('tab', { name: 'Code signing' }).click()
    await rowActions(page, 'Upload key').click()
    await page.getByRole('menuitem', { name: 'Delete' }).click()
    await expect(page.getByRole('dialog')).toContainText(
      'Delete Upload key? Appwrite deletes its encrypted files.',
    )
  })

  test('names the expected file type when a key file is wrong', async ({
    page,
  }) => {
    await mockDistributionApi(page)

    await page.goto(distributionPath('/connections'))
    await openCreateMenu(page, 'Google Play')
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('button', { name: 'Next' }).click()
    await dialog.getByRole('button', { name: 'Next' }).click()
    await dialog.locator('#google-connection-key-file').setInputFiles({
      name: 'service-account.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('{}'),
    })

    await expect(
      dialog.getByText('Choose a file of type .json.', { exact: true }),
    ).toBeVisible()
  })

  test('offers every App Store Connect connection when generating', async ({
    page,
  }) => {
    const apple = connectionsFixtures()[0]
    const teams: DistributionConnection[] = Array.from(
      { length: 120 },
      (_, index) => ({
        ...apple,
        $id: `connection${String(index).padStart(16, '0')}`,
        name: `Team ${index + 1}`,
      }),
    )
    const { requests } = await mockDistributionApi(page, {
      seed: { connections: teams },
    })

    await page.goto(distributionPath('/signing'))
    await openCreateMenu(page, 'Generate with Appwrite')
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('radio', { name: 'iOS' }).click()
    await expect(dialog.getByRole('radio', { name: 'iOS' })).toBeChecked()
    await dialog.getByLabel('App Store Connect connection').click()
    await page.getByRole('option', { name: 'Team 120', exact: true }).click()
    await dialog.getByLabel('Bundle IDs').fill('com.acme.shop')
    await dialog.getByLabel('Bundle IDs').press('Enter')
    await dialog.getByRole('button', { name: 'Generate' }).click()

    await expect(dialog).toBeHidden()
    const ios = requests.find(
      (request) =>
        request.method === 'POST' &&
        request.path === '/distribution/signing-identities/ios',
    )
    expect(ios?.body).toMatchObject({ connectionId: teams[119].$id })
  })
})
