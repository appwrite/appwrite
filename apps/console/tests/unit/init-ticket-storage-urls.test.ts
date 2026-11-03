import { afterEach, describe, expect, test } from 'bun:test'
import { initTicketStorageFileExists } from '@/lib/init/init-ticket-share'
import { getInitTicketStorageFileViewUrl } from '@/lib/init/init-ticket-storage-config'

const originalFetch = globalThis.fetch

function recordFetches(): string[] {
  const urls: string[] = []
  globalThis.fetch = (async (input: string | URL | Request) => {
    urls.push(String(input))
    return new Response('{}', { status: 200 })
  }) as typeof fetch
  return urls
}

afterEach(() => {
  globalThis.fetch = originalFetch
})

describe('init ticket storage URLs', () => {
  test('looks up a ticket file by its ID', async () => {
    const urls = recordFetches()

    expect(await initTicketStorageFileExists('6650f1c2a9b3d4e5f601')).toBe(true)
    expect(urls).toHaveLength(1)
    expect(new URL(urls[0]).pathname).toEndWith(
      '/storage/buckets/tickets/files/6650f1c2a9b3d4e5f601',
    )
  })

  test.each([
    '../../../users',
    '..%2F..%2Fusers',
    'ticket?project=other',
    'ticket#fragment',
    'ticket/view',
    '"ticket',
    'a'.repeat(37),
  ])('does not request storage for ticket ID %p', async (ticketId) => {
    const urls = recordFetches()

    expect(await initTicketStorageFileExists(ticketId)).toBe(false)
    expect(urls).toHaveLength(0)
  })

  test('encodes the ticket ID in the view URL', () => {
    const url = new URL(getInitTicketStorageFileViewUrl('../../users?x=1'))

    expect(url.pathname).toEndWith(
      '/storage/buckets/tickets/files/..%2F..%2Fusers%3Fx%3D1/view',
    )
    expect(url.searchParams.get('x')).toBeNull()
  })
})
