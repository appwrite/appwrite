import type { Page, Route, TestInfo } from '@playwright/test'

export type UsageEndpoint = 'events' | 'gauges'

export type CapturedUsageRequest = {
  endpoint: UsageEndpoint
  method: string
  project: string | undefined
  params: Record<string, string[]>
}

export type UsageMockOptions = {
  status?: number
  /** Explicit response gate for deterministic loading-state assertions. */
  responseGate?: Promise<void>
  omitMetrics?: readonly string[]
  emptyMetrics?: readonly string[]
  values?: readonly number[]
  failuresBeforeSuccess?: number
}

/**
 * The SDK flattens array parameters to indexed keys (`metrics[0]=a&metrics[1]=b`),
 * so an index segment has to be stripped to recover the original parameter name.
 */
const ARRAY_INDEX_SUFFIX = /\[\d*\]$/

function parseUsageQueryParams(url: URL): Record<string, string[]> {
  const params: Record<string, string[]> = {}
  for (const [rawKey, value] of url.searchParams.entries()) {
    const key = rawKey.replace(ARRAY_INDEX_SUFFIX, '')
    params[key] = [...(params[key] ?? []), value]
  }
  return params
}

async function fulfillUsageRoute(
  route: Route,
  requests: CapturedUsageRequest[],
  options: UsageMockOptions,
) {
  const request = route.request()
  const url = new URL(request.url())
  const endpoint = url.pathname.endsWith('/gauges') ? 'gauges' : 'events'
  const params = parseUsageQueryParams(url)
  requests.push({
    endpoint,
    method: request.method(),
    project: request.headers()['x-appwrite-project'],
    params,
  })

  if (options.responseGate) {
    await options.responseGate
  }

  const status = options.status ?? 200
  if (status !== 200) {
    await route.fulfill({
      status,
      contentType: 'application/json',
      body: JSON.stringify({
        code: status,
        type: 'general_mocked_usage_error',
        message: 'Mocked usage request failed',
      }),
    })
    return
  }

  const interval = params.interval?.[0] ?? ''
  const requestedMetrics = params.metrics ?? []
  const omit = new Set(options.omitMetrics ?? [])
  const empty = new Set(options.emptyMetrics ?? [])
  const values = options.values ?? [0, 0.375, 4]
  const base = Date.parse(params.startAt?.[0] ?? '2025-01-01T00:00:00.000Z')

  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      interval,
      metrics: requestedMetrics
        .filter((metric) => !omit.has(metric))
        .map((metric) => ({
          metric,
          points: empty.has(metric)
            ? []
            : values.map((value, index) => ({
                time: new Date(base + index * 60 * 60 * 1000).toISOString(),
                value,
                path: `/v1/mock/${index}`,
                method: index % 2 === 0 ? 'GET' : 'POST',
                status: index % 2 === 0 ? '200' : '201',
                country: index % 2 === 0 ? 'us' : 'de',
                sdk: 'web',
                sdkVersion: `1.${index}.0`,
                resourceId: `resource-${index}`,
                resourceType: 'site',
                service: 'sites',
                ordinal: String(index),
              })),
        })),
    }),
  })
}

export async function installUsageApiMock(
  page: Page,
  options: UsageMockOptions = {},
): Promise<CapturedUsageRequest[]> {
  const requests: CapturedUsageRequest[] = []
  let attempts = 0
  await page.route(/\/v1\/usage\/(events|gauges)(?:\?|$)/, (route) => {
    attempts += 1
    const status =
      attempts <= (options.failuresBeforeSuccess ?? 0)
        ? (options.status ?? 500)
        : options.failuresBeforeSuccess
          ? 200
          : options.status
    return fulfillUsageRoute(route, requests, { ...options, status })
  })
  return requests
}

export function redactUsageQuery(query: string): string {
  try {
    const parsed = JSON.parse(query) as unknown
    const redact = (value: unknown, key?: string): unknown => {
      if (key === 'values') {
        return Array.isArray(value)
          ? value.map(() => '[redacted]')
          : '[redacted]'
      }
      if (Array.isArray(value)) return value.map((entry) => redact(entry))
      if (value && typeof value === 'object') {
        return Object.fromEntries(
          Object.entries(value).map(([entryKey, entryValue]) => [
            entryKey,
            redact(entryValue, entryKey),
          ]),
        )
      }
      return value
    }
    return JSON.stringify(redact(parsed))
  } catch {
    return '[unparseable query redacted]'
  }
}

export async function attachUsageEvidence(
  page: Page,
  testInfo: TestInfo,
  requests: CapturedUsageRequest[],
  name: string,
) {
  const redactedRequests = requests.map((request) => ({
    ...request,
    project: request.project ? '[redacted]' : undefined,
    params: {
      ...request.params,
      queries: request.params.queries?.map(redactUsageQuery),
    },
  }))
  await testInfo.attach(`${name}-network.json`, {
    body: Buffer.from(JSON.stringify(redactedRequests, null, 2)),
    contentType: 'application/json',
  })
  await testInfo.attach(`${name}.png`, {
    body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
    contentType: 'image/png',
  })
}

export function assertUsageRequestContract(
  request: CapturedUsageRequest,
  projectId: string,
) {
  if (request.method !== 'GET') {
    throw new Error(`Expected GET usage request, received ${request.method}`)
  }
  if (request.project !== projectId) {
    throw new Error('Usage request was not isolated to the active project')
  }
  if (!request.params.metrics?.length) {
    throw new Error('Usage request omitted metrics')
  }
  if (!request.params.startAt?.[0] || !request.params.endAt?.[0]) {
    throw new Error('Usage request omitted date bounds')
  }
  if (!request.params.orderBy?.[0] || !request.params.orderDir?.[0]) {
    throw new Error('Usage request omitted ordering')
  }
  if (!request.params.limit?.[0]) {
    throw new Error('Usage request omitted limit')
  }
}
