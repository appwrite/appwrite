/**
 * Small helpers for the Console OAuth2 *server* flows (Appwrite acting as an
 * OAuth2 / OIDC identity provider — i.e. "Sign in with Appwrite").
 *
 * All endpoints are now in the SDK as `sdk.forConsole.oauth2.*`
 * (`authorize`, `getGrant`, `createGrant`, `approve`, `reject`) returning
 * `Models.Oauth2*`, and app branding via `sdk.forConsole.apps.get`. Call those
 * directly — this module only holds pure constants/helpers, no API calls.
 */

/** A single parsed authorization detail entry (RFC 9396). */
export interface AuthorizationDetail {
  type: string
  [key: string]: unknown
}

/** Safely parse a grant's `authorizationDetails` JSON string into entries. */
export function parseAuthorizationDetails(raw: string): AuthorizationDetail[] {
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? (parsed as AuthorizationDetail[]) : []
  } catch {
    return []
  }
}
