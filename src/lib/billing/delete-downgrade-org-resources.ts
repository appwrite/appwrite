import { sdk } from '@/lib/appwrite/sdk'
import { deleteOrganizationDomain } from '@/lib/react-query/hooks/domains'

async function tryUpdateSelectedMemberships(
  organizationId: string,
  membershipIds: string[],
) {
  if ((sdk.forConsole as unknown).billing?.updateSelectedMemberships) {
    return await (sdk.forConsole as unknown).billing.updateSelectedMemberships(
      organizationId,
      membershipIds,
    )
  }
  if ((sdk.forConsole.organizations as unknown).updateSelectedMemberships) {
    return await (
      sdk.forConsole.organizations as unknown
    ).updateSelectedMemberships(organizationId, membershipIds)
  }
  return null
}

async function tryUpdateSelectedDomains(
  organizationId: string,
  domainIds: string[],
) {
  if ((sdk.forConsole as unknown).billing?.updateSelectedDomains) {
    return await (sdk.forConsole as unknown).billing.updateSelectedDomains(
      organizationId,
      domainIds,
    )
  }
  if ((sdk.forConsole.organizations as unknown).updateSelectedDomains) {
    return await (
      sdk.forConsole.organizations as unknown
    ).updateSelectedDomains(organizationId, domainIds)
  }
  return null
}

export async function deleteDowngradeMemberships(
  organizationId: string,
  membershipIds: string[],
  selectedMembershipIds: string[],
): Promise<void> {
  if (membershipIds.length === 0) return

  const updated = await tryUpdateSelectedMemberships(
    organizationId,
    selectedMembershipIds,
  ).catch(() => null)

  if (updated) return

  await Promise.all(
    membershipIds.map((membershipId) =>
      sdk.forConsole.teams.deleteMembership(organizationId, membershipId),
    ),
  )
}

export async function deleteDowngradeDomains(
  organizationId: string,
  domainIds: string[],
  selectedDomainIds: string[],
): Promise<void> {
  if (domainIds.length === 0) return

  const updated = await tryUpdateSelectedDomains(
    organizationId,
    selectedDomainIds,
  ).catch(() => null)

  if (updated) return

  await Promise.all(domainIds.map((domainId) => deleteOrganizationDomain(domainId)))
}
