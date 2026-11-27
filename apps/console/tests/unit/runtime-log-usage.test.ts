import { describe, expect, test } from 'bun:test'
import {
  fillRuntimeLogVolume,
  runtimeLogUsageInterval,
} from '@/lib/runtime-log-usage'

const HOUR = 60 * 60_000

describe('runtime log usage', () => {
  test('picks a bucket width for the span', () => {
    expect(runtimeLogUsageInterval(15 * 60_000)).toBe('1m')
    expect(runtimeLogUsageInterval(2 * HOUR)).toBe('1m')
    expect(runtimeLogUsageInterval(6 * HOUR)).toBe('15m')
    expect(runtimeLogUsageInterval(24 * HOUR)).toBe('15m')
    expect(runtimeLogUsageInterval(3 * 24 * HOUR)).toBe('1h')
    expect(runtimeLogUsageInterval(30 * 24 * HOUR)).toBe('1d')
    expect(runtimeLogUsageInterval(null)).toBe('1h')
  })

  test('fills empty buckets between the bounds and splits stderr out', () => {
    const points = fillRuntimeLogVolume(
      [
        { date: '2026-11-24T10:01:00.000+00:00', total: 5, stderr: 2 },
        { date: '2026-11-24T10:03:00.000+00:00', total: 1, stderr: 0 },
      ],
      new Date('2026-11-24T10:00:30Z'),
      new Date('2026-11-24T10:04:10Z'),
      '1m',
    )
    expect(points.map((p) => [p.date, p.stdout, p.stderr, p.total])).toEqual([
      ['2026-11-24T10:00:00.000Z', 0, 0, 0],
      ['2026-11-24T10:01:00.000Z', 3, 2, 5],
      ['2026-11-24T10:02:00.000Z', 0, 0, 0],
      ['2026-11-24T10:03:00.000Z', 1, 0, 1],
      ['2026-11-24T10:04:00.000Z', 0, 0, 0],
    ])
    expect(points[1].day).toBeInstanceOf(Date)
  })

  test('starts at the first bucket when the window has no start', () => {
    const points = fillRuntimeLogVolume(
      [{ date: '2026-11-24T08:00:00.000Z', total: 4, stderr: 4 }],
      null,
      new Date('2026-11-24T10:30:00Z'),
      '1h',
    )
    expect(points.map((p) => p.date)).toEqual([
      '2026-11-24T08:00:00.000Z',
      '2026-11-24T09:00:00.000Z',
      '2026-11-24T10:00:00.000Z',
    ])
    expect(points[0].stdout).toBe(0)
  })

  test('shows the last day when the window has no start and no lines', () => {
    const points = fillRuntimeLogVolume(
      [],
      null,
      new Date('2026-11-24T10:30:00Z'),
      '1h',
    )
    expect(points).toHaveLength(25)
    expect(points.every((p) => p.total === 0)).toBe(true)
  })
})
