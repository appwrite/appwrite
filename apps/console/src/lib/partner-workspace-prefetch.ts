import type { QueryClient } from '@tanstack/react-query'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  getDefaultPartnerClientId,
  isPartnerClientSection,
  isPartnerWorkspaceResourceSection,
  type PartnerClientSection,
  type PartnerWorkspaceResourceSection,
} from '@/lib/partners'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import {
  parsePinnedClientIds,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import { prefetchOrganizationBillingTab } from '@/lib/react-query/hooks/billing-prefetch'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { organizationAppsQueryOptions } from '@/lib/react-query/hooks/apps'
import { organizationAddonsQueryOptions } from '@/lib/react-query/hooks/addons'
import { organizationDomainsQueryOptions } from '@/lib/react-query/hooks/domains'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  organizationPlanQueryOptions,
  organizationProjectScopeQueryOptions,
  organizationQueryOptions,
  organizationScopesQueryOptions,
} from '@/lib/react-query/hooks/organizations'
import { partnerClientsQueryOptions } from '@/lib/react-query/hooks/partners'
import {
  ACTIVE_PROJECTS_DEFAULT_SORT_BY,
  ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
  activeProjectsQueryOptions,
  pinnedProjectsQueryOptions,
} from '@/lib/react-query/hooks/projects'
import {
  consoleTeamQueryOptions,
  organizationMembershipsQueryOptions,
} from '@/lib/react-query/hooks/teams'

/** Same default page size as partner list panes (`PARTNER_LIST_PAGE_SIZE`). */
const PARTNER_PANE_PAGE_SIZE = ROWS_DEFAULT_PAGE_SIZE

function pinnedIdsForProjectList(
  allPinnedIds: string[],
  restrictToProjectIds: string[] | null,
): string[] {
  if (!restrictToProjectIds) return allPinnedIds
  const allowed = new Set(restrictToProjectIds)
  return allPinnedIds.filter((id) => allowed.has(id))
}

/**
 * Prefetch the partner/client projects spreadsheet the same way
 * `DevelopmentProjects` / `ClientProjectsList` subscribe (page 0, default
 * sort, pinned ids excluded, no project-scope restrict on the list query).
 */
export async function prefetchPartnerProjectsPane(
  queryClient: QueryClient,
  organizationId: string,
): Promise<void> {
  if (!organizationId || typeof window === 'undefined') return

  const features = getActiveProfileFeatures()
  const teamPromise = queryClient.ensureQueryData(
    consoleTeamQueryOptions(organizationId),
  )
  const projectScopePromise = features.orgRoles
    ? queryClient
        .ensureQueryData(organizationProjectScopeQueryOptions(organizationId))
        .catch(() => null)
    : Promise.resolve(null)

  const [team, projectScope] = await Promise.all([
    teamPromise,
    projectScopePromise,
  ])
  const pinnedIds = pinnedIdsForProjectList(
    parsePinnedProjectIds(
      (team as { prefs?: Record<string, unknown> } | null | undefined)?.prefs,
    ),
    projectScope ?? null,
  )
  const excludeProjectIds = pinnedIds.length > 0 ? pinnedIds : undefined

  await Promise.all([
    queryClient.ensureQueryData(
      activeProjectsQueryOptions(
        organizationId,
        0,
        PARTNER_PANE_PAGE_SIZE,
        '',
        excludeProjectIds,
        undefined,
        ACTIVE_PROJECTS_DEFAULT_SORT_BY,
        ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
        undefined,
      ),
    ),
    ...(pinnedIds.length > 0
      ? [
          queryClient.ensureQueryData(
            pinnedProjectsQueryOptions(organizationId, pinnedIds),
          ),
        ]
      : []),
    queryClient.ensureQueryData(organizationQueryOptions(organizationId)),
    ...(features.billing
      ? [queryClient.ensureQueryData(organizationPlanQueryOptions(organizationId))]
      : []),
    ...(features.orgRoles
      ? [
          queryClient
            .ensureQueryData(organizationScopesQueryOptions(organizationId))
            .catch(() => {}),
        ]
      : []),
  ])
}

async function prefetchPartnerDomainsPane(
  queryClient: QueryClient,
  organizationId: string,
): Promise<void> {
  await queryClient.ensureQueryData(
    organizationDomainsQueryOptions(
      organizationId,
      0,
      PARTNER_PANE_PAGE_SIZE,
      undefined,
      undefined,
    ),
  )
}

async function prefetchPartnerAppsPane(
  queryClient: QueryClient,
  organizationId: string,
): Promise<void> {
  await queryClient.ensureQueryData(
    organizationAppsQueryOptions(
      organizationId,
      undefined,
      undefined,
      0,
      PARTNER_PANE_PAGE_SIZE,
    ),
  )
}

export async function prefetchPartnerWorkspaceSection(
  queryClient: QueryClient,
  partnerId: string,
  section?: PartnerWorkspaceResourceSection,
): Promise<void> {
  const pane: PartnerWorkspaceResourceSection =
    section && isPartnerWorkspaceResourceSection(section) ? section : 'projects'
  if (pane === 'domains') {
    await prefetchPartnerDomainsPane(queryClient, partnerId)
    return
  }
  if (pane === 'apps') {
    await prefetchPartnerAppsPane(queryClient, partnerId)
    return
  }
  await prefetchPartnerProjectsPane(queryClient, partnerId)
}

export async function prefetchPartnerWorkspacePage(
  queryClient: QueryClient,
  partnerId: string,
  section?: PartnerWorkspaceResourceSection,
): Promise<void> {
  if (!partnerId || typeof window === 'undefined') return
  await Promise.all([
    queryClient.ensureQueryData(partnerClientsQueryOptions(partnerId)),
    prefetchPartnerWorkspaceSection(queryClient, partnerId, section),
  ])
}

export async function prefetchPartnerClientSection(
  queryClient: QueryClient,
  organizationId: string,
  section?: PartnerClientSection,
): Promise<void> {
  const pane: PartnerClientSection =
    section && isPartnerClientSection(section) ? section : 'projects'
  if (pane === 'members') {
    await Promise.all([
      queryClient.ensureQueryData(organizationQueryOptions(organizationId)),
      queryClient.ensureQueryData(
        organizationMembershipsQueryOptions(
          organizationId,
          0,
          PARTNER_PANE_PAGE_SIZE,
          undefined,
        ),
      ),
    ])
    return
  }
  if (pane === 'domains') {
    await prefetchPartnerDomainsPane(queryClient, organizationId)
    return
  }
  if (pane === 'apps') {
    await prefetchPartnerAppsPane(queryClient, organizationId)
    return
  }
  if (pane === 'addons') {
    await queryClient.ensureQueryData(
      organizationAddonsQueryOptions(organizationId),
    )
    return
  }
  if (pane === 'billing') {
    await prefetchOrganizationBillingTab(queryClient, organizationId, {
      waitForAggregation: true,
    })
    return
  }
  if (pane === 'settings' || pane === 'compliance') {
    await Promise.all([
      queryClient.ensureQueryData(organizationQueryOptions(organizationId)),
      getActiveProfileFeatures().orgRoles
        ? queryClient
            .ensureQueryData(organizationScopesQueryOptions(organizationId))
            .catch(() => {})
        : Promise.resolve(),
    ])
    return
  }
  await prefetchPartnerProjectsPane(queryClient, organizationId)
}

export async function prefetchPartnerClientPage(
  queryClient: QueryClient,
  partnerId: string,
  clientId: string,
  section?: PartnerClientSection,
): Promise<void> {
  if (!partnerId || !clientId || typeof window === 'undefined') return
  await Promise.all([
    queryClient.ensureQueryData(partnerClientsQueryOptions(partnerId)),
    prefetchPartnerClientSection(queryClient, clientId, section),
  ])
}

export async function prefetchPartnerClientsIndexPage(
  queryClient: QueryClient,
  partnerId: string,
): Promise<void> {
  if (!partnerId || typeof window === 'undefined') return

  const [account, clients] = await Promise.all([
    queryClient.ensureQueryData(consoleAccountQueryOptions()),
    queryClient.ensureQueryData(partnerClientsQueryOptions(partnerId)),
  ])
  const pinnedClientIds = parsePinnedClientIds(
    account?.prefs as UserPrefs | undefined,
    partnerId,
  )
  const defaultClientId = getDefaultPartnerClientId(
    clients.teams ?? [],
    pinnedClientIds,
  )
  if (!defaultClientId) return
  await prefetchPartnerClientSection(queryClient, defaultClientId, 'projects')
}
