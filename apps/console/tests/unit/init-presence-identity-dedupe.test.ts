import { describe, expect, it } from 'bun:test'
import {
  dedupeInitSelfOnlineUsers,
  dedupeInitSelfPresenceRecords,
} from '@/lib/init/presence'
import type { LaunchEventOnlineUser } from '@/lib/init/types'

describe('dedupeInitSelfOnlineUsers', () => {
  it('keeps only the current presence id when self appears twice', () => {
    const users: LaunchEventOnlineUser[] = [
      {
        id: 'user-1',
        ownerId: 'user-1',
        name: 'Visible self',
        activity: 'On Init',
      },
      {
        id: 'anon_abc123',
        identityHidden: true,
        name: 'Guest name',
        activity: 'On Init',
      },
    ]

    const deduped = dedupeInitSelfOnlineUsers(users, 'user-1', 'anon_abc123')

    expect(deduped).toHaveLength(1)
    expect(deduped[0]?.id).toBe('anon_abc123')
  })
})

describe('dedupeInitSelfPresenceRecords', () => {
  it('drops the stale real-id row when the anonymous row is active', () => {
    const presences = [
      {
        $id: 'user-1',
        userId: 'user-1',
        status: 'init:event',
        metadata: { eventId: 'event', name: 'Visible self' },
      },
      {
        $id: 'anon_abc123',
        userId: 'anon_abc123',
        status: 'init:event',
        metadata: { eventId: 'event', name: 'Guest name' },
      },
    ]

    const deduped = dedupeInitSelfPresenceRecords(
      presences as never,
      'user-1',
      'anon_abc123',
    )

    expect(deduped).toHaveLength(1)
    expect(deduped[0]?.$id).toBe('anon_abc123')
  })
})
