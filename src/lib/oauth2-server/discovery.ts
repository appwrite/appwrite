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
  const endpoint = getApiEndpoint(region).replace(/\/$/, '')
  return `${endpoint}/oauth2/${projectId}/.well-known/openid-configuration`
}
