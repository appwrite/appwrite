import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import { env } from '../config/env'
import { acceptCookieBannerIfPresent } from './cookie-banner'

export type ConsoleTargets = {
  orgId: string
  projectId: string | null
}

/**
 * Resolve an org (and optionally a project) for read-only console smoke tests.
 * Prefers E2E_ORG_ID / E2E_PROJECT_ID, otherwise discovers from post-login redirects
 * and the projects list API response.
 */
export async function discoverConsoleTargets(
  page: Page,
): Promise<ConsoleTargets> {
  if (env.E2E_ORG_ID) {
    return {
      orgId: env.E2E_ORG_ID,
      projectId: env.E2E_PROJECT_ID ?? null,
    }
  }

  let projectId: string | null = env.E2E_PROJECT_ID ?? null

  const onResponse = async (response: import('@playwright/test').Response) => {
    if (projectId) return
    if (response.request().method() !== 'GET') return
    if (!response.ok()) return
    if (!/\/v1\/projects(?:\?|$)/.test(response.url())) return

    try {
      const body = (await response.json()) as {
        projects?: Array<{ $id?: string }>
      }
      const first = body.projects?.find((p) => typeof p.$id === 'string')?.$id
      if (first) projectId = first
    } catch {
      // Ignore non-JSON or partial responses.
    }
  }

  page.on('response', onResponse)

  try {
    await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 })
    await acceptCookieBannerIfPresent(page)
    await page.waitForURL(/\/organizations\/[^/?#]+/, { timeout: 45_000 })

    const match = new URL(page.url()).pathname.match(
      /^\/organizations\/([^/?#]+)/,
    )
    const orgId = match?.[1]
    if (!orgId) {
      throw new Error(
        `Could not discover org id from URL after login: ${page.url()}`,
      )
    }

    // Org overview loads projects; wait briefly if we still need a project id.
    if (!projectId) {
      try {
        await expect.poll(() => projectId, { timeout: 20_000 }).toBeTruthy()
      } catch {
        // Account may have zero projects; console service tests will skip.
      }
    }

    return { orgId, projectId }
  } finally {
    page.off('response', onResponse)
  }
}
