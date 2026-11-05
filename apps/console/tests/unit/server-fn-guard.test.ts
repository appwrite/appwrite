import { describe, expect, test } from 'bun:test'
import {
  SERVER_FN_RESPONSE_CSP,
  createServerFnRejectionResponse,
  getServerFnRequestRejection,
  hardenServerFnResponse,
} from '@/lib/server-fn-guard'

const SERVER_FN_URL =
  'https://console.appwrite.io/_serverFn/2c1f6a8b?payload=%5B%7B%22t%22%3A10%7D%5D'

// Header shape produced by @tanstack/start-client-core's serverFnFetcher plus
// the fetch metadata browsers add to a same-origin fetch() call.
function rpcRequest(headers: Record<string, string> = {}) {
  return new Request(SERVER_FN_URL, {
    headers: {
      'x-tsr-serverFn': 'true',
      accept: 'application/x-tss-framed, application/x-ndjson, application/json',
      'sec-fetch-mode': 'cors',
      'sec-fetch-dest': 'empty',
      'sec-fetch-site': 'same-origin',
      ...headers,
    },
  })
}

// Header shape of a browser following a crafted link (address bar, anchor,
// window.open): no custom headers, document destination, HTML accept.
function navigationRequest(headers: Record<string, string> = {}) {
  return new Request(SERVER_FN_URL, {
    headers: {
      accept:
        'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'sec-fetch-mode': 'navigate',
      'sec-fetch-dest': 'document',
      'sec-fetch-site': 'cross-site',
      ...headers,
    },
  })
}

describe('server function request boundary', () => {
  test('accepts calls made by the TanStack RPC client', () => {
    expect(getServerFnRequestRejection(rpcRequest())).toBeNull()
    // Custom fetch implementations may omit fetch metadata entirely.
    expect(
      getServerFnRequestRejection(
        new Request(SERVER_FN_URL, { headers: { 'X-TSR-ServerFn': 'true' } }),
      ),
    ).toBeNull()
  })

  test('rejects requests without the exact RPC header value', () => {
    expect(getServerFnRequestRejection(new Request(SERVER_FN_URL))).toBe(
      'missing-rpc-header',
    )
    for (const value of ['', '1', 'True', 'yes']) {
      expect(
        getServerFnRequestRejection(rpcRequest({ 'x-tsr-serverFn': value })),
      ).toBe('missing-rpc-header')
    }
  })

  test('rejects browser navigations', () => {
    expect(getServerFnRequestRejection(navigationRequest())).toBe(
      'missing-rpc-header',
    )
    // Even with the header present, a document load is never a valid RPC call.
    expect(
      getServerFnRequestRejection(
        navigationRequest({ 'x-tsr-serverFn': 'true' }),
      ),
    ).toBe('navigation')
    expect(
      getServerFnRequestRejection(rpcRequest({ 'sec-fetch-dest': 'iframe' })),
    ).toBe('navigation')
    expect(
      getServerFnRequestRejection(rpcRequest({ accept: 'text/html' })),
    ).toBe('navigation')
  })

  test('rejection is a sandboxed plain-text 403', async () => {
    const response = createServerFnRejectionResponse()
    expect(response.status).toBe(403)
    expect(response.headers.get('content-type')).toBe(
      'text/plain; charset=utf-8',
    )
    expect(response.headers.get('content-security-policy')).toBe(
      SERVER_FN_RESPONSE_CSP,
    )
    expect(response.headers.get('x-content-type-options')).toBe('nosniff')
    expect(response.headers.get('cache-control')).toBe('no-store')
    expect(await response.text()).toBe('Forbidden')
  })
})

describe('server function response hardening', () => {
  test('sandboxes a real response in place', async () => {
    const response = Response.json({ ok: true }, { status: 201 })
    const hardened = hardenServerFnResponse(response)
    expect(hardened).toBe(response)
    expect(hardened.status).toBe(201)
    expect(hardened.headers.get('content-security-policy')).toBe(
      SERVER_FN_RESPONSE_CSP,
    )
    expect(hardened.headers.get('x-content-type-options')).toBe('nosniff')
    expect(await hardened.json()).toEqual({ ok: true })
  })

  test('keeps streaming bodies intact', () => {
    const body = new ReadableStream()
    const response = new Response(body, {
      headers: { 'content-type': 'application/x-tss-framed' },
    })
    expect(hardenServerFnResponse(response).body).toBe(body)
  })

  test('replaces anything that is not a Response with a plain 500', async () => {
    // Shape used by the reported attack: a deserialized object that h3 would
    // otherwise accept as an HTTPResponse and serve as HTML.
    const forged = {
      constructor: { name: 'HTTPResponse' },
      status: 200,
      headers: [['content-type', 'text/html']],
      body: '<script>globalThis.compromised = true</script>',
    }
    const values: Array<unknown> = [
      forged,
      { response: forged, serverSsrCleanup: 'none' },
      '<script>globalThis.compromised = true</script>',
      null,
      undefined,
    ]
    for (const value of values) {
      const hardened = hardenServerFnResponse(value)
      expect(hardened).toBeInstanceOf(Response)
      expect(hardened.status).toBe(500)
      expect(hardened.headers.get('content-type')).toBe(
        'text/plain; charset=utf-8',
      )
      expect(hardened.headers.get('content-security-policy')).toBe(
        SERVER_FN_RESPONSE_CSP,
      )
      expect(await hardened.text()).toBe('Internal Server Error')
    }
  })

  test('rebuilds responses whose headers are immutable', async () => {
    const response = new Response('payload', {
      status: 200,
      headers: { 'content-type': 'application/json' },
    })
    const immutableHeaders = new Headers(response.headers)
    immutableHeaders.set = () => {
      throw new TypeError('immutable')
    }
    Object.defineProperty(response, 'headers', { value: immutableHeaders })

    const hardened = hardenServerFnResponse(response)
    expect(hardened).not.toBe(response)
    expect(hardened.status).toBe(200)
    expect(hardened.headers.get('content-type')).toBe('application/json')
    expect(hardened.headers.get('content-security-policy')).toBe(
      SERVER_FN_RESPONSE_CSP,
    )
    expect(await hardened.text()).toBe('payload')
  })
})
