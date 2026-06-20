import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import {
  createFileRoute,
  Outlet,
  useMatches,
  useLocation,
} from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'
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
        await prefetchOrganizationOverviewData(queryClient, orgId)
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
  const isMarketplaceAppDetailRoute =
    pathname.includes('/marketplace/') && !pathname.endsWith('/marketplace')
  const isOrgAppDetailRoute =
    pathname.includes('/apps/') && !pathname.endsWith('/apps')
  const isUpgradeWizardRoute = pathname === '/upgrade'
  const renderOutletOnly =
    isDomainDetailRoute ||
    isMarketplaceAppDetailRoute ||
    isOrgAppDetailRoute ||
    isSupportRoute ||
    isOrgDomainsWizardRoute ||
    isUpgradeWizardRoute

  return (
    <RequireAuth>
      {renderOutletOnly ? (
        // Domain detail, support, upgrade, and domain wizards: outlet only (fullscreen / own chrome)
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
