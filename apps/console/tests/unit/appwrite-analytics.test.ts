import { afterAll, beforeEach, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@appwrite.io/console'
import {
  resetCookieConsentStateForTests,
  setCookieConsentState,
} from '@/lib/cookie-consent/consent-state'
import * as actualRuntimeConfig from '@/lib/runtime-config'

const realSdk = { ...actualSdk }
const realRuntimeConfig = { ...actualRuntimeConfig }

const calls: string[] = []
let failImport = false
let config = {
  appwriteAnalyticsEndpoint: '',
  appwriteAnalyticsProjectId: '',
  appwriteAnalyticsPropertyId: '',
}

mock.module('@/lib/runtime-config', () => ({
  ...realRuntimeConfig,
  getRuntimeConfig: () => ({
    ...realRuntimeConfig.getRuntimeConfig(),
    ...config,
  }),
}))

mock.module('@appwrite.io/console', () => ({
  Client: class {
    setEndpoint(value: string) {
      if (failImport) throw new Error('chunk load failed')
      calls.push(`endpoint:${value}`)
      return this
    }
    setProject(value: string) {
      calls.push(`project:${value}`)
      return this
    }
    setCredentials(value: string) {
      calls.push(`credentials:${value}`)
      return this
    }
  },
  Analytics: class {},
  Tracking: class {
    constructor(_analytics: unknown, propertyId: string) {
      calls.push(`property:${propertyId}`)
    }
    start() {
      calls.push('start')
    }
    enableAutoDownloadTracking() {
      calls.push('downloads')
    }
    enableAutoOutboundTracking() {
      calls.push('outbound')
    }
    track(
      name: string,
      options: { url?: string; referrer?: string; props?: object },
    ) {
      calls.push(
        `track:${name}:${options.url}:${options.referrer}:${JSON.stringify(options.props)}`,
      )
    }
  },
}))

const { resetAppwriteAnalyticsForTests, startAppwriteAnalytics } = await import(
  '@/lib/appwrite-analytics'
)
const { trackEvent, trackPageView } = await import('@/lib/analytics')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const fullConfig = () => ({
  appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
  appwriteAnalyticsProjectId: 'site',
  appwriteAnalyticsPropertyId: 'web',
})

describe('appwrite analytics', () => {
  // Later test files branch on `window`, so put back what was there: a file
  // that ran before may have left a property set to `undefined`.
  const originals = Object.fromEntries(
    (['window', 'location', 'document'] as const).map((name) => [
      name,
      Object.getOwnPropertyDescriptor(globalThis, name),
    ]),
  )

  beforeEach(() => {
    resetAppwriteAnalyticsForTests()
    calls.length = 0
    failImport = false
    config = fullConfig()
    Object.assign(globalThis, {
      window: globalThis,
      location: new URL(
        'https://cloud.appwrite.io/console/recover?userId=u1&secret=s3cr3t#frag',
      ),
      document: { referrer: 'https://mail.example.com/inbox?token=t0k3n' },
    })
  })

  afterAll(() => {
    resetAppwriteAnalyticsForTests()
    resetCookieConsentStateForTests()
    for (const [name, descriptor] of Object.entries(originals)) {
      if (descriptor) {
        Object.defineProperty(globalThis, name, descriptor)
      } else {
        Reflect.deleteProperty(globalThis, name)
      }
    }
    mock.module('@/lib/runtime-config', () => realRuntimeConfig)
    mock.module('@appwrite.io/console', () => realSdk)
  })

  test('stays off unless endpoint, project and property are all set', async () => {
    for (const key of Object.keys(config) as (keyof typeof config)[]) {
      config = { ...fullConfig(), [key]: '' }
      startAppwriteAnalytics()
      trackEvent('Form Submitted', { form: 'enterprise' })
      await flush()
    }
    expect(calls).toEqual([])
  })

  test('starts once on its own client without credentials, regardless of consent', async () => {
    setCookieConsentState({
      resolved: true,
      bannerRequired: true,
      analyticsGranted: false,
    })
    startAppwriteAnalytics()
    startAppwriteAnalytics()
    await flush()
    expect(calls).toEqual([
      'endpoint:https://fra.cloud.appwrite.io/v1',
      'project:site',
      'credentials:omit',
      'property:web',
    ])
  })

  test('custom events reach the started tracker with the Plausible name and props', async () => {
    startAppwriteAnalytics()
    trackEvent('Form Submitted', { form: 'enterprise', skipped: undefined })
    await flush()
    expect(calls.at(-1)).toBe(
      'track:Form Submitted:https://cloud.appwrite.io/console/recover:undefined:{"form":"enterprise","auth":"guest","plan":"none","lang":"en"}',
    )
  })

  test('a failed load is retried on the next start', async () => {
    failImport = true
    startAppwriteAnalytics()
    await flush()
    expect(calls).toEqual([])

    failImport = false
    startAppwriteAnalytics()
    await flush()
    expect(calls.at(-1)).toBe('property:web')
  })

  test('pageviews carry no query string, fragment or referrer query', async () => {
    trackPageView('/console/recover')
    startAppwriteAnalytics()
    await flush()
    trackPageView('/console/login')
    await flush()

    const tracked = calls.filter((call) => call.startsWith('track:'))
    expect(tracked).toHaveLength(2)
    expect(tracked[0]).toStartWith(
      'track:pageview:https://cloud.appwrite.io/console/recover:https://mail.example.com/inbox:',
    )
    expect(tracked[1]).toStartWith(
      'track:pageview:https://cloud.appwrite.io/console/login:https://cloud.appwrite.io/console/recover:',
    )
    for (const call of calls) {
      expect(call).not.toContain('s3cr3t')
      expect(call).not.toContain('t0k3n')
      expect(call).not.toContain('frag')
    }
  })

  test('no automatic collector that reads the full page URL is enabled', async () => {
    startAppwriteAnalytics()
    await flush()
    expect(calls).not.toContain('start')
    expect(calls).not.toContain('downloads')
    expect(calls).not.toContain('outbound')
  })
})
