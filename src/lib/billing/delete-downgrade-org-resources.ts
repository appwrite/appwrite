import { sdk } from '@/lib/appwrite/sdk'
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
