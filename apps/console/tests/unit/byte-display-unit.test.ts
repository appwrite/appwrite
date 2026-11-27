import { describe, expect, test } from 'bun:test'
import { formatBinaryBytesProgress } from '@/lib/utils/byte-display-unit'

const MEGABYTE = 1024 * 1024

describe('formatBinaryBytesProgress', () => {
  test.each([
    [10_496_246, 11 * MEGABYTE, '10.01 MB / 11.00 MB'],
    [512 * 1024, 11 * MEGABYTE, '0.50 MB / 11.00 MB'],
    [0, 11 * MEGABYTE, '0.00 MB / 11.00 MB'],
    [11 * MEGABYTE, 11 * MEGABYTE, '11.00 MB / 11.00 MB'],
    [300, 800, '300 B / 800 B'],
    [1536, 2 * 1024 * MEGABYTE, '0.00 GB / 2.00 GB'],
  ])('formats %d of %d bytes as %s', (loaded, total, expected) => {
    expect(formatBinaryBytesProgress(loaded, total)).toBe(expected)
  })

  test('caps the loaded size at the total', () => {
    expect(formatBinaryBytesProgress(12 * MEGABYTE, 11 * MEGABYTE)).toBe(
      '11.00 MB / 11.00 MB',
    )
  })

  test('clamps negative and empty sizes to zero', () => {
    expect(formatBinaryBytesProgress(-5, 0)).toBe('0 B / 0 B')
  })

  test('accepts bigint sizes', () => {
    expect(
      formatBinaryBytesProgress(BigInt(MEGABYTE), BigInt(4 * MEGABYTE)),
    ).toBe('1.00 MB / 4.00 MB')
  })
})
