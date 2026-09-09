/**
 * Provider-agnostic pieces of the SMTP quick setup.
 *
 * Every email provider we can set up in one click (Resend today, Mailgun and
 * SendGrid next) follows the same shape: authorize through a console OAuth2
 * provider, list the sending domains, mint a sending-only credential, then
 * write it into the project's custom SMTP settings. This module holds the
 * parts of that flow that do not depend on which provider is used.
 *
 * Safe to import from both the browser and the `_api` server routes.
 */

/** Card layouts available while the design is being reviewed (debug menu → Flags). */
export const SMTP_QUICK_SETUP_LAYOUTS = ['rows', 'tiles', 'dropdown'] as const

export type SmtpQuickSetupLayout = (typeof SMTP_QUICK_SETUP_LAYOUTS)[number]

export const DEFAULT_SMTP_QUICK_SETUP_LAYOUT: SmtpQuickSetupLayout = 'rows'

/**
 * Provider access tokens are short lived (Resend issues 15 minutes). Anything
 * expiring inside this window counts as expired so a request never races the
 * expiry.
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

/** Query param carrying the outcome of the round trip. */
export const QUICK_SETUP_STATUS_PARAM = 'smtpSetup'
/** Query param carrying which provider was authorized. */
export const QUICK_SETUP_PROVIDER_PARAM = 'smtpProvider'

export type QuickSetupReturn =
  | { status: 'connected'; providerId: string; userId: string; secret: string }
  | { status: 'failed'; providerId: string; message?: string }

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
 * the page was not reached through a quick setup round trip.
 */
export function parseQuickSetupReturn(
  search: unknown,
): QuickSetupReturn | null {
  if (!search || typeof search !== 'object') return null
  const params = search as Record<string, unknown>
  const status = params[QUICK_SETUP_STATUS_PARAM]
  const providerId = asNonEmptyString(params[QUICK_SETUP_PROVIDER_PARAM])

  if (!providerId) return null

  if (status === 'connected') {
    const userId = asNonEmptyString(params.userId)
    const secret = asNonEmptyString(params.secret)
    if (!userId || !secret) {
      return { status: 'failed', providerId }
    }
    return { status: 'connected', providerId, userId, secret }
  }

  if (status === 'failed') {
    return {
      status: 'failed',
      providerId,
      message: parseOAuthErrorMessage(params.error),
    }
  }

  return null
}

/** Drop every param the round trip adds; keeps unrelated search state. */
export function stripQuickSetupReturn(prev: unknown): Record<string, unknown> {
  const next: Record<string, unknown> =
    prev && typeof prev === 'object'
      ? { ...(prev as Record<string, unknown>) }
      : {}
  delete next[QUICK_SETUP_STATUS_PARAM]
  delete next[QUICK_SETUP_PROVIDER_PARAM]
  delete next.userId
  delete next.secret
  delete next.error
  return next
}

/** Success and failure URLs bring the user straight back to the SMTP tab. */
export function buildQuickSetupOAuthUrls(
  origin: string,
  projectId: string,
  providerId: string,
): { success: string; failure: string } {
  const base = `${origin}/projects/${encodeURIComponent(projectId)}/settings/smtp`
  const provider = `${QUICK_SETUP_PROVIDER_PARAM}=${encodeURIComponent(providerId)}`
  return {
    success: `${base}?${QUICK_SETUP_STATUS_PARAM}=connected&${provider}`,
    failure: `${base}?${QUICK_SETUP_STATUS_PARAM}=failed&${provider}`,
  }
}

// ---------------------------------------------------------------------------
// Sending domains and credentials
// ---------------------------------------------------------------------------

/**
 * A sending domain, normalized across providers. Each adapter maps its own
 * shape onto this (Resend `status: 'verified'`, Mailgun `state: 'active'`,
 * SendGrid `valid: true`).
 */
export interface QuickSetupDomain {
  id: string
  name: string
  verified: boolean
}

/** Verified domains first, then alphabetical, so the default pick is usable. */
export function sortQuickSetupDomains<T extends QuickSetupDomain>(
  domains: T[],
): T[] {
  return [...domains].sort((a, b) => {
    const verifiedDelta = Number(b.verified) - Number(a.verified)
    if (verifiedDelta !== 0) return verifiedDelta
    return a.name.localeCompare(b.name)
  })
}

export const DEFAULT_SENDER_LOCAL_PART = 'noreply'

export function defaultSenderEmail(domainName: string): string {
  return `${DEFAULT_SENDER_LOCAL_PART}@${domainName}`
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
export function pickDefaultQuickSetupDomain<T extends QuickSetupDomain>(
  domains: T[],
  currentSenderEmail: string | null | undefined,
): T | undefined {
  const verified = domains.filter((domain) => domain.verified)
  if (currentSenderEmail) {
    const match = verified.find((domain) =>
      emailBelongsToDomain(currentSenderEmail, domain.name),
    )
    if (match) return match
  }
  return verified[0]
}

/** Credential name written at the provider, trimmed to that provider's limit. */
export function buildCredentialName(
  projectName: string,
  maxLength: number,
): string {
  const prefix = 'Appwrite SMTP'
  const trimmed = projectName.trim()
  const name = trimmed ? `${prefix}: ${trimmed}` : prefix
  if (name.length <= maxLength) return name
  return name.slice(0, maxLength).trimEnd()
}
