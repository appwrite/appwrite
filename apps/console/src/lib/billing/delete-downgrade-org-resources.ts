import { sdk } from '@/lib/appwrite/sdk'
import type { DowngradeAddonRemoval } from '@/lib/billing/downgrade-addons'
import { deleteOrganizationDomain } from '@/lib/react-query/hooks/domains'

/**
 * Only the ids the user confirmed. An earlier version passed the complement of
 * a client-side snapshot to a bulk "keep these" API, which would delete
 * anything added to the organization after that snapshot was taken.
 */
export async function deleteDowngradeMemberships(
  organizationId: string,
  membershipIds: string[],
): Promise<void> {
  await Promise.all(
    membershipIds.map((membershipId) =>
      sdk.forConsole.teams.deleteMembership(organizationId, membershipId),
    ),
  )
}

export async function deleteDowngradeDomains(
  domainIds: string[],
): Promise<void> {
  await Promise.all(
    domainIds.map((domainId) => deleteOrganizationDomain(domainId)),
  )
}

/**
 * On an active addon this only sets `nextValue = 0`; the plan change is what
 * actually removes it, and plan changes apply immediately.
 */
export async function deleteDowngradeAddons(
  addons: DowngradeAddonRemoval[],
): Promise<void> {
  // Best effort: the plan change removes addons the new plan does not support
  // anyway, so one refusing to disable must not abort a run whose deletions
  // have already happened.
  await Promise.allSettled(
    addons.map((addon) =>
      addon.scope === 'organization'
        ? sdk.forConsole.organizations.deleteAddon({
            organizationId: addon.resourceId,
            addonId: addon.addonId,
          })
        : sdk.forConsole.projects.deleteAddon({
            projectId: addon.resourceId,
            addonId: addon.addonId,
          }),
    ),
  )
}
