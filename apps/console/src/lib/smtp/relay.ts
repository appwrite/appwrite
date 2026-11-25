/**
 * Server-side relay for email provider APIs the SMTP quick setup calls from
 * the browser. Provider APIs such as `api.resend.com` and Bird's platform API
 * send no CORS headers, so the console forwards the caller's bearer token and
 * the upstream response without storing either.
 *
 * Each provider keeps its own module (`resend-proxy.ts`, `bird-proxy.ts`) that
 * decides which upstream calls exist and what they may send; this one holds
 * the guard rails they share.
 */

const UPSTREAM_TIMEOUT_MS = 15_000
const MAX_REQUEST_BODY_BYTES = 8 * 1024

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

/** The token from a header {@link readBearerAuthorization} accepted. */
export function bearerToken(authorization: string): string {
  return authorization.slice('Bearer'.length).trim()
}

/**
 * Browsers label cross-site fetches through `Sec-Fetch-Site`; reject those so
 * the relay only serves the console itself. Non-browser clients omit the
 * header and are allowed (they could call the provider directly anyway).
 */
export function isAllowedFetchSite(request: Request): boolean {
  const site = request.headers.get('sec-fetch-site')
  return !site || site === 'same-origin' || site === 'none'
}

/**
 * Shared guard rails before anything reaches the provider. Returns the
 * caller's `Authorization` header, or the response that refuses the request.
 */
export function guardRelayRequest(
  request: Request,
  providerName: string,
): { authorization: string } | { refusal: Response } {
  if (!isAllowedFetchSite(request)) {
    return {
      refusal: jsonError(
        403,
        'forbidden',
        'Cross-site requests are not allowed',
      ),
    }
  }
  const authorization = readBearerAuthorization(request)
  if (!authorization) {
    return {
      refusal: jsonError(
        401,
        'missing_access_token',
        `A ${providerName} access token is required`,
      ),
    }
  }
  return { authorization }
}

/**
 * Request body as text, or `null` once it passes `limit` bytes. The body is
 * read chunk by chunk and abandoned at the limit, so an oversized request is
 * never buffered in full.
 */
async function readBoundedText(
  request: Request,
  limit: number,
): Promise<string | null> {
  const declared = Number(request.headers.get('content-length'))
  if (Number.isFinite(declared) && declared > limit) return null
  if (!request.body) return ''

  const reader = request.body.getReader()
  const chunks: Uint8Array[] = []
  let size = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    size += value.byteLength
    if (size > limit) {
      await reader.cancel().catch(() => {})
      return null
    }
    chunks.push(value)
  }

  const bytes = new Uint8Array(size)
  let offset = 0
  for (const chunk of chunks) {
    bytes.set(chunk, offset)
    offset += chunk.byteLength
  }
  return new TextDecoder().decode(bytes)
}

/**
 * JSON body of the incoming request, capped so the relay never buffers more
 * than a key name needs. Returns the parsed value, or the refusal. Run the
 * {@link guardRelayRequest} checks first, so refused callers are not read.
 */
export async function readJsonBody(
  request: Request,
): Promise<{ body: unknown } | { refusal: Response }> {
  const raw = await readBoundedText(request, MAX_REQUEST_BODY_BYTES)
  if (raw === null) {
    return {
      refusal: jsonError(413, 'payload_too_large', 'Request body is too large'),
    }
  }
  try {
    return { body: raw ? JSON.parse(raw) : null }
  } catch {
    return {
      refusal: jsonError(400, 'invalid_json', 'Request body must be JSON'),
    }
  }
}

export interface RelayUpstream {
  method: 'GET' | 'POST' | 'DELETE'
  url: URL
  /** JSON payload, already rebuilt by the provider module. */
  body?: string
}

/** Forward one call to the provider and pass its response back unchanged. */
export async function forwardToProvider(
  authorization: string,
  upstream: RelayUpstream,
  unreachable: { name: string; message: string },
): Promise<Response> {
  const headers: Record<string, string> = {
    Authorization: authorization,
    Accept: 'application/json',
  }
  if (upstream.body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT_MS)
  try {
    const response = await fetch(upstream.url, {
      method: upstream.method,
      headers,
      body: upstream.body,
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
    return jsonError(502, unreachable.name, unreachable.message)
  } finally {
    clearTimeout(timer)
  }
}

/** Copy the allowed query params from the incoming request onto `url`. */
export function forwardQuery(
  url: URL,
  incomingUrl: string,
  allowed: readonly string[],
): URL {
  const incoming = new URL(incomingUrl).searchParams
  for (const key of allowed) {
    const value = incoming.get(key)
    if (value) url.searchParams.set(key, value)
  }
  return url
}
