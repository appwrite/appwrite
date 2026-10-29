/**
 * Identities created through native sign-in carry the provider's ID token, the
 * signed JWT the app obtained on device and handed to Appwrite.
 *
 * The console decodes it for display only. The server already verified the
 * signature when it created the session, so nothing here re-checks it and no
 * trust decision is made from these claims. Treat the result as a rendering of
 * what the token says, not as proof of anything.
 */

export type IdTokenClaims = {
  /** Client IDs the token was issued for. One of these matched the project. */
  audience: string[]
  issuer: string
  subject: string
  email: string
  issuedAt: Date | null
  expiresAt: Date | null
}

/** JWT segments are base64url with the padding stripped. */
function decodeBase64UrlSegment(segment: string): string | null {
  const base64 = segment.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64.padEnd(
    base64.length + ((4 - (base64.length % 4)) % 4),
    '=',
  )
  try {
    const binary = atob(padded)
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
    return new TextDecoder().decode(bytes)
  } catch {
    return null
  }
}

/** Seconds since the epoch, as JWTs record time. Anything else is dropped. */
function readNumericDate(value: unknown): Date | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) return null
  const date = new Date(value * 1000)
  return Number.isNaN(date.getTime()) ? null : date
}

function readStringClaim(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

/** `aud` is a single string or an array of them, depending on the provider. */
function readAudience(value: unknown): string[] {
  if (typeof value === 'string') return value ? [value] : []
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return []
}

/**
 * Returns the token's claims, or null when it is not a readable JWT. Never
 * throws: the value comes from a provider and may be anything.
 */
export function decodeIdTokenClaims(token: string): IdTokenClaims | null {
  const segments = token.split('.')
  if (segments.length < 2) return null

  const payloadJson = decodeBase64UrlSegment(segments[1])
  if (!payloadJson) return null

  let payload: unknown
  try {
    payload = JSON.parse(payloadJson)
  } catch {
    return null
  }
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return null
  }

  const claims = payload as Record<string, unknown>
  return {
    audience: readAudience(claims.aud),
    issuer: readStringClaim(claims.iss),
    subject: readStringClaim(claims.sub),
    email: readStringClaim(claims.email),
    issuedAt: readNumericDate(claims.iat),
    expiresAt: readNumericDate(claims.exp),
  }
}
