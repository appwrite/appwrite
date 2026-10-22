import { getApiEndpoint } from '@/lib/appwrite/sdk'

/**
 * OIDC discovery document for a project's OAuth2 authorization server.
 *
 * Uses the same regional project API endpoint shown in Settings → API credentials.
 *
 * Pattern: `{projectApiEndpoint}/oauth2/{projectId}/.well-known/openid-configuration`
 *
 * Example: `https://fra.cloud.appwrite.io/v1/oauth2/my-project/.well-known/openid-configuration`
 */
export function getOAuth2ServerDiscoveryUrl(
  projectId: string,
  region?: string,
): string {
  return `${getOAuth2ServerIssuer(projectId, region)}/.well-known/openid-configuration`
}

/**
 * RFC 8414 OAuth 2.0 Authorization Server Metadata. Same document as the OIDC
 * discovery endpoint, at the path plain OAuth2 clients look for.
 */
export function getOAuth2ServerMetadataUrl(
  projectId: string,
  region?: string,
): string {
  return `${getOAuth2ServerIssuer(projectId, region)}/.well-known/oauth-authorization-server`
}

/**
 * OIDC issuer / base path for the project's authorization server.
 * All protocol endpoints are rooted here.
 */
export function getOAuth2ServerIssuer(
  projectId: string,
  region?: string,
): string {
  const endpoint = getApiEndpoint(region).replace(/\/$/, '')
  return `${endpoint}/oauth2/${projectId}`
}

export type OAuth2ServerEndpoint = {
  id: string
  label: string
  /** Key name as it appears in the OIDC discovery document */
  discoveryKey: string
  path: string
  /** Only advertised once the project has a device flow verification URL. */
  requiresDeviceFlow?: boolean
}

/**
 * Endpoints advertised by the discovery document.
 * Paths match Appwrite's authorization-server routes under the issuer.
 */
export const OAUTH2_SERVER_COMMON_ENDPOINTS: OAuth2ServerEndpoint[] = [
  {
    id: 'authorization',
    label: 'Authorization endpoint',
    discoveryKey: 'authorization_endpoint',
    path: '/authorize',
  },
  {
    id: 'par',
    label: 'Pushed authorization request endpoint',
    discoveryKey: 'pushed_authorization_request_endpoint',
    path: '/par',
  },
  {
    id: 'token',
    label: 'Token endpoint',
    discoveryKey: 'token_endpoint',
    path: '/token',
  },
  {
    id: 'device-authorization',
    label: 'Device authorization endpoint',
    discoveryKey: 'device_authorization_endpoint',
    path: '/device_authorization',
    requiresDeviceFlow: true,
  },
  {
    id: 'userinfo',
    label: 'UserInfo endpoint',
    discoveryKey: 'userinfo_endpoint',
    path: '/userinfo',
  },
  {
    id: 'introspection',
    label: 'Introspection endpoint',
    discoveryKey: 'introspection_endpoint',
    path: '/introspect',
  },
  {
    id: 'revocation',
    label: 'Revocation endpoint',
    discoveryKey: 'revocation_endpoint',
    path: '/revoke',
  },
  {
    id: 'end-session',
    label: 'End session endpoint',
    discoveryKey: 'end_session_endpoint',
    path: '/logout',
  },
  {
    id: 'registration',
    label: 'Dynamic client registration endpoint',
    discoveryKey: 'registration_endpoint',
    path: '/register',
  },
  {
    id: 'jwks',
    label: 'JWKS URI',
    discoveryKey: 'jwks_uri',
    path: '/.well-known/jwks.json',
  },
]

export function getOAuth2ServerEndpointUrl(
  projectId: string,
  path: string,
  region?: string,
): string {
  return `${getOAuth2ServerIssuer(projectId, region)}${path}`
}
