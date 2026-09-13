import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'
import { CONSOLE_SESSION_COOKIE_NAME } from '../src/lib/console-session-cookie'

const NOW = '2026-09-08T00:00:00.000Z'
const PROGRAM_PATH = '/console/programs/github-student-developer/memberships'
const ORGANIZATION = {
  $id: 'education00000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'GitHub Student Organization',
  total: 1,
  prefs: {},
  billingPlan: 'auto-1',
  billingPlanId: 'auto-1',
  status: 'active',
} satisfies Pick<
  Models.Organization<Models.Preferences>,
  | '$id'
  | '$createdAt'
  | '$updatedAt'
  | 'name'
  | 'total'
  | 'prefs'
  | 'billingPlan'
  | 'billingPlanId'
  | 'status'
>

const PERSONAL_ORGANIZATION = {
  ...ORGANIZATION,
  $id: 'personal000000000001',
  name: 'Student projects',
  billingPlan: 'tier-0',
  billingPlanId: 'tier-0',
}

function student(verified = true): Models.User<Models.Preferences> {
  return {
    $id: 'student0000000000001',
    $createdAt: NOW,
    $updatedAt: NOW,
    name: 'Test Student',
    email: 'student@example.com',
    registration: NOW,
    status: true,
    labels: [],
    passwordUpdate: NOW,
    phone: '',
    emailVerification: verified,
    phoneVerification: false,
    mfa: false,
    prefs: {},
    targets: [],
    accessedAt: NOW,
  }
}

type Scenario = {
  account: Models.User<Models.Preferences> | null
  profile?: 'cloud' | 'self-hosted'
  linked?: boolean
  membershipStatus?: 403 | 409
  failPrefs?: boolean
  pausePrefs?: Promise<void>
  pauseOrganizations?: Promise<void>
  identityUnavailable?: boolean
}

function corsHeaders(route: Route) {
  return {
    'access-control-allow-origin': route.request().headers().origin ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
    'access-control-allow-headers':
      route.request().headers()['access-control-request-headers'] ?? '*',
  }
}

/** All external traffic is intercepted, including OAuth and the mailing list. */
async function mockEducationApi(page: Page, scenario: Scenario) {
  if (scenario.account) {
    // The localhost root route checks for a session hint before account.get.
    // Model signed-in browser state as well as the mocked API response, including
    // on reload. This cookie is synthetic and scoped to the local test server.
    await page.context().addCookies([
      {
        name: CONSOLE_SESSION_COOKIE_NAME,
        value: 'mock-education-session',
        url: String(test.info().project.use.baseURL),
      },
    ])
  }
  await page.addInitScript((profile) => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: profile,
        features: { userVerification: profile === 'cloud' },
      }),
    )
  }, scenario.profile ?? 'cloud')
  let startPreferences!: () => void
  const preferencesStarted = new Promise<void>((resolve) => {
    startPreferences = resolve
  })
  let startOrganizations!: () => void
  const organizationsStarted = new Promise<void>((resolve) => {
    startOrganizations = resolve
  })
  let sendVerificationEmail!: (url: string) => void
  const verificationEmail = new Promise<string>((resolve) => {
    sendVerificationEmail = resolve
  })
  let created = false
  const existingOrganization =
    scenario.membershipStatus === 403
      ? PERSONAL_ORGANIZATION
      : scenario.membershipStatus === 409
        ? ORGANIZATION
        : null

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
    const apiPath = url.pathname.match(/^\/v1(\/.*)$/)?.[1]
    if (url.origin === localOrigin && !apiPath) return route.continue()
    const headers = corsHeaders(route)
    const json = (status: number, body: unknown) =>
      route.fulfill({
        status,
        headers: { ...headers, 'content-type': 'application/json' },
        body: JSON.stringify(body),
      })
    const failure = (code: number, type: string) =>
      json(code, {
        code,
        type,
        message: type,
        version: '1.0',
      })

    if (!apiPath) {
      return route.fulfill({ status: 204, headers })
    }
    if (request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    if (
      apiPath.startsWith('/account/sessions') &&
      request.method() === 'DELETE'
    ) {
      scenario.account = null
      return route.fulfill({ status: 204, headers })
    }

    if (apiPath === '/account/sessions/oauth2/github') {
      // Stop at Appwrite's OAuth entry point instead of contacting GitHub.
      return route.fulfill({
        status: 200,
        body: 'Mock GitHub OAuth entry point',
      })
    }
    if (apiPath === '/account') {
      return scenario.account
        ? json(200, scenario.account)
        : failure(401, 'general_unauthorized_scope')
    }
    if (apiPath === '/account/identities') {
      if (scenario.identityUnavailable) {
        return failure(500, 'general_server_error')
      }
      const identities: Models.Identity[] =
        scenario.linked && scenario.account
          ? [
              {
                $id: 'githubidentity000001',
                $createdAt: NOW,
                $updatedAt: NOW,
                userId: scenario.account.$id,
                provider: 'github',
                providerUid: 'student-github',
                providerEmail: scenario.account.email,
                providerAccessToken: '',
                providerAccessTokenExpiry: '',
                providerRefreshToken: '',
              },
            ]
          : []
      return json(200, { total: identities.length, identities })
    }
    if (apiPath === PROGRAM_PATH && request.method() === 'POST') {
      if (scenario.membershipStatus) {
        return failure(
          scenario.membershipStatus,
          scenario.membershipStatus === 409
            ? 'team_already_exists'
            : 'user_unauthorized',
        )
      }
      if (created) return failure(409, 'team_already_exists')
      created = true
      return json(200, ORGANIZATION)
    }
    if (apiPath === '/account/prefs') {
      if (request.method() === 'PATCH') {
        const body = request.postDataJSON() as { prefs: Models.Preferences }
        startPreferences()
        if (scenario.pausePrefs) await scenario.pausePrefs
        if (scenario.failPrefs) return failure(500, 'general_server_error')
        if (scenario.account) scenario.account.prefs = body.prefs
        return json(200, scenario.account)
      }
      return json(200, scenario.account?.prefs ?? {})
    }
    if (apiPath === '/account/verifications/email') {
      if (request.method() === 'POST') {
        sendVerificationEmail((request.postDataJSON() as { url: string }).url)
      } else if (request.method() === 'PUT' && scenario.account) {
        scenario.account.emailVerification = true
      }
      return json(200, {})
    }
    if (apiPath === '/organizations' || apiPath === '/teams') {
      startOrganizations()
      if (scenario.pauseOrganizations) await scenario.pauseOrganizations
      const teams = created
        ? [ORGANIZATION]
        : existingOrganization
          ? [existingOrganization]
          : []
      return json(200, { total: teams.length, teams })
    }
    if (apiPath === `/teams/${ORGANIZATION.$id}`) return json(200, ORGANIZATION)
    if (apiPath === `/teams/${PERSONAL_ORGANIZATION.$id}`)
      return json(200, PERSONAL_ORGANIZATION)
    if (apiPath.endsWith('/memberships'))
      return json(200, { total: 0, memberships: [] })
    if (apiPath.endsWith('/projects'))
      return json(200, { total: 0, projects: [] })
    if (apiPath.endsWith('/scopes'))
      return json(200, { roles: ['owner'], scopes: [] })
    if (apiPath.endsWith('/invoices'))
      return json(200, { total: 0, invoices: [] })
    if (apiPath === '/account/sessions')
      return json(200, { total: 0, sessions: [] })
    return failure(404, 'general_route_not_found')
  })

  return {
    preferencesStarted,
    organizationsStarted,
    verificationEmail,
  }
}

async function expectOrganization(page: Page, organization = ORGANIZATION) {
  await expect(page).toHaveURL(
    new RegExp(`/organizations/${organization.$id}/?(?:\\?|$)`),
  )
  await expect(
    page.getByRole('heading', { name: organization.name, exact: true }),
  ).toBeVisible()
  await expect(page.getByRole('button', { name: /Test Student/ })).toBeVisible()
}

test.describe('Education enrollment (mocked API)', () => {
  test('self-hosted guests return to sign-in without starting enrollment', async ({
    page,
  }) => {
    await mockEducationApi(page, {
      account: null,
      profile: 'self-hosted',
    })
    await page.goto('/education/join')
    await expect(page).toHaveURL(/\/sign-in(?:\?|$)/)
    await expect(
      page.getByRole('heading', { name: 'Welcome back', exact: true }),
    ).toBeVisible()
  })

  test('guest starts GitHub OAuth with student scopes and enrollment return URLs', async ({
    page,
  }) => {
    await mockEducationApi(page, { account: null })
    await page.goto('/education/join')
    await page
      .getByRole('button', { name: 'Sign up with GitHub', exact: true })
      .click()
    await expect(page).toHaveURL(/\/account\/sessions\/oauth2\/github\?/)

    const oauth = new URL(page.url())
    const scopes = [...oauth.searchParams.entries()]
      .filter(([key]) => key.startsWith('scopes'))
      .map(([, value]) => value)
    expect(scopes).toEqual(['read:user', 'user:email'])
    expect(new URL(oauth.searchParams.get('success')!).pathname).toBe(
      '/education/join',
    )
    const failure = new URL(oauth.searchParams.get('failure')!)
    expect(failure.pathname).toBe('/education/join')
    expect(failure.searchParams.get('status')).toBe('failure')
  })

  for (const failPrefs of [false, true]) {
    test(`linked student enrolls and opens the Education organization${failPrefs ? ' when saving preferences fails' : ''}`, async ({
      page,
    }) => {
      await mockEducationApi(page, {
        account: student(),
        linked: true,
        failPrefs,
      })
      await page.goto('/education/join')
      await expectOrganization(page)
    })
  }

  test('opens the Education organization while saving preferences is stalled', async ({
    page,
  }) => {
    let finishPreferences!: () => void
    const pausePrefs = new Promise<void>((resolve) => {
      finishPreferences = resolve
    })
    const api = await mockEducationApi(page, {
      account: student(),
      linked: true,
      pausePrefs,
    })
    try {
      await page.goto('/education/join')
      await api.preferencesStarted
      await expectOrganization(page)
    } finally {
      finishPreferences()
    }
  })

  test('opens the Education organization while the organization list is stalled', async ({
    page,
  }) => {
    let finishOrganizations!: () => void
    const pauseOrganizations = new Promise<void>((resolve) => {
      finishOrganizations = resolve
    })
    const api = await mockEducationApi(page, {
      account: student(),
      linked: true,
      pauseOrganizations,
    })
    try {
      await page.goto('/education/join')
      await api.organizationsStarted
      await expectOrganization(page)
    } finally {
      finishOrganizations()
    }
  })

  for (const code of [403, 409] as const) {
    test(`${code} preserves the account and provides an exit`, async ({
      page,
    }) => {
      await mockEducationApi(page, {
        account: student(),
        linked: true,
        membershipStatus: code,
      })
      await page.goto('/education/join')
      const message =
        code === 403
          ? "It looks like you're not currently eligible for the GitHub Student Developer Pack."
          : "You've already joined the Education program."
      await expect(
        page.getByRole('heading', { name: message, exact: true }),
      ).toBeVisible()
      if (code === 409) {
        await expect(
          page.getByText(
            'Continue to Appwrite, then use the organization switcher to find your Education plan.',
            { exact: true },
          ),
        ).toBeVisible()
      }
      await page
        .getByRole('button', { name: 'Continue to Appwrite', exact: true })
        .click()
      await expectOrganization(
        page,
        code === 403 ? PERSONAL_ORGANIZATION : ORGANIZATION,
      )
      // A fresh account read must still resolve the same signed-in student.
      await page.reload()
      await expectOrganization(
        page,
        code === 403 ? PERSONAL_ORGANIZATION : ORGANIZATION,
      )
    })
  }

  test('a failed identity lookup can be retried to open the Education organization', async ({
    page,
  }) => {
    const scenario: Scenario = {
      account: student(),
      linked: true,
      identityUnavailable: true,
    }
    await mockEducationApi(page, scenario)
    await page.goto('/education/join')
    await expect(
      page.getByRole('heading', {
        name: 'We could not check your GitHub connection',
        exact: true,
      }),
    ).toBeVisible()
    scenario.identityUnavailable = false
    await page.getByRole('button', { name: 'Try again', exact: true }).click()
    await expectOrganization(page)
  })

  test('an already verified account resumes Education from the verification page', async ({
    page,
  }) => {
    await mockEducationApi(page, {
      account: student(),
      linked: true,
    })
    await page.goto('/verify-email?redirect=%2Feducation%2Fjoin')
    await expectOrganization(page)
  })

  test('email verification retains enrollment intent and enrolls after confirmation', async ({
    page,
  }) => {
    const account = student(false)
    const api = await mockEducationApi(page, { account, linked: true })
    await page.goto('/education/join')
    await expect(page).toHaveURL(/\/verify-email\?/)
    expect(new URL(page.url()).searchParams.get('redirect')).toBe(
      '/education/join',
    )

    await page
      .getByRole('button', { name: 'Resend verification email', exact: true })
      .click()
    const verification = new URL(await api.verificationEmail)
    verification.searchParams.set('userId', account.$id)
    verification.searchParams.set('secret', 'mock-education-verification')
    await page.goto(verification.toString())
    await expectOrganization(page)
  })

  test('returning to enrollment reopens the existing Education organization', async ({
    page,
  }) => {
    await mockEducationApi(page, { account: student(), linked: true })
    await page.goto('/education/join')
    await expectOrganization(page)
    // The backend rejects a repeated enrollment after the first creation.
    await page.goto('/education/join')
    await expect(
      page.getByRole('heading', {
        name: "You've already joined the Education program.",
        exact: true,
      }),
    ).toBeVisible()
    await page
      .getByRole('button', { name: 'Continue to Appwrite', exact: true })
      .click()
    await expectOrganization(page)
  })
})
