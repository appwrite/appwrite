import { describe, expect, test } from 'bun:test'
import { suggestProjectRegion } from '@/lib/project-region-suggestion'

const LIVE = ['fra', 'nyc', 'sfo', 'sgp', 'syd', 'tor'] as const

function suggest(
  countryCode: string | null,
  timeZone?: string | null,
  available: readonly string[] = LIVE,
  continentCode?: string | null,
) {
  return suggestProjectRegion({
    countryCode,
    timeZone,
    availableRegionIds: available,
    continentCode,
  })
}

describe('suggestProjectRegion', () => {
  test('returns null when no regions are available', () => {
    expect(suggest('US', 'America/New_York', [])).toBeNull()
  })

  test('keeps Canadians in Toronto instead of New York', () => {
    expect(suggest('CA')).toBe('tor')
    expect(suggest('CA', 'America/Toronto')).toBe('tor')
    expect(suggest('CA', 'America/New_York')).toBe('tor')
    expect(suggest('ca', 'America/Vancouver')).toBe('tor')
  })

  test('keeps US visitors in a US region, never Toronto', () => {
    expect(suggest('US')).toBe('nyc')
    expect(suggest('US', 'America/New_York')).toBe('nyc')
    expect(suggest('US', 'America/Chicago')).toBe('nyc')
    expect(suggest('US', 'America/Toronto')).toBe('nyc')
    expect(suggest('us', 'America/Los_Angeles')).toBe('sfo')
    expect(suggest('US', 'America/Denver')).toBe('sfo')
    expect(suggest('US', 'America/Phoenix')).toBe('sfo')
    expect(suggest('US', 'Pacific/Honolulu')).toBe('sfo')
  })

  test('maps each region-host country to its own region', () => {
    expect(suggest('DE')).toBe('fra')
    expect(suggest('SG')).toBe('sgp')
    expect(suggest('AU')).toBe('syd')
  })

  test('uses geographic catchments when the country has no region', () => {
    expect(suggest('FR')).toBe('fra')
    expect(suggest('NG')).toBe('fra')
    expect(suggest('AE')).toBe('fra')
    expect(suggest('ZA')).toBe('fra')
    expect(suggest('IN')).toBe('sgp')
    expect(suggest('JP')).toBe('sgp')
    expect(suggest('BR')).toBe('nyc')
    expect(suggest('AR')).toBe('nyc')
    expect(suggest('PR')).toBe('nyc')
    expect(suggest('NZ')).toBe('syd')
  })

  test('defaults Mexico west and flips the Caribbean coast to New York', () => {
    expect(suggest('MX')).toBe('sfo')
    expect(suggest('MX', 'America/Mexico_City')).toBe('sfo')
    expect(suggest('MX', 'America/Cancun')).toBe('nyc')
  })

  test('prefers a not-yet-live local region when it is actually available', () => {
    expect(suggest('GB', null, [...LIVE, 'lon'])).toBe('lon')
    expect(suggest('GB')).toBe('fra')
    expect(suggest('IN', null, [...LIVE, 'blr'])).toBe('blr')
    expect(suggest('NL', null, [...LIVE, 'ams'])).toBe('ams')
  })

  test('falls through when the preferred region is missing', () => {
    expect(suggest('CA', null, ['nyc', 'fra'])).toBe('nyc')
    expect(suggest('US', 'America/Los_Angeles', ['nyc', 'fra'])).toBe('nyc')
    expect(suggest('IN', null, ['fra', 'nyc'])).toBe('fra')
  })

  test('uses continent when country is unknown', () => {
    expect(suggest(null, null, LIVE, 'EU')).toBe('fra')
    expect(suggest(null, null, LIVE, 'AS')).toBe('sgp')
    expect(suggest(null, null, LIVE, 'NA')).toBe('nyc')
    expect(suggest(null, null, LIVE, 'OC')).toBe('syd')
  })

  test('uses timezone when country and continent are unknown', () => {
    expect(suggest(null, 'Europe/Paris')).toBe('fra')
    expect(suggest(null, 'America/Toronto')).toBe('tor')
    expect(suggest(null, 'America/New_York')).toBe('nyc')
    expect(suggest(null, 'America/Los_Angeles')).toBe('sfo')
    expect(suggest(null, 'Asia/Tokyo')).toBe('sgp')
    expect(suggest(null, 'Australia/Sydney')).toBe('syd')
    expect(suggest(null, 'Asia/Dubai')).toBe('fra')
  })

  test('falls back to the live catalog order when location is unknown', () => {
    expect(suggest(null, null, ['syd', 'sgp'])).toBe('sgp')
    expect(suggest(null, null, ['ams'])).toBe('ams')
  })

  test('country wins over a conflicting timezone or continent', () => {
    expect(suggest('IN', 'America/New_York', LIVE, 'NA')).toBe('sgp')
    expect(suggest('CA', 'America/Los_Angeles', LIVE, 'NA')).toBe('tor')
  })
})
