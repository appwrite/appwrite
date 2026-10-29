import { describe, expect, test } from 'bun:test'
import {
  formatLength,
  fromBytes,
  suggestThreshold,
  thresholdRatio,
  toBytes,
} from '@/lib/alerts/metrics'
import { fillBuckets, firings, rolling, seriesPlan } from '@/lib/alerts/series'
import {
  MIN_BAR_PERCENT,
  alertLength,
  barWidths,
  groupAlertsByDay,
} from '@/lib/alerts/timeline'
import {
  buildRules,
  parseMonitor,
  parseRules,
  type Monitor,
} from '@/lib/appwrite/monitors'
import {
  emptyRuleForm,
  monitorInputFromForm,
  ruleFormFromMonitor,
} from '@/components/pages/projects/$projectId/usage/monitors/RuleFields'

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
    expect(monitorInputFromForm(form, t).webhookUrl).toBeUndefined()
    expect(
      monitorInputFromForm({ ...form, channels: ['webhook'] }, t).webhookUrl,
    ).toBe('https://example.com')
  })

  test('names an unnamed rule after its condition', () => {
    expect(monitorInputFromForm(emptyRuleForm(), t).name).toBe(
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

describe('monitor rules', () => {
  test('a project-wide monitor holds only its comparison', () => {
    expect(
      buildRules({
        metric: 'bandwidth.outbound',
        comparator: 'greaterThan',
        threshold: 5_000,
        resourceType: 'project',
        resourceId: '',
      }).map((rule) => JSON.parse(rule)),
    ).toEqual([
      {
        method: 'greaterThan',
        attribute: 'bandwidth.outbound',
        values: [5_000],
      },
    ])
  })

  test('a scoped monitor names its resource in the rules', () => {
    const condition = {
      metric: 'files.storage',
      comparator: 'greaterThanEqual',
      threshold: 1e9,
      resourceType: 'bucket',
      resourceId: 'images',
    } as const
    const rules = buildRules(condition)
    expect(rules.map((rule) => JSON.parse(rule))).toEqual([
      { method: 'greaterThanEqual', attribute: 'files.storage', values: [1e9] },
      { method: 'equal', attribute: 'resourceType', values: ['bucket'] },
      { method: 'equal', attribute: 'resourceId', values: ['images'] },
    ])
    expect(parseRules(rules)).toEqual(condition)
  })

  test('reads the comparison whatever order the server returns the rules in', () => {
    expect(
      parseRules([
        '{"method":"equal","attribute":"resourceType","values":["database"]}',
        'not a query',
        '{"method":"lessThan","attribute":"databases.reads","values":[10]}',
      ]),
    ).toEqual({
      metric: 'databases.reads',
      comparator: 'lessThan',
      threshold: 10,
      resourceType: 'database',
      resourceId: '',
    })
  })

  test('rules without a comparison hold no condition', () => {
    expect(
      parseRules([
        '{"method":"equal","attribute":"resourceId","values":["a"]}',
      ]),
    ).toBeNull()
  })

  test('an untouched monitor edits back to the rules it was saved with', () => {
    const t = (text: string) => text
    const input = monitorInputFromForm(
      {
        ...emptyRuleForm(),
        metric: 'databases.storage',
        resourceType: 'database',
        resourceId: 'main',
        threshold: 2,
        byteUnit: 'GB',
      },
      t,
    )
    const monitor = parseMonitor({
      $id: 'm1',
      $createdAt: '',
      $updatedAt: '',
      type: 'usage',
      resourceType: 'database',
      resourceId: 'main',
      window: 3600,
      cooldown: 3600,
      channels: ['email'],
      webhookUrl: '',
      webhookSecret: '',
      enabled: true,
      status: 'ok',
      value: null,
      evaluatedAt: null,
      alertId: '',
      triggeredAt: null,
      ...input,
    } as Monitor)
    expect(monitorInputFromForm(ruleFormFromMonitor(monitor), t).rules).toEqual(
      input.rules,
    )
  })

  test('a gauge sends no window', () => {
    const t = (text: string) => text
    const form = { ...emptyRuleForm(), metric: 'files.storage' as const }
    expect(monitorInputFromForm(form, t).window).toBeUndefined()
    expect(monitorInputFromForm(emptyRuleForm(), t).window).toBe(3600)
  })
})

describe('alert lengths', () => {
  test.each([
    [0, '<1 m'],
    [59, '<1 m'],
    [60, '1 m'],
    [8_040, '2 h 14 m'],
    [90_061, '1 d 1 h'],
  ])('%d seconds read as %s', (seconds, text) => {
    expect(formatLength(seconds)).toBe(text)
  })
})

describe('alerts feed', () => {
  // Local times, so the day boundaries hold in any time zone the tests run in.
  const at = (day: number, hour: number, minute = 0) =>
    new Date(2026, 9, day, hour, minute).toISOString()
  const now = new Date(2026, 9, 29, 12, 0).getTime()
  const alert = (start: string, end: string | null) => ({
    $createdAt: start,
    resolvedAt: end,
  })

  test('groups alerts by the local day they started, newest day first', () => {
    const late = alert(at(29, 9), null)
    const early = alert(at(29, 0, 5), at(29, 1))
    const yesterday = alert(at(28, 23, 55), at(29, 0, 30))
    const older = alert(at(20, 8), at(20, 9))

    const days = groupAlertsByDay([late, early, yesterday, older], now)

    expect(days.map((day) => day.daysAgo)).toEqual([0, 1, 9])
    expect(days[0].alerts).toEqual([late, early])
    // Grouped by when it started, even though it resolved today.
    expect(days[1].alerts).toEqual([yesterday])
    expect(days[2].key).toBe('2026-10-20')
  })

  test('scales bars to the longest alert, ongoing ones running to now', () => {
    const ongoing = alert(at(29, 8), null)
    const hour = alert(at(29, 6), at(29, 7))
    const blip = alert(at(29, 5), at(29, 5))

    const widths = barWidths([ongoing, hour, blip], now)

    expect(alertLength(ongoing, now)).toBe(4 * 3600)
    expect(widths.get(ongoing)).toBe(100)
    expect(widths.get(hour)).toBe(25)
    // A zero-length alert still draws a mark.
    expect(widths.get(blip)).toBe(MIN_BAR_PERCENT)
  })

  test('draws only marks when every alert is instant', () => {
    const instant = alert(at(29, 5), at(29, 5))
    expect(barWidths([instant], now).get(instant)).toBe(MIN_BAR_PERCENT)
  })
})
