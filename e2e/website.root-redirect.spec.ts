import { expect, test } from '@playwright/test'

// A stale cookie bypasses the server's guest redirect, leaving the root loader
// to resolve the account and choose the guest destination in the browser.
test('expired session reaches the homepage from the root', async ({
  context,
  page,
  baseURL,
}) => {
  await context.addCookies([
    { name: 'a_session_console', value: 'expired-session', url: baseURL! },
  ])
  await page.route('**/v1/account', (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 401,
        type: 'general_unauthorized_scope',
        message: 'Session expired',
      }),
    }),
  )
  await page.goto('/')
  await expect(page).toHaveURL(/\/home$/)
  await expect(page).toHaveTitle(/Home/)
})
