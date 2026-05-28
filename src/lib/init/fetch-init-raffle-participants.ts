import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import { listInitPresences, mapPresencesToOnlineUsers } from '@/lib/init/presence'
import type { LaunchEvent, LaunchEventOnlineUser } from '@/lib/init/types'

const RAFFLE_PARTICIPANT_LIMIT = 200

export async function fetchInitRaffleParticipants(
  event: LaunchEvent,
): Promise<LaunchEventOnlineUser[]> {
  const allowlist = buildInitPresenceActivityAllowlist(event)
  const presences = await listInitPresences(event.id, 'online', RAFFLE_PARTICIPANT_LIMIT)
  return mapPresencesToOnlineUsers(presences, allowlist)
}
