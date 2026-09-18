import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  organizationOverviewProjectsParamsFromUrl,
  prefetchOrganizationOverviewData,
} from '@/lib/organization-overview-prefetch'
import { urlFromRouterLocation } from '@/lib/table-filters'
import { z } from 'zod'

const searchSchema = z
  .object({
    createOrg: z.boolean().optional(),
  })
  .passthrough()

export const Route = createFileRoute('/_public/organizations/$orgId')({
  validateSearch: searchSchema,
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Same pattern as projects grid (AGENTS.md): ensureQueryData with exact query keys the View uses.
    if (orgId) {
      try {
        await prefetchOrganizationOverviewData(
          queryClient,
          orgId,
          organizationOverviewProjectsParamsFromUrl(
            urlFromRouterLocation(location),
          ),
        )
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
  // Match-based (not pathname): pathname updates before the buy/transfer-in loader finishes,
  // which previously tore down OrgOverview and flashed a blank shell until the wizard mounted.
  const isOrgDomainsWizardRoute = matches.some(
    (match) =>
      match.routeId === '/_public/organizations/$orgId/domains/buy' ||
      match.routeId === '/_public/organizations/$orgId/domains/transfer-in',
  )

  // Match-based (same as domain detail): pathname updates before the loader, which caused
  // OrgOverview to unmount while the marketplace list was still visible.
  const isMarketplaceAppDetailRoute = matches.some((match) =>
    match.routeId.includes('/marketplace/$appId'),
  )
  const isOrgAppDetailRoute = matches.some((match) =>
    match.routeId.includes('/apps/$appId'),
  )

  // Agent has its own ConsoleLayout (console header + fixed chat chrome).
  const isAgentRoute = matches.some(
    (match) =>
      match.routeId.includes('/agent') ||
      match.routeId === '/_public/organizations/$orgId/agent' ||
      match.routeId.startsWith('/_public/organizations/$orgId/agent'),
  )
  const renderOutletOnly =
    isDomainDetailRoute ||
    isMarketplaceAppDetailRoute ||
    isOrgAppDetailRoute ||
    isSupportRoute ||
    isOrgDomainsWizardRoute ||
    isAgentRoute

  return (
    <RequireAuth>
      {renderOutletOnly ? (
        // Domain detail, apps, marketplace detail, support, domain wizards,
        // and agent: outlet only (own ConsoleLayout / fullscreen chrome)
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
