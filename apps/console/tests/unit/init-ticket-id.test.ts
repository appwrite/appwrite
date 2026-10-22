import { afterEach, describe, expect, test } from 'bun:test'
import { initTicketStorageFileExists } from '@/lib/init/init-ticket-share'
import {
  getInitTicketStorageFileViewUrl,
  isInitTicketId,
} from '@/lib/init/init-ticket-storage-config'

const realFetch = globalThis.fetch

afterEach(() => {
  globalThis.fetch = realFetch
})

describe('isInitTicketId', () => {
  test.each(['6612ab', 'ticket_1.png-v2', 'a'.repeat(36)])(
    'accepts %s',
    (id) => {
      expect(isInitTicketId(id)).toBe(true)
    },
  )

  test.each([
    '',
    'a'.repeat(37),
    '.hidden',
    '-dash',
    '_under',
    'a/b',
    '..%2F',
    'a?b',
    'a b',
    'a"b',
  ])('rejects %j', (id) => {
    expect(isInitTicketId(id)).toBe(false)
  })
})

describe('init ticket storage URLs', () => {
  test('encode the file id as one path segment', () => {
    const url = new URL(getInitTicketStorageFileViewUrl('a/b'))
    expect(url.pathname.endsWith('/files/a%2Fb/view')).toBe(true)
  })

  test('do not request storage for ids outside the Appwrite id format', async () => {
    const requested: string[] = []
    globalThis.fetch = (async (input: unknown) => {
      requested.push(String(input))
      return new Response(null, { status: 200 })
    }) as typeof fetch

    expect(await initTicketStorageFileExists('../other')).toBe(false)
    expect(requested).toHaveLength(0)

    expect(await initTicketStorageFileExists('ticket1')).toBe(true)
    expect(requested[0]).toContain('/files/ticket1?')
  })
})
