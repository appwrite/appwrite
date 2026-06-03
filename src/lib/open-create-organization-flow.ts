import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'

/**
 * Starts the create-organization flow:
 * - Cloud (billing): fullscreen upgrade wizard at `/upgrade`
 * - Self-hosted: optional in-app callback (org overview), else `createOrg` search param
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
  if (getActiveProfileFeatures().billing) {
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
