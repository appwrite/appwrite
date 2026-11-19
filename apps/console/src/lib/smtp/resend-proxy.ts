/**
 * Server-side relay for the handful of Resend API calls the SMTP quick setup
 * needs. `api.resend.com` has no CORS support, so the browser cannot call it
 * directly; the shared guard rails live in `relay.ts`.
 *
 * Used by `src/routes/_api/resend/*`.
 */

import { sanitizeCreateApiKeyBody } from './resend'
import {
  forwardQuery,
  forwardToProvider,
  guardRelayRequest,
  jsonError,
  readJsonBody,
} from './relay'

const RESEND_API_BASE_URL = 'https://api.resend.com'
const FORWARDED_QUERY_PARAMS = ['limit', 'after', 'before'] as const

const UNREACHABLE = {
  name: 'resend_unreachable',
  message: 'Could not reach the Resend API. Try again in a moment.',
}

export type ResendUpstream =
  | { method: 'GET'; path: string; forwardQuery?: boolean }
  | { method: 'DELETE'; path: string }
  | { method: 'POST'; path: string; body: string }

/** Shared guard rails, then forward. */
export async function proxyResendRequest(
  request: Request,
  upstream: ResendUpstream,
): Promise<Response> {
  const guard = guardRelayRequest(request, 'Resend')
  if ('refusal' in guard) return guard.refusal

  const url = new URL(`${RESEND_API_BASE_URL}${upstream.path}`)
  if (upstream.method === 'GET' && upstream.forwardQuery) {
    forwardQuery(url, request.url, FORWARDED_QUERY_PARAMS)
  }
  return await forwardToProvider(
    guard.authorization,
    {
      method: upstream.method,
      url,
      body: upstream.method === 'POST' ? upstream.body : undefined,
    },
    UNREACHABLE,
  )
}

/**
 * `POST /api-keys`: the payload is rebuilt server-side so this relay can only
 * mint sending-only keys, whatever the caller sends.
 */
export async function proxyCreateResendApiKey(
  request: Request,
): Promise<Response> {
  const parsed = await readJsonBody(request)
  if ('refusal' in parsed) return parsed.refusal

  const body = sanitizeCreateApiKeyBody(parsed.body)
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
