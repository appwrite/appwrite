import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

/**
 * Resource descriptions: what the settings cards actually send.
 *
 * `description` is not in the pinned `@appwrite.io/console`, so the console
 * sends it through `withDescription` (`src/lib/appwrite/resource-descriptions.ts`),
 * which merges the param into the payload the generated service builds. Three
 * things can silently break and none of them raise:
 *
 * 1. The param is dropped before the request leaves the client, so the save
 *    reports success and persists nothing.
 * 2. The field is sent as `null` instead of `''`. On the API an omitted or
 *    null `description` *keeps* the saved value, so clearing the box would
 *    look like it worked and change nothing.
 * 3. More than 1024 chars reach the request and the API rejects the save.
 *
 * The API is mocked at the network layer so the real page runs, and every
 * assertion is on the request body the page sends plus what the form shows
 * after the response comes back.
 */

const NOW = '2026-10-15T09:00:00.000+00:00'

/** `Text(1024, 0)` on every `description` param. */
const DESCRIPTION_MAX_LENGTH = 1024

const ALL_SERVICES = [
  'account',
  'databases',
  'tablesdb',
  'locale',
  'health',
  'project',
  'storage',
  'teams',
  'users',
  'sites',
  'functions',
  'migrations',
  'messaging',
] as const

const OWNER_ROLES = ['owner'] satisfies Models.Roles['roles']
const OWNER_SCOPES = [
  'projects.read',
  'projects.write',
  'functions.read',
  'functions.write',
  'buckets.read',
  'buckets.write',
  'files.read',
  'files.write',
] satisfies Models.Roles['scopes']

const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Operator',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'demo@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  teamId: ORGANIZATION.$id,
  region: 'default',
  status: 'active',
  services: ALL_SERVICES.map(($id) => ({
    $id,
    enabled: true,
  })) as Models.Project['services'],
} satisfies Partial<Models.Project>

/**
 * A function that already carries a description, so the specs can prove the
 * saved value loads into the form instead of starting empty.
 */
const FUNCTION_SEED = {
  $id: 'fn00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Welcome mailer',
  description: 'Sends a welcome email to newly registered users.',
  execute: [],
  enabled: true,
  live: true,
  logging: true,
  runtime: 'node-22',
  deploymentRetention: 10,
  deploymentId: '',
  deploymentCreatedAt: '',
  latestDeploymentId: '',
  latestDeploymentCreatedAt: '',
  latestDeploymentStatus: '',
  scopes: [],
  vars: [],
  events: [],
  schedule: '',
  timeout: 15,
  entrypoint: 'src/main.js',
  commands: 'npm install',
  version: 'v5',
  installationId: '',
  providerRepositoryId: '',
  providerBranch: '',
  providerRootDirectory: '',
  providerSilentMode: false,
  buildSpecification: 's-0.5vcpu-512mb',
  runtimeSpecification: 's-0.5vcpu-512mb',
}

const BUCKET_SEED = {
  $id: 'bkt00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  $permissions: [],
  name: 'Invoices',
  description: 'Invoices and receipts uploaded by customers.',
  fileSecurity: false,
  enabled: true,
  maximumFileSize: 30_000_000,
  allowedFileExtensions: [],
  compression: 'none',
  encryption: true,
  antivirus: true,
  transformations: true,
  totalSize: 0,
}

const SPECIFICATIONS = {
  total: 1,
  specifications: [
    {
      memory: 512,
      cpus: 0.5,
      enabled: true,
      slug: 's-0.5vcpu-512mb',
    },
  ],
}

const RUNTIMES = {
  total: 1,
  runtimes: [
    {
      $id: 'node-22',
      key: 'node-22',
      name: 'Node.js',
      version: '22.0',
      base: 'node:22',
      image: 'openruntimes/node:22',
      logo: 'node.png',
      supports: [],
    },
  ],
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

/** The last write the page sent for a resource: where it went and what it carried. */
type Write = {
  path: string
  body: Record<string, unknown>
}

type Captured = {
  function?: Write
  bucket?: Write
}

/**
 * Intercepts all API traffic for an owner with one function and one bucket.
 * Writes are recorded and merged into the seeded resource, so a reload serves
 * what the page actually saved rather than a canned response.
 */
async function mockAppwriteApi(page: Page, captured: Captured) {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const fn: Record<string, unknown> = { ...FUNCTION_SEED }
  const bucket: Record<string, unknown> = { ...BUCKET_SEED }

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()

    const headers = corsHeaders(route)
    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })

    // --- account / shell ---
    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json(200, { ...ACCOUNT, prefs: request.postDataJSON().prefs })
        : json(200, {})
    }
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    if (apiPath === '/health/version') return json(200, { version: '2.0.0' })
    if (apiPath === '/locale')
      return json(200, {
        ip: '203.0.113.10',
        countryCode: 'DE',
        country: 'Germany',
        continentCode: 'EU',
        continent: 'Europe',
        eu: true,
        currency: 'EUR',
      })
    if (apiPath === '/console/variables') return json(200, {})
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    )
      return json(200, { roles: OWNER_ROLES, scopes: OWNER_SCOPES })
    if (apiPath === '/organizations' || apiPath === '/teams')
      return json(200, { total: 1, teams: [ORGANIZATION] })
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    )
      return json(200, ORGANIZATION)
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath === '/projects' || apiPath === '/organization/projects')
      return json(200, { total: 1, projects: [PROJECT] })
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT.$id}` ||
      apiPath === `/projects/${PROJECT.$id}/console-access`
    )
      return json(200, PROJECT)
    if (apiPath === '/project/variables')
      return json(200, { total: 0, variables: [] })
    if (apiPath === '/account/consents')
      return json(200, { total: 0, consents: [] })
    if (apiPath === '/console/plans') return json(200, { plans: [] })
    if (apiPath === `/organizations/${ORGANIZATION.$id}/plan`)
      return json(200, { $id: 'tier-0', name: 'Free' })
    if (apiPath === `/projects/${PROJECT.$id}/stages`)
      return json(200, { total: 0, stages: [] })
    if (apiPath === '/vcs/installations')
      return json(200, { total: 0, installations: [] })
    if (apiPath === '/avatars/photo')
      return route.fulfill({ status: 204, headers })

    // --- functions ---
    if (apiPath === '/functions/runtimes') return json(200, RUNTIMES)
    if (apiPath === '/functions/specifications')
      return json(200, SPECIFICATIONS)
    if (apiPath === '/functions')
      return json(200, { total: 1, functions: [fn] })
    if (apiPath === `/functions/${FUNCTION_SEED.$id}`) {
      if (request.method() === 'PUT') {
        const body = request.postDataJSON() as Record<string, unknown>
        captured.function = { path: apiPath, body }
        // The API keeps the saved value when `description` is null or absent,
        // and clears it on ''. Mirror that, or spec 2 cannot tell them apart.
        if (typeof body.description === 'string') {
          fn.description = body.description
        }
        if (typeof body.name === 'string') fn.name = body.name
        return json(200, fn)
      }
      return json(200, fn)
    }
    if (apiPath === `/functions/${FUNCTION_SEED.$id}/variables`)
      return json(200, { total: 0, variables: [] })
    if (apiPath === `/functions/${FUNCTION_SEED.$id}/deployments`)
      return json(200, { total: 0, deployments: [] })
    if (apiPath === `/functions/${FUNCTION_SEED.$id}/executions`)
      return json(200, { total: 0, executions: [] })

    // --- storage ---
    if (apiPath === '/storage/buckets')
      return json(200, { total: 1, buckets: [bucket] })
    if (apiPath === `/storage/buckets/${BUCKET_SEED.$id}`) {
      if (request.method() === 'PUT') {
        const body = request.postDataJSON() as Record<string, unknown>
        captured.bucket = { path: apiPath, body }
        if (typeof body.description === 'string') {
          bucket.description = body.description
        }
        if (typeof body.name === 'string') bucket.name = body.name
        return json(200, bucket)
      }
      return json(200, bucket)
    }
    if (apiPath === `/storage/buckets/${BUCKET_SEED.$id}/files`)
      return json(200, { total: 0, files: [] })

    return json(404, {
      message: 'Not found',
      code: 404,
      type: 'general_route_not_found',
      version: '1.0',
    })
  })
}

function newCaptured(): Captured {
  return {}
}

const functionSettingsUrl = `/projects/${PROJECT.$id}/functions/${FUNCTION_SEED.$id}/settings`
const bucketSettingsUrl = `/projects/${PROJECT.$id}/storage/${BUCKET_SEED.$id}/settings`

/**
 * The description box plus the Update button of the name-and-description card.
 * Settings pages carry several Update buttons, so the button is scoped to the
 * card that holds this field rather than picked off the page.
 */
function detailsCard(page: Page, fieldId: string) {
  const description = page.locator(`#${fieldId}`)
  const card = page
    .locator('div.rounded-xl')
    .filter({ has: description })
    .first()
  return {
    description,
    update: card.getByRole('button', { name: 'Update', exact: true }),
  }
}

test.describe('function description (mocked API)', () => {
  test('loads the saved description and sends an edited one', async ({
    page,
  }) => {
    const captured = newCaptured()
    await mockAppwriteApi(page, captured)
    await page.goto(functionSettingsUrl, { waitUntil: 'domcontentloaded' })

    const card = detailsCard(page, 'function-description')

    // 1. The saved value loads into the form, it does not start empty.
    await expect(card.description).toHaveValue(FUNCTION_SEED.description, {
      timeout: 30_000,
    })
    // Nothing is dirty yet, so the card cannot be saved.
    await expect(card.update).toBeDisabled()

    // 2. Editing only the description makes the card savable.
    await card.description.fill('Sends the welcome email and seeds prefs.')
    await expect(card.update).toBeEnabled()
    await card.update.click()

    await expect
      .poll(() => captured.function?.body.description, { timeout: 30_000 })
      .toBe('Sends the welcome email and seeds prefs.')
    // The generated method still owns the route and every other param; the id
    // is a path param, so it belongs in the URL and not in the body.
    expect(captured.function?.path).toBe(`/functions/${FUNCTION_SEED.$id}`)
    expect(captured.function?.body).toMatchObject({
      name: FUNCTION_SEED.name,
      entrypoint: FUNCTION_SEED.entrypoint,
      timeout: FUNCTION_SEED.timeout,
    })

    // 3. The response is what the form settles on.
    await expect(page.getByText('Function updated successfully')).toBeVisible()
    await expect(card.update).toBeDisabled()
  })

  test("clearing the box sends '' and really clears the description", async ({
    page,
  }) => {
    const captured = newCaptured()
    await mockAppwriteApi(page, captured)
    await page.goto(functionSettingsUrl, { waitUntil: 'domcontentloaded' })

    const card = detailsCard(page, 'function-description')
    await expect(card.description).toHaveValue(FUNCTION_SEED.description, {
      timeout: 30_000,
    })

    await card.description.fill('')
    await expect(card.update).toBeEnabled()
    await card.update.click()

    // An omitted or null `description` keeps the saved value on the API, so
    // '' is the only thing that clears it. `null` here would be the bug.
    await expect
      .poll(() => captured.function?.body, { timeout: 30_000 })
      .toHaveProperty('description', '')
    expect(captured.function?.body.description).not.toBeNull()

    // Reload against the mock's updated state: it really is gone.
    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(card.description).toHaveValue('', { timeout: 30_000 })
  })

  test('clamps the description to 1024 characters before sending', async ({
    page,
  }) => {
    const captured = newCaptured()
    await mockAppwriteApi(page, captured)
    await page.goto(functionSettingsUrl, { waitUntil: 'domcontentloaded' })

    const card = detailsCard(page, 'function-description')
    await expect(card.description).toHaveValue(FUNCTION_SEED.description, {
      timeout: 30_000,
    })

    const tooLong = 'a'.repeat(DESCRIPTION_MAX_LENGTH + 500)
    await card.description.fill(tooLong)

    // Clamped in the field, not just on the way out, so the count the user
    // reads matches what gets saved.
    await expect(card.description).toHaveValue('a'.repeat(1024))
    await expect(
      page.getByText(`${DESCRIPTION_MAX_LENGTH}/${DESCRIPTION_MAX_LENGTH}`),
    ).toBeVisible()

    await card.update.click()
    await expect
      .poll(
        () =>
          (captured.function?.body.description as string | undefined)?.length,
        { timeout: 30_000 },
      )
      .toBe(DESCRIPTION_MAX_LENGTH)
  })
})

test.describe('bucket description (mocked API)', () => {
  test('loads the saved description and sends an edited one', async ({
    page,
  }) => {
    const captured = newCaptured()
    await mockAppwriteApi(page, captured)
    await page.goto(bucketSettingsUrl, { waitUntil: 'domcontentloaded' })

    const card = detailsCard(page, 'bucket-description')
    await expect(card.description).toHaveValue(BUCKET_SEED.description, {
      timeout: 30_000,
    })
    await expect(card.update).toBeDisabled()

    await card.description.fill('Customer invoices, kept for seven years.')
    await expect(card.update).toBeEnabled()
    await card.update.click()

    await expect
      .poll(() => captured.bucket?.body.description, { timeout: 30_000 })
      .toBe('Customer invoices, kept for seven years.')
    // The other bucket settings ride along unchanged, as the SDK method built
    // them: a description save must not reset encryption or the size limit.
    expect(captured.bucket?.path).toBe(`/storage/buckets/${BUCKET_SEED.$id}`)
    expect(captured.bucket?.body).toMatchObject({
      name: BUCKET_SEED.name,
      encryption: BUCKET_SEED.encryption,
      maximumFileSize: BUCKET_SEED.maximumFileSize,
    })
    await expect(page.getByText('Bucket has been updated')).toBeVisible()
  })

  test("clearing the box sends '' and really clears the description", async ({
    page,
  }) => {
    const captured = newCaptured()
    await mockAppwriteApi(page, captured)
    await page.goto(bucketSettingsUrl, { waitUntil: 'domcontentloaded' })

    const card = detailsCard(page, 'bucket-description')
    await expect(card.description).toHaveValue(BUCKET_SEED.description, {
      timeout: 30_000,
    })

    await card.description.fill('')
    await expect(card.update).toBeEnabled()
    await card.update.click()

    await expect
      .poll(() => captured.bucket?.body, { timeout: 30_000 })
      .toHaveProperty('description', '')
    expect(captured.bucket?.body.description).not.toBeNull()

    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(card.description).toHaveValue('', { timeout: 30_000 })
  })
})
