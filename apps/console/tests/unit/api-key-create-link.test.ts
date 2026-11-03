import { describe, expect, test } from 'bun:test'
import {
  buildApiKeyCreateUrl,
  parseApiKeyPrefill,
} from '@/lib/api-keys/create-link'

describe('parseApiKeyPrefill', () => {
  test('reads a name and comma-separated scopes', () => {
    expect(
      parseApiKeyPrefill({
        name: 'Acme server',
        scopes: 'sessions.write, users.read',
      }),
    ).toEqual({ name: 'Acme server', scopes: ['sessions.write', 'users.read'] })
  })

  test('accepts the values the router types for numeric, boolean, and repeated params', () => {
    expect(parseApiKeyPrefill({ name: 2026 })).toEqual({ name: '2026' })
    expect(parseApiKeyPrefill({ name: true })).toEqual({ name: 'true' })
    expect(
      parseApiKeyPrefill({ scopes: ['users.read', 'users.write'] }),
    ).toEqual({ scopes: ['users.read', 'users.write'] })
  })

  test('drops empty and duplicate scopes', () => {
    expect(
      parseApiKeyPrefill({ scopes: 'users.read,,users.read, ,rows.write' }),
    ).toEqual({ scopes: ['users.read', 'rows.write'] })
  })

  test('returns null when the link carries nothing to prefill', () => {
    expect(parseApiKeyPrefill({})).toBeNull()
    expect(parseApiKeyPrefill({ name: '  ', scopes: ',' })).toBeNull()
  })

  test('caps the name at the server limit', () => {
    expect(parseApiKeyPrefill({ name: 'a'.repeat(200) })?.name).toHaveLength(
      128,
    )
  })
})

describe('buildApiKeyCreateUrl', () => {
  test('points at the project API keys page with the prefill params', () => {
    const url = buildApiKeyCreateUrl({
      origin: 'https://appwrite.io',
      projectId: 'acme',
      name: 'Acme server',
      scopes: ['sessions.write', 'users.read'],
    })
    expect(url.startsWith('https://appwrite.io/projects/acme/api-keys?')).toBe(
      true,
    )
    const params = new URL(url).searchParams
    expect(
      parseApiKeyPrefill({
        name: params.get('name') ?? undefined,
        scopes: params.get('scopes') ?? undefined,
      }),
    ).toEqual({ name: 'Acme server', scopes: ['sessions.write', 'users.read'] })
  })

  test('leaves out the query when there is nothing to prefill', () => {
    expect(
      buildApiKeyCreateUrl({
        origin: 'https://appwrite.io',
        projectId: 'acme',
      }),
    ).toBe('https://appwrite.io/projects/acme/api-keys')
  })
})
