import { describe, expect, test } from 'bun:test'
import {
  DOCUMENT_SECURITY_HEADERS,
  applyDocumentSecurityHeaders,
  withDocumentSecurityHeaders,
} from '@/lib/security-headers'

describe('applyDocumentSecurityHeaders', () => {
  test('limits framing to this origin and blocks MIME sniffing', () => {
    const headers = applyDocumentSecurityHeaders(
      new Headers({ 'Content-Type': 'text/html; charset=utf-8' }),
    )
    expect(headers.get('x-frame-options')).toBe('SAMEORIGIN')
    expect(headers.get('content-security-policy')).toBe(
      "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
    )
    expect(headers.get('x-content-type-options')).toBe('nosniff')
    expect(headers.get('referrer-policy')).toBe(
      'strict-origin-when-cross-origin',
    )
    expect(headers.get('content-type')).toBe('text/html; charset=utf-8')
  })

  test('keeps a stricter value a route already set', () => {
    const headers = applyDocumentSecurityHeaders(
      new Headers({
        'Content-Security-Policy': "frame-ancestors 'none'",
        'X-Frame-Options': 'DENY',
      }),
    )
    expect(headers.get('content-security-policy')).toBe(
      "frame-ancestors 'none'",
    )
    expect(headers.get('x-frame-options')).toBe('DENY')
    expect(headers.get('x-content-type-options')).toBe('nosniff')
  })
})

describe('withDocumentSecurityHeaders', () => {
  test('adds the headers to HTML responses and keeps the response', async () => {
    const response = withDocumentSecurityHeaders(
      new Response('<!doctype html><p>ok</p>', {
        status: 404,
        statusText: 'Not Found',
        headers: {
          'Content-Type': 'text/html; charset=utf-8',
          'Cache-Control': 'no-store',
        },
      }),
    )
    for (const [name, value] of Object.entries(DOCUMENT_SECURITY_HEADERS)) {
      expect(response.headers.get(name)).toBe(value)
    }
    expect(response.status).toBe(404)
    expect(response.statusText).toBe('Not Found')
    expect(response.headers.get('cache-control')).toBe('no-store')
    expect(await response.text()).toBe('<!doctype html><p>ok</p>')
  })

  test('leaves other responses alone', () => {
    const json = new Response('{}', {
      headers: { 'Content-Type': 'application/json' },
    })
    expect(withDocumentSecurityHeaders(json)).toBe(json)
    const redirect = Response.redirect('https://cloud.appwrite.io/', 302)
    expect(withDocumentSecurityHeaders(redirect)).toBe(redirect)
  })
})
