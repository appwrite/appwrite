import { describe, expect, test } from 'bun:test'
import { AnalyticsPropertyType, type Models } from '@appwrite.io/console'
import { propertyType } from '@/lib/analytics-wizard/property-types'

const withType = (type: unknown) =>
  ({ type }) as Pick<Models.AnalyticsProperty, 'type'>

describe('analytics property type', () => {
  test('keeps a known type', () => {
    expect(propertyType(withType('mobile'))).toBe(AnalyticsPropertyType.Mobile)
    expect(propertyType(withType('agent'))).toBe(AnalyticsPropertyType.Agent)
  })

  test('falls back to web when the API has not returned a type yet', () => {
    expect(propertyType(withType(undefined))).toBe(AnalyticsPropertyType.Web)
    expect(propertyType(undefined)).toBe(AnalyticsPropertyType.Web)
  })

  test('falls back to web for a type this build does not know', () => {
    expect(propertyType(withType('server'))).toBe(AnalyticsPropertyType.Web)
  })
})
