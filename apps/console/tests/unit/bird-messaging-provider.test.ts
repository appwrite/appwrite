import { describe, expect, test } from 'bun:test'
import type { Messaging, Models } from '@appwrite.io/console'
import { patchMessagingProvider } from '@/lib/messaging/patch-messaging-provider'

type Call = { method: string; args: Record<string, unknown> }

function makeMessaging() {
  const calls: Call[] = []
  const record = (method: string) => (args: Record<string, unknown>) => {
    calls.push({ method, args })
    return Promise.resolve({ $id: args.providerId } as Models.Provider)
  }
  const messaging = {
    updateBirdSMSProvider: record('updateBirdSMSProvider'),
    updateBirdEmailProvider: record('updateBirdEmailProvider'),
  } as unknown as Messaging

  return { messaging, calls }
}

// Bird's email and SMS providers are both stored as `bird` and differ by type.
function birdProvider(
  type: 'email' | 'sms',
  options: Record<string, unknown>,
): Models.Provider {
  return {
    $id: 'provider1',
    provider: 'bird',
    type,
    credentials: {},
    options,
  } as unknown as Models.Provider
}

describe('patchMessagingProvider (Bird)', () => {
  test('updates a Bird email provider through the email endpoint', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(
      messaging,
      birdProvider('email', { fromName: 'Acme', fromEmail: 'old@acme.dev' }),
      {
        providerId: 'provider1',
        options: { fromEmail: 'new@acme.dev', replyToEmail: 'help@acme.dev' },
      },
    )
    expect(calls).toHaveLength(1)
    expect(calls[0].method).toBe('updateBirdEmailProvider')
    expect(calls[0].args.fromName).toBe('Acme')
    expect(calls[0].args.fromEmail).toBe('new@acme.dev')
    expect(calls[0].args.replyToEmail).toBe('help@acme.dev')
    // The API never returns the key, so an untouched one is left out.
    expect(calls[0].args.apiKey).toBeUndefined()
    expect(calls[0].args).not.toHaveProperty('from')
  })

  test('updates a Bird SMS provider through the SMS endpoint', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(
      messaging,
      birdProvider('sms', { from: '+447700900123' }),
      { providerId: 'provider1', options: { from: 'Acme' } },
    )
    expect(calls).toHaveLength(1)
    expect(calls[0].method).toBe('updateBirdSMSProvider')
    expect(calls[0].args.from).toBe('Acme')
    expect(calls[0].args).not.toHaveProperty('fromEmail')
  })

  test('picks the endpoint by type when only the name or status changes', async () => {
    const { messaging, calls } = makeMessaging()
    await patchMessagingProvider(messaging, birdProvider('email', {}), {
      providerId: 'provider1',
      enabled: false,
    })
    await patchMessagingProvider(messaging, birdProvider('sms', {}), {
      providerId: 'provider1',
      name: 'Bird SMS',
    })
    expect(calls.map((call) => call.method)).toEqual([
      'updateBirdEmailProvider',
      'updateBirdSMSProvider',
    ])
    expect(calls[0].args).toEqual({ providerId: 'provider1', enabled: false })
    expect(calls[1].args).toEqual({ providerId: 'provider1', name: 'Bird SMS' })
  })
})
