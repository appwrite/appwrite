import type { QueryClient } from '@tanstack/react-query'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  organizationsQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
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

  await queryClient.ensureQueryData(organizationsQueryOptions())
  await queryClient.ensureQueryData(organizationQueryOptions(orgId))

  if (features.billing) {
    await queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
    await prefetchOrganizationInvoiceDataIfAllowed(queryClient, orgId)
  }

  await queryClient.ensureQueryData(consoleTeamQueryOptions(orgId))
  const team = queryClient.getQueryData(
    consoleTeamQueryOptions(orgId).queryKey,
  ) as { prefs?: Record<string, unknown> } | null | undefined
  const pinnedIds = parsePinnedProjectIds(team?.prefs)

  const parallel: Promise<unknown>[] = [
    queryClient.ensureQueryData(
      organizationMembershipsQueryOptions(
        orgId,
        0,
        GRID_DEFAULT_PAGE_SIZE,
        '',
      ),
    ),
    queryClient.ensureQueryData(
      activeProjectsQueryOptions(
        orgId,
        projectsPage,
        projectsLimit,
        search,
        pinnedIds,
      ),
    ),
  ]

  if (features.orgRoles) {
    parallel.push(
      queryClient
        .ensureQueryData(organizationScopesQueryOptions(orgId))
        .catch(() => {}),
    )
  }

  if (pinnedIds.length > 0) {
    parallel.push(
      queryClient.ensureQueryData(pinnedProjectsQueryOptions(orgId, pinnedIds)),
    )
  }

  await Promise.all(parallel)
}

/** Extract org id from `/organizations/:orgId` paths for post-auth prefetch. */
export function parseOrganizationIdFromPath(path: string): string | undefined {
  const match = path.match(/^\/organizations\/([^/]+)/)
  return match?.[1]
}
