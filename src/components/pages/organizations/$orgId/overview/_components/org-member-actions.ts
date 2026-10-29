import type { TeamMember } from '@/lib/utils/mock-data'

function isAccountIdentity(
  account: unknown,
): account is { $id: string; email: string } {
  if (!account || typeof account !== 'object') return false
  const candidate = account as { $id?: unknown; email?: unknown }
  return typeof candidate.$id === 'string' && typeof candidate.email === 'string'
}

export function isCurrentOrgMember(
  member: TeamMember,
  account: unknown,
): boolean {
  if (!isAccountIdentity(account)) return false
  return (
    (!!member.userId && member.userId === account.$id) ||
    member.userEmail.toLowerCase() === account.email.toLowerCase()
  )
}

/** Matches the org members table: owners can remove anyone; members can leave (not pending). */
export function canRemoveOrgTeamMember(
  member: TeamMember,
  {
    canManageMembers,
    isCurrentMember,
  }: {
    canManageMembers: boolean
    isCurrentMember: boolean
  },
): boolean {
  if (canManageMembers) return true
  return isCurrentMember && member.status !== 'pending'
}
