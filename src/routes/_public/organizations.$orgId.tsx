import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import {
  createFileRoute,
  Outlet,
  isRedirect,
  useMatches,
} from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import {
  organizationOverviewProjectsParamsFromUrl,
  prefetchOrganizationOverviewData,
} from '@/lib/organization-overview-prefetch'
import { organizationQueryOptions } from '@/lib/react-query/hooks'
import { isPartnerOrganization } from '@/lib/partners'
import { PartnerOrganizationUnavailable } from '@/components/pages/organizations/$orgId/_components/PartnerOrganizationUnavailable'
import { urlFromRouterLocation } from '@/lib/table-filters'
import { z } from 'zod'

const searchSchema = z
  .object({
    createOrg: z.boolean().optional(),
  })
  .passthrough()

export const Route = createFileRoute('/_public/organizations/$orgId')({
  validateSearch: searchSchema,
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return { partnerOrganizationBlocked: false as const }
    }
    const { orgId } = params
    if (!orgId) return
    try {
      const org = await context.queryClient.ensureQueryData(
        organizationQueryOptions(orgId),
      )
      if (isPartnerOrganization(org)) {
        return { partnerOrganizationBlocked: true as const }
      }
    } catch (error) {
      if (isRedirect(error)) throw error
    }
    return { partnerOrganizationBlocked: false as const }
  },
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    if (context.partnerOrganizationBlocked) {
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
  const { partnerOrganizationBlocked } = Route.useRouteContext()

  if (partnerOrganizationBlocked) {
    return (
      <RequireAuth>
        <PartnerOrganizationUnavailable />
      </RequireAuth>
    )
  }

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
