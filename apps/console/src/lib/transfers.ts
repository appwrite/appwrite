/** What a transfer link hands over. */
export type TransferResource = 'projects' | 'domains'

/**
 * The claim page path for a transfer link. Project links carry no resource,
 * so links shared before domains could be transferred keep working.
 */
export function buildTransferPath(
  resource: TransferResource,
  transferId: string,
  secret: string,
): string {
  const params = new URLSearchParams({ transferId, secret })
  if (resource === 'domains') params.set('resource', 'domains')
  return `/transfer?${params}`
}

/** Role a member needs in an organization to receive a transferred project or domain. */
export const TRANSFER_RECIPIENT_ROLE = 'owner'

export type TransferOrganization = {
  id: string
  eligible: boolean
}

/**
 * Which of the user's organizations can receive a transferred project or
 * domain: the server only accepts a transfer into an organization the user owns. Roles
 * that are unknown (still loading, or a profile without organization roles)
 * count as eligible, and the server has the final say.
 */
export function getTransferOrganizations(
  organizationIds: readonly string[],
  rolesById: Readonly<Record<string, readonly string[] | undefined>>,
): TransferOrganization[] {
  return organizationIds.map((id) => {
    const roles = rolesById[id]
    return {
      id,
      eligible: roles === undefined || roles.includes(TRANSFER_RECIPIENT_ROLE),
    }
  })
}

/**
 * The organization the picker should hold: the current choice while it can
 * receive the transfer, otherwise the first one that can. With none eligible
 * it keeps an organization selected so the transfer can still be previewed.
 */
export function pickTransferOrganization(
  organizations: readonly TransferOrganization[],
  current: string,
): string {
  const selected = organizations.find(({ id }) => id === current)
  if (selected?.eligible) return current
  const eligible = organizations.find(({ eligible }) => eligible)
  if (eligible) return eligible.id
  return selected ? current : (organizations[0]?.id ?? '')
}
