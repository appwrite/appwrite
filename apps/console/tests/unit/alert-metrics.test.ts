import { describe, expect, test } from 'bun:test'
import { fromBytes, toBytes } from '@/lib/alerts/metrics'

describe('alert byte thresholds', () => {
  test.each([5e9, 1e12, 999_999_999, 1_234_567_891, 1_500, 0])(
    'round-trips %d bytes through the edit form',
    (bytes) => {
      const { amount, unit } = fromBytes(bytes)
      expect(toBytes(amount, unit)).toBe(bytes)
    },
  )

  test('shows a whole threshold in its largest unit', () => {
    expect(fromBytes(5e9)).toEqual({ amount: 5, unit: 'GB' })
    expect(fromBytes(2.5e12)).toEqual({ amount: 2.5, unit: 'TB' })
  })
})
