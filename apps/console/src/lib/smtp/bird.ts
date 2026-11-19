/**
 * Bird specifics for the SMTP quick setup. Everything provider-agnostic lives
 * in `quick-setup.ts`; the registry entry is in `providers.ts`.
 *
 * Safe to import from both the browser and the `_api` server routes.
 */

/**
 * A grant can only put scopes on a key that it holds itself, so it asks for
 * `emails:write` (the key's only scope) next to `api_keys:write` (to mint and
 * revoke the key) and `domains:read` (to list sending domains).
 */
export const BIRD_OAUTH_SCOPES = [
  'api_keys:write',
  'emails:write',
  'domains:read',
]

/** SMTP relay settings documented at https://bird.com/docs/guides/email/smtp */
export const BIRD_SMTP_PORT = 587
export const BIRD_SMTP_USERNAME = 'bird'
export const BIRD_SMTP_SECURE = 'tls' as const

export const BIRD_DOMAINS_URL = 'https://bird.com/dashboard/w/email/domains'
/** Bird publishes no limit for key names; stay well inside any sensible one. */
export const BIRD_CREDENTIAL_NAME_MAX_LENGTH = 64

/**
 * Region a Bird credential belongs to. API keys are shaped
 * `bk_{region}_…` and OAuth access tokens `bt_{region}_…`; the region picks
 * the API and SMTP hosts, and a credential sent to another region is refused.
 * Returns `null` for anything else, so callers never build a host from it.
 */
export function birdRegion(credential: string): string | null {
  const [kind, region, rest] = credential.trim().split('_', 3)
  if (kind !== 'bk' && kind !== 'bt') return null
  if (!region || !rest || !/^[a-z]+[0-9]+$/.test(region)) return null
  return region
}

export function birdApiBaseUrl(region: string): string {
  return `https://${region}.platform.bird.com`
}

export function birdSmtpHost(region: string): string {
  return `${region}.smtp.bird.com`
}

/** Bird ids are a prefix plus a 26 character Crockford base32 ULID. */
export function isBirdApiKeyId(value: string): boolean {
  return /^key_[0-9a-hjkmnp-tv-z]{26}$/.test(value)
}

/**
 * Sanitized body for `POST /v1/api-keys`. The server relay rebuilds the
 * payload from this so it can only ever mint keys that send email.
 */
export interface CreateBirdApiKeyBody {
  name: string
  scopes: [{ scope: 'emails'; level: 'write' }]
}

export function sanitizeCreateBirdApiKeyBody(
  input: unknown,
): CreateBirdApiKeyBody | null {
  if (!input || typeof input !== 'object') return null
  const record = input as Record<string, unknown>
  const name = typeof record.name === 'string' ? record.name.trim() : ''
  if (!name || name.length > BIRD_CREDENTIAL_NAME_MAX_LENGTH) return null
  return { name, scopes: [{ scope: 'emails', level: 'write' }] }
}
