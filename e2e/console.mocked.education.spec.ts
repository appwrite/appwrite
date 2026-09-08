import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

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
  await page.addInitScript((profile) => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: profile,
        features: { userVerification: profile === 'cloud' },
      }),
    )
  }, scenario.profile ?? 'cloud')
  const membershipRequests: string[] = []
  const identityRequests: string[] = []
  const oauthRequests: string[] = []
  const deletions: string[] = []
  const verificationUrls: string[] = []
  const preferenceWrites: Models.Preferences[] = []
  let created = false

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
    if (url.origin === localOrigin) return route.continue()
    const apiPath = url.pathname.match(/\/v1(\/.*)$/)?.[1]
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
    if (request.method() === 'DELETE') deletions.push(apiPath)

    if (apiPath === '/account/sessions/oauth2/github') {
      oauthRequests.push(apiPath)
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
      identityRequests.push(apiPath)
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
      membershipRequests.push(apiPath)
      if (scenario.membershipStatus) {
        return failure(
          scenario.membershipStatus,
          scenario.membershipStatus === 409
            ? 'team_already_exists'
            : 'user_unauthorized',
        )
      }
      created = true
      return json(200, ORGANIZATION)
    }
    if (apiPath === '/account/prefs') {
      if (request.method() === 'PATCH') {
        const body = request.postDataJSON() as { prefs: Models.Preferences }
        preferenceWrites.push(body.prefs)
        if (scenario.failPrefs) return failure(500, 'general_server_error')
        if (scenario.account) scenario.account.prefs = body.prefs
        return json(200, scenario.account)
      }
      return json(200, scenario.account?.prefs ?? {})
    }
    if (apiPath === '/account/verifications/email') {
      if (request.method() === 'POST') {
        verificationUrls.push((request.postDataJSON() as { url: string }).url)
      } else if (request.method() === 'PUT' && scenario.account) {
        scenario.account.emailVerification = true
      }
      return json(200, {})
    }
    if (apiPath === '/organizations' || apiPath === '/teams') {
      const teams =
        created || scenario.membershipStatus === 409 ? [ORGANIZATION] : []
      return json(200, { total: teams.length, teams })
    }
    if (apiPath === `/teams/${ORGANIZATION.$id}`) return json(200, ORGANIZATION)
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
    membershipRequests,
    identityRequests,
    oauthRequests,
    deletions,
    verificationUrls,
    preferenceWrites,
  }
}

test.describe('Education enrollment (mocked API)', () => {
  test('self-hosted guests return to sign-in without starting enrollment', async ({
    page,
  }) => {
    const api = await mockEducationApi(page, {
      account: null,
      profile: 'self-hosted',
    })
    await page.goto('/education/join')
    await expect(page).toHaveURL(/\/sign-in(?:\?|$)/)
    await expect(
      page.getByRole('heading', { name: 'Welcome back', exact: true }),
    ).toBeVisible()
    expect(api.membershipRequests).toEqual([])
    expect(api.identityRequests).toEqual([])
    expect(api.oauthRequests).toEqual([])
  })

  test('guest starts GitHub OAuth with student scopes and enrollment return URLs', async ({
    page,
  }) => {
    const api = await mockEducationApi(page, { account: null })
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
    expect(api.membershipRequests).toEqual([])
  })

  for (const failPrefs of [false, true]) {
    test(`linked student enrolls and opens the Education organization${failPrefs ? ' when saving preferences fails' : ''}`, async ({
      page,
    }) => {
      const api = await mockEducationApi(page, {
        account: student(),
        linked: true,
        failPrefs,
      })
      await page.goto('/education/join')
      await expect(page).toHaveURL(
        new RegExp(`/organizations/${ORGANIZATION.$id}/?(?:\\?|$)`),
      )
      expect(api.membershipRequests).toEqual([PROGRAM_PATH])
      expect(api.identityRequests).toEqual(['/account/identities'])
      expect(
        api.preferenceWrites.some(
          (prefs) =>
            (prefs as Record<string, unknown>).organization ===
            ORGANIZATION.$id,
        ),
      ).toBe(true)
      expect(api.deletions).toEqual([])
      await expect(
        page.getByText('We could not set up your Education plan', {
          exact: true,
        }),
      ).toHaveCount(0)
    })
  }

  for (const code of [403, 409] as const) {
    test(`${code} preserves the account and provides an exit`, async ({
      page,
    }) => {
      const api = await mockEducationApi(page, {
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
      expect(api.membershipRequests).toEqual([PROGRAM_PATH])
      expect(api.deletions).toEqual([])
      expect(api.preferenceWrites).toEqual([])
      if (code === 409) {
        await expect(
          page.getByText(
            'Open your organizations to find your Education plan.',
            { exact: true },
          ),
        ).toBeVisible()
      }
      await expect(
        page.getByRole('button', { name: 'Continue to Appwrite', exact: true }),
      ).toBeEnabled()
    })
  }

  test('a failed identity lookup can be retried before enrolling exactly once', async ({
    page,
  }) => {
    const scenario: Scenario = {
      account: student(),
      linked: true,
      identityUnavailable: true,
    }
    const api = await mockEducationApi(page, scenario)
    await page.goto('/education/join')
    await expect(
      page.getByRole('heading', {
        name: 'We could not check your GitHub connection',
        exact: true,
      }),
    ).toBeVisible()
    expect(api.membershipRequests).toEqual([])
    const identityReadsBeforeRetry = api.identityRequests.length
    scenario.identityUnavailable = false
    await page.getByRole('button', { name: 'Try again', exact: true }).click()
    await expect(page).toHaveURL(
      new RegExp(`/organizations/${ORGANIZATION.$id}/?(?:\\?|$)`),
    )
    expect(api.identityRequests).toHaveLength(identityReadsBeforeRetry + 1)
    expect(api.membershipRequests).toEqual([PROGRAM_PATH])
    expect(api.deletions).toEqual([])
  })

  test('an already verified account resumes Education from the verification page', async ({
    page,
  }) => {
    const api = await mockEducationApi(page, {
      account: student(),
      linked: true,
    })
    await page.goto('/verify-email?redirect=%2Feducation%2Fjoin')
    await expect(page).toHaveURL(
      new RegExp(`/organizations/${ORGANIZATION.$id}/?(?:\\?|$)`),
    )
    expect(api.membershipRequests).toEqual([PROGRAM_PATH])
    expect(api.deletions).toEqual([])
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
    expect(api.membershipRequests).toEqual([])

    await page
      .getByRole('button', { name: 'Resend verification email', exact: true })
      .click()
    await expect.poll(() => api.verificationUrls.length).toBe(1)
    const verification = new URL(api.verificationUrls[0]!)
    expect(verification.searchParams.get('redirect')).toBe('/education/join')
    verification.searchParams.set('userId', account.$id)
    verification.searchParams.set('secret', 'mock-education-verification')
    await page.goto(verification.toString())
    await expect(page).toHaveURL(
      new RegExp(`/organizations/${ORGANIZATION.$id}/?(?:\\?|$)`),
    )
    expect(api.membershipRequests).toEqual([PROGRAM_PATH])
    expect(api.deletions).toEqual([])
  })
})
