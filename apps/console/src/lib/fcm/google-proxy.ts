/**
 * Server-side relay for the Google Cloud calls the FCM quick setup makes
 * (listing Firebase projects, the IAM preflight, and provisioning a service
 * account key). It forwards the caller's bearer token to a fixed set of Google
 * API hosts and returns the response body without storing either.
 *
 * Unlike Resend, Google's APIs do send CORS headers, so this is not strictly
 * required for the browser to reach them. It is kept so the token leaves the
 * browser only toward allow-listed Google hosts, mirroring the Resend relay and
 * giving the flow one origin-guarded path to the provider.
 *
 * Used by `src/routes/_api/google/$.tsx`.
 */

import { GOOGLE_RELAY_HOSTS, isGoogleRelayService } from './google'

const UPSTREAM_TIMEOUT_MS = 15_000
// setIamPolicy sends the whole existing policy back; projects with many members
// or conditional bindings can be well over 16 KiB. Google caps an IAM policy at
// ~64 KiB, so allow comfortably above that while still bounding the relay.
const MAX_REQUEST_BODY_BYTES = 256 * 1024

const NO_STORE_HEADERS = {
  'Cache-Control': 'no-store',
  'X-Robots-Tag': 'noindex',
} as const

const ALLOWED_METHODS = new Set(['GET', 'POST', 'DELETE'])

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
function readBearerAuthorization(request: Request): string | null {
  const header = request.headers.get('authorization')?.trim() ?? ''
  return /^Bearer\s+\S+$/i.test(header) ? header : null
}

/**
 * Browsers label cross-site fetches through `Sec-Fetch-Site`; reject those so
 * the relay only serves the console itself. Non-browser clients omit the header
 * and are allowed (they could call Google directly anyway).
 */
function isAllowedFetchSite(request: Request): boolean {
  const site = request.headers.get('sec-fetch-site')
  return !site || site === 'same-origin' || site === 'none'
}

/**
 * Forward one relayed call to its Google host. `splat` is the path the browser
 * addressed under `_api/google`, i.e. `<service>/<rest-of-google-path>`; the
 * leading service segment selects the host and the rest is passed through.
 */
export async function proxyGoogleRequest(
  request: Request,
  splat: string,
): Promise<Response> {
  if (!isAllowedFetchSite(request)) {
    return jsonError(403, 'forbidden', 'Cross-site requests are not allowed')
  }
  if (!ALLOWED_METHODS.has(request.method)) {
    return jsonError(405, 'method_not_allowed', 'Method not allowed')
  }

  const authorization = readBearerAuthorization(request)
  if (!authorization) {
    return jsonError(
      401,
      'missing_access_token',
      'A Google access token is required',
    )
  }

  const separator = splat.indexOf('/')
  const service = separator === -1 ? splat : splat.slice(0, separator)
  const path = separator === -1 ? '' : splat.slice(separator)
  if (!isGoogleRelayService(service)) {
    return jsonError(404, 'unknown_upstream', 'Unknown Google API')
  }

  const target = new URL(`https://${GOOGLE_RELAY_HOSTS[service]}${path}`)
  for (const [key, value] of new URL(request.url).searchParams) {
    target.searchParams.set(key, value)
  }

  const headers: Record<string, string> = {
    Authorization: authorization,
    Accept: 'application/json',
  }
  // Quota/consumer project: scopes the call (and API-enablement) to the user's
  // own Firebase project rather than the OAuth client's project. Only a plain
  // project id is forwarded.
  const quotaProject = request.headers.get('x-goog-user-project')?.trim()
  // A project id (letter-led) or a bare project number.
  if (
    quotaProject &&
    (/^[a-z][a-z0-9-]{4,29}$/.test(quotaProject) ||
      /^[0-9]{1,30}$/.test(quotaProject))
  ) {
    headers['X-Goog-User-Project'] = quotaProject
  }
  let body: string | undefined
  if (request.method === 'POST') {
    body = await request.text()
    if (body.length > MAX_REQUEST_BODY_BYTES) {
      return jsonError(413, 'payload_too_large', 'Request body is too large')
    }
    headers['Content-Type'] = 'application/json'
  }

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT_MS)
  try {
    const response = await fetch(target, {
      method: request.method,
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
      'google_unreachable',
      'Could not reach the Google API. Try again in a moment.',
    )
  } finally {
    clearTimeout(timer)
  }
}
