import { describe, expect, test } from 'bun:test'
import {
  DEFAULT_START_BANDWIDTH,
  startBandwidth,
} from '@/lib/videos/start-bandwidth'

describe('startBandwidth', () => {
  test('starts from the last measurement in this browser', () => {
    expect(startBandwidth(12_000_000, 3)).toBe(12_000_000)
  })

  test('falls back to the connection downlink, in Mbps', () => {
    expect(startBandwidth(null, 8)).toBe(8_000_000)
  })

  test('assumes a broadband line when nothing is known', () => {
    expect(startBandwidth(null, null)).toBe(DEFAULT_START_BANDWIDTH)
  })

  test('ignores implausible values', () => {
    expect(startBandwidth(10, 0)).toBe(DEFAULT_START_BANDWIDTH)
    expect(startBandwidth(Number.NaN, 2_000_000)).toBe(DEFAULT_START_BANDWIDTH)
  })
})
