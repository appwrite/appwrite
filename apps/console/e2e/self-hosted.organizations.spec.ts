import type { Page } from '@playwright/test'
import { test, expect } from './fixtures'
import { env } from './config/env'
import { getConsoleFallbackCookies } from './helpers/console-api'
import { newE2ePage } from './helpers/cookie-banner'
import { E2E_VIEWPORT } from './config/viewport'

/**
 * Self-hosted profile with an account that belongs to several organizations
 * (a 1.x instance upgraded to 2.0). The profile blocks creating another
 * organization, but every existing one must stay reachable from the UI.
 * Local-only: needs a self-hosted backend. Run with
 * `bun run e2e:organizations-self-hosted`.
 */

type Team = { $id: string; name: string }
type Project = { $id: string; name: string; teamId: string }

async function consoleApi<T>(
  method: 'GET' | 'POST',
  apiPath: string,
  body?: Record<string, unknown>,
): Promise<T> {
  const cookies = getConsoleFallbackCookies()
  if (!cookies) throw new Error('Missing console session. Run e2e setup first.')
  const response = await fetch(
    `${env.VITE_APPWRITE_ENDPOINT.replace(/\/+$/, '')}${apiPath}`,
    {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Appwrite-Project': 'console',
        'X-Fallback-Cookies': cookies,
      },
      body: body ? JSON.stringify(body) : undefined,
    },
  )
  if (!response.ok) {
    throw new Error(`${method} ${apiPath} failed: ${response.status}`)
  }
  return (await response.json()) as T
}

async function ensureTwoOrganizations(): Promise<[Team, Team]> {
  const list = await consoleApi<{ teams: Team[] }>('GET', '/teams')
  const teams = [...list.teams]
  while (teams.length < 2) {
    teams.push(
      await consoleApi<Team>('POST', '/teams', {
        teamId: 'unique()',
        name: `E2E Org ${teams.length + 1}`,
      }),
    )
  }
  return [teams[0], teams[1]]
}

async function ensureProjectIn(team: Team): Promise<Project> {
  const query = new URLSearchParams({
    'queries[]': JSON.stringify({
      method: 'equal',
      attribute: 'teamId',
      values: [team.$id],
    }),
  })
  const list = await consoleApi<{ projects: Project[] }>(
    'GET',
    `/projects?${query}`,
  )
  if (list.projects[0]) return list.projects[0]
  return consoleApi<Project>('POST', '/projects', {
    projectId: 'unique()',
    name: 'E2E Multi Org Project',
    teamId: team.$id,
    region: 'default',
  })
}

function orgSwitcher(page: Page) {
  return page.locator('button[data-analytics="organization-switcher"]')
}

async function attachScreenshot(page: Page, name: string) {
  await test.info().attach(name, {
    body: await page.screenshot({ animations: 'disabled' }),
    contentType: 'image/png',
  })
}

test.describe('self-hosted account with several organizations', () => {
  let orgA: Team
  let orgB: Team
  let project: Project

  test.beforeAll(async ({ browser }) => {
    ;[orgA, orgB] = await ensureTwoOrganizations()
    project = await ensureProjectIn(orgA)
    // Warm the console once so the personal-org bootstrap settles before tests.
    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
    })
    const page = await newE2ePage(context)
    try {
      await page.goto(`/organizations/${orgA.$id}`, {
        waitUntil: 'domcontentloaded',
      })
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        orgA.name,
      )
    } finally {
      await context.close()
    }
  })

  test('organization overview exposes the switcher and moves between organizations', async ({
    page,
  }) => {
    await page.goto(`/organizations/${orgA.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(orgA.name)
    await expect(orgSwitcher(page)).toBeVisible()
    await orgSwitcher(page).click()
    await expect(page.getByText('Switch organization')).toBeVisible()
    await expect(
      page.getByRole('button', { name: orgB.name, exact: false }),
    ).toBeVisible()
    // Single-tenant profiles still block creating another organization.
    await expect(
      page.locator('[data-analytics="create-organization"]'),
    ).toHaveCount(0)
    await attachScreenshot(page, 'org-switcher-open.png')

    await page.getByRole('button', { name: orgB.name, exact: false }).click()
    await expect(page).toHaveURL(new RegExp(`/organizations/${orgB.$id}`))
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(orgB.name)
    await attachScreenshot(page, 'org-switched.png')

    await page.reload({ waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(orgB.name)
    await expect(orgSwitcher(page)).toBeVisible()
  })

  test('switcher stays hidden for an account with a single organization', async ({
    page,
  }) => {
    await page.route(/\/v1\/teams(?:\?|$)/, async (route) => {
      if (route.request().method() !== 'GET') return route.continue()
      const response = await route.fetch()
      const body = (await response.json()) as { teams: Team[]; total: number }
      const only = body.teams.filter((team) => team.$id === orgA.$id)
      await route.fulfill({
        response,
        body: JSON.stringify({ ...body, teams: only, total: only.length }),
      })
    })
    await page.goto(`/organizations/${orgA.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(orgA.name)
    await expect(orgSwitcher(page)).toHaveCount(0)
    await attachScreenshot(page, 'single-org-no-switcher.png')
  })

  test('project selector lists organizations and opens another one', async ({
    page,
  }) => {
    await page.goto(`/projects/${project.$id}`, {
      waitUntil: 'domcontentloaded',
    })
    const trigger = page
      .getByRole('button', { name: project.name, exact: false })
      .first()
    await expect(trigger).toBeVisible()
    await expect(trigger).toContainText(orgA.name)
    await trigger.click()
    await expect(page.getByPlaceholder('Find Organization...')).toBeVisible()
    await expect(page.getByText(orgB.name, { exact: true })).toBeVisible()
    await expect(
      page.locator('[data-analytics="create-organization"]'),
    ).toHaveCount(0)
    await attachScreenshot(page, 'project-selector-open.png')

    await page.getByRole('button', { name: orgB.name, exact: false }).click()
    await expect(page.getByText('No projects found')).toBeVisible()
  })

  test('project settings offers transfer to another organization', async ({
    page,
  }) => {
    await page.goto(`/projects/${project.$id}/settings`, {
      waitUntil: 'domcontentloaded',
    })
    const card = page.locator('[data-card-id="transfer-project"]')
    await card.scrollIntoViewIfNeeded()
    await expect(card).toBeVisible()
    await expect(
      card.getByRole('heading', { name: 'Transfer project' }),
    ).toBeVisible()
    await attachScreenshot(page, 'project-transfer-card.png')
  })
})
