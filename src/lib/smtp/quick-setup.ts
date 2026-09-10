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
// OAuth2 round trip
// ---------------------------------------------------------------------------

/**
 * Appwrite deletes the caller's current session as soon as an OAuth2 flow
 * starts for a signed-in user (see the `$current` session delete in
 * `account.php`'s oauth2 redirect handler). With the token flow that leaves the
 * browser signed out until `account.createSession` runs, so the provider must
 * return to a route that tolerates guests and restores the session there.
 * Landing straight on the SMTP tab would bounce through /sign-in instead.
 */
export const QUICK_SETUP_CALLBACK_PATH = '/auth/smtp/callback'

/** Query param carrying the outcome of the round trip. */
export const QUICK_SETUP_STATUS_PARAM = 'smtpSetup'
/** Query param carrying which provider was authorized. */
export const QUICK_SETUP_PROVIDER_PARAM = 'smtpProvider'
/** Query param carrying the project to return to. */
export const QUICK_SETUP_PROJECT_PARAM = 'projectId'

export type QuickSetupStatus = 'connected' | 'failed'

export type QuickSetupReturn =
  | { status: 'connected'; providerId: string }
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
 * Success and failure URLs handed to Appwrite. Both point at the callback
 * route; Appwrite appends `userId` + `secret` on success and `error` on
 * failure. The project and provider ride along so the callback knows where to
 * send the user once the session is back.
 */
export function buildQuickSetupOAuthUrls(
  origin: string,
  projectId: string,
  providerId: string,
): { success: string; failure: string } {
  const base = `${origin}${QUICK_SETUP_CALLBACK_PATH}`
  const shared =
    `${QUICK_SETUP_PROVIDER_PARAM}=${encodeURIComponent(providerId)}` +
    `&${QUICK_SETUP_PROJECT_PARAM}=${encodeURIComponent(projectId)}`
  return {
    success: `${base}?${QUICK_SETUP_STATUS_PARAM}=connected&${shared}`,
    failure: `${base}?${QUICK_SETUP_STATUS_PARAM}=failed&${shared}`,
  }
}

/** Where the callback sends the user once the console session is restored. */
export function buildQuickSetupReturnPath(options: {
  projectId: string
  providerId: string
  status: QuickSetupStatus
  /** Raw `error` value from Appwrite, forwarded so the tab can show it. */
  error?: string
}): string {
  const params = new URLSearchParams({
    [QUICK_SETUP_STATUS_PARAM]: options.status,
    [QUICK_SETUP_PROVIDER_PARAM]: options.providerId,
  })
  if (options.error) params.set('error', options.error)
  return `/projects/${encodeURIComponent(options.projectId)}/settings/smtp?${params.toString()}`
}

/**
 * Read the outcome from the SMTP tab's search params. Returns `null` when the
 * page was not reached through a quick setup round trip. The one-time token
 * never reaches this page: the callback route consumes it.
 */
export function parseQuickSetupReturn(
  search: unknown,
): QuickSetupReturn | null {
  if (!search || typeof search !== 'object') return null
  const params = search as Record<string, unknown>
  const status = params[QUICK_SETUP_STATUS_PARAM]
  const providerId = asNonEmptyString(params[QUICK_SETUP_PROVIDER_PARAM])

  if (!providerId) return null
  if (status === 'connected') return { status: 'connected', providerId }
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
  delete next.error
  return next
}

// ---------------------------------------------------------------------------
// Pending flow state
// ---------------------------------------------------------------------------

/**
 * Recorded before leaving for the provider so the callback can prove this
 * browser started the flow. Without it, opening a crafted callback URL would
 * create a session from attacker-supplied credentials.
 */
export interface QuickSetupPending {
  providerId: string
  projectId: string
  /** Console account that started the flow; the claim must come back for it. */
  accountId: string
}

const PENDING_STORAGE_KEY = 'smtp.quickSetup.pending'

export function rememberQuickSetupPending(pending: QuickSetupPending): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.setItem(PENDING_STORAGE_KEY, JSON.stringify(pending))
  } catch {
    // Private mode: the callback falls back to refusing the claim.
  }
}

export function readQuickSetupPending(): QuickSetupPending | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.sessionStorage.getItem(PENDING_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<QuickSetupPending>
    if (
      typeof parsed?.providerId === 'string' &&
      typeof parsed?.projectId === 'string' &&
      typeof parsed?.accountId === 'string'
    ) {
      return parsed as QuickSetupPending
    }
  } catch {
    // Corrupt entry is treated as absent.
  }
  return null
}

export function clearQuickSetupPending(): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.removeItem(PENDING_STORAGE_KEY)
  } catch {
    // Nothing to clean up.
  }
}

/**
 * True when a callback matches the flow this browser started: same provider,
 * same project, and a token issued for the same console account. Appwrite
 * links the identity to the signed-in user, so the ids always match on the
 * happy path; a mismatch means the callback was not ours to claim.
 */
export function isExpectedQuickSetupClaim(
  pending: QuickSetupPending | null,
  claim: { providerId: string; projectId: string; userId: string },
): boolean {
  if (!pending) return false
  return (
    pending.providerId === claim.providerId &&
    pending.projectId === claim.projectId &&
    pending.accountId === claim.userId
  )
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

/**
 * Feature ID stored in the account's `featureNotifications` pref when a user
 * registers interest in a provider that is still coming soon. Must stay free
 * of commas, since that pref is a comma-separated list.
 */
export function providerInterestFeatureId(providerId: string): string {
  return `smtp-quick-setup-${providerId}`
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
