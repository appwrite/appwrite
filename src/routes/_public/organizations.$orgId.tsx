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
} from '@/lib/react-query/hooks'
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

    // Fetch org plan + organizations + projects + memberships so overview has data before first render.
    // Organizations list is required for selectedOrg so the projects list can render.
    // When navigating from change-plan/support to the projects tab, only the index loader runs
    // (parent does not re-run); the index route blocks until projects/memberships are loaded.
    if (orgId) {
      try {
        const timeoutPromise = new Promise((_, reject) => {
          setTimeout(
            () => reject(new Error('Organization data fetch timeout')),
            10000,
          )
        })

        const loaders: Promise<unknown>[] = [
          queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
          queryClient.ensureQueryData(organizationsQueryOptions()),
          queryClient.ensureQueryData(
            activeProjectsQueryOptions(orgId, 0, GRID_DEFAULT_PAGE_SIZE, ''),
          ),
          queryClient.ensureQueryData(
            organizationMembershipsQueryOptions(
              orgId,
              0,
              GRID_DEFAULT_PAGE_SIZE,
              '',
            ),
          ),
        ]
        if (getActiveProfileFeatures().orgRoles) {
          loaders.push(
            queryClient
              .ensureQueryData(organizationScopesQueryOptions(orgId))
              .catch(() => {}),
          )
        }
        await Promise.race([Promise.all(loaders), timeoutPromise])

        // Return prefetched data so OrgOverview can use it as initialData and avoid layout shift
        return {
          organizationsData: queryClient.getQueryData(
            organizationsQueryOptions().queryKey,
          ),
          organizationPlan: queryClient.getQueryData(
            organizationPlanQueryOptions(orgId).queryKey,
          ),
          membershipsData: queryClient.getQueryData(
            organizationMembershipsQueryOptions(orgId, 0, GRID_DEFAULT_PAGE_SIZE, '')
              .queryKey,
          ),
          scopesData: getActiveProfileFeatures().orgRoles
            ? queryClient.getQueryData(
                organizationScopesQueryOptions(orgId).queryKey,
              )
            : undefined,
        }
      } catch (error) {
        // Don't block navigation if fetch fails or times out - component will handle
        console.warn('Failed to fetch organization data in loader:', error)
        return undefined
      }
    }
    return undefined
  },
  component: OrganizationLayout,
})

function OrganizationLayout() {
  const matches = useMatches()
  const location = useLocation()
  const loaderData = Route.useLoaderData()

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

  // Check if we're on the buy domain route (fullscreen wizard)
  const isBuyDomainRoute = matches.some(
    (match) =>
      match.routeId?.includes('/domains/buy') ||
      match.routeId === '/_public/organizations/$orgId/domains/buy',
  )

  return (
    <RequireAuth>
      {isDomainDetailRoute ||
      isSupportRoute ||
      isBuyDomainRoute ? (
        // For domain detail and support routes, render outlet directly (they have their own layout)
        <Outlet />
      ) : isChangePlanRoute ? (
        // Change-plan: fullscreen wrapper so the wizard looks identical from header upgrade or billing upgrade
        <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
          <Outlet />
        </div>
      ) : (
        // For other routes, render OrgOverview which provides header/tabs
        <OrgOverview initialData={loaderData}>
          <Outlet />
        </OrgOverview>
      )}
    </RequireAuth>
  )
}
