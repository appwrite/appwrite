import { describe, expect, test } from 'bun:test'
import {
  fromBytes,
  suggestThreshold,
  thresholdRatio,
  toBytes,
} from '@/lib/alerts/metrics'
import { fillBuckets, firings, rolling, seriesPlan } from '@/lib/alerts/series'
import {
  emptyRuleForm,
  ruleInputFromForm,
} from '@/components/pages/projects/$projectId/usage/alerts/RuleFields'

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

describe('alert rule history', () => {
  const at = (minutes: number[]) =>
    minutes.map((value, index) => ({ time: index * 60_000, value }))

  test('sums a counter over the window ending at each bucket', () => {
    expect(rolling(at([1, 2, 3, 4, 5]), 3).map((point) => point.value)).toEqual(
      [6, 9, 12],
    )
    expect(rolling(at([1, 2]), 1).map((point) => point.value)).toEqual([1, 2])
  })

  test('counts each breach once, from the bucket it starts', () => {
    const series = at([1, 6, 7, 2, 9, 9, 1])
    expect(firings(series, 'greaterThan', 5)).toEqual([60_000, 240_000])
    expect(firings(series, 'lessThanEqual', 1)).toEqual([0, 360_000])
    expect(firings(series, 'greaterThan', 100)).toEqual([])
  })

  test('fills missing counter buckets with zero and carries a gauge forward', () => {
    const samples = [
      { time: 0, value: 4 },
      { time: 30_000, value: 1 },
      { time: 180_000, value: 7 },
    ]
    expect(
      fillBuckets(samples, 0, 240_000, 60, true).map((point) => point.value),
    ).toEqual([5, 0, 0, 7, 0])
    expect(
      fillBuckets(samples, 0, 240_000, 60, false).map((point) => point.value),
    ).toEqual([5, 5, 5, 7, 7])
  })

  test('reads a window in buckets that divide it', () => {
    expect(seriesPlan(300, true)).toMatchObject({ interval: '1m', span: 5 })
    expect(seriesPlan(3600, true)).toMatchObject({ interval: '15m', span: 4 })
    expect(seriesPlan(86400, true)).toMatchObject({ interval: '1h', span: 24 })
    expect(seriesPlan(604800, true)).toMatchObject({ interval: '1d', span: 7 })
    expect(seriesPlan(3600, false)).toMatchObject({ interval: '1h', span: 1 })
  })
})

describe('alert rule form', () => {
  const t = (text: string) => text

  test('leaves the webhook URL out unless the webhook channel is on', () => {
    const form = { ...emptyRuleForm(), webhookUrl: 'https://example.com' }
    expect(ruleInputFromForm(form, t).webhookUrl).toBeUndefined()
    expect(
      ruleInputFromForm({ ...form, channels: ['webhook'] }, t).webhookUrl,
    ).toBe('https://example.com')
  })

  test('names an unnamed rule after its condition', () => {
    expect(ruleInputFromForm(emptyRuleForm(), t).name).toBe(
      'Outbound bandwidth > 10 GB',
    )
  })
})

describe('alert threshold suggestions', () => {
  test('leave room over what the project already does', () => {
    expect(suggestThreshold(14_000)).toBe(30_000)
    expect(suggestThreshold(2_140)).toBe(5_000)
    expect(suggestThreshold(1_200_000)).toBe(3_000_000)
    expect(suggestThreshold(5)).toBe(10)
    expect(suggestThreshold(0)).toBe(0)
  })
})

describe('alert threshold ratio', () => {
  test('is said only for an upper threshold crossed at least twice over', () => {
    const rule = { comparator: 'greaterThan' as const, threshold: 1000 }
    expect(thresholdRatio({ ...rule, value: 12_000 })).toBe('12×')
    expect(thresholdRatio({ ...rule, value: 2_500 })).toBe('2.5×')
    expect(thresholdRatio({ ...rule, value: 1_500 })).toBeNull()
    expect(
      thresholdRatio({ ...rule, comparator: 'lessThan', value: 12_000 }),
    ).toBeNull()
    expect(thresholdRatio({ ...rule, value: null })).toBeNull()
  })
})
