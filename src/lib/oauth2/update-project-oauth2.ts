import type { ProjectSdk } from '@/lib/appwrite/sdk'

const METHOD_NAME_OVERRIDES: Record<string, string> = {
  github: 'updateOAuth2GitHub',
  gitlab: 'updateOAuth2Gitlab',
  paypalSandbox: 'updateOAuth2PaypalSandbox',
  tradeshiftBox: 'updateOAuth2TradeshiftSandbox',
  wordpress: 'updateOAuth2WordPress',
  fusionauth: 'updateOAuth2FusionAuth',
}

function providerIdToUpdateMethodName(providerId: string): string {
  if (METHOD_NAME_OVERRIDES[providerId]) {
    return METHOD_NAME_OVERRIDES[providerId]!
  }
  return `updateOAuth2${providerId.charAt(0).toUpperCase()}${providerId.slice(1)}`
}

/** Drop empty strings so omitted credentials are left unchanged on the server. */
function pruneOAuth2Body(values: Record<string, string | boolean>) {
  const body: Record<string, unknown> = {}
  if (typeof values.enabled === 'boolean') {
    body.enabled = values.enabled
  }
  for (const [k, v] of Object.entries(values)) {
    if (k === 'enabled') continue
    if (typeof v === 'string') {
      const t = v.trim()
      if (t !== '') body[k] = t
    }
  }
  return body
}

/**
 * Updates one OAuth2 provider using the project-scoped SDK method that matches the provider id.
 */
export async function updateProjectOAuth2Provider(
  projectSdk: ProjectSdk,
  providerId: string,
  values: Record<string, string | boolean>,
): Promise<void> {
  const methodName = providerIdToUpdateMethodName(providerId)
  const api = projectSdk.project as unknown as Record<
    string,
    ((body?: object) => Promise<unknown>) | unknown
  >
  const fn = api[methodName]
  if (typeof fn !== 'function') {
    throw new Error(`No OAuth2 update method for provider "${providerId}".`)
  }
  await (fn as (body?: object) => Promise<unknown>)(pruneOAuth2Body(values))
}
