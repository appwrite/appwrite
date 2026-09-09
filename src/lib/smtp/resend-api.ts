/**
 * Resend REST adapter for the SMTP quick setup (browser side).
 *
 * `api.resend.com` sends no CORS headers, so requests go through the console's
 * own `_api/resend/*` server routes (see `resend-proxy.ts`), which forward the
 * bearer token verbatim and never persist it.
 */

import type { QuickSetupDomain } from './quick-setup'
import type { SmtpQuickSetupCredential } from './providers'

/** Mount point of the `src/routes/_api/resend/*` server routes. */
export const RESEND_PROXY_BASE_PATH = '/resend'

export class ResendApiError extends Error {
  status: number
  code?: string

  constructor(message: string, status: number, code?: string) {
    super(message)
    this.name = 'ResendApiError'
    this.status = status
    this.code = code
  }
}

export function isResendUnauthorizedError(error: unknown): boolean {
  return error instanceof ResendApiError && error.status === 401
}

type ResendErrorPayload = {
  message?: unknown
  name?: unknown
  statusCode?: unknown
}

function readErrorPayload(payload: unknown): {
  message?: string
  code?: string
} {
  if (!payload || typeof payload !== 'object') return {}
  const record = payload as ResendErrorPayload
  return {
    message:
      typeof record.message === 'string' && record.message.trim()
        ? record.message
        : undefined,
    code: typeof record.name === 'string' ? record.name : undefined,
  }
}

async function resendFetch<T>(
  path: string,
  accessToken: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Authorization', `Bearer ${accessToken}`)
  if (init.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${RESEND_PROXY_BASE_PATH}${path}`, {
    ...init,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
  })

  const text = await response.text()
  let payload: unknown = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    const { message, code } = readErrorPayload(payload)
    throw new ResendApiError(
      message ?? `Resend request failed (${response.status})`,
      response.status,
      code,
    )
  }

  return payload as T
}

type ResendDomainResponse = {
  id?: unknown
  name?: unknown
  status?: unknown
}

function normalizeDomain(raw: ResendDomainResponse): QuickSetupDomain | null {
  if (typeof raw.id !== 'string' || typeof raw.name !== 'string') return null
  return {
    id: raw.id,
    name: raw.name,
    verified: raw.status === 'verified',
  }
}

const DOMAINS_PAGE_SIZE = 100
const DOMAINS_MAX_PAGES = 10

/** Every domain on the connected Resend account (verified or not). */
export async function listResendDomains(
  accessToken: string,
): Promise<QuickSetupDomain[]> {
  const domains: QuickSetupDomain[] = []
  let after: string | undefined

  for (let page = 0; page < DOMAINS_MAX_PAGES; page++) {
    const params = new URLSearchParams({ limit: String(DOMAINS_PAGE_SIZE) })
    if (after) params.set('after', after)

    const result = await resendFetch<{
      data?: ResendDomainResponse[]
      has_more?: boolean
    }>(`/domains?${params.toString()}`, accessToken)

    const chunk = (result?.data ?? [])
      .map(normalizeDomain)
      .filter((domain): domain is QuickSetupDomain => domain !== null)
    domains.push(...chunk)

    if (!result?.has_more || chunk.length === 0) break
    after = chunk[chunk.length - 1]!.id
  }

  return domains
}

/** Mint a sending-only API key, optionally restricted to one domain. */
export async function createResendCredential(
  accessToken: string,
  input: { name: string; domainId?: string },
): Promise<SmtpQuickSetupCredential> {
  const result = await resendFetch<{ id?: unknown; token?: unknown }>(
    '/api-keys',
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({
        name: input.name,
        permission: 'sending_access',
        ...(input.domainId ? { domain_id: input.domainId } : {}),
      }),
    },
  )

  if (typeof result?.id !== 'string' || typeof result?.token !== 'string') {
    throw new ResendApiError('Resend did not return an API key', 502)
  }
  return { id: result.id, secret: result.token }
}
