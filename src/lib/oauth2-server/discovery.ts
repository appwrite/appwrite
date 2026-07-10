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
}

/**
 * Common OIDC endpoints advertised by the discovery document.
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
    id: 'token',
    label: 'Token endpoint',
    discoveryKey: 'token_endpoint',
    path: '/token',
  },
  {
    id: 'introspection',
    label: 'Introspection endpoint',
    discoveryKey: 'introspection_endpoint',
    path: '/introspect',
  },
  {
    id: 'jwks',
    label: 'JWKS URI',
    discoveryKey: 'jwks_uri',
    path: '/.well-known/jwks.json',
  },
  {
    id: 'userinfo',
    label: 'UserInfo endpoint',
    discoveryKey: 'userinfo_endpoint',
    path: '/userinfo',
  },
  {
    id: 'revocation',
    label: 'Revocation endpoint',
    discoveryKey: 'revocation_endpoint',
    path: '/revoke',
  },
]

export function getOAuth2ServerEndpointUrl(
  projectId: string,
  path: string,
  region?: string,
): string {
  return `${getOAuth2ServerIssuer(projectId, region)}${path}`
}
