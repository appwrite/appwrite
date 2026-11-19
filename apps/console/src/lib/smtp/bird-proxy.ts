/**
 * Server-side relay for the handful of Bird API calls the SMTP quick setup
 * needs. Bird's platform API sends no CORS headers, so the browser cannot call
 * it directly; the shared guard rails live in `relay.ts`.
 *
 * Bird serves each region from its own host, and the access token's
 * `bt_{region}_` prefix names it. The relay builds the host from that region
 * only after {@link birdRegion} accepts it, so a caller cannot point the relay
 * anywhere but Bird.
 *
 * Used by `src/routes/_api/bird/*`.
 */

import {
  birdApiBaseUrl,
  birdRegion,
  isBirdApiKeyId,
  sanitizeCreateBirdApiKeyBody,
} from './bird'
import {
  bearerToken,
  forwardQuery,
  forwardToProvider,
  guardRelayRequest,
  jsonError,
  readJsonBody,
} from './relay'

const FORWARDED_QUERY_PARAMS = ['limit', 'starting_after'] as const

const UNREACHABLE = {
  name: 'bird_unreachable',
  message: 'Could not reach the Bird API. Try again in a moment.',
}

type BirdUpstream =
  | { method: 'GET'; path: string; forwardQuery?: boolean }
  | { method: 'POST'; path: string; body: string }

async function proxyBirdRequest(
  request: Request,
  upstream: BirdUpstream,
): Promise<Response> {
  const guard = guardRelayRequest(request, 'Bird')
  if ('refusal' in guard) return guard.refusal

  const region = birdRegion(bearerToken(guard.authorization))
  if (!region) {
    return jsonError(
      401,
      'invalid_access_token',
      'The Bird access token does not name a region',
    )
  }

  const url = new URL(`${birdApiBaseUrl(region)}${upstream.path}`)
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

/** `GET /v1/email/domains`, one page at a time. */
export async function proxyListBirdDomains(
  request: Request,
): Promise<Response> {
  return await proxyBirdRequest(request, {
    method: 'GET',
    path: '/v1/email/domains',
    forwardQuery: true,
  })
}

/**
 * `POST /v1/api-keys`: the payload is rebuilt server-side so this relay can
 * only mint keys that send email, whatever the caller sends.
 */
export async function proxyCreateBirdApiKey(
  request: Request,
): Promise<Response> {
  const parsed = await readJsonBody(request)
  if ('refusal' in parsed) return parsed.refusal

  const body = sanitizeCreateBirdApiKeyBody(parsed.body)
  if (!body) {
    return jsonError(
      400,
      'validation_error',
      'A key name of up to 64 characters is required',
    )
  }

  return await proxyBirdRequest(request, {
    method: 'POST',
    path: '/v1/api-keys',
    body: JSON.stringify(body),
  })
}

/** `POST /v1/api-keys/:id/revoke` (cleanup after a failed SMTP save). */
export async function proxyRevokeBirdApiKey(
  request: Request,
  apiKeyId: string,
): Promise<Response> {
  if (!isBirdApiKeyId(apiKeyId)) {
    return jsonError(400, 'validation_error', 'Invalid API key id')
  }
  return await proxyBirdRequest(request, {
    method: 'POST',
    path: `/v1/api-keys/${apiKeyId}/revoke`,
    body: '{}',
  })
}
