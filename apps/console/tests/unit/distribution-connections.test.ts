import { afterEach, beforeEach, describe, expect, spyOn, test } from 'bun:test'
import type { DistributionConnection } from '@/lib/appwrite/distribution'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchAllDistributionConnections } from '@/lib/react-query/hooks/distribution'

const STORED_CONNECTIONS = 250

type Query = { method: string; attribute?: string; values?: unknown[] }

function connection(index: number): DistributionConnection {
  return {
    $id: `connection${index}`,
    provider: 'apple',
    name: `Team ${index}`,
  } as DistributionConnection
}

describe('fetchAllDistributionConnections', () => {
  const stored = Array.from({ length: STORED_CONNECTIONS }, (_, index) =>
    connection(index),
  )
  const requests: Query[][] = []
  let forProject: ReturnType<typeof spyOn>

  beforeEach(() => {
    requests.length = 0
    forProject = spyOn(sdk, 'forProject').mockReturnValue({
      distribution: {
        listConnections: async ({ queries }: { queries: string[] }) => {
          const parsed = queries.map((query) => JSON.parse(query) as Query)
          requests.push(parsed)
          const value = (method: string) =>
            parsed.find((query) => query.method === method)?.values?.[0]
          const cursor = value('cursorAfter')
          const start = cursor
            ? stored.findIndex((item) => item.$id === cursor) + 1
            : 0
          return {
            total: stored.length,
            connections: stored.slice(start, start + Number(value('limit'))),
          }
        },
      },
    } as unknown as ReturnType<typeof sdk.forProject>)
  })

  afterEach(() => {
    forProject.mockRestore()
  })

  test('returns every connection past the first page', async () => {
    const connections = await fetchAllDistributionConnections(
      'project',
      'apple',
    )

    expect(connections.map((item) => item.$id)).toEqual(
      stored.map((item) => item.$id),
    )
    expect(requests.length).toBeGreaterThan(1)
  })

  test('filters every page by provider', async () => {
    await fetchAllDistributionConnections('project', 'apple')

    for (const queries of requests) {
      expect(queries).toContainEqual({
        method: 'equal',
        attribute: 'provider',
        values: ['apple'],
      })
    }
  })

  test('stops when the server returns an empty page', async () => {
    forProject.mockReturnValue({
      distribution: {
        listConnections: async () => {
          requests.push([])
          return { total: STORED_CONNECTIONS, connections: [] }
        },
      },
    } as unknown as ReturnType<typeof sdk.forProject>)

    expect(await fetchAllDistributionConnections('project', 'apple')).toEqual(
      [],
    )
    expect(requests).toHaveLength(1)
  })
})
