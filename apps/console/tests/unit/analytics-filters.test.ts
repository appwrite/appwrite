import { describe, expect, test } from 'bun:test'
import { AnalyticsDimension } from '@appwrite.io/console'
import {
  analyticsFilterAttributeForTab,
  analyticsFiltersFromMap,
  equalFilterEntry,
  isAnalyticsFilterShapeSupported,
  sanitizeAnalyticsFilterMap,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import type { FilterMap } from '@/lib/table-filters'

function mapOf(
  ...entries: { key: { c: string; o: string; v: string }; query: string }[]
): FilterMap {
  return new Map(entries.map((entry) => [entry.key, entry.query])) as FilterMap
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
