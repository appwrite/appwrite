/**
 * Server-side relay for the handful of Resend API calls the SMTP quick setup
 * needs. `api.resend.com` has no CORS support, so the browser cannot call it
 * directly; these helpers forward the caller's bearer token and the response
 * body without storing either.
 *
 * Used by `src/routes/_api/resend/*`.
 */

import { sanitizeCreateApiKeyBody } from './resend'

const RESEND_API_BASE_URL = 'https://api.resend.com'
const UPSTREAM_TIMEOUT_MS = 15_000
const MAX_REQUEST_BODY_BYTES = 8 * 1024
const FORWARDED_QUERY_PARAMS = ['limit', 'after', 'before'] as const

const NO_STORE_HEADERS = {
  'Cache-Control': 'no-store',
  'X-Robots-Tag': 'noindex',
} as const

export function jsonError(
  status: number,
  name: string,
  message: string,
): Response {
  return Response.json(
    { statusCode: status, name, message },
    { status, headers: NO_STORE_HEADERS },
  )
}

/** `Authorization: Bearer <token>` header from the incoming request, or null. */
export function readBearerAuthorization(request: Request): string | null {
  const header = request.headers.get('authorization')?.trim() ?? ''
  return /^Bearer\s+\S+$/i.test(header) ? header : null
}

/**
 * Browsers label cross-site fetches through `Sec-Fetch-Site`; reject those so
 * the relay only serves the console itself. Non-browser clients omit the
 * header and are allowed (they could call Resend directly anyway).
 */
export function isAllowedFetchSite(request: Request): boolean {
  const site = request.headers.get('sec-fetch-site')
  return !site || site === 'same-origin' || site === 'none'
}

export type ResendUpstream =
  | { method: 'GET'; path: string; forwardQuery?: boolean }
  | { method: 'DELETE'; path: string }
  | { method: 'POST'; path: string; body: string }

async function forwardToResend(
  authorization: string,
  upstream: ResendUpstream,
  incomingUrl: string,
): Promise<Response> {
  const url = new URL(`${RESEND_API_BASE_URL}${upstream.path}`)
  if (upstream.method === 'GET' && upstream.forwardQuery) {
    const incoming = new URL(incomingUrl).searchParams
    for (const key of FORWARDED_QUERY_PARAMS) {
      const value = incoming.get(key)
      if (value) url.searchParams.set(key, value)
    }
  }

  const headers: Record<string, string> = {
    Authorization: authorization,
    Accept: 'application/json',
  }
  let body: string | undefined
  if (upstream.method === 'POST') {
    headers['Content-Type'] = 'application/json'
    body = upstream.body
  }

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT_MS)
  try {
    const response = await fetch(url, {
      method: upstream.method,
      headers,
      body,
      signal: controller.signal,
      redirect: 'error',
    })
    const text = await response.text()
    return new Response(text, {
      status: response.status,
      headers: {
        ...NO_STORE_HEADERS,
        'Content-Type':
          response.headers.get('content-type') ??
          'application/json; charset=utf-8',
      },
    })
  } catch {
    return jsonError(
      502,
      'resend_unreachable',
      'Could not reach the Resend API. Try again in a moment.',
    )
  } finally {
    clearTimeout(timer)
  }
}

/** Shared guard rails, then forward. */
export async function proxyResendRequest(
  request: Request,
  upstream: ResendUpstream,
): Promise<Response> {
  if (!isAllowedFetchSite(request)) {
    return jsonError(403, 'forbidden', 'Cross-site requests are not allowed')
  }
  const authorization = readBearerAuthorization(request)
  if (!authorization) {
    return jsonError(
      401,
      'missing_access_token',
      'A Resend access token is required',
    )
  }
  return await forwardToResend(authorization, upstream, request.url)
}

/**
 * `POST /api-keys`: the payload is rebuilt server-side so this relay can only
 * mint sending-only keys, whatever the caller sends.
 */
export async function proxyCreateResendApiKey(
  request: Request,
): Promise<Response> {
  const raw = await request.text()
  if (raw.length > MAX_REQUEST_BODY_BYTES) {
    return jsonError(413, 'payload_too_large', 'Request body is too large')
  }

  let parsed: unknown = null
  try {
    parsed = raw ? JSON.parse(raw) : null
  } catch {
    return jsonError(400, 'invalid_json', 'Request body must be JSON')
  }

  const body = sanitizeCreateApiKeyBody(parsed)
  if (!body) {
    return jsonError(
      400,
      'validation_error',
      'A key name of up to 50 characters is required',
    )
  }

  return await proxyResendRequest(request, {
    method: 'POST',
    path: '/api-keys',
    body: JSON.stringify(body),
  })
}
