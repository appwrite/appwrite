import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import {
  createFileRoute,
  Outlet,
  useMatches,
  useLocation,
} from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  organizationsQueryOptions,
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
  activeProjectsQueryOptions,
  organizationMembershipsQueryOptions,
  consoleTeamQueryOptions,
  pinnedProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { parsePinnedProjectIds } from '@/lib/team-prefs-keys'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { z } from 'zod'

const searchSchema = z
  .object({
    createOrg: z.boolean().optional(),
  })
  .passthrough()

export const Route = createFileRoute('/_public/organizations/$orgId')({
  validateSearch: searchSchema,
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Same pattern as projects grid (AGENTS.md): ensureQueryData with exact query keys the View uses.
    // Order: console team → pinned IDs → active projects list key matches OrgOverview (exclude pinned).
    if (orgId) {
      try {
        const features = getActiveProfileFeatures()
        await queryClient.ensureQueryData(organizationsQueryOptions())
        if (features.billing) {
          await queryClient.ensureQueryData(organizationPlanQueryOptions(orgId))
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
              0,
              GRID_DEFAULT_PAGE_SIZE,
              '',
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
            queryClient.ensureQueryData(
              pinnedProjectsQueryOptions(orgId, pinnedIds),
            ),
          )
        }
        await Promise.all(parallel)
      } catch (error) {
        console.warn('Failed to fetch organization data in loader:', error)
      }
    }
    return undefined
  },
  component: OrganizationLayout,
})

function OrganizationLayout() {
  const matches = useMatches()
  const location = useLocation()

  // Use pathname as well as matches so we switch to wizard immediately on navigation
  // (matches can lag one frame, causing a flash of projects list when clicking Upgrade)
  const pathname = location.pathname

  // Check if we're on a domain detail route (should not have org header/tabs)
  const isDomainDetailRoute = matches.some(
    (match) =>
      match.routeId.includes('/domains/$domainId') ||
      match.routeId === '/_public/organizations/$orgId/domains/$domainId' ||
      match.routeId.startsWith(
        '/_public/organizations/$orgId/domains/$domainId',
      ),
  )

  // Check if we're on the change-plan route (should not have org header/tabs - it's fullscreen)
  // Pathname check avoids one-frame flash of projects list when navigating to change-plan
  const isChangePlanRoute =
    pathname.includes('/change-plan') ||
    matches.some(
      (match) =>
        match.routeId.includes('/change-plan') ||
        match.routeId === '/_public/organizations/$orgId/change-plan' ||
        match.routeId.startsWith('/_public/organizations/$orgId/change-plan'),
    )

  // Check if we're on the support route (fullscreen wizard)
  const isSupportRoute = matches.some(
    (match) =>
      match.routeId.includes('/support') ||
      match.routeId === '/_public/organizations/$orgId/support' ||
      match.routeId.startsWith('/_public/organizations/$orgId/support'),
  )

  // Buy / transfer-in wizards: must bypass OrgOverview - it only mounts <Outlet> on the domains
  // index, so nested routes like .../domains/buy would never render (blank page).
  const isOrgDomainsWizardRoute =
    pathname.includes('/domains/buy') ||
    pathname.includes('/domains/transfer-in')

  return (
    <RequireAuth>
      {isDomainDetailRoute || isSupportRoute || isOrgDomainsWizardRoute ? (
        // Domain detail, support, and domain wizards: outlet only (fullscreen / own chrome)
        <Outlet />
      ) : isChangePlanRoute ? (
        // Change-plan: fullscreen wrapper so the wizard looks identical from header upgrade or billing upgrade
        <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
          <Outlet />
        </div>
      ) : (
        // For other routes, render OrgOverview which provides header/tabs
        <OrgOverview>
          <Outlet />
        </OrgOverview>
      )}
    </RequireAuth>
  )
}
