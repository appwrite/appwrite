/**
 * Resend quick setup for project SMTP: shared constants and pure helpers.
 *
 * Safe to import from both the browser and the `_api` server routes. Anything
 * that talks to Appwrite lives in `resend-oauth.ts`; anything that talks to
 * the Resend API lives in `resend-api.ts` (browser) and `resend-proxy.ts`
 * (server).
 */

import { OAuthProvider } from '@appwrite.io/console'

/** Console OAuth2 provider id, also the `provider` value on sessions and identities. */
export const RESEND_PROVIDER_ID: string = OAuthProvider.Resend

/**
 * Resend only issues `emails:send` and `full_access`. Appwrite's adapter asks
 * for `emails:send` by default; creating API keys and listing domains needs
 * `full_access`, so it is requested explicitly on top.
 */
export const RESEND_OAUTH_SCOPES = ['full_access']

/** SMTP relay settings documented at https://resend.com/docs/send-with-smtp */
export const RESEND_SMTP_HOST = 'smtp.resend.com'
export const RESEND_SMTP_PORT = 587
export const RESEND_SMTP_USERNAME = 'resend'
export const RESEND_SMTP_SECURE = 'tls' as const

export const RESEND_DOMAINS_URL = 'https://resend.com/domains'
export const RESEND_API_KEY_NAME_MAX_LENGTH = 50
export const RESEND_DEFAULT_SENDER_LOCAL_PART = 'noreply'

/**
 * Resend access tokens live 15 minutes. Anything expiring inside this window
 * is treated as expired so a request never races the expiry.
 */
export const PROVIDER_TOKEN_EXPIRY_SKEW_MS = 60_000

export function isProviderTokenExpired(
  expiry: string | null | undefined,
  now: number = Date.now(),
  skewMs: number = PROVIDER_TOKEN_EXPIRY_SKEW_MS,
): boolean {
  if (!expiry) return true
  const timestamp = Date.parse(expiry)
  if (Number.isNaN(timestamp)) return true
  return timestamp - skewMs <= now
}

// ---------------------------------------------------------------------------
// OAuth2 round trip (success / failure URLs and the search params they carry)
// ---------------------------------------------------------------------------

/** Query param that marks a return from the Resend OAuth2 flow. */
export const RESEND_RETURN_PARAM = 'resend'

export type ResendReturnSearch =
  | { status: 'connected'; userId: string; secret: string }
  | { status: 'failed'; message?: string }

function asNonEmptyString(value: unknown): string | undefined {
  if (typeof value === 'string') return value.trim() || undefined
  // TanStack Router JSON-parses search values, so an all-digit id arrives as a number.
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return undefined
}

/** Appwrite appends `error` as a JSON string `{ message, type, code }` to the failure URL. */
export function parseOAuthErrorMessage(error: unknown): string | undefined {
  const raw = asNonEmptyString(error)
  if (!raw) return undefined
  try {
    const parsed = JSON.parse(raw) as { message?: unknown }
    if (
      parsed &&
      typeof parsed === 'object' &&
      typeof parsed.message === 'string'
    ) {
      return parsed.message.trim() || undefined
    }
  } catch {
    // Not JSON, use the raw value.
  }
  return raw
}

/**
 * Read the OAuth2 return state from route search params. Returns `null` when
 * the page was not reached through the Resend flow.
 */
export function parseResendReturnSearch(
  search: unknown,
): ResendReturnSearch | null {
  if (!search || typeof search !== 'object') return null
  const params = search as Record<string, unknown>
  const status = params[RESEND_RETURN_PARAM]

  if (status === 'connected') {
    const userId = asNonEmptyString(params.userId)
    const secret = asNonEmptyString(params.secret)
    if (!userId || !secret) {
      return { status: 'failed' }
    }
    return { status: 'connected', userId, secret }
  }

  if (status === 'failed') {
    return { status: 'failed', message: parseOAuthErrorMessage(params.error) }
  }

  return null
}

/** Drop every param the Resend round trip adds; keeps unrelated search state. */
export function stripResendReturnSearch(
  prev: unknown,
): Record<string, unknown> {
  const next: Record<string, unknown> =
    prev && typeof prev === 'object'
      ? { ...(prev as Record<string, unknown>) }
      : {}
  delete next[RESEND_RETURN_PARAM]
  delete next.userId
  delete next.secret
  delete next.error
  return next
}

/** Success and failure URLs bring the user straight back to the SMTP tab. */
export function buildResendOAuthUrls(
  origin: string,
  projectId: string,
): { success: string; failure: string } {
  const base = `${origin}/projects/${encodeURIComponent(projectId)}/settings/smtp`
  return {
    success: `${base}?${RESEND_RETURN_PARAM}=connected`,
    failure: `${base}?${RESEND_RETURN_PARAM}=failed`,
  }
}

// ---------------------------------------------------------------------------
// Resend resources
// ---------------------------------------------------------------------------

export interface ResendDomain {
  id: string
  name: string
  /** `not_started` | `pending` | `verified` | `failed` | `temporary_failure` */
  status: string
  region?: string
}

export function isVerifiedResendDomain(domain: Pick<ResendDomain, 'status'>) {
  return domain.status === 'verified'
}

/** Verified domains first, then alphabetical, so the default pick is usable. */
export function sortResendDomains<T extends ResendDomain>(domains: T[]): T[] {
  return [...domains].sort((a, b) => {
    const verifiedDelta =
      Number(isVerifiedResendDomain(b)) - Number(isVerifiedResendDomain(a))
    if (verifiedDelta !== 0) return verifiedDelta
    return a.name.localeCompare(b.name)
  })
}

/** Resend caps API key names at 50 characters. */
export function buildResendApiKeyName(projectName: string): string {
  const prefix = 'Appwrite SMTP'
  const trimmed = projectName.trim()
  const name = trimmed ? `${prefix}: ${trimmed}` : prefix
  if (name.length <= RESEND_API_KEY_NAME_MAX_LENGTH) return name
  return name.slice(0, RESEND_API_KEY_NAME_MAX_LENGTH).trimEnd()
}

export function defaultResendSenderEmail(domainName: string): string {
  return `${RESEND_DEFAULT_SENDER_LOCAL_PART}@${domainName}`
}

export function emailBelongsToDomain(email: string, domainName: string) {
  const trimmed = email.trim()
  const at = trimmed.lastIndexOf('@')
  if (at <= 0 || at === trimmed.length - 1) return false
  return trimmed.slice(at + 1).toLowerCase() === domainName.trim().toLowerCase()
}

/**
 * Prefer the domain the project already sends from; otherwise the first
 * verified domain. Returns `undefined` when nothing is verified.
 */
export function pickDefaultResendDomain(
  domains: ResendDomain[],
  currentSenderEmail: string | null | undefined,
): ResendDomain | undefined {
  const verified = domains.filter(isVerifiedResendDomain)
  if (currentSenderEmail) {
    const match = verified.find((domain) =>
      emailBelongsToDomain(currentSenderEmail, domain.name),
    )
    if (match) return match
  }
  return verified[0]
}

/**
 * Sanitized body for `POST /api-keys`. The server proxy rebuilds the payload
 * from this so the relay can only ever mint sending-only keys.
 */
export interface CreateResendApiKeyBody {
  name: string
  permission: 'sending_access'
  domain_id?: string
}

export function sanitizeCreateApiKeyBody(
  input: unknown,
): CreateResendApiKeyBody | null {
  if (!input || typeof input !== 'object') return null
  const record = input as Record<string, unknown>
  const name = typeof record.name === 'string' ? record.name.trim() : ''
  if (!name || name.length > RESEND_API_KEY_NAME_MAX_LENGTH) return null

  const body: CreateResendApiKeyBody = { name, permission: 'sending_access' }
  const domainId = record.domain_id ?? record.domainId
  if (typeof domainId === 'string' && domainId.trim()) {
    body.domain_id = domainId.trim()
  }
  return body
}
