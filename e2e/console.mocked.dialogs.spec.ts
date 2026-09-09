import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import { expect, test } from './fixtures'

const NOW = '2026-09-09T00:00:00.000Z'

const ACCOUNT: Models.User<Models.Preferences> = {
  $id: 'identityuser00000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Test User',
  email: 'user@example.com',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
}

const IDENTITY: Models.Identity = {
  $id: 'githubidentity000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  userId: ACCOUNT.$id,
  provider: 'github',
  providerUid: 'octocat',
  providerEmail: 'octocat@example.com',
  providerAccessToken: '',
  providerAccessTokenExpiry: '',
  providerRefreshToken: '',
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

/** Intercepts all API traffic for a signed-in user with one GitHub identity. */
async function mockAccountApi(
  page: Page,
  options: { pauseDelete?: Promise<void>; failDelete?: boolean } = {},
) {
  await page.addInitScript(() => {
    window.localStorage.setItem(
      'debug:consoleProfile',
      JSON.stringify({
        id: 'cloud',
        features: {
          userVerification: true,
          accountIdentities: true,
          accountMfa: true,
        },
      }),
    )
  })
  let identities = [IDENTITY]
  const deleted: string[] = []

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

    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    if (apiPath === '/account') return json(200, ACCOUNT)
    if (apiPath === '/account/identities') {
      return json(200, { total: identities.length, identities })
    }
    const identityId = apiPath.match(/^\/account\/identities\/([^/]+)$/)?.[1]
    if (identityId && request.method() === 'DELETE') {
      await options.pauseDelete
      if (options.failDelete) {
        return json(500, {
          code: 500,
          type: 'general_unknown',
          message: 'Identity deletion failed',
        })
      }
      deleted.push(identityId)
      identities = identities.filter((identity) => identity.$id !== identityId)
      return route.fulfill({ status: 204, headers })
    }
    if (apiPath === '/account/mfa/factors') {
      return json(200, {
        totp: false,
        email: false,
        phone: false,
        recoveryCode: false,
      })
    }
    if (apiPath === '/account/prefs') return json(200, {})
    if (apiPath === '/account/sessions') {
      return json(200, { total: 0, sessions: [] })
    }
    if (apiPath === '/organizations' || apiPath === '/teams') {
      return json(200, { total: 0, teams: [] })
    }
    return json(404, {
      code: 404,
      type: 'general_route_not_found',
      message: 'general_route_not_found',
      version: '1.0',
    })
  })

  return { deleted }
}

test.describe('Account identities (mocked API)', () => {
  test('deleting an identity confirms in an app dialog, never a native one', async ({
    page,
  }) => {
    const nativeDialogs: string[] = []
    page.on('dialog', (dialog) => {
      nativeDialogs.push(`${dialog.type()}: ${dialog.message()}`)
      void dialog.dismiss()
    })
    const { deleted } = await mockAccountApi(page)

    await page.goto('/account/security')
    const row = page.getByRole('row').filter({ hasText: 'GitHub' })
    await expect(row).toContainText('octocat@example.com')
    await row.getByRole('button', { name: 'Delete identity' }).click()

    const dialog = page.getByRole('dialog', { name: 'Delete identity' })
    await expect(dialog).toBeVisible()
    await expect(dialog).toContainText(
      'Are you sure you want to delete this identity?',
    )
    await expect(dialog).toContainText('GitHub')
    await expect(dialog).toContainText('octocat@example.com')

    await dialog.getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog).toBeHidden()
    await expect(
      row.getByRole('button', { name: 'Delete identity' }),
    ).toBeFocused()
    expect(deleted).toEqual([])

    await row.getByRole('button', { name: 'Delete identity' }).click()
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText('Identity has been deleted')).toBeVisible()
    expect(deleted).toEqual([IDENTITY.$id])
    await expect(
      page.getByText('No identities are currently available.'),
    ).toBeVisible()
    expect(nativeDialogs).toEqual([])
  })

  test('cannot dismiss an identity deletion while the request is pending', async ({
    page,
  }) => {
    let finishDelete!: () => void
    const pauseDelete = new Promise<void>((resolve) => {
      finishDelete = resolve
    })
    const { deleted } = await mockAccountApi(page, { pauseDelete })

    await page.goto('/account/security')
    await page.getByRole('button', { name: 'Delete identity' }).click()
    const dialog = page.getByRole('dialog', { name: 'Delete identity' })
    const deleteStarted = page.waitForRequest(
      (request) =>
        request.method() === 'DELETE' &&
        request.url().includes(`/account/identities/${IDENTITY.$id}`),
    )
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
    await deleteStarted

    try {
      await expect(
        dialog.getByRole('button', { name: 'Cancel' }),
      ).toBeDisabled()
      await expect(
        dialog.getByRole('button', { name: 'Delete', exact: true }),
      ).toBeDisabled()
      await page.keyboard.press('Escape')
      await expect(dialog).toBeVisible()
      await dialog.getByRole('button', { name: 'Close', exact: true }).click()
      await expect(dialog).toBeVisible()
      await page.mouse.click(2, 2)
      await expect(dialog).toBeVisible()
      expect(deleted).toEqual([])
    } finally {
      finishDelete()
    }

    await expect(dialog).toBeHidden()
    expect(deleted).toEqual([IDENTITY.$id])
  })

  test('a failed deletion keeps the identity and makes the dialog dismissible', async ({
    page,
  }) => {
    const { deleted } = await mockAccountApi(page, { failDelete: true })
    await page.goto('/account/security')
    await page.getByRole('button', { name: 'Delete identity' }).click()
    const dialog = page.getByRole('dialog', { name: 'Delete identity' })
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(
      page.getByText('Identity deletion failed', { exact: true }),
    ).toBeVisible()
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeEnabled()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(
      page.getByRole('row').filter({ hasText: 'GitHub' }),
    ).toBeVisible()
    expect(deleted).toEqual([])
  })
})

test.describe('Debug dialogs (mocked API)', () => {
  test.beforeEach(async ({ page }) => {
    await mockAccountApi(page)
    await page.addInitScript(() => {
      localStorage.setItem('debug:modeOpen', 'true')
      localStorage.setItem('debug:language', 'he')
    })
    await page.goto('/account/security')
    await page.getByRole('button', { name: 'Debug menu', exact: true }).click()
  })

  test('confirmation stays English and LTR and traps then restores keyboard focus', async ({
    page,
  }) => {
    await page.getByRole('option', { name: /^Terminal\b/ }).click()
    const trigger = page.getByRole('button', {
      name: 'Clear terminal cache',
      exact: true,
    })
    await expect(trigger).toBeEnabled()
    await trigger.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog', { name: 'Clear terminal cache' })
    await expect(dialog).toHaveAttribute('dir', 'ltr')
    await expect(dialog).toHaveAttribute('lang', 'en')
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(
      dialog.getByRole('button', { name: 'Clear cache', exact: true }),
    ).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(trigger).toBeFocused()
    await expect(
      page.getByText('Browser CLI cache', { exact: true }),
    ).toBeVisible()
  })

  test('prompt stays English and LTR and restores focus after cancellation', async ({
    page,
  }) => {
    await page.getByRole('option', { name: /^Server endpoint\b/ }).click()
    const trigger = page.getByRole('option', { name: /^Add custom\.\.\./ })
    await trigger.hover()
    await trigger.focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog', { name: 'Custom API endpoint' })
    await expect(dialog).toHaveAttribute('dir', 'ltr')
    await expect(dialog).toHaveAttribute('lang', 'en')
    const input = dialog.getByRole('textbox', { name: 'API endpoint URL' })
    await expect(input).toBeFocused()
    const defaultValue = await input.inputValue()
    await input.fill('https://cancelled.example/v1')
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(trigger).toBeFocused()

    await page.keyboard.press('Enter')
    await expect(input).toHaveValue(defaultValue)
    await dialog.getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog).toBeHidden()
    await expect(trigger).toBeFocused()
  })
})
