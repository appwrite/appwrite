import { describe, expect, test } from 'bun:test'
import {
  aggregateInitCommunityCountriesFromUsers,
} from '@/lib/init/presence'
import { getCountryCoordinates } from '@/lib/country-coordinates'

describe('aggregateInitCommunityCountriesFromUsers', () => {
  test('counts normalized country codes and skips unknown', () => {
    const countries = aggregateInitCommunityCountriesFromUsers([
      { countryCode: 'il' },
      { countryCode: 'IL' },
      { countryCode: '--' },
      { countryCode: undefined },
      { countryCode: 'DE' },
    ])

    expect(countries).toEqual([
      { code: 'IL', count: 2 },
      { code: 'DE', count: 1 },
    ])
  })

  test('keeps coordinates for common identity-visible locales', () => {
    for (const code of ['IL', 'GB', 'DE', 'US', 'LI', 'IM', 'XK']) {
      expect(getCountryCoordinates(code)).not.toBeNull()
    }
  })
})
