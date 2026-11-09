import { afterAll, describe, expect, mock, test } from 'bun:test'
import { Query } from '@appwrite.io/console'
import * as actualSdk from '@/lib/appwrite/sdk'
import * as actualProfiles from '@/lib/console-profiles'

// Copy the real modules now: the namespace imports are live and point at the mocks once set.
const realSdk = { ...actualSdk }
const realProfiles = { ...actualProfiles }

afterAll(() => {
  mock.module('@/lib/appwrite/sdk', () => realSdk)
  mock.module('@/lib/console-profiles', () => realProfiles)
})

/**
 * Serves organizations 100 at a time after the given cursor, the way the API
 * pages them. Drafts are counted in the total but left out of the page.
 */
function mockOrganizations(count: number, drafts: number[] = []) {
  const all = Array.from({ length: count }, (_, i) => ({
    $id: `org${i}`,
    name: `Org ${i}`,
  }))
  const calls: string[][] = []
  mock.module('@/lib/console-profiles', () => ({
    ...realProfiles,
    getActiveProfileFeatures: () => ({
      ...realProfiles.getActiveProfileFeatures(),
      billing: true,
    }),
  }))
  mock.module('@/lib/appwrite/sdk', () => ({
    ...realSdk,
    sdk: {
      forConsole: {
        organizations: {
          list: async ({ queries }: { queries: string[] }) => {
            calls.push(queries)
            const cursor = queries
              .map((query) => JSON.parse(query))
              .find((query) => query.method === 'cursorAfter')?.values[0]
            const start = cursor
              ? all.findIndex((org) => org.$id === cursor) + 1
              : 0
            const teams = all
              .slice(start, start + 100)
              .filter((_, i) => !drafts.includes(start + i))
            return { total: count, teams }
          },
        },
      },
    },
  }))
  return calls
}

describe('fetchOrganizations', () => {
  test('returns every organization past the first page', async () => {
    const calls = mockOrganizations(130)
    const { fetchOrganizations } = await import(
      '@/lib/react-query/hooks/organizations'
    )

    const response = await fetchOrganizations()

    expect(response.teams).toHaveLength(130)
    expect(response.teams.at(-1)?.$id).toBe('org129')
    expect(calls).toHaveLength(2)
    expect(calls[1]).toContain(Query.cursorAfter('org99'))
  })

  test('asks once when one page holds them all', async () => {
    const calls = mockOrganizations(3)
    const { fetchOrganizations } = await import(
      '@/lib/react-query/hooks/organizations'
    )

    const response = await fetchOrganizations()

    expect(response.teams.map((org) => org.$id)).toEqual([
      'org0',
      'org1',
      'org2',
    ])
    expect(calls).toHaveLength(1)
  })

  test('keeps paging past a draft the total still counts', async () => {
    mockOrganizations(130, [5])
    const { fetchOrganizations } = await import(
      '@/lib/react-query/hooks/organizations'
    )

    const response = await fetchOrganizations()

    expect(response.teams).toHaveLength(129)
    expect(response.teams.at(-1)?.$id).toBe('org129')
  })
})
