import { afterAll, beforeAll, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@/lib/appwrite/sdk'

describe('project domains list', () => {
  const requests: Record<string, unknown>[] = []

  beforeAll(() => {
    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forProject: () => ({
          proxy: {
            listRules: async (params: Record<string, unknown>) => {
              requests.push(params)
              return { rules: [], total: 30 }
            },
          },
        }),
      },
    }))
  })

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => actualSdk)
  })

  async function listQueries(page: number, limit: number, search?: string) {
    const { fetchProjectDomains } = await import(
      '@/lib/react-query/hooks/project-domains'
    )
    requests.length = 0
    const result = await fetchProjectDomains(
      'project',
      'fra',
      page,
      limit,
      search,
    )
    expect(result.total).toBe(30)
    expect(requests).toHaveLength(1)
    return (requests[0].queries as string[]).map((query) => JSON.parse(query))
  }

  test('pages on the server instead of loading only the first rules', async () => {
    const queries = await listQueries(1, 25)

    expect(queries).toContainEqual({ method: 'limit', values: [25] })
    expect(queries).toContainEqual({ method: 'offset', values: [25] })
    expect(queries.some((query) => query.method === 'or')).toBe(false)
  })

  test('searches every rule on the server by domain or ID', async () => {
    const queries = await listQueries(0, 10, '  Example.com ')

    expect(queries).toContainEqual({ method: 'offset', values: [0] })
    const search = queries.find((query) => query.method === 'or')
    expect(search.values).toEqual([
      { method: 'contains', attribute: 'domain', values: ['example.com'] },
      { method: 'startsWith', attribute: '$id', values: ['Example.com'] },
    ])
  })
})
