/** Role a member needs in an organization to receive a transferred project. */
export const TRANSFER_RECIPIENT_ROLE = 'owner'

export type TransferOrganization = {
  id: string
  eligible: boolean
}

/**
 * Which of the user's organizations can receive a transferred project: the
 * server only accepts a transfer into an organization the user owns. Roles
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
 * receive the project, otherwise the first one that can. With none eligible
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
