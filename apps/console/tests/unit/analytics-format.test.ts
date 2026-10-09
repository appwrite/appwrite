import { describe, expect, test } from 'bun:test'
import {
  formatExactNumber,
  formatNumber,
  isCompactNumber,
} from '@/components/pages/projects/$projectId/analytics/_components/format'

describe('analytics number format', () => {
  test('abbreviates thousands and millions', () => {
    expect(formatNumber(999)).toBe('999')
    expect(formatNumber(1000)).toBe('1.0K')
    expect(formatNumber(1234)).toBe('1.2K')
    expect(formatNumber(1_000_000)).toBe('1.0M')
  })

  test('isCompactNumber matches the K/M threshold', () => {
    expect(isCompactNumber(999)).toBe(false)
    expect(isCompactNumber(1000)).toBe(true)
    expect(isCompactNumber(undefined)).toBe(false)
  })

  test('formatExactNumber keeps the full integer', () => {
    expect(formatExactNumber(1234).replace(/\D/g, '')).toBe('1234')
    expect(formatExactNumber(1234)).not.toBe(formatNumber(1234))
  })
})
