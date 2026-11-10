import { afterAll, beforeEach, describe, expect, mock, test } from 'bun:test'
import * as actualSdk from '@appwrite.io/console'
import * as actualRuntimeConfig from '@/lib/runtime-config'

const realSdk = { ...actualSdk }
const realRuntimeConfig = { ...actualRuntimeConfig }

const calls: string[] = []
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
    disableAutoPageviews() {
      calls.push('stop')
    }
    disableAutoOutboundTracking() {}
    disableAutoScrollDepth() {}
    disableAutoEngagementTime() {}
  },
}))

const { startAppwriteAnalytics, stopAppwriteAnalytics } = await import(
  '@/lib/appwrite-analytics'
)

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('appwrite analytics', () => {
  const hadWindow = 'window' in globalThis

  beforeEach(async () => {
    stopAppwriteAnalytics()
    await flush()
    calls.length = 0
    Object.assign(globalThis, { window: globalThis })
  })

  afterAll(() => {
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
    ])

    stopAppwriteAnalytics()
    await flush()
    expect(calls.at(-1)).toBe('stop')
  })
})
