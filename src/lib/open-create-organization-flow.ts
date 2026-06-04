import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'

/**
 * Starts the create-organization flow:
 * - Multi-tenant Cloud (billing): fullscreen upgrade wizard at `/upgrade`
 * - Multi-tenant without billing: optional in-app callback, else `createOrg` search param
 * - Single-tenant profiles: no-op
 */
export function openCreateOrganizationFlow(
  navigate: (opts: {
    to: '/organizations/$orgId' | '/' | '/upgrade'
    params?: { orgId: string }
    search?: { createOrg: boolean }
  }) => void,
  options: {
    onCreateOrganization?: () => void
    orgId: string | undefined
  },
): void {
  const features = getActiveProfileFeatures()
  if (!features.multiTenancy) return

  if (features.billing) {
    navigateToUpgradeWizard(navigate)
    return
  }

  if (options.onCreateOrganization) {
    options.onCreateOrganization()
    return
  }
  if (options.orgId) {
    navigate({
      to: '/organizations/$orgId',
      params: { orgId: options.orgId },
      search: { createOrg: true },
    })
  } else {
    navigate({
      to: '/',
      search: { createOrg: true },
    })
  }
}
