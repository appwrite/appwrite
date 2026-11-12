import { test, expect } from './fixtures'
import { env } from './config/env'
import { E2E_VIEWPORT } from './config/viewport'
import { discoverConsoleTargets } from './helpers/discovery'
import { ensureProjectActive } from './helpers/ensure-project-active'
import { newE2ePage } from './helpers/cookie-banner'
import {
  parseLiveUsageSeeds,
  seedLiveUsageActivity,
  type LiveUsageSeed,
} from './fixtures/usage-live'

const isLocalEndpoint = (() => {
  try {
    const host = new URL(env.VITE_APPWRITE_ENDPOINT).hostname
    return host === 'localhost' || host === '127.0.0.1' || host === '::1'
  } catch {
    return false
  }
})()

type MetricEvidence = {
  metric?: string
  pointCount: number
  total: number
  latestTime?: string
}

type UsageEvidence = {
  endpoint: string
  status: number
  interval?: string
  metrics?: MetricEvidence[]
}

function metricSnapshot(
  evidence: UsageEvidence[],
  metricName: string,
): { pointCount: number; total: number; latestTime?: string } | null {
  const metrics = evidence.flatMap((entry) => entry.metrics ?? [])
  const matching = metrics.filter((metric) => metric.metric === metricName)
  if (matching.length === 0) return null
  return matching.reduce(
    (latest, metric) => ({
      pointCount: metric.pointCount,
      total: metric.total,
      latestTime:
        !latest.latestTime ||
        (metric.latestTime && metric.latestTime > latest.latestTime)
          ? metric.latestTime
          : latest.latestTime,
    }),
    { pointCount: 0, total: 0, latestTime: undefined } as {
      pointCount: number
      total: number
      latestTime?: string
    },
  )
}

test.describe('self-hosted usage live lane', () => {
  let projectId: string | null
  let backendVersion = 'unknown'

  test.beforeAll(async ({ browser, request }) => {
    test.skip(
      !isLocalEndpoint,
      `Live usage requires a local Appwrite endpoint (received ${new URL(env.VITE_APPWRITE_ENDPOINT).host}).`,
    )

    const versionResponse = await request.get(
      `${env.VITE_APPWRITE_ENDPOINT}/health/version`,
    )
    if (versionResponse.ok()) {
      const body = (await versionResponse.json()) as { version?: string }
      backendVersion = body.version ?? 'unknown'
    }

    const context = await browser.newContext({
      storageState: 'e2e/.auth/auth.json',
      viewport: E2E_VIEWPORT,
      screen: E2E_VIEWPORT,
    })
    const page = await newE2ePage(context)
    try {
      const targets = await discoverConsoleTargets(page)
      projectId = targets.projectId
      if (projectId) await ensureProjectActive(page, projectId)
    } finally {
      await context.close()
    }
  })

  test.beforeEach(({}, testInfo) => {
    testInfo.annotations.push({
      type: 'backend-version',
      description: backendVersion,
    })
    test.skip(!projectId, `No project available on backend ${backendVersion}.`)
  })

  const services = [
    {
      name: 'API requests',
      category: 'requests',
      metric: 'network.requests',
      cardTitle: 'Requests',
    },
    {
      name: 'database',
      category: 'databases',
      metric: 'databases.operations.reads',
      cardTitle: 'Database reads',
    },
    {
      name: 'storage',
      category: 'storage',
      metric: 'storage',
      cardTitle: 'Buckets',
    },
    {
      name: 'messaging',
      category: 'messaging',
      metric: 'messages.sent',
      cardTitle: 'Messages sent',
    },
    {
      name: 'function',
      category: 'functions',
      metric: 'functions.executions',
      cardTitle: 'Function executions',
    },
    {
      name: 'site',
      category: 'sites',
      metric: 'sites.executions',
      cardTitle: 'Site executions',
    },
  ] as const

  for (const service of services) {
    test(`${service.name} activity becomes visible`, async ({
      page,
      context,
    }, testInfo) => {
      const unsupportedServices = new Set(
        (process.env.E2E_USAGE_UNSUPPORTED_SERVICES ?? '')
          .split(',')
          .map((value) => value.trim().toLowerCase())
          .filter(Boolean),
      )
      test.skip(
        unsupportedServices.has(service.category),
        `${service.name} usage is explicitly unsupported by backend ${backendVersion}.`,
      )

      const configuredSeeds = parseLiveUsageSeeds(
        process.env.E2E_USAGE_SEEDS_JSON,
      )
      const seed: LiveUsageSeed | undefined =
        service.category === 'requests'
          ? { url: `${env.VITE_APPWRITE_ENDPOINT}/locale` }
          : configuredSeeds[service.category]
      test.skip(
        !seed,
        `${service.name} seeding is not configured for backend ${backendVersion}.`,
      )

      const evidence: UsageEvidence[] = []
      page.on('response', async (response) => {
        if (!/\/v1\/usage\/(events|gauges)/.test(response.url())) return
        try {
          const body = (await response.json()) as {
            interval?: string
            metrics?: Array<{
              metric?: string
              points?: Array<{ time?: string; value?: number }>
            }>
          }
          evidence.push({
            endpoint: new URL(response.url()).pathname,
            status: response.status(),
            interval: body.interval,
            metrics: body.metrics?.map((metric) => {
              const points = metric.points ?? []
              return {
                metric: metric.metric,
                pointCount: points.length,
                total: points.reduce(
                  (sum, point) => sum + (point.value ?? 0),
                  0,
                ),
                latestTime: points
                  .map((point) => point.time)
                  .filter((time): time is string => !!time)
                  .sort()
                  .at(-1),
              }
            }),
          })
        } catch {
          evidence.push({
            endpoint: new URL(response.url()).pathname,
            status: response.status(),
          })
        }
      })

      await page.goto(`/projects/${projectId}/usage/${service.category}`, {
        waitUntil: 'domcontentloaded',
      })
      await expect(
        page
          .getByRole('heading', { name: service.cardTitle, exact: true })
          .first(),
      ).toBeVisible()
      await expect
        .poll(() => metricSnapshot(evidence, service.metric), {
          message: `baseline ${service.metric}`,
        })
        .not.toBeNull()
      const baseline = metricSnapshot(evidence, service.metric)!
      const baselineEvidenceCount = evidence.length

      // Named APIRequestContext fixture keeps seed data out of browser trace
      // action arguments. Validation rejects credentials and sensitive fields.
      const seedResult = await seedLiveUsageActivity(
        context.request,
        seed!,
        projectId!,
      )
      expect(
        seedResult.ok,
        `${service.name} seed returned HTTP ${seedResult.status}`,
      ).toBeTruthy()

      await expect
        .poll(
          async () => {
            const afterSeed = metricSnapshot(
              evidence.slice(baselineEvidenceCount),
              service.metric,
            )
            const changed =
              !!afterSeed &&
              afterSeed.pointCount > 0 &&
              (afterSeed.total > baseline.total ||
                (!baseline.latestTime && !!afterSeed.latestTime) ||
                (!!afterSeed.latestTime &&
                  !!baseline.latestTime &&
                  afterSeed.latestTime > baseline.latestTime))
            if (!changed) {
              const refresh = page
                .getByRole('button', { name: /refresh/i })
                .first()
              if (await refresh.isVisible().catch(() => false)) {
                await refresh.click()
              }
            }
            return changed
          },
          {
            timeout: 60_000,
            intervals: [250, 500, 1_000, 2_000, 5_000],
            message: `${service.metric} visibility on backend ${backendVersion}`,
          },
        )
        .toBeTruthy()

      await expect(
        page
          .getByRole('heading', { name: service.cardTitle, exact: true })
          .first(),
      ).toBeVisible()
      await testInfo.attach(`usage-live-${service.category}-network.json`, {
        body: Buffer.from(JSON.stringify(evidence, null, 2)),
        contentType: 'application/json',
      })
      await testInfo.attach(`usage-live-${service.category}.png`, {
        body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
        contentType: 'image/png',
      })
    })
  }
})
