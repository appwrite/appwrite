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
    track(name: string, options: { url?: string; props?: object }) {
      calls.push(
        `track:${name}:${options.url}:${JSON.stringify(options.props)}`,
      )
    }
  },
}))

const { resetAppwriteAnalyticsForTests, startAppwriteAnalytics } = await import(
  '@/lib/appwrite-analytics'
)
const { trackEvent } = await import('@/lib/analytics')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const fullConfig = () => ({
  appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
  appwriteAnalyticsProjectId: 'site',
  appwriteAnalyticsPropertyId: 'web',
})

describe('appwrite analytics', () => {
  const hadWindow = 'window' in globalThis

  beforeEach(() => {
    resetAppwriteAnalyticsForTests()
    calls.length = 0
    failImport = false
    config = fullConfig()
    Object.assign(globalThis, { window: globalThis })
  })

  afterAll(() => {
    resetAppwriteAnalyticsForTests()
    resetCookieConsentStateForTests()
    if (!hadWindow) Reflect.deleteProperty(globalThis, 'window')
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
      'start',
      'downloads',
    ])
  })

  test('custom events reach the started tracker with the Plausible name and props', async () => {
    startAppwriteAnalytics()
    trackEvent('Form Submitted', { form: 'enterprise', skipped: undefined })
    await flush()
    expect(calls.at(-1)).toBe(
      'track:Form Submitted:undefined:{"form":"enterprise","auth":"guest","plan":"none","lang":"en"}',
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
    expect(calls.at(-1)).toBe('downloads')
  })
})
