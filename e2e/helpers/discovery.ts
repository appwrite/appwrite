import type { Page, Response } from '@playwright/test'
import { expect } from '@playwright/test'
import { env } from '../config/env'
import { acceptCookieBannerIfPresent } from './cookie-banner'

export type ConsoleTargets = {
  orgId: string
  projectId: string | null
}

type ListedProject = { $id: string; name: string }
type ListedSite = { $id: string }

function isProjectsListUrl(url: string): boolean {
  try {
    const pathname = new URL(url).pathname.replace(/\/+$/, '')
    return (
      pathname.endsWith('/projects') &&
      !pathname.includes('/projects/') &&
      (pathname.includes('/organization') || pathname.endsWith('/v1/projects'))
    )
  } catch {
    return false
  }
}

function readListedProjects(body: unknown): ListedProject[] {
  if (!body || typeof body !== 'object') return []
  const record = body as { projects?: unknown; data?: unknown }
  const rows = Array.isArray(record.projects)
    ? record.projects
    : Array.isArray(record.data)
      ? record.data
      : []
  return rows.flatMap((row) => {
    if (!row || typeof row !== 'object') return []
    const project = row as { $id?: unknown; name?: unknown }
    if (typeof project.$id !== 'string' || !project.$id) return []
    return [
      {
        $id: project.$id,
        name: typeof project.name === 'string' ? project.name : '',
      },
    ]
  })
}

function pickLiveProjectId(
  listed: ListedProject[],
  preferredId: string | null,
): string | null {
  if (preferredId && listed.some((project) => project.$id === preferredId)) {
    return preferredId
  }
  const stable = listed.find((project) => !/^e2e[-_]/i.test(project.name))
  return stable?.$id ?? listed[0]?.$id ?? null
}

async function collectOrgProjects(
  page: Page,
  orgId: string,
): Promise<ListedProject[]> {
  const listed: ListedProject[] = []

  const onResponse = async (response: Response) => {
    if (response.request().method() !== 'GET') return
    if (!response.ok()) return
    if (!isProjectsListUrl(response.url())) return
    try {
      const next = readListedProjects(await response.json())
      if (next.length > 0) {
        listed.splice(0, listed.length, ...next)
      }
    } catch {
      // Ignore non-JSON or already-consumed bodies.
    }
  }

  page.on('response', onResponse)
  try {
    await page.goto(`/organizations/${orgId}`, {
      waitUntil: 'domcontentloaded',
      timeout: 45_000,
    })
    await acceptCookieBannerIfPresent(page)
    await expect(page).toHaveURL(new RegExp(`/organizations/${orgId}`), {
      timeout: 45_000,
    })
    await expect
      .poll(() => listed.length, { timeout: 20_000 })
      .toBeGreaterThan(0)
      .catch(() => undefined)
  } finally {
    page.off('response', onResponse)
  }

  return listed
}

function isSitesListUrl(url: string): boolean {
  try {
    return new URL(url).pathname.replace(/\/+$/, '').endsWith('/sites')
  } catch {
    return false
  }
}

/** Discover sites from the real list API response used by the Sites page. */
export async function discoverSiteIds(
  page: Page,
  projectId: string,
): Promise<string[]> {
  const listed: ListedSite[] = []
  const onResponse = async (response: Response) => {
    if (response.request().method() !== 'GET' || !response.ok()) return
    if (!isSitesListUrl(response.url())) return
    try {
      const body = (await response.json()) as { sites?: unknown }
      if (!Array.isArray(body.sites)) return
      for (const row of body.sites) {
        if (!row || typeof row !== 'object') continue
        const id = (row as { $id?: unknown }).$id
        if (typeof id === 'string' && id) listed.push({ $id: id })
      }
    } catch {
      // Ignore non-JSON or already-consumed bodies.
    }
  }

  page.on('response', onResponse)
  try {
    await page.goto(`/projects/${projectId}/sites`, {
      waitUntil: 'domcontentloaded',
    })
    await expect
      .poll(() => listed.length, { timeout: 20_000 })
      .toBeGreaterThan(0)
      .catch(() => undefined)
  } finally {
    page.off('response', onResponse)
  }
  return [...new Set(listed.map((site) => site.$id))]
}

export async function discoverFirstSiteId(
  page: Page,
  projectId: string,
): Promise<string | null> {
  return (await discoverSiteIds(page, projectId))[0] ?? null
}

/**
 * Resolve an org (and optionally a project) for read-only console smoke tests.
 * Prefers E2E_ORG_ID / E2E_PROJECT_ID, then discovers a live project from the
 * org list. Disposable `e2e-*` database-suite projects are skipped when a
 * stable project exists.
 */
export async function discoverConsoleTargets(
  page: Page,
): Promise<ConsoleTargets> {
  let orgId = env.E2E_ORG_ID ?? ''

  if (!orgId) {
    await page.goto('/', { waitUntil: 'domcontentloaded', timeout: 45_000 })
    await acceptCookieBannerIfPresent(page)
    await page.waitForURL(/\/organizations\/[^/?#]+/, { timeout: 45_000 })
    const match = new URL(page.url()).pathname.match(
      /^\/organizations\/([^/?#]+)/,
    )
    orgId = match?.[1] ?? ''
    if (!orgId) {
      throw new Error(
        `Could not discover org id from URL after login: ${page.url()}`,
      )
    }
  }

  const listed = await collectOrgProjects(page, orgId)
  const projectId = pickLiveProjectId(listed, env.E2E_PROJECT_ID ?? null)
  return { orgId, projectId }
}
