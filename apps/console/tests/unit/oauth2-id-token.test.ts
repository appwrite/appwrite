/**
 * The ID token shown on an identity comes from an external provider, so the
 * decoder has to survive anything and never throw in a table cell.
 */

import { describe, expect, test } from 'bun:test'
import { decodeIdTokenClaims } from '@/lib/oauth2/id-token'

/** Encodes like a provider does: UTF-8 bytes, base64url, no padding. */
function makeToken(payload: unknown): string {
  const encode = (value: unknown) => {
    const bytes = new TextEncoder().encode(JSON.stringify(value))
    let binary = ''
    for (const byte of bytes) binary += String.fromCharCode(byte)
    return btoa(binary)
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/, '')
  }
  return `${encode({ alg: 'RS256' })}.${encode(payload)}.signature`
}

describe('decodeIdTokenClaims', () => {
  test('reads the claims a native sign-in token carries', () => {
    const claims = decodeIdTokenClaims(
      makeToken({
        aud: '1082189303325-ff6bgk.apps.googleusercontent.com',
        iss: 'https://accounts.google.com',
        sub: '105927859319404022154',
        email: 'user@example.com',
        iat: 1_757_000_000,
        exp: 1_757_003_600,
      }),
    )

    expect(claims?.audience).toEqual([
      '1082189303325-ff6bgk.apps.googleusercontent.com',
    ])
    expect(claims?.issuer).toBe('https://accounts.google.com')
    expect(claims?.subject).toBe('105927859319404022154')
    expect(claims?.email).toBe('user@example.com')
    // JWTs count seconds; the claims expose milliseconds.
    expect(claims?.issuedAt?.getTime()).toBe(1_757_000_000 * 1000)
    expect(claims?.expiresAt?.getTime()).toBe(1_757_003_600 * 1000)
  })

  test('accepts an audience array, which some providers send', () => {
    const claims = decodeIdTokenClaims(
      makeToken({ aud: ['com.example.app', 'com.example.dev'] }),
    )
    expect(claims?.audience).toEqual(['com.example.app', 'com.example.dev'])
  })

  test('decodes non-ASCII claims rather than mangling them', () => {
    const claims = decodeIdTokenClaims(
      makeToken({ email: 'péter@example.com' }),
    )
    expect(claims?.email).toBe('péter@example.com')
  })

  test('returns null for anything that is not a readable JWT', () => {
    for (const value of ['', 'not-a-token', 'only.two', 'a.!!!.c']) {
      expect(decodeIdTokenClaims(value)).toBeNull()
    }
  })

  test('returns null when the payload is not a JSON object', () => {
    expect(decodeIdTokenClaims(makeToken('a string'))).toBeNull()
    expect(decodeIdTokenClaims(makeToken([1, 2]))).toBeNull()
  })

  test('drops claims that are present but the wrong shape', () => {
    const claims = decodeIdTokenClaims(
      makeToken({ aud: 42, iss: null, exp: 'soon', iat: {} }),
    )
    expect(claims).not.toBeNull()
    expect(claims?.audience).toEqual([])
    expect(claims?.issuer).toBe('')
    expect(claims?.expiresAt).toBeNull()
    expect(claims?.issuedAt).toBeNull()
  })
})
