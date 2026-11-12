/**
 * Resend specifics for the SMTP quick setup. Everything provider-agnostic
 * lives in `quick-setup.ts`; the registry entry is in `providers.ts`.
 *
 * Safe to import from both the browser and the `_api` server routes.
 */

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
export const RESEND_CREDENTIAL_NAME_MAX_LENGTH = 50

/**
 * Sanitized body for `POST /api-keys`. The server relay rebuilds the payload
 * from this so it can only ever mint sending-only keys.
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
  if (!name || name.length > RESEND_CREDENTIAL_NAME_MAX_LENGTH) return null

  const body: CreateResendApiKeyBody = { name, permission: 'sending_access' }
  const domainId = record.domain_id ?? record.domainId
  if (typeof domainId === 'string' && domainId.trim()) {
    body.domain_id = domainId.trim()
  }
  return body
}
