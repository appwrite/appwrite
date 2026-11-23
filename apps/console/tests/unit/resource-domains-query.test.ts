import { afterEach, beforeEach, describe, expect, spyOn, test } from 'bun:test'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchFunctionDomains } from '@/lib/react-query/hooks/functions'
import { fetchSiteDomains } from '@/lib/react-query/hooks/sites'
import { proxyRulesFilterColumns } from '@/lib/table-filters'

describe('function and site domains list', () => {
  const requests: Record<string, unknown>[] = []
  let forProject: ReturnType<typeof spyOn>

  beforeEach(() => {
    requests.length = 0
    forProject = spyOn(sdk, 'forProject').mockReturnValue({
      proxy: {
        listRules: async (params: Record<string, unknown>) => {
          requests.push(params)
          return { rules: [], total: 0 }
        },
      },
    } as unknown as ReturnType<typeof sdk.forProject>)
  })

  afterEach(() => {
    forProject.mockRestore()
  })

  const fetchers = [
    ['function', fetchFunctionDomains],
    ['site', fetchSiteDomains],
  ] as const

  for (const [resource, fetchDomains] of fetchers) {
    test(`${resource} search goes in the queries`, async () => {
      await fetchDomains('project', 'resource', 0, 10, '  Shop.Example ')

      expect(requests).toHaveLength(1)
      expect(requests[0].search).toBeUndefined()
      const queries = (requests[0].queries as string[]).map((query) =>
        JSON.parse(query),
      )
      const search = queries.find((query) => query.method === 'or')
      expect(search.values).toEqual([
        { method: 'contains', attribute: 'domain', values: ['shop.example'] },
        { method: 'startsWith', attribute: '$id', values: ['Shop.Example'] },
      ])
    })

    test(`${resource} without search sends no search query`, async () => {
      await fetchDomains('project', 'resource', 0, 10, '  ')

      const queries = (requests[0].queries as string[]).map((query) =>
        JSON.parse(query),
      )
      expect(queries.some((query) => query.method === 'or')).toBe(false)
    })
  }
})

describe('proxy rules filter columns', () => {
  test('offer no status filter the list endpoint rejects', () => {
    const ids = proxyRulesFilterColumns.map((column) => column.id)
    expect(ids).not.toContain('status')
  })
})
