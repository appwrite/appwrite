import { describe, expect, test } from 'bun:test'
import {
  isHalloween,
  isHalloweenCountry,
  isHalloweenSeason,
} from '@/lib/halloween'

describe('isHalloweenSeason', () => {
  test.each([
    ['2026-10-25T00:00:00', true],
    ['2026-10-31T23:59:00', true],
    ['2026-10-24T23:59:00', false],
    ['2026-11-01T00:00:00', false],
    ['2026-03-31T12:00:00', false],
  ])('%s → %p', (iso, expected) => {
    expect(isHalloweenSeason(new Date(iso))).toBe(expected)
  })
})

describe('isHalloweenCountry', () => {
  test('matches celebrating countries case-insensitively', () => {
    expect(isHalloweenCountry('US')).toBe(true)
    expect(isHalloweenCountry('ie')).toBe(true)
  })

  test('rejects other, unknown, and missing countries', () => {
    expect(isHalloweenCountry('IL')).toBe(false)
    expect(isHalloweenCountry('XX')).toBe(false)
    expect(isHalloweenCountry(null)).toBe(false)
  })
})

describe('isHalloween', () => {
  test('requires both the season and a celebrating country', () => {
    const halloween = new Date('2026-10-31T12:00:00')
    const spring = new Date('2026-04-01T12:00:00')

    expect(isHalloween(halloween, 'GB')).toBe(true)
    expect(isHalloween(halloween, 'IN')).toBe(false)
    expect(isHalloween(spring, 'GB')).toBe(false)
  })
})
