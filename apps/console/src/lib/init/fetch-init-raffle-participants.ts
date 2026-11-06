import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  getInitPresencePublicId,
  isInitAnonymousPresenceUserId,
  isInitHiddenPresence,
  listInitPresences,
  mapPresencesToOnlineUsers,
} from '@/lib/init/presence'
import type { LaunchEvent, LaunchEventOnlineUser } from '@/lib/init/types'

const RAFFLE_PARTICIPANT_LIMIT = 1000

/** Online users eligible for a live giveaway draw (identity visible, not privacy mode). */
export function filterInitRaffleParticipants(
  users: readonly LaunchEventOnlineUser[],
): LaunchEventOnlineUser[] {
  return users.filter((user) => !user.identityHidden)
}

export async function fetchInitRaffleParticipants(
  event: LaunchEvent,
): Promise<LaunchEventOnlineUser[]> {
  const allowlist = buildInitPresenceActivityAllowlist(event)
  const presences = await listInitPresences(event.id, 'online', RAFFLE_PARTICIPANT_LIMIT)
  const eligible = presences.filter((presence) => {
    if (isInitHiddenPresence(presence)) return false
    return !isInitAnonymousPresenceUserId(getInitPresencePublicId(presence))
  })
  return filterInitRaffleParticipants(mapPresencesToOnlineUsers(eligible, allowlist))
}
