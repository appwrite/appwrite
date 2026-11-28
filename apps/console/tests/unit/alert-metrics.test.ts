import { afterAll, describe, expect, mock, test } from 'bun:test'
import { QueryClient } from '@tanstack/react-query'
import * as actualSdk from '@/lib/appwrite/sdk'
import {
  METRIC_CATEGORIES,
  METRIC_NAMES,
  formatLength,
  formatMetricValue,
  fromBytes,
  metricDefinition,
  suggestThreshold,
  thresholdRatio,
  toBytes,
} from '@/lib/alerts/metrics'
import {
  alertingLimits,
  countResponders,
  defaultProbeInterval,
  monitorCaps,
} from '@/lib/alerts/plan-limits'
import {
  fillBuckets,
  firings,
  growth,
  rolling,
  seriesPlan,
} from '@/lib/alerts/series'
import {
  MIN_BAR_PERCENT,
  alertLength,
  barWidths,
  groupAlertsByDay,
} from '@/lib/alerts/timeline'
import {
  DEFAULT_PROBE_CONDITION,
  buildProbeRules,
  buildRules,
  parseMonitor,
  parseProbeRules,
  parseRules,
  type Monitor,
} from '@/lib/appwrite/monitors'
import {
  budgetRecipients,
  changedBudgetRecipients,
} from '@/lib/appwrite/organization-budget'
import {
  emptyRuleForm,
  monitorInputFromForm,
  ruleFormFromMonitor,
} from '@/components/pages/projects/$projectId/monitors/RuleFields'
import {
  emptyProbeForm,
  probeFormError,
  probeFormFromMonitor,
  probeInputFromForm,
} from '@/components/pages/projects/$projectId/monitors/ProbeFields'
import { alertCondition } from '@/components/pages/projects/$projectId/monitors/alerts/AlertHistory'
import {
  parseHeaders,
  parseStatusCodes,
  smtpTlsMode,
} from '@/lib/alerts/probes'
import type {
  Alert,
  ParsedMonitor,
  ProbeCondition,
  ProbeType,
} from '@/lib/appwrite/monitors'

type ParsedMonitorOf<T extends ProbeType> = ParsedMonitor & { type: T }

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
  test('growth is the rise over the span before each point', () => {
    const points = [3, 5, 9, 9, 12].map((value, index) => ({
      time: index,
      value,
    }))
    expect(growth(points, 2).map((point) => point.value)).toEqual([6, 4, 3])
  })

  test('growth never reads below zero, as the server measures it', () => {
    const points = [9, 7, 4].map((value, index) => ({ time: index, value }))
    expect(growth(points, 1).map((point) => point.value)).toEqual([0, 0])
  })

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

  test('sends the roles to notify and no delivery channels', () => {
    const input = monitorInputFromForm(emptyRuleForm(), t)
    expect(Object.keys(input).sort()).toEqual([
      'cooldown',
      'escalationId',
      'name',
      'notify',
      'rules',
      'severity',
      'window',
    ])
    expect(input.notify).toEqual(['owner'])
    // Empty clears an escalation, so moving a monitor back to roles stops its escalation.
    expect(input.escalationId).toBe('')
    expect(input.severity).toBe('warning')
  })

  test("keeps a monitor's severity through the edit form", () => {
    const input = monitorInputFromForm(
      { ...emptyRuleForm(), severity: 'critical' },
      t,
    )
    expect(input.severity).toBe('critical')
    const monitor = parseMonitor({
      $id: 'm1',
      $createdAt: '',
      $updatedAt: '',
      type: 'usage',
      resourceType: 'project',
      resourceId: '',
      window: 3600,
      cooldown: 3600,
      enabled: true,
      status: 'ok',
      value: null,
      evaluatedAt: null,
      alertId: '',
      triggeredAt: null,
      ...input,
    } as Monitor)
    expect(monitorInputFromForm(ruleFormFromMonitor(monitor), t).severity).toBe(
      'critical',
    )
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
        metric: 'network.outbound',
        comparator: 'greaterThan',
        threshold: 5_000,
        resourceType: 'project',
        resourceId: '',
      }).map((rule) => JSON.parse(rule)),
    ).toEqual([
      {
        method: 'greaterThan',
        attribute: 'network.outbound',
        values: [5_000],
      },
    ])
  })

  test('a scoped monitor names its resource in the rules', () => {
    const condition = {
      metric: 'storage',
      comparator: 'greaterThanEqual',
      threshold: 1e9,
      resourceType: 'bucket',
      resourceId: 'images',
    } as const
    const rules = buildRules(condition)
    expect(rules.map((rule) => JSON.parse(rule))).toEqual([
      { method: 'greaterThanEqual', attribute: 'storage', values: [1e9] },
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
        '{"method":"lessThan","attribute":"databases.operations.reads","values":[10]}',
      ]),
    ).toEqual({
      metric: 'databases.operations.reads',
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

  test('a GB-hours threshold is typed in GB-hours and sent in MB-seconds', () => {
    const t = (text: string) => text
    const form = {
      ...emptyRuleForm(),
      metric: 'executions.mbSeconds' as const,
      resourceType: 'function' as const,
      resourceId: 'resize',
      threshold: 2.5,
    }
    const input = monitorInputFromForm(form, t)
    expect(JSON.parse(input.rules[0])).toEqual({
      method: 'greaterThan',
      attribute: 'executions.mbSeconds',
      values: [9_000_000],
    })
    expect(input.name).toBe('Execution GB-hours > 2.5 GB-hours')
    expect(formatMetricValue('executions.mbSeconds', 9_000_000)).toBe(
      '2.5 GB-hours',
    )
  })

  test('sign-ups are measured over the window', () => {
    const t = (text: string) => text
    const form = { ...emptyRuleForm(), metric: 'users' as const, window: 86400 }
    expect(monitorInputFromForm(form, t).window).toBe(86400)
  })

  test('every metric is offered under a usage page section', () => {
    for (const metric of METRIC_NAMES) {
      expect(METRIC_CATEGORIES.map((category) => category.value)).toContain(
        metricDefinition(metric).category,
      )
    }
  })

  test('a gauge sends no window', () => {
    const t = (text: string) => text
    const form = { ...emptyRuleForm(), metric: 'storage' as const }
    expect(monitorInputFromForm(form, t).window).toBeUndefined()
    expect(monitorInputFromForm(emptyRuleForm(), t).window).toBe(3600)
  })
})

describe('probe monitors', () => {
  const t = (text: string) => text

  test('sends only the fields of its type, named after its target', () => {
    const http = probeInputFromForm({
      ...emptyProbeForm('http'),
      url: ' https://example.com/health ',
      headers: 'Accept: application/json\nX-Token: a:b',
      statusCodes: '200, 204 200',
      body: 'ignored for GET',
    })
    expect(http).toEqual({
      type: 'http',
      params: {
        name: 'GET https://example.com/health',
        interval: 60,
        timeout: 10,
        cooldown: 3600,
        notify: ['owner'],
        escalationId: '',
        severity: 'warning',
        url: 'https://example.com/health',
        method: 'GET',
        headers: { Accept: 'application/json', 'X-Token': 'a:b' },
        body: '',
        rules: [
          '{"method":"or","values":[{"method":"notEqual","attribute":"statusCode","values":[200,204]},{"method":"and","values":[{"method":"lessThan","attribute":"certificateDays","values":[14]},{"method":"lessThan","attribute":"certificateLifetime","values":[25]}]}]}',
        ],
      },
    })

    const smtp = probeInputFromForm({
      ...emptyProbeForm('smtp'),
      host: 'mail.example.com',
    })
    expect(Object.keys(smtp.params).sort()).toEqual([
      'cooldown',
      'escalationId',
      'host',
      'interval',
      'name',
      'notify',
      'port',
      'severity',
      'timeout',
      'tlsMode',
    ])
    expect(smtp.params.name).toBe('mail.example.com:587')
  })

  test('an untouched probe monitor edits back to the input it was saved with', () => {
    const forms = [
      {
        ...emptyProbeForm('http'),
        url: 'https://example.com',
        method: 'POST' as const,
        headers: 'Content-Type: application/json',
        body: '{}',
        statusCodes: '201',
      },
      {
        ...emptyProbeForm('dns'),
        domain: 'example.com',
        record: 'MX' as const,
      },
      { ...emptyProbeForm('tcp'), host: 'db.example.com', port: 5432 },
      {
        ...emptyProbeForm('smtp'),
        host: 'mail.example.com',
        tlsMode: 'implicit' as const,
      },
    ]
    for (const form of forms) {
      const { type, params } = probeInputFromForm(form)
      // The model's defaults for the fields a type does not set.
      const monitor = parseMonitor({
        url: '',
        method: '',
        headers: {},
        body: '',
        domain: '',
        record: '',
        resolver: '',
        host: '',
        port: 0,
        tlsMode: null,
        rules: [],
        ...params,
        $id: 'm1',
        $createdAt: '',
        $updatedAt: '',
        type,
        resourceType: 'project',
        resourceId: 'p1',
        enabled: true,
        status: 'ok',
        alertId: '',
      } as unknown as Monitor)
      expect(
        probeInputFromForm(
          probeFormFromMonitor(monitor as typeof monitor & { type: ProbeType }),
        ),
      ).toEqual({ type, params } as never)
    }
  })

  test('refuses a target the server would reject', () => {
    expect(probeFormError(emptyProbeForm('http'), t)).toBe(
      'Enter a URL starting with http:// or https://.',
    )
    expect(
      probeFormError(
        { ...emptyProbeForm('http'), url: 'ftp://example.com' },
        t,
      ),
    ).toBe('Enter a URL starting with http:// or https://.')
    expect(
      probeFormError(
        { ...emptyProbeForm('tcp'), host: 'example.com', port: 0 },
        t,
      ),
    ).toBe('Enter a port between 1 and 65535.')
    expect(
      probeFormError(
        {
          ...emptyProbeForm('dns'),
          domain: 'example.com',
          interval: 30,
          timeout: 60,
        },
        t,
      ),
    ).toBe('The timeout must not be longer than the interval.')
    expect(
      probeFormError({ ...emptyProbeForm('dns'), domain: 'example.com' }, t),
    ).toBeUndefined()
  })

  test('reads headers and status codes as typed', () => {
    expect(parseHeaders('\nAccept: */*\n')).toEqual({ Accept: '*/*' })
    expect(parseHeaders('no separator')).toBeNull()
    expect(parseStatusCodes('')).toEqual([])
    expect(parseStatusCodes('200,abc')).toBeNull()
    expect(parseStatusCodes('99')).toBeNull()
  })

  test('derives the SMTP TLS mode from the port when it is auto', () => {
    expect(smtpTlsMode(465, 'auto')).toBe('implicit')
    expect(smtpTlsMode(587, 'auto')).toBe('starttls')
    expect(smtpTlsMode(25, 'auto')).toBe('none')
    expect(smtpTlsMode(25, 'starttls')).toBe('starttls')
  })

  test('writes the rules the server reads for monitors created before rules', () => {
    // Without rules the server fires on these defaults, so they read back as the empty form.
    expect(buildProbeRules('http', DEFAULT_PROBE_CONDITION)).toEqual([
      '{"method":"or","values":[{"method":"notBetween","attribute":"statusCode","values":[200,299]},{"method":"and","values":[{"method":"lessThan","attribute":"certificateDays","values":[14]},{"method":"lessThan","attribute":"certificateLifetime","values":[25]}]}]}',
    ])
    expect(
      buildProbeRules('http', {
        ...DEFAULT_PROBE_CONDITION,
        statusCodes: [301],
        certificateDays: 0,
      }),
    ).toEqual(['{"method":"notEqual","attribute":"statusCode","values":[301]}'])
    expect(buildProbeRules('dns', DEFAULT_PROBE_CONDITION)).toEqual([
      '{"method":"equal","attribute":"answers","values":[0]}',
    ])
    expect(
      buildProbeRules('dns', {
        ...DEFAULT_PROBE_CONDITION,
        answer: ' "Mail.Example.com." ',
      }),
    ).toEqual([
      '{"method":"or","values":[{"method":"equal","attribute":"answers","values":[0]},{"method":"notEqual","attribute":"answer","values":["mail.example.com"]}]}',
    ])
    expect(buildProbeRules('tcp', DEFAULT_PROBE_CONDITION)).toEqual([])
    expect(buildProbeRules('smtp', DEFAULT_PROBE_CONDITION)).toEqual([])
  })

  test.each<ProbeCondition>([
    {
      ...DEFAULT_PROBE_CONDITION,
      statusCodes: [200, 204],
      certificateDays: 30,
    },
    { ...DEFAULT_PROBE_CONDITION, certificateDays: 0 },
  ])('reads HTTP rules back into the fields that wrote them', (condition) => {
    expect(parseProbeRules('http', buildProbeRules('http', condition))).toEqual(
      condition,
    )
  })

  test('reads the server floats and no rules as the fields', () => {
    // PHP encodes the stored lifetime as 25.0, which JSON reads as 25.
    expect(
      parseProbeRules('http', [
        '{"method":"or","values":[{"method":"notBetween","attribute":"statusCode","values":[200,299]},{"method":"and","values":[{"method":"lessThan","attribute":"certificateDays","values":[7]},{"method":"lessThan","attribute":"certificateLifetime","values":[25.0]}]}]}',
      ]),
    ).toEqual({ ...DEFAULT_PROBE_CONDITION, certificateDays: 7 })
    expect(parseProbeRules('http', [])).toEqual(DEFAULT_PROBE_CONDITION)
    expect(
      parseProbeRules(
        'dns',
        buildProbeRules('dns', {
          ...DEFAULT_PROBE_CONDITION,
          answer: '203.0.113.10',
        }),
      ),
    ).toEqual({ ...DEFAULT_PROBE_CONDITION, answer: '203.0.113.10' })
  })

  test('keeps rules set through the API until a field they cover changes', () => {
    const latency =
      '{"method":"greaterThan","attribute":"latency","values":[2000]}'
    expect(parseProbeRules('http', [latency])).toBeNull()
    const monitor = parseMonitor({
      $id: 'm1',
      $createdAt: '',
      $updatedAt: '',
      type: 'http',
      url: 'https://example.com',
      method: 'GET',
      headers: {},
      body: '',
      rules: [latency],
      interval: 60,
      timeout: 10,
      cooldown: 3600,
      notify: ['owner'],
      escalationId: '',
      severity: 'warning',
      name: 'Example',
    } as unknown as Monitor) as ParsedMonitorOf<'http'>
    const form = probeFormFromMonitor(monitor)
    expect(probeInputFromForm(form).params.rules).toEqual([latency])
    expect(
      probeInputFromForm({ ...form, statusCodes: '200' }).params.rules,
    ).toEqual([
      '{"method":"or","values":[{"method":"notEqual","attribute":"statusCode","values":[200]},{"method":"and","values":[{"method":"lessThan","attribute":"certificateDays","values":[14]},{"method":"lessThan","attribute":"certificateLifetime","values":[25]}]}]}',
    ])
  })

  test('a probe alert reads as what its check found', () => {
    const alert = {
      metric: null,
      comparator: null,
      result: 'connect_refused',
      threshold: 0,
    } as Alert
    expect(alertCondition(alert, t)).toBe('Connection refused')
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

describe('alert history pages', () => {
  // Copy the real module now: the namespace import is live and points at the mock once it is set.
  const realSdk = { ...actualSdk }

  afterAll(() => {
    mock.module('@/lib/appwrite/sdk', () => realSdk)
  })

  test('loads the alerts past the first page after the last one', async () => {
    const page = (from: number, count: number) =>
      Array.from({ length: count }, (_, i) => ({ $id: `a${from + i}` }))
    const requests: string[][] = []
    mock.module('@/lib/appwrite/sdk', () => ({
      ...actualSdk,
      sdk: {
        forProject: () => ({
          alerts: {
            list: async ({ queries }: { queries: string[] }) => {
              requests.push(queries)
              return {
                alerts: requests.length === 1 ? page(0, 100) : page(100, 3),
              }
            },
          },
        }),
      },
    }))
    const { alertsInfiniteQueryOptions } = await import(
      '@/lib/react-query/hooks/monitors'
    )

    const queryClient = new QueryClient()
    const options = alertsInfiniteQueryOptions('project', { status: 'firing' })
    await queryClient.fetchInfiniteQuery({ ...options, pages: 3 })

    const data = queryClient.getQueryData(options.queryKey)
    expect(data?.pages.flatMap((p) => p.alerts)).toHaveLength(103)
    expect(requests).toHaveLength(2)
    expect(requests[1].map((query) => JSON.parse(query))).toContainEqual({
      method: 'cursorAfter',
      values: ['a99'],
    })
    // The filter holds on every page.
    expect(requests[1].map((query) => JSON.parse(query))).toContainEqual({
      method: 'equal',
      attribute: 'status',
      values: ['firing'],
    })
  })
})

describe('alert email unsubscribe page', () => {
  test('opens from the email without a console session', async () => {
    const { isOptionalAuthPage } = await import(
      '@/components/global/auth/RequireAuth'
    )
    const { isSelfHostedAllowedPath } = await import(
      '@/lib/self-hosted-route-access'
    )
    expect(isOptionalAuthPage('/auth/alerts/unsubscribe')).toBe(true)
    expect(isSelfHostedAllowedPath('/auth/alerts/unsubscribe')).toBe(true)
  })
})

describe('budget alert recipients', () => {
  test('reads recipients only from editions that return them', () => {
    expect(budgetRecipients({ budgetNotify: ['owner', 'billing'] })).toEqual([
      'owner',
      'billing',
    ])
    expect(budgetRecipients({ budgetAlerts: [50] })).toBeNull()
    expect(budgetRecipients(null)).toBeNull()
  })

  test('sends a change only when it differs and keeps someone emailed', () => {
    const current = ['owner', 'billing']
    expect(changedBudgetRecipients(current, ['owner'])).toEqual(['owner'])
    expect(changedBudgetRecipients(current, ['billing', 'owner'])).toBeNull()
    expect(changedBudgetRecipients(current, [])).toBeNull()
  })
})

describe('alerting plan limits', () => {
  test('reads a missing or zero cap as uncapped, as the API does', () => {
    expect(alertingLimits(undefined)).toEqual({
      probesAllowed: true,
      probes: null,
      probeInterval: 0,
      escalations: null,
      escalationSteps: null,
      escalationRepeat: null,
    })
    const free = {
      probes: 1,
      probeInterval: 300,
      escalations: 1,
      escalationSteps: 2,
      escalationRepeat: 0,
    } as unknown as Parameters<typeof alertingLimits>[0]
    expect(alertingLimits(free)).toEqual({
      probesAllowed: true,
      probes: 1,
      probeInterval: 300,
      escalations: 1,
      escalationSteps: 2,
      escalationRepeat: 0,
    })
    const scale = {
      probes: 0,
      escalations: 0,
      escalationSteps: 0,
    } as unknown as Parameters<typeof alertingLimits>[0]
    expect(alertingLimits(scale).probes).toBeNull()
    expect(alertingLimits(scale).escalationSteps).toBeNull()
  })

  test('caps usage monitors and synthetic checks separately', () => {
    const free = {
      monitors: 3,
      probes: 1,
    } as unknown as Parameters<typeof monitorCaps>[0]
    // Three usage monitors fill a Free project, yet a synthetic check can
    // still be added, so creating a monitor stays open.
    expect(monitorCaps(free, 3, 0)).toEqual({
      usageLimit: 3,
      usageAtLimit: true,
      probeLimit: 1,
      probesAtLimit: false,
      probesAllowed: true,
      allAtLimit: false,
    })
    expect(monitorCaps(free, 3, 1).allAtLimit).toBe(true)
    expect(monitorCaps(free, 2, 1).usageAtLimit).toBe(false)
    // A count not loaded yet never closes creating.
    expect(monitorCaps(free, undefined, undefined).allAtLimit).toBe(false)
    expect(monitorCaps(undefined, 500, 500).allAtLimit).toBe(false)
  })

  test('closes synthetic checks on a plan without them, keeping usage monitors', () => {
    const free = {
      monitors: 3,
      monitorsAllowProbes: false,
    } as unknown as Parameters<typeof monitorCaps>[0]
    expect(monitorCaps(free, 2, 0)).toEqual({
      usageLimit: 3,
      usageAtLimit: false,
      probeLimit: null,
      probesAtLimit: false,
      probesAllowed: false,
      allAtLimit: false,
    })
    // With the usage monitors full, nothing is left to create.
    expect(monitorCaps(free, 3, 0).allAtLimit).toBe(true)
  })

  test('starts new checks on a minute, or the plan minimum when longer', () => {
    expect(defaultProbeInterval(0)).toBe(60)
    expect(defaultProbeInterval(30)).toBe(60)
    expect(defaultProbeInterval(300)).toBe(300)
  })

  test('counts each member an escalation notifies once, by role or by name', () => {
    const members = [
      { userId: 'ola', roles: ['owner'] },
      { userId: 'bob', roles: ['developer'] },
      { userId: 'cy', roles: ['developer'] },
      { userId: 'dee', roles: ['analyst'] },
    ]
    expect(countResponders([], members)).toBe(0)
    expect(
      countResponders(
        [
          {
            steps: [
              { delay: 0, roles: ['owner'], userIds: ['ola'] },
              { delay: 600, roles: [], userIds: ['bob'] },
            ],
          },
          { steps: [{ delay: 0, roles: ['owner'], userIds: [] }] },
        ],
        members,
      ),
    ).toBe(2)
    expect(
      countResponders(
        [{ steps: [{ delay: 0, roles: ['developer'], userIds: ['dee'] }] }],
        members,
      ),
    ).toBe(3)
  })
})
