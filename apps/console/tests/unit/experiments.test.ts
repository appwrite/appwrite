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
  test('lists Appwrite Analytics and project environments', () => {
    expect(EXPERIMENTS.map((experiment) => experiment.flag)).toEqual([
      'analytics',
      'projectEnvironments',
    ])
    expect(EXPERIMENTS[0]?.name).toBe('Appwrite Analytics')
    expect(EXPERIMENTS[1]?.name).toBe('Project environments')
    for (const experiment of EXPERIMENTS) {
      expect(experiment.description.length).toBeGreaterThan(0)
    }
  })
})
