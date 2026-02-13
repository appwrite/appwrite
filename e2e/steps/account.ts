import { test, type Page } from '@playwright/test'

export function signInStep(
  page: Page,
  email: string,
  password: string,
): Promise<void> {
  return test.step('sign in', async () => {
    await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })
    await page.waitForURL(/\/sign-in/)
    await page.getByLabel('Email').waitFor({ state: 'visible', timeout: 30000 })
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill(password)
    const sessionPromise = page.waitForResponse(
      (response) =>
        response.url().includes('/sessions') &&
        response.request().method() === 'POST',
    )
    await page.getByRole('button', { name: 'Login', exact: true }).click()
    const response = await sessionPromise
    if (!response.ok()) {
      console.log('Login failed:', response.status(), await response.text())
    }

    await page.waitForURL(/\/(onboarding|organizations\/[^/]+)/, {
      timeout: 15000,
    })
  })
}
