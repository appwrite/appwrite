import { describe, expect, test } from 'bun:test'
import { formatCompactCount } from '@/lib/usage/format-metric'

describe('formatCompactCount', () => {
  test('keeps one decimal by default', () => {
    expect(formatCompactCount(1_750_000)).toBe('1.8M')
    expect(formatCompactCount(12_500)).toBe('12.5K')
    expect(formatCompactCount(2_000_000)).toBe('2M')
  })

  test('keeps a second decimal only when it is not zero', () => {
    expect(formatCompactCount(1_750_000, { decimals: 2 })).toBe('1.75M')
    expect(formatCompactCount(1_500_000, { decimals: 2 })).toBe('1.5M')
    expect(formatCompactCount(2_000_000, { decimals: 2 })).toBe('2M')
    expect(formatCompactCount(500_000, { decimals: 2 })).toBe('500K')
    expect(formatCompactCount(1_250, { decimals: 2 })).toBe('1.25K')
  })
})
