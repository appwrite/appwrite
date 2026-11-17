import { describe, expect, test } from 'bun:test'
import { withFilter, type CompactFilterKey } from '@/lib/table-filters'

const path: CompactFilterKey = { c: 'path', o: 'equal', v: '/v1/users' }
const method: CompactFilterKey = { c: 'method', o: 'equal', v: 'get' }
const country: CompactFilterKey = { c: 'country', o: 'equal', v: 'US' }

describe('withFilter', () => {
  test('a replaced filter keeps its place', () => {
    const map = new Map([
      [path, 'q-path'],
      [method, 'q-method'],
      [country, 'q-country'],
    ])
    const notGet: CompactFilterKey = { ...method, o: 'notEqual' }

    const next = withFilter(map, notGet, 'q-not-get', method)

    expect(Array.from(next.keys())).toEqual([path, notGet, country])
    expect(next.get(notGet)).toBe('q-not-get')
    expect(map.has(method)).toBe(true)
  })

  test('a new filter goes last', () => {
    const map = new Map([[path, 'q-path']])

    const next = withFilter(map, method, 'q-method')

    expect(Array.from(next.keys())).toEqual([path, method])
  })
})
