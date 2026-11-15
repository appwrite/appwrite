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
    disableAutoPageviews() {
      calls.push('stop')
    }
    disableAutoOutboundTracking() {}
    disableAutoScrollDepth() {}
    disableAutoEngagementTime() {}
    disableAutoDownloadTracking() {}
  },
}))

const { startAppwriteAnalytics, stopAppwriteAnalytics } = await import(
  '@/lib/appwrite-analytics'
)
const { trackEvent } = await import('@/lib/analytics')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('appwrite analytics', () => {
  const hadWindow = 'window' in globalThis

  beforeEach(async () => {
    stopAppwriteAnalytics()
    await flush()
    calls.length = 0
    failImport = false
    Object.assign(globalThis, { window: globalThis })
    setCookieConsentState({
      resolved: true,
      bannerRequired: true,
      analyticsGranted: true,
    })
  })

  afterAll(() => {
    resetCookieConsentStateForTests()
    if (!hadWindow) Reflect.deleteProperty(globalThis, 'window')
    mock.module('@/lib/runtime-config', () => realRuntimeConfig)
    mock.module('@appwrite.io/console', () => realSdk)
  })

  test('stays off unless endpoint, project and property are all set', async () => {
    config = {
      appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
      appwriteAnalyticsProjectId: 'site',
      appwriteAnalyticsPropertyId: '',
    }
    startAppwriteAnalytics()
    await flush()
    expect(calls).toEqual([])
  })

  test('custom events reach the started tracker with the Plausible name and props', async () => {
    config = {
      appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
      appwriteAnalyticsProjectId: 'site',
      appwriteAnalyticsPropertyId: '',
    }
    trackEvent('Form Submitted', { form: 'enterprise' })
    await flush()
    expect(calls).toEqual([])

    config.appwriteAnalyticsPropertyId = 'web'
    startAppwriteAnalytics()
    trackEvent('Form Submitted', { form: 'enterprise', skipped: undefined })
    await flush()
    expect(calls.at(-1)).toBe(
      'track:Form Submitted:undefined:{"form":"enterprise","auth":"guest","plan":"none","lang":"en"}',
    )
  })

  test('starts once on its own client without credentials, then stops', async () => {
    config = {
      appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
      appwriteAnalyticsProjectId: 'site',
      appwriteAnalyticsPropertyId: 'web',
    }
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

    stopAppwriteAnalytics()
    await flush()
    expect(calls.at(-1)).toBe('stop')
  })

  test('does not start once consent is withdrawn', async () => {
    config = {
      appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
      appwriteAnalyticsProjectId: 'site',
      appwriteAnalyticsPropertyId: 'web',
    }
    setCookieConsentState({
      resolved: true,
      bannerRequired: true,
      analyticsGranted: false,
    })
    startAppwriteAnalytics()
    await flush()
    expect(calls).toEqual([])
  })

  test('a failed load is retried on the next start', async () => {
    config = {
      appwriteAnalyticsEndpoint: 'https://fra.cloud.appwrite.io/v1',
      appwriteAnalyticsProjectId: 'site',
      appwriteAnalyticsPropertyId: 'web',
    }
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
