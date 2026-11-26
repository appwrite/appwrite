import { readdirSync } from 'node:fs'
import path from 'node:path'
import { describe, expect, test } from 'bun:test'
import {
  applyFrameDenyHeaders,
  isAuthScreenPath,
} from '@/lib/security/frame-headers'

describe('applyFrameDenyHeaders', () => {
  test('refuses framing of the OAuth2 consent and device screens', () => {
    for (const pathname of ['/oauth2/consent', '/oauth2/device']) {
      const headers = applyFrameDenyHeaders(new Headers(), pathname)
      expect(headers.get('X-Frame-Options')).toBe('DENY')
      expect(headers.get('Content-Security-Policy')).toBe(
        "frame-ancestors 'none'",
      )
    }
  })

  test('matches with the /console prefix, a trailing slash and any case', () => {
    expect(isAuthScreenPath('/console/oauth2/consent')).toBe(true)
    expect(isAuthScreenPath('/sign-in/')).toBe(true)
    expect(isAuthScreenPath('/OAuth2/Consent')).toBe(true)
  })

  test('adds frame-ancestors to a policy already set, without replacing it', () => {
    const headers = new Headers({
      'Content-Security-Policy': "default-src 'self';",
    })
    applyFrameDenyHeaders(headers, '/sign-in')
    expect(headers.get('Content-Security-Policy')).toBe(
      "default-src 'self'; frame-ancestors 'none'",
    )
  })

  test('replaces a frame-ancestors directive already set, keeping the rest', () => {
    for (const allowed of ["'self'", '*', 'https://attacker.example']) {
      const headers = new Headers({
        'Content-Security-Policy': `default-src 'self'; frame-ancestors ${allowed}; img-src *`,
      })
      applyFrameDenyHeaders(headers, '/oauth2/consent')
      expect(headers.get('Content-Security-Policy')).toBe(
        "default-src 'self'; img-src *; frame-ancestors 'none'",
      )
      expect(headers.get('X-Frame-Options')).toBe('DENY')
    }
  })

  test('keeps separate policies apart, replacing frame-ancestors in each', () => {
    const headers = new Headers()
    headers.append(
      'Content-Security-Policy',
      "default-src 'self'; frame-ancestors *",
    )
    headers.append('Content-Security-Policy', "script-src 'self'")
    applyFrameDenyHeaders(headers, '/oauth2/device')
    expect(headers.get('Content-Security-Policy')).toBe(
      "default-src 'self'; frame-ancestors 'none', script-src 'self'; frame-ancestors 'none'",
    )
  })

  test('leaves other console pages alone', () => {
    for (const pathname of [
      '/',
      '/organizations/abc',
      '/console/projects/x',
      '/sign-in-help',
    ]) {
      const headers = applyFrameDenyHeaders(new Headers(), pathname)
      expect(headers.get('X-Frame-Options')).toBeNull()
      expect(headers.get('Content-Security-Policy')).toBeNull()
    }
  })

  test('covers every screen of the _auth layout', () => {
    const directory = path.join(import.meta.dir, '../../src/routes/_auth')
    const paths = readdirSync(directory)
      .filter((file) => file.endsWith('.tsx'))
      .map((file) => '/' + file.replace(/\.tsx$/, '').replaceAll('.', '/'))

    expect(paths.length).toBeGreaterThan(0)
    for (const pathname of paths) {
      expect({ pathname, framed: isAuthScreenPath(pathname) }).toEqual({
        pathname,
        framed: true,
      })
    }
  })
})
