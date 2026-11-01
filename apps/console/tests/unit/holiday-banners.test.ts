import { describe, expect, test } from 'bun:test'
import {
  CHRISTMAS_BANNER_ID,
  FOURTH_OF_JULY_BANNER_ID,
  getConsoleBannerById,
  HANUKKAH_BANNER_ID,
} from '@/lib/console-banners/catalog'
import { isConsoleBannerVisible } from '@/lib/console-banners/visibility'

const christmasBanner = getConsoleBannerById(CHRISTMAS_BANNER_ID)!
const hanukkahBanner = getConsoleBannerById(HANUKKAH_BANNER_ID)!
const fourthOfJulyBanner = getConsoleBannerById(FOURTH_OF_JULY_BANNER_ID)!

describe('Christmas banner', () => {
  test.each([
    ['2026-12-19T00:00:00.000Z', true],
    ['2026-12-25T23:59:59.999Z', true],
    ['2026-12-18T23:59:59.999Z', false],
    ['2026-12-26T00:00:00.000Z', false],
  ])('%s → %p', (iso, expected) => {
    expect(
      isConsoleBannerVisible(christmasBanner, {
        now: new Date(iso),
        cloud: true,
      }),
    ).toBe(expected)
  })
})

describe('Fourth of July banner', () => {
  test.each([
    ['2027-06-28T00:00:00.000Z', true],
    ['2027-07-04T23:59:59.999Z', true],
    ['2027-06-27T23:59:59.999Z', false],
    ['2027-07-05T00:00:00.000Z', false],
  ])('%s → %p', (iso, expected) => {
    expect(
      isConsoleBannerVisible(fourthOfJulyBanner, {
        now: new Date(iso),
        cloud: true,
        country: 'US',
      }),
    ).toBe(expected)
  })
})

describe('Hanukkah banner', () => {
  test.each([
    ['2026-12-04T00:00:00.000Z', true],
    ['2026-12-12T23:59:59.999Z', true],
    ['2026-12-03T23:59:59.999Z', false],
    ['2026-12-13T00:00:00.000Z', false],
  ])('%s → %p', (iso, expected) => {
    expect(
      isConsoleBannerVisible(hanukkahBanner, {
        now: new Date(iso),
        cloud: true,
        country: 'IL',
      }),
    ).toBe(expected)
  })

  test('hides outside Israel', () => {
    expect(
      isConsoleBannerVisible(hanukkahBanner, {
        now: new Date('2026-12-08T12:00:00.000Z'),
        cloud: true,
        country: 'US',
      }),
    ).toBe(false)
  })
})
