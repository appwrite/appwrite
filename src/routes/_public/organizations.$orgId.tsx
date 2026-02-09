import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  organizationsQueryOptions,
  organizationPlanQueryOptions,
  activeProjectsQueryOptions,
  organizationMembershipsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { z } from 'zod'

const searchSchema = z.object({
  createOrg: z.boolean().optional(),
})

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

        await Promise.race([
          Promise.all([
            queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
            queryClient.ensureQueryData(organizationsQueryOptions()),
            queryClient.ensureQueryData(
              activeProjectsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
            ),
            queryClient.ensureQueryData(
              organizationMembershipsQueryOptions(
                orgId,
                0,
                DEFAULT_PAGE_SIZE,
                '',
              ),
            ),
          ]),
          timeoutPromise,
        ])
      } catch (error) {
        // Don't block navigation if fetch fails or times out - component will handle
        console.warn('Failed to fetch organization data in loader:', error)
      }
    }
  },
  component: OrganizationLayout,
})

function OrganizationLayout() {
  const matches = useMatches()

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
  const isChangePlanRoute = matches.some(
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

  return (
    <RequireAuth>
      {isDomainDetailRoute || isChangePlanRoute || isSupportRoute ? (
        // For domain detail, change-plan, and support routes, render outlet directly (they have their own layout)
        <Outlet />
      ) : (
        // For other routes, render OrgOverview which provides header/tabs
        <OrgOverview>
          <Outlet />
        </OrgOverview>
      )}
    </RequireAuth>
  )
}
