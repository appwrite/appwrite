/**
 * Boundary guard for TanStack Start server functions (`/_serverFn/*`).
 *
 * TanStack Start (`@tanstack/start-server-core` <= 1.169.37) let a crafted
 * server-function URL seed internal middleware state from the wire payload and
 * answered a plain browser navigation with attacker-controlled HTML from our
 * own origin (reflected XSS; private TanStack advisory, September 2026). The
 * framework fix is backported in `patches/`; this module is the independent
 * second layer that keeps the vulnerable path unreachable on any version:
 *
 * 1. Only TanStack RPC calls reach a server function. The client fetcher always
 *    sends `x-tsr-serverFn: true`; a browser cannot attach that header to a
 *    navigation, and a cross-site script cannot attach it without a CORS
 *    preflight we never approve.
 * 2. Document loads (fetch metadata or an HTML `Accept`) are refused even when
 *    the header is present.
 * 3. Every server-function response is sandboxed and MIME-locked, so an HTML
 *    body could never run on our origin if one ever got through.
 */

/** Header the TanStack client sends on every server-function call. */
export const SERVER_FN_RPC_HEADER = 'x-tsr-serverFn'

/** Browser-side lockdown for anything served from a server-function URL. */
export const SERVER_FN_RESPONSE_CSP =
  "sandbox; default-src 'none'; form-action 'none'; base-uri 'none'"

const NAVIGATION_FETCH_DESTINATIONS = new Set([
  'document',
  'iframe',
  'frame',
  'embed',
  'object',
])

export type ServerFnRequestRejection = 'missing-rpc-header' | 'navigation'

/** Why a request must not reach a server function, or `null` if it may. */
export function getServerFnRequestRejection(
  request: Request,
): ServerFnRequestRejection | null {
  if (request.headers.get(SERVER_FN_RPC_HEADER) !== 'true') {
    return 'missing-rpc-header'
  }
  if (isDocumentNavigation(request.headers)) {
    return 'navigation'
  }
  return null
}

export function isDocumentNavigation(headers: Headers): boolean {
  if (headers.get('sec-fetch-mode') === 'navigate') {
    return true
  }
  const destination = headers.get('sec-fetch-dest')
  if (destination && NAVIGATION_FETCH_DESTINATIONS.has(destination)) {
    return true
  }
  return (headers.get('accept') ?? '').toLowerCase().includes('text/html')
}

export function applyServerFnResponseHeaders(headers: Headers): Headers {
  headers.set('content-security-policy', SERVER_FN_RESPONSE_CSP)
  headers.set('x-content-type-options', 'nosniff')
  return headers
}

function createPlainTextResponse(body: string, status: number): Response {
  return new Response(body, {
    status,
    headers: applyServerFnResponseHeaders(
      new Headers({
        'content-type': 'text/plain; charset=utf-8',
        'cache-control': 'no-store',
      }),
    ),
  })
}

export function createServerFnRejectionResponse(): Response {
  return createPlainTextResponse('Forbidden', 403)
}

/**
 * Sandbox a server-function response. Anything that is not a real `Response`
 * (for example a deserialized object impersonating one) becomes a plain 500,
 * mirroring the upstream fix.
 */
export function hardenServerFnResponse(response: unknown): Response {
  if (!(response instanceof Response)) {
    return createPlainTextResponse('Internal Server Error', 500)
  }
  try {
    applyServerFnResponseHeaders(response.headers)
    return response
  } catch {
    // Immutable headers (e.g. `Response.redirect()` under Node): rebuild below.
  }
  try {
    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: applyServerFnResponseHeaders(new Headers(response.headers)),
    })
  } catch {
    return createPlainTextResponse('Internal Server Error', 500)
  }
}
