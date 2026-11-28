import { describe, expect, test } from 'bun:test'
import {
  DEFAULT_RUNTIME_LOG_FILTERS,
  normalizeRuntimeLogLevels,
  runtimeLogFiltersFromSearch,
  runtimeLogsSearchFromFilters,
  runtimeLogsSearchSchema,
} from '@/lib/runtime-log-filters'

describe('runtime log filters', () => {
  test('read the defaults from an empty URL', () => {
    expect(runtimeLogFiltersFromSearch({})).toEqual({
      ...DEFAULT_RUNTIME_LOG_FILTERS,
      window: undefined,
    })
  })

  test('round-trip through search params', () => {
    const filters = {
      resource: { type: 'functions' as const, id: 'checkout' },
      stream: 'stderr' as const,
      levels: normalizeRuntimeLogLevels(['error', 'warn']),
      range: '6h' as const,
      window: {
        from: '2026-11-27T10:00:00.000Z',
        to: '2026-11-27T10:15:00.000Z',
      },
      search: 'timeout',
    }
    const search = runtimeLogsSearchFromFilters(filters)
    expect(search).toEqual({
      resource: 'functions:checkout',
      stream: 'stderr',
      level: 'warn,error',
      range: '6h',
      from: '2026-11-27T10:00:00.000Z',
      to: '2026-11-27T10:15:00.000Z',
      q: 'timeout',
    })
    expect(runtimeLogFiltersFromSearch(search)).toEqual(filters)
  })

  test('leave defaults out of the URL', () => {
    expect(runtimeLogsSearchFromFilters(DEFAULT_RUNTIME_LOG_FILTERS)).toEqual({
      resource: undefined,
      stream: undefined,
      level: undefined,
      range: undefined,
      from: undefined,
      to: undefined,
      q: undefined,
    })
  })

  test('ignore values the viewer does not know', () => {
    const search = runtimeLogsSearchSchema.parse({
      resource: 'buckets:photos',
      stream: 'stdin',
      level: 'fatal,error',
      range: '7d',
      from: 'yesterday',
      to: '2026-11-27T10:00:00.000Z',
    })
    const filters = runtimeLogFiltersFromSearch(search)
    expect(filters.resource).toEqual({ type: null })
    expect(filters.stream).toBe('all')
    expect(filters.levels).toEqual(['error'])
    expect(filters.range).toBe('1h')
    expect(filters.window).toBeUndefined()
  })

  test('treat every level as no level filter', () => {
    expect(
      normalizeRuntimeLogLevels(['debug', 'info', 'warn', 'error']),
    ).toEqual([])
  })

  test('read a whole kind of resource', () => {
    expect(
      runtimeLogFiltersFromSearch({ resource: 'databases' }).resource,
    ).toEqual({ type: 'databases' })
  })
})
