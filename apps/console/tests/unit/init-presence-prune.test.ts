import { describe, expect, it } from 'bun:test'
import { pruneExpiredPresenceMaps } from '@/lib/init/presence'

type PresenceMaps = Parameters<typeof pruneExpiredPresenceMaps>[0]
type PresenceRecord =
  PresenceMaps['online'] extends Map<string, infer R> ? R : never

const inMinutes = (minutes: number) =>
  new Date(Date.now() + minutes * 60_000).toISOString()

const presence = (id: string, expiresAt?: string) =>
  ({ $id: id, expiresAt }) as unknown as PresenceRecord

describe('pruneExpiredPresenceMaps', () => {
  it('returns the same maps when nothing expired', () => {
    const maps: PresenceMaps = {
      online: new Map([['user-1', presence('user-1', inMinutes(5))]]),
      away: new Map([['user-2', presence('user-2')]]),
    }

    expect(pruneExpiredPresenceMaps(maps)).toBe(maps)
  })

  it('drops expired presences from both lists', () => {
    const maps: PresenceMaps = {
      online: new Map([
        ['user-1', presence('user-1', inMinutes(5))],
        ['user-2', presence('user-2', inMinutes(-1))],
      ]),
      away: new Map([['user-3', presence('user-3', inMinutes(-1))]]),
    }

    const pruned = pruneExpiredPresenceMaps(maps)

    expect(pruned).not.toBe(maps)
    expect([...pruned.online.keys()]).toEqual(['user-1'])
    expect(pruned.away.size).toBe(0)
    expect(maps.online.size).toBe(2)
  })
})
