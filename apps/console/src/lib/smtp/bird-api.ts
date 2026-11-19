/**
 * Bird REST adapter for the SMTP quick setup (browser side).
 *
 * Bird's platform API sends no CORS headers, so requests go through the
 * console's own `_api/bird/*` server routes (see `bird-proxy.ts`), which
 * forward the bearer token verbatim and never persist it.
 */

import { birdRegion, type BirdKeyChannel } from './bird'
import type { QuickSetupDomain } from './quick-setup'
import type { SmtpQuickSetupCredential } from './providers'

/** Mount point of the `src/routes/_api/bird/*` server routes. */
export const BIRD_PROXY_BASE_PATH = '/bird'

export class BirdApiError extends Error {
  status: number
  code?: string

  constructor(message: string, status: number, code?: string) {
    super(message)
    this.name = 'BirdApiError'
    this.status = status
    this.code = code
  }
}

export function isBirdUnauthorizedError(error: unknown): boolean {
  return error instanceof BirdApiError && error.status === 401
}

/**
 * Bird answers `{ error: { name, message } }`; the relay's own refusals use
 * `{ name, message }` at the top level.
 */
function readErrorPayload(payload: unknown): {
  message?: string
  code?: string
} {
  if (!payload || typeof payload !== 'object') return {}
  const outer = payload as { error?: unknown }
  const record = (
    outer.error && typeof outer.error === 'object' ? outer.error : payload
  ) as { message?: unknown; name?: unknown }
  return {
    message:
      typeof record.message === 'string' && record.message.trim()
        ? record.message
        : undefined,
    code: typeof record.name === 'string' ? record.name : undefined,
  }
}

async function birdFetch<T>(
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

  const response = await fetch(`${BIRD_PROXY_BASE_PATH}${path}`, {
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
    throw new BirdApiError(
      message ?? `Bird request failed (${response.status})`,
      response.status,
      code,
    )
  }

  return payload as T
}

type BirdDomainResponse = {
  id?: unknown
  domain?: unknown
  capabilities?: { sending?: { status?: unknown } }
}

/**
 * A domain can send once its `sending` capability is verified. `warning`
 * means a record stopped matching but is still inside Bird's grace period, so
 * sending is not affected yet.
 */
function normalizeDomain(
  raw: BirdDomainResponse,
  region: string,
): QuickSetupDomain | null {
  if (typeof raw.id !== 'string' || typeof raw.domain !== 'string') return null
  const sending = raw.capabilities?.sending?.status
  return {
    id: raw.id,
    name: raw.domain,
    verified: sending === 'verified' || sending === 'warning',
    region,
  }
}

const LIST_PAGE_SIZE = 100
const LIST_MAX_PAGES = 10

/** Every sending domain in the connected Bird workspace (verified or not). */
export async function listBirdDomains(
  accessToken: string,
): Promise<QuickSetupDomain[]> {
  const region = birdRegion(accessToken)
  if (!region) {
    throw new BirdApiError('The Bird access token does not name a region', 401)
  }

  const domains: QuickSetupDomain[] = []
  let cursor: string | undefined

  for (let page = 0; page < LIST_MAX_PAGES; page++) {
    const params = new URLSearchParams({ limit: String(LIST_PAGE_SIZE) })
    if (cursor) params.set('starting_after', cursor)

    const result = await birdFetch<{
      data?: BirdDomainResponse[]
      next_cursor?: unknown
    }>(`/domains?${params.toString()}`, accessToken)

    const chunk = (result?.data ?? [])
      .map((raw) => normalizeDomain(raw, region))
      .filter((domain): domain is QuickSetupDomain => domain !== null)
    domains.push(...chunk)

    if (typeof result?.next_cursor !== 'string' || !result.next_cursor) break
    cursor = result.next_cursor
  }

  return domains
}

async function mintBirdKey(
  accessToken: string,
  name: string,
  channel: BirdKeyChannel,
): Promise<SmtpQuickSetupCredential> {
  const result = await birdFetch<{ id?: unknown; token?: unknown }>(
    '/api-keys',
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ name, channel }),
    },
  )

  if (typeof result?.id !== 'string' || typeof result?.token !== 'string') {
    throw new BirdApiError('Bird did not return an API key', 502)
  }
  return { id: result.id, secret: result.token }
}

/**
 * Mint a key that can only send email. Bird keys are scoped by permission,
 * not by domain, so the key can send from any verified domain in the
 * workspace and `domainId` is not used.
 */
export async function createBirdCredential(
  accessToken: string,
  input: { name: string; domainId?: string },
): Promise<SmtpQuickSetupCredential> {
  return await mintBirdKey(accessToken, input.name, 'email')
}

/** Mint a key that can only send SMS, for the Bird messaging provider. */
export async function createBirdSmsCredential(
  accessToken: string,
  input: { name: string },
): Promise<SmtpQuickSetupCredential> {
  return await mintBirdKey(accessToken, input.name, 'sms')
}

type BirdSenderResponse = {
  id?: unknown
  sender?: unknown
  status?: unknown
}

/**
 * Senders the workspace owns (numbers and alphanumeric IDs), in the domain
 * shape the one-click picker already renders. Whether a sender may reach a
 * country is decided per destination by Bird, so only rejected senders are
 * left unselectable.
 */
export async function listBirdSmsSenders(
  accessToken: string,
): Promise<QuickSetupDomain[]> {
  const senders: QuickSetupDomain[] = []
  let cursor: string | undefined

  for (let page = 0; page < LIST_MAX_PAGES; page++) {
    const params = new URLSearchParams({ limit: String(LIST_PAGE_SIZE) })
    if (cursor) params.set('starting_after', cursor)

    const result = await birdFetch<{
      data?: BirdSenderResponse[]
      next_cursor?: unknown
    }>(`/senders?${params.toString()}`, accessToken)

    for (const raw of result?.data ?? []) {
      if (typeof raw.id !== 'string' || typeof raw.sender !== 'string') continue
      senders.push({
        id: raw.id,
        name: raw.sender,
        verified: raw.status !== 'rejected',
      })
    }

    if (typeof result?.next_cursor !== 'string' || !result.next_cursor) break
    cursor = result.next_cursor
  }

  return senders
}

/** Best-effort cleanup when a freshly minted key could not be saved to the project. */
export async function deleteBirdCredential(
  accessToken: string,
  credentialId: string,
): Promise<void> {
  await birdFetch<unknown>(
    `/api-keys/${encodeURIComponent(credentialId)}`,
    accessToken,
    { method: 'DELETE' },
  )
}
