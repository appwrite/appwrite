import { describe, expect, test } from 'bun:test'
import { AnalyticsDimension } from '@appwrite.io/console'
import {
  analyticsFilterAttributeForTab,
  analyticsFilterColumns,
  analyticsFiltersFromMap,
  equalFilterEntry,
  isAnalyticsFilterShapeSupported,
  sanitizeAnalyticsFilterMap,
  trafficKindFilterEntry,
  trafficKindOfFilterKey,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import type { CompactFilterKey, FilterMap } from '@/lib/table-filters'

function mapOf(
  ...entries: { key: CompactFilterKey; query: string }[]
): FilterMap {
  return new Map(entries.map((entry) => [entry.key, entry.query]))
}

describe('analytics filter attributes', () => {
  test('keeps prop, user and version filters, drops unknown attributes', () => {
    const sanitized = sanitizeAnalyticsFilterMap(
      mapOf(
        equalFilterEntry('props.plan', 'pro'),
        equalFilterEntry('userId', 'user-a'),
        equalFilterEntry('browserVersion', '141.0'),
        {
          key: { c: 'props.', o: 'equal', v: 'x' },
          query: 'equal("props.", ["x"])',
        },
        {
          key: { c: 'props', o: 'equal', v: 'x' },
          query: 'equal("props", ["x"])',
        },
        {
          key: { c: 'email', o: 'equal', v: 'x' },
          query: 'equal("email", ["x"])',
        },
      ),
    )
    expect(analyticsFiltersFromMap(sanitized).map((f) => f.attribute)).toEqual([
      'props.plan',
      'userId',
      'browserVersion',
    ])
  })

  test('a prop tab filters on props.<key>', () => {
    expect(
      analyticsFilterAttributeForTab({
        dimension: AnalyticsDimension.Props,
        propKey: 'plan',
      }),
    ).toBe('props.plan')
    // The key ranking itself has nothing to filter on.
    expect(
      analyticsFilterAttributeForTab({ dimension: AnalyticsDimension.Props }),
    ).toBeNull()
    expect(
      analyticsFilterAttributeForTab({ dimension: AnalyticsDimension.UserId }),
    ).toBe('userId')
  })
})

describe('analytics filter shapes', () => {
  const only = (attribute: AnalyticsFilter['attribute']): AnalyticsFilter[] => [
    { attribute, query: equalFilterEntry(attribute, 'x').query },
  ]

  test.each(['userId', 'props.plan', 'page', 'eventName'] as const)(
    '%s is events-only',
    (attribute) => {
      const filters = only(attribute)
      expect(isAnalyticsFilterShapeSupported(filters, { kind: 'flat' })).toBe(
        false,
      )
      expect(
        isAnalyticsFilterShapeSupported(filters, {
          kind: 'breakdown',
          dimension: AnalyticsDimension.EntryPage,
        }),
      ).toBe(false)
      expect(isAnalyticsFilterShapeSupported(filters, { kind: 'series' })).toBe(
        true,
      )
      expect(
        isAnalyticsFilterShapeSupported(filters, {
          kind: 'breakdown',
          dimension: AnalyticsDimension.Country,
        }),
      ).toBe(true)
    },
  )

  test.each(['browserVersion', 'operatingSystemVersion', 'hostname'] as const)(
    '%s works on every shape',
    (attribute) => {
      expect(
        isAnalyticsFilterShapeSupported(only(attribute), { kind: 'flat' }),
      ).toBe(true)
    },
  )
})

describe('analytics filter columns', () => {
  test('adds a column titled with the key for each active prop filter', () => {
    const columns = analyticsFilterColumns([
      { attribute: 'props.plan', query: equalFilterEntry('props.plan', 'pro').query },
      { attribute: 'props.plan', query: equalFilterEntry('props.plan', 'team').query },
      { attribute: 'country', query: equalFilterEntry('country', 'NP').query },
    ])
    const props = columns.filter((column) => column.id.startsWith('props.'))
    expect(props.map((column) => [column.id, column.title])).toEqual([
      ['props.plan', 'plan'],
    ])
  })
})

describe('humans / bots filter', () => {
  test('filters on trafficType by its label', () => {
    expect(trafficKindFilterEntry('human')).toEqual({
      key: { c: 'trafficType', o: 'equal', v: 'human' },
      query: equalFilterEntry('trafficType', 'human').query,
    })
    expect(trafficKindFilterEntry('bot').key).toEqual({
      c: 'trafficType',
      o: 'equal',
      v: 'bot',
    })
  })

  test('reads the kind back from trafficType and older botCategory keys', () => {
    expect(trafficKindOfFilterKey({ c: 'trafficType', o: 'equal', v: 'human' })).toBe('human')
    expect(trafficKindOfFilterKey({ c: 'trafficType', o: 'notEqual', v: 'human' })).toBe('bot')
    expect(trafficKindOfFilterKey({ c: 'botCategory', o: 'equal', v: '' })).toBe('human')
    expect(trafficKindOfFilterKey({ c: 'botCategory', o: 'notEqual', v: '' })).toBe('bot')
    expect(trafficKindOfFilterKey({ c: 'trafficType', o: 'equal', v: 'robot' })).toBeNull()
    expect(trafficKindOfFilterKey({ c: 'botCategory', o: 'equal', v: 'datacenter' })).toBeNull()
  })

  test('trafficType keeps only equal and notEqual', () => {
    const sanitized = sanitizeAnalyticsFilterMap(
      mapOf(trafficKindFilterEntry('human'), {
        key: { c: 'trafficType', o: 'contains', v: 'hum' },
        query: 'contains("trafficType", ["hum"])',
      }),
    )
    expect([...sanitized.keys()].map((key) => key.o)).toEqual(['equal'])
    const column = analyticsFilterColumns([]).find((c) => c.id === 'trafficType')
    expect(column?.allowedOperators).toEqual(['equal', 'notEqual'])
  })

  test('the traffic type breakdown filters on trafficType', () => {
    expect(
      analyticsFilterAttributeForTab({ dimension: AnalyticsDimension.TrafficType }),
    ).toBe('trafficType')
  })
})
