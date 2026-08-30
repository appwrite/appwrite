import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  organizationsQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
  organizationProjectScopeQueryOptions,
  prefetchOrganizationInvoiceDataIfAllowed,
} from '@/lib/react-query/hooks/organizations'
import { activeProjectsQueryOptions, pinnedProjectsQueryOptions } from '@/lib/react-query/hooks/projects'
import {
  consoleTeamQueryOptions,
  organizationMembershipsQueryOptions,
} from '@/lib/react-query/hooks/teams'
import { USER_PREFS_KEY_ORGANIZATION } from '@/lib/user-prefs-keys'
import { consoleVariablesQueryOptions } from '@/lib/react-query/hooks/console-variables'
import { prefetchProjectListRequestsUsage } from '@/lib/react-query/hooks/usage-events'

export type PrefetchOrganizationOverviewOptions = {
  projectsPage?: number
  projectsLimit?: number
  search?: string
}

/** 0-indexed page and limit for the org overview projects list (URL is 1-indexed). */
export function organizationOverviewProjectsParamsFromUrl(url: URL): {
  projectsPage: number
  projectsLimit: number
} {
  const pageRaw = url.searchParams.get('projectsPage')
  const limitRaw = url.searchParams.get('projectsLimit')
  const pageNum = Number(pageRaw)
  const limitNum = Number(limitRaw)
  return {
    projectsPage:
      pageRaw == null || pageRaw === ''
        ? 0
        : Number.isInteger(pageNum) && pageNum >= 1
          ? pageNum - 1
          : 0,
    projectsLimit:
      limitRaw == null || limitRaw === ''
        ? GRID_DEFAULT_PAGE_SIZE
        : Number.isInteger(limitNum) && limitNum >= 1
          ? limitNum
          : GRID_DEFAULT_PAGE_SIZE,
  }
}

function preferredOrganizationIdFromAccount(
  account: Pick<Models.User, 'prefs'>,
): string | undefined {
  const value = (account.prefs as Record<string, unknown> | undefined)?.[
    USER_PREFS_KEY_ORGANIZATION
  ]
  return typeof value === 'string' && value.trim() ? value.trim() : undefined
}

/**
 * Prefetch data the org overview layout and projects tab need before first paint.
 * Route loaders and post-auth navigation should await this so the org header and
 * projects grid do not flash empty skeletons.
 */
export async function prefetchOrganizationOverviewData(
  queryClient: QueryClient,
  orgId: string,
  options?: PrefetchOrganizationOverviewOptions,
): Promise<void> {
  if (!orgId || typeof window === 'undefined') return

  const features = getActiveProfileFeatures()
  const projectsPage = options?.projectsPage ?? 0
  const projectsLimit = options?.projectsLimit ?? GRID_DEFAULT_PAGE_SIZE
  const search = options?.search ?? ''

  // Failed-invoice banner data is non-critical: fetch in the background so it
  // never delays first paint (its query is marked skipInitialLoader).
  if (features.billing) {
    void prefetchOrganizationInvoiceDataIfAllowed(queryClient, orgId).catch(
      () => {},
    )
  }

  const teamPromise = queryClient.ensureQueryData(
    consoleTeamQueryOptions(orgId),
  )
  const projectScopePromise = features.orgRoles
    ? queryClient
        .ensureQueryData(organizationProjectScopeQueryOptions(orgId))
        .catch(() => null)
    : Promise.resolve(null)

  // Projects depend only on team prefs (pinned ids) and project-scope. Start
  // that second hop as soon as those two resolve; do not wait for memberships,
  // plan, or the organizations list.
  const projectsPromise = (async () => {
    const [team, projectScope] = await Promise.all([
      teamPromise,
      projectScopePromise,
    ])
    const pinnedIds = parsePinnedProjectIds(
      (team as { prefs?: Record<string, unknown> } | null | undefined)?.prefs,
    )

    await Promise.all([
      queryClient.ensureQueryData(
        activeProjectsQueryOptions(
          orgId,
          projectsPage,
          projectsLimit,
          search,
          pinnedIds,
          projectScope ?? null,
        ),
      ),
      ...(pinnedIds.length > 0
        ? [
            queryClient.ensureQueryData(
              pinnedProjectsQueryOptions(orgId, pinnedIds),
            ),
          ]
        : []),
    ])
  })()

  const parallel: Promise<unknown>[] = [
    queryClient
      .ensureQueryData(consoleVariablesQueryOptions())
      .catch(() => {}),
    queryClient.ensureQueryData(organizationsQueryOptions()),
    queryClient.ensureQueryData(organizationQueryOptions(orgId)),
    queryClient.ensureQueryData(
      organizationMembershipsQueryOptions(
        orgId,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        '',
      ),
    ),
    projectsPromise,
  ]

  if (features.billing) {
    parallel.push(
      queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
    )
  }

  if (features.orgRoles) {
    parallel.push(
      queryClient
        .ensureQueryData(organizationScopesQueryOptions(orgId))
        .catch(() => {}),
    )
  }

  try {
    await Promise.all(parallel)
  } catch (error) {
    // If a sibling query failed first, observe projectsPromise so a later
    // rejection is not an unhandled rejection.
    await projectsPromise.catch(() => {})
    throw error
  }

  if (!getActiveProfileFeatures().usageStats) return

  const team = queryClient.getQueryData(consoleTeamQueryOptions(orgId).queryKey) as
    | { prefs?: Record<string, unknown> }
    | undefined
  const pinnedIds = parsePinnedProjectIds(team?.prefs)
  const projectScope = features.orgRoles
    ? ((queryClient.getQueryData(
        organizationProjectScopeQueryOptions(orgId).queryKey,
      ) as string[] | null | undefined) ?? null)
    : null
  const active = queryClient.getQueryData(
    activeProjectsQueryOptions(
      orgId,
      projectsPage,
      projectsLimit,
      search,
      pinnedIds,
      projectScope,
    ).queryKey,
  ) as { projects?: Array<{ $id: string }> } | undefined
  const pinned =
    pinnedIds.length > 0
      ? (queryClient.getQueryData(
          pinnedProjectsQueryOptions(orgId, pinnedIds).queryKey,
        ) as { projects?: Array<{ $id: string }> } | undefined)
      : undefined
  const projectIds = [
    ...new Set(
      [...(pinned?.projects ?? []), ...(active?.projects ?? [])]
        .map((project) => project.$id)
        .filter(Boolean),
    ),
  ]
  if (projectIds.length > 0) {
    prefetchProjectListRequestsUsage(queryClient, projectIds)
  }
}

/**
 * Resolve the default organization and prefetch its overview, overlapping the
 * two. When prefs already name an org, overview fetches start immediately
 * instead of waiting to validate that id.
 */
export async function resolveAndPrefetchDefaultOrganization(
  queryClient: QueryClient,
  account: Models.User,
): Promise<string> {
  const preferredId = preferredOrganizationIdFromAccount(account)
  const resolvePromise = resolvePostAuthOrganizationId(account, queryClient)

  if (!preferredId) {
    const orgId = await resolvePromise
    await prefetchOrganizationOverviewData(queryClient, orgId)
    return orgId
  }

  const speculative = prefetchOrganizationOverviewData(queryClient, preferredId)

  try {
    const orgId = await resolvePromise
    if (orgId === preferredId) {
      await speculative
      return orgId
    }
    void speculative.catch(() => {})
    await prefetchOrganizationOverviewData(queryClient, orgId)
    return orgId
  } catch (error) {
    void speculative.catch(() => {})
    throw error
  }
}

/**
 * Start overview fetches for the preferred org without awaiting them.
 * Used by the `_public` parent loader so work is in-flight before the `/`
 * child loader runs.
 */
export function kickoffDefaultOrganizationPrefetch(
  queryClient: QueryClient,
  account: Models.User,
): void {
  const preferredId = preferredOrganizationIdFromAccount(account)
  if (!preferredId) return
  void prefetchOrganizationOverviewData(queryClient, preferredId).catch(
    () => {},
  )
}

/** Extract org id from `/organizations/:orgId` paths for post-auth prefetch. */
export function parseOrganizationIdFromPath(path: string): string | undefined {
  const match = path.match(/^\/organizations\/([^/]+)/)
  return match?.[1]
}
