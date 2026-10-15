import { afterEach, describe, expect, test } from 'bun:test'
import { Functions, Messaging, Storage, Webhooks } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DESCRIPTION_MAX_LENGTH,
  normalizeDescription,
  readDescription,
  withDescription,
} from '@/lib/appwrite/resource-descriptions'

type CapturedRequest = {
  url: string
  method: string
  body: Record<string, unknown>
  headers: Record<string, string>
}

const realFetch = globalThis.fetch

/** Captures the next request instead of sending it, and answers with `{}`. */
function captureRequest(): { captured: CapturedRequest | null } {
  const result: { captured: CapturedRequest | null } = { captured: null }
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    result.captured = {
      url: String(input),
      method: String(init?.method ?? 'GET'),
      body: JSON.parse(String(init?.body ?? '{}')),
      headers: Object.fromEntries(
        new Headers(init?.headers ?? {}).entries(),
      ) as Record<string, string>,
    }
    return new Response('{}', {
      status: 200,
      headers: { 'content-type': 'application/json' },
    })
  }) as typeof globalThis.fetch
  return result
}

afterEach(() => {
  globalThis.fetch = realFetch
})

describe('readDescription', () => {
  test('reads the field the pinned SDK models do not declare', () => {
    expect(readDescription({ description: 'Sends welcome emails' })).toBe(
      'Sends welcome emails',
    )
  })

  test('falls back to empty for older servers and missing resources', () => {
    expect(readDescription({})).toBe('')
    expect(readDescription(undefined)).toBe('')
    expect(readDescription(null)).toBe('')
    expect(readDescription({ description: 42 })).toBe('')
  })
})

describe('normalizeDescription', () => {
  test('trims and clamps to the API limit', () => {
    expect(normalizeDescription('  padded  ')).toBe('padded')
    expect(normalizeDescription('a'.repeat(2000))).toHaveLength(
      DESCRIPTION_MAX_LENGTH,
    )
  })

  test('keeps an empty string, which is what clears the field', () => {
    expect(normalizeDescription('   ')).toBe('')
  })
})

describe('withDescription', () => {
  test('sends description alongside the params the SDK knows', async () => {
    const request = captureRequest()

    await withDescription(
      'test-project',
      'Sends a welcome email',
      Functions,
    ).update({ functionId: 'welcome', name: 'Welcome' })

    // `PUT /v1/functions/:functionId`, same route the SDK method owns.
    expect(request.captured?.method).toBe('PUT')
    expect(request.captured?.url).toEndWith('/functions/welcome')
    expect(request.captured?.body).toMatchObject({
      name: 'Welcome',
      description: 'Sends a welcome email',
    })
  })

  test('sends description on create calls too', async () => {
    const request = captureRequest()

    await withDescription(
      'test-project',
      'Profile photos',
      Storage,
    ).createBucket({ bucketId: 'avatars', name: 'Avatars' })

    expect(request.captured?.method).toBe('POST')
    expect(request.captured?.url).toEndWith('/storage/buckets')
    expect(request.captured?.body).toMatchObject({
      bucketId: 'avatars',
      name: 'Avatars',
      description: 'Profile photos',
    })
  })

  test('sends an empty description, which is how the API clears it', async () => {
    const request = captureRequest()

    await withDescription('test-project', '', Functions).update({
      functionId: 'welcome',
      name: 'Welcome',
    })

    expect(request.captured?.body.description).toBe('')
  })

  test('the pinned SDK drops description on its own, hence this helper', async () => {
    const request = captureRequest()

    // `description` only exists on the API, not in the pinned SDK's params,
    // so it reaches the service as an extra key the generated method ignores.
    const params = {
      functionId: 'welcome',
      name: 'Welcome',
      description: 'Sends a welcome email',
    }
    await sdk.forProject('test-project').functions.update(params)

    expect(request.captured?.body).toHaveProperty('name', 'Welcome')
    expect(request.captured?.body).not.toHaveProperty('description')
  })
})

/**
 * Payload shape, per resource.
 *
 * `withDescription` hands the generated method a client whose `call` merges one
 * extra key in. If that merge ever replaced the payload instead of extending
 * it, the save would still return 200 and would quietly drop whatever the user
 * did not retype. These lock the full param set each update sends, not just
 * the description.
 */
describe('payload shape', () => {
  test('a function update keeps every param the SDK method builds', async () => {
    const request = captureRequest()

    await withDescription(
      'test-project',
      'Sends welcome email',
      Functions,
    ).update({
      functionId: 'welcome',
      name: 'Welcome',
      enabled: true,
      logging: true,
      timeout: 15,
      entrypoint: 'src/main.js',
      commands: 'npm install',
      schedule: '',
    })

    expect(request.captured?.body).toEqual({
      name: 'Welcome',
      enabled: true,
      logging: true,
      timeout: 15,
      entrypoint: 'src/main.js',
      commands: 'npm install',
      schedule: '',
      description: 'Sends welcome email',
    })
    // The resource id is a path param, so it must not appear in the body.
    expect(request.captured?.body).not.toHaveProperty('functionId')
    expect(request.captured?.url).toEndWith('/functions/welcome')
  })

  test('a bucket update keeps the settings the user did not touch', async () => {
    const request = captureRequest()

    await withDescription('test-project', 'Invoices', Storage).updateBucket({
      bucketId: 'invoices',
      name: 'Invoices',
      enabled: true,
      encryption: true,
      antivirus: true,
      maximumFileSize: 30_000_000,
      fileSecurity: false,
    })

    expect(request.captured?.body).toEqual({
      name: 'Invoices',
      enabled: true,
      encryption: true,
      antivirus: true,
      maximumFileSize: 30_000_000,
      fileSecurity: false,
      description: 'Invoices',
    })
  })

  test('a webhook update carries the auth user, which the API resets when absent', async () => {
    const request = captureRequest()

    // The webhook update endpoint writes `httpUser` from a param that defaults
    // to '', so the console has to resend it or the stored value is cleared.
    await withDescription('test-project', 'Billing hook', Webhooks).update({
      webhookId: 'billing',
      name: 'Billing',
      url: 'https://example.com/hook',
      events: ['users.*.create'],
      tls: true,
      enabled: true,
      authUsername: 'svc-billing',
    })

    expect(request.captured?.body).toMatchObject({
      authUsername: 'svc-billing',
      events: ['users.*.create'],
      tls: true,
      description: 'Billing hook',
    })
  })

  test('a topic update sends the description alongside the name', async () => {
    const request = captureRequest()

    await withDescription(
      'test-project',
      'Order updates',
      Messaging,
    ).updateTopic({
      topicId: 'orders',
      name: 'Orders',
    })

    expect(request.captured?.method).toBe('PATCH')
    expect(request.captured?.url).toEndWith('/messaging/topics/orders')
    expect(request.captured?.body).toEqual({
      name: 'Orders',
      description: 'Order updates',
    })
  })

  test('the clamped description is what gets sent, not the raw input', async () => {
    const request = captureRequest()
    const tooLong = `  ${'a'.repeat(DESCRIPTION_MAX_LENGTH + 200)}  `

    await withDescription(
      'test-project',
      normalizeDescription(tooLong),
      Functions,
    ).update({ functionId: 'welcome', name: 'Welcome' })

    expect(String(request.captured?.body.description)).toHaveLength(
      DESCRIPTION_MAX_LENGTH,
    )
  })
})

describe('withDescription does not leak', () => {
  test('keeps the project client it was built from intact', async () => {
    const request = captureRequest()

    await withDescription('test-project', 'Scoped', Functions).update({
      functionId: 'welcome',
      name: 'Welcome',
    })

    // The proxy forwards config and headers, so the call still identifies the
    // project; a proxy that shadowed them would send an unscoped request.
    expect(request.captured?.headers['x-appwrite-project']).toBe('test-project')
  })

  test('later calls on the shared project SDK carry no description', async () => {
    const request = captureRequest()
    await withDescription(
      'test-project',
      'Only for this call',
      Functions,
    ).update({ functionId: 'welcome', name: 'Welcome' })
    expect(request.captured?.body.description).toBe('Only for this call')

    // Same stub, next call: the shared client was never mutated.
    await sdk.forProject('test-project').functions.update({
      functionId: 'welcome',
      name: 'Welcome again',
    })

    expect(request.captured?.body).toMatchObject({ name: 'Welcome again' })
    expect(request.captured?.body).not.toHaveProperty('description')
  })

  test('two scoped services do not see each other description', async () => {
    const first = captureRequest()
    const fnService = withDescription(
      'test-project',
      'For the function',
      Functions,
    )
    const bucketService = withDescription(
      'test-project',
      'For the bucket',
      Storage,
    )

    await fnService.update({ functionId: 'welcome', name: 'Welcome' })
    expect(first.captured?.body.description).toBe('For the function')

    const second = captureRequest()
    await bucketService.updateBucket({ bucketId: 'invoices', name: 'Invoices' })
    expect(second.captured?.body.description).toBe('For the bucket')
  })
})
