import { describe, expect, test } from 'bun:test'
import type { Messaging, Models } from '@appwrite.io/console'
import {
  parseAppwritePushExpiry,
  parseAppwritePushQos,
} from '@/lib/messaging/appwrite-push'
import { patchMessagingProvider } from '@/lib/messaging/patch-messaging-provider'

describe('parseAppwritePushQos', () => {
  test('maps the default selection to the reset representation', () => {
    expect(parseAppwritePushQos('default')).toBeNull()
    expect(parseAppwritePushQos('')).toBeNull()
    expect(parseAppwritePushQos('  ')).toBeNull()
  })

  test('keeps a typed zero (QoS 0) instead of dropping it', () => {
    expect(parseAppwritePushQos('0')).toBe(0)
    expect(parseAppwritePushQos('1')).toBe(1)
  })

  test('treats an unrecognized value as the default', () => {
    expect(parseAppwritePushQos('2.5')).toBeNull()
    expect(parseAppwritePushQos('abc')).toBeNull()
  })
})

describe('parseAppwritePushExpiry', () => {
  test('an empty field resets the stored value', () => {
    expect(parseAppwritePushExpiry('')).toEqual({ ok: true, value: null })
    expect(parseAppwritePushExpiry('   ')).toEqual({ ok: true, value: null })
  })

  test('keeps a typed zero', () => {
    expect(parseAppwritePushExpiry('0')).toEqual({ ok: true, value: 0 })
  })

  test('reads the whole value, not just the integer prefix', () => {
    // parseInt('1e3', 10) is 1; Number('1e3') is 1000.
    expect(parseAppwritePushExpiry('1e3')).toEqual({ ok: true, value: 1000 })
    expect(parseAppwritePushExpiry(' 86400 ')).toEqual({
      ok: true,
      value: 86400,
    })
  })

  test('rejects fractional, negative, and unparseable input', () => {
    expect(parseAppwritePushExpiry('1.5')).toEqual({ ok: false })
    expect(parseAppwritePushExpiry('-1')).toEqual({ ok: false })
    expect(parseAppwritePushExpiry('abc')).toEqual({ ok: false })
  })
})

type AppwriteUpdateArgs = Parameters<Messaging['updateAppwriteProvider']>[0]

function makeMessaging() {
  const calls: AppwriteUpdateArgs[] = []
  const messaging = {
    updateAppwriteProvider: (args: AppwriteUpdateArgs) => {
      calls.push(args)
      return Promise.resolve({ $id: args.providerId } as Models.Provider)
    },
  } as unknown as Messaging

  return { messaging, calls }
}

function appwriteProvider(options: Record<string, unknown>): Models.Provider {
  return {
    $id: 'provider1',
    provider: 'appwrite',
    type: 'push',
    credentials: {},
    options,
  } as unknown as Models.Provider
}

describe('patchMessagingProvider (appwrite push options)', () => {
  test('persists a numeric QoS and expiry, including zero', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(messaging, appwriteProvider({}), {
      providerId: 'provider1',
      options: { qos: 0, expiry: 86400 },
    })
    expect(calls).toHaveLength(1)
    expect(calls[0].qos).toBe(0)
    expect(calls[0].expiry).toBe(86400)
  })

  test('sends an explicit null to reset a previously saved value', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(
      messaging,
      appwriteProvider({ qos: 1, expiry: 100 }),
      {
        providerId: 'provider1',
        options: { qos: null, expiry: null },
      },
    )
    expect(calls).toHaveLength(1)
    // null (not undefined) so the SDK forwards it and the API clears the value.
    expect(calls[0].qos).toBeNull()
    expect(calls[0].expiry).toBeNull()
  })

  test('omits an untouched option as undefined so it is left unchanged', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(
      messaging,
      appwriteProvider({ expiry: 100 }),
      {
        providerId: 'provider1',
        // Only qos is being set; expiry is not part of this update.
        options: { qos: 1 },
      },
    )
    expect(calls).toHaveLength(1)
    expect(calls[0].qos).toBe(1)
    // expiry baseline (100) is merged, so it round-trips rather than clearing.
    expect(calls[0].expiry).toBe(100)
  })
})
