/**
 * Downgrade compliance runs against `total`, so every page must be fetched.
 */

import { describe, expect, test } from 'bun:test'
import { fetchAllPages } from '@/lib/billing/fetch-project-downgrade-resources'

function item(id: string) {
  return { $id: id }
}

function pagesOf(pages: { $id: string }[][], total: number) {
  const requested: number[] = []
  const load = async (page: number) => {
    requested.push(page)
    return { items: pages[page] ?? [], total }
  }
  return { requested, load }
}

describe('fetchAllPages', () => {
  test('stops after one call when the first page holds everything', async () => {
    const { requested, load } = pagesOf([[item('a'), item('b')]], 2)

    const result = await fetchAllPages(load)

    expect(result).toEqual({ items: [item('a'), item('b')], total: 2 })
    expect(requested).toEqual([0])
  })

  test('accumulates every page until the total is reached', async () => {
    const { requested, load } = pagesOf(
      [[item('a'), item('b')], [item('c'), item('d')], [item('e')]],
      5,
    )

    const result = await fetchAllPages(load)

    expect(result.items.map((entry) => entry.$id)).toEqual([
      'a',
      'b',
      'c',
      'd',
      'e',
    ])
    expect(result.total).toBe(5)
    expect(requested).toEqual([0, 1, 2])
  })

  test('gives up when a page comes back empty but the total claims more', async () => {
    const { requested, load } = pagesOf([[item('a')], []], 10)

    const result = await fetchAllPages(load)

    expect(result).toEqual({ items: [item('a')], total: 10 })
    expect(requested).toEqual([0, 1])
  })

  test('stops when more items arrive than the total reports', async () => {
    const { requested, load } = pagesOf([[item('a'), item('b')]], 1)

    const result = await fetchAllPages(load)

    expect(result).toEqual({ items: [item('a'), item('b')], total: 1 })
    expect(requested).toEqual([0])
  })

  test('handles an empty first page', async () => {
    const { requested, load } = pagesOf([[]], 0)

    const result = await fetchAllPages(load)

    expect(result).toEqual({ items: [], total: 0 })
    expect(requested).toEqual([0])
  })

  test('passes the page size through to the loader', async () => {
    const limits: number[] = []

    await fetchAllPages(async (_page, limit) => {
      limits.push(limit)
      return { items: [item('a')], total: 1 }
    })

    // The size itself is a tuning constant; what matters is that a single
    // consistent page size is requested rather than an unbounded one.
    expect(limits).toHaveLength(1)
    expect(limits[0]).toBeGreaterThan(0)
  })
})
