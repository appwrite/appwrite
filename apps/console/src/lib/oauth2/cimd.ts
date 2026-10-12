import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

// CIMD (Client ID Metadata Document): a client_id may be an HTTPS URL pointing
// to a JSON document of RFC 7591 client metadata. The Appwrite API no longer
// resolves these behind apps.get/apps.list, so the console fetches the
// document itself for branding.

const FETCH_TIMEOUT = 10_000
const DEVICE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code'
const HTTP_URL = /^https?:\/\//i

type CimdDocument = {
  client_id?: unknown
  client_name?: unknown
  client_uri?: unknown
  logo_uri?: unknown
  policy_uri?: unknown
  tos_uri?: unknown
  contacts?: unknown
  redirect_uris?: unknown
  post_logout_redirect_uris?: unknown
  token_endpoint_auth_method?: unknown
  grant_types?: unknown
}

// Plain app IDs never parse as URLs; http is allowed for local development only.
export function isCimdClientId(clientId: string): boolean {
  try {
    const url = new URL(clientId)
    return (
      url.protocol === 'https:' ||
      (url.protocol === 'http:' &&
        ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))
    )
  } catch {
    return false
  }
}

function httpUrlOrEmpty(value: unknown): string {
  return typeof value === 'string' && HTTP_URL.test(value) ? value : ''
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((entry): entry is string => typeof entry === 'string')
    : []
}

export function cimdDocumentToApp(
  clientId: string,
  document: unknown,
): Models.App {
  if (typeof document !== 'object' || document === null) {
    throw new Error('CIMD document is not a JSON object')
  }
  const doc = document as CimdDocument
  // The document's client_id must equal the URL it was fetched from.
  if (doc.client_id !== clientId) {
    throw new Error('CIMD document client_id does not match its URL')
  }
  const name = typeof doc.client_name === 'string' ? doc.client_name.trim() : ''
  return {
    $id: clientId,
    $createdAt: '',
    $updatedAt: '',
    name: name || new URL(clientId).hostname,
    description: '',
    // Untrusted values rendered in href/src must be http(s) URLs.
    clientUri: httpUrlOrEmpty(doc.client_uri),
    logoUri: httpUrlOrEmpty(doc.logo_uri),
    privacyPolicyUrl: httpUrlOrEmpty(doc.policy_uri),
    termsUrl: httpUrlOrEmpty(doc.tos_uri),
    contacts: stringArray(doc.contacts),
    tagline: '',
    tags: [],
    labels: [],
    images: [],
    supportUrl: '',
    dataDeletionUrl: '',
    redirectUris: stringArray(doc.redirect_uris),
    postLogoutRedirectUris: stringArray(doc.post_logout_redirect_uris),
    enabled: true,
    type: doc.token_endpoint_auth_method === 'none' ? 'public' : 'confidential',
    deviceFlow: stringArray(doc.grant_types).includes(DEVICE_GRANT_TYPE),
    teamId: '',
    userId: '',
    installationScopes: [],
    installationRedirectUrl: '',
    secrets: [],
  }
}

/**
 * Resolve OAuth2 client branding for display. Plain IDs resolve via the API;
 * CIMD URLs are fetched directly from the browser. Fetch or validation
 * failures for CIMD clients (document unreachable, no CORS, client_id
 * mismatch) fall back to hostname-only branding rather than rejecting - the
 * server still validates the client during authorization, this data is
 * display-only.
 */
export async function getOAuth2App(appId: string): Promise<Models.App> {
  if (!isCimdClientId(appId)) {
    return sdk.forConsole.apps.get({ appId })
  }
  try {
    const response = await fetch(appId, {
      headers: { accept: 'application/json' },
      credentials: 'omit',
      signal: AbortSignal.timeout(FETCH_TIMEOUT),
    })
    if (!response.ok) {
      throw new Error(`CIMD document request failed: ${response.status}`)
    }
    return cimdDocumentToApp(appId, await response.json())
  } catch {
    return cimdDocumentToApp(appId, { client_id: appId })
  }
}
