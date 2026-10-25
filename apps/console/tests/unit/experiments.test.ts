import { describe, expect, test } from 'bun:test'
import { EXPERIMENTS, hasQualityLabel } from '@/lib/experiments'

describe('hasQualityLabel', () => {
  test('matches only the quality label', () => {
    expect(hasQualityLabel(['quality'])).toBe(true)
    expect(hasQualityLabel(['vip', 'quality'])).toBe(true)
    expect(hasQualityLabel(['Quality'])).toBe(false)
    expect(hasQualityLabel([])).toBe(false)
    expect(hasQualityLabel(undefined)).toBe(false)
    expect(hasQualityLabel(null)).toBe(false)
  })
})

describe('experiments catalog', () => {
  test('starts with Appwrite Analytics', () => {
    expect(EXPERIMENTS.map((experiment) => experiment.flag)).toEqual([
      'analytics',
    ])
    expect(EXPERIMENTS[0]?.name).toBe('Appwrite Analytics')
    expect(EXPERIMENTS[0]?.description.length).toBeGreaterThan(0)
  })
})
