import type { QueryClient } from '@tanstack/react-query'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
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

export type PrefetchOrganizationOverviewOptions = {
  projectsPage?: number
  projectsLimit?: number
  search?: string
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

  // Everything except the projects lists is independent: fetch in parallel.
  const teamPromise = queryClient.ensureQueryData(
    consoleTeamQueryOptions(orgId),
  )
  const parallel: Promise<unknown>[] = [
    queryClient.ensureQueryData(organizationsQueryOptions()),
    queryClient.ensureQueryData(organizationQueryOptions(orgId)),
    teamPromise,
    queryClient.ensureQueryData(
      organizationMembershipsQueryOptions(
        orgId,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        '',
      ),
    ),
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

  await Promise.all(parallel)

  // Projects query keys depend on pinned IDs from team prefs, so these two
  // must wait for the team document (second and final round trip).
  const team = (await teamPromise) as
    | { prefs?: Record<string, unknown> }
    | null
    | undefined
  const pinnedIds = parsePinnedProjectIds(team?.prefs)

  // Resolved first so the prefetch lands on the key the overview reads.
  const projectScope = await queryClient
    .ensureQueryData(organizationProjectScopeQueryOptions(orgId))
    .catch(() => null)

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
}

/** Extract org id from `/organizations/:orgId` paths for post-auth prefetch. */
export function parseOrganizationIdFromPath(path: string): string | undefined {
  const match = path.match(/^\/organizations\/([^/]+)/)
  return match?.[1]
}
