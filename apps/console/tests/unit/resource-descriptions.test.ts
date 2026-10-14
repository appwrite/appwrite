import { afterEach, describe, expect, test } from 'bun:test'
import { Functions, Storage } from '@appwrite.io/console'
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
