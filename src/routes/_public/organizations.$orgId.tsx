import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { fetchOrganizations } from '@/lib/react-query/hooks'
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

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })
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
      match.routeId.startsWith('/_public/organizations/$orgId/domains/$domainId')
  )

  // Check if we're on the change-plan route (should not have org header/tabs - it's fullscreen)
  const isChangePlanRoute = matches.some(
    (match) =>
      match.routeId.includes('/change-plan') ||
      match.routeId === '/_public/organizations/$orgId/change-plan' ||
      match.routeId.startsWith('/_public/organizations/$orgId/change-plan')
  )

  return (
    <RequireAuth>
      {isDomainDetailRoute || isChangePlanRoute ? (
        // For domain detail routes and change-plan route, render outlet directly (they have their own layout)
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

