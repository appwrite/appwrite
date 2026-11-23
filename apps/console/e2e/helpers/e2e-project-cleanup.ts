import { env } from '../config/env'
import {
  deleteOrganizationProject,
  getConsoleFallbackCookies,
  listOrganizationProjects,
  type ConsoleApiProject,
} from './console-api'
import {
  listRegisteredE2eProjects,
  unregisterCreatedE2eProject,
} from './e2e-project-registry'

/** Name prefixes used by database e2e suites (`e2e-sqlm-<suffix>`, …). */
export const E2E_DISPOSABLE_PROJECT_NAME = /^e2e-(db|sqlm|sqlp|tdb|ddb|vdb)-/i

/** Worker fixture timeout: dedicated-DB setup + serial suite + API delete. */
export const DATABASE_SUITE_FIXTURE_TIMEOUT_MS = 90 * 60_000

/**
 * Leftovers older than this are assumed to be from crashed/cancelled runs.
 * Newer matching names are left alone so a concurrent e2e run is not deleted
 * mid-suite. Must outlive the longest lane timeout in `.github/workflows/console.yml`.
 */
export const E2E_STALE_PROJECT_MS = 4 * 60 * 60 * 1000

const DELETE_CONCURRENCY = 12

export function isDisposableE2eProjectName(name: string): boolean {
  return E2E_DISPOSABLE_PROJECT_NAME.test(name)
}

export type E2eProjectCleanupResult = {
  deleted: string[]
  failed: Array<{ projectId: string; error: string }>
}

function logCleanup(message: string): void {
  console.error(`[e2e] ${message}`)
}

function isStale(project: ConsoleApiProject, staleAfterMs: number): boolean {
  if (staleAfterMs <= 0) return true
  if (!project.$createdAt) return false
  const created = Date.parse(project.$createdAt)
  if (Number.isNaN(created)) return false
  return Date.now() - created >= staleAfterMs
}

async function deleteWithLimit(
  organizationId: string,
  projectIds: Iterable<string>,
): Promise<E2eProjectCleanupResult> {
  const ids = [...new Set(projectIds)].filter(Boolean)
  const deleted: string[] = []
  const failed: Array<{ projectId: string; error: string }> = []
  let cursor = 0

  async function worker() {
    while (cursor < ids.length) {
      const index = cursor
      cursor += 1
      const projectId = ids[index]!
      try {
        await deleteOrganizationProject(organizationId, projectId)
        unregisterCreatedE2eProject(projectId)
        deleted.push(projectId)
      } catch (error) {
        failed.push({
          projectId,
          error: error instanceof Error ? error.message : String(error),
        })
      }
    }
  }

  await Promise.all(
    Array.from({ length: Math.min(DELETE_CONCURRENCY, ids.length) }, () =>
      worker(),
    ),
  )

  return { deleted, failed }
}

/**
 * Delete registered leftovers from this run, and optionally stale `e2e-*`
 * projects still sitting in E2E_ORG_ID from failed/cancelled suites.
 */
export async function cleanupE2eProjects(options?: {
  includeStaleLeftovers?: boolean
  /** 0 = delete every disposable leftover, including recent ones. */
  staleAfterMs?: number
}): Promise<E2eProjectCleanupResult> {
  const organizationId = env.E2E_ORG_ID
  if (!organizationId) {
    return { deleted: [], failed: [] }
  }

  if (!getConsoleFallbackCookies()) {
    logCleanup(
      'Skipping project cleanup: no console session in e2e/.auth/auth.json',
    )
    return { deleted: [], failed: [] }
  }

  const registered = listRegisteredE2eProjects()
  const ids = new Set(registered.map((project) => project.projectId))

  if (options?.includeStaleLeftovers) {
    const staleAfterMs = options.staleAfterMs ?? E2E_STALE_PROJECT_MS
    try {
      const listed = await listOrganizationProjects(organizationId, {
        startsWithName: 'e2e-',
      })
      for (const project of listed) {
        if (!isDisposableE2eProjectName(project.name)) continue
        if (!isStale(project, staleAfterMs)) continue
        ids.add(project.$id)
      }
    } catch (error) {
      logCleanup(
        `Failed to list leftover e2e projects: ${
          error instanceof Error ? error.message : String(error)
        }`,
      )
    }
  }

  if (ids.size === 0) {
    return { deleted: [], failed: [] }
  }

  logCleanup(
    `Deleting ${ids.size} leftover e2e project(s) in ${organizationId}`,
  )
  const result = await deleteWithLimit(organizationId, ids)
  if (result.deleted.length > 0) {
    logCleanup(`Deleted ${result.deleted.length} e2e project(s)`)
  }
  for (const failure of result.failed) {
    logCleanup(`Failed to delete ${failure.projectId}: ${failure.error}`)
  }
  return result
}
