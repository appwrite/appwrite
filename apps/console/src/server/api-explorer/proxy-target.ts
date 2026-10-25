import { assertAllowedExplorerRequestUrl } from '@/lib/api-explorer/proxy-validation'
import {
  resolvesToPublicAddresses,
  type AddressLookup,
} from '@/server/lib/public-address'

/**
 * Refuse a URL the API explorer proxy must not fetch for `endpoint`, the
 * console's configured API endpoint. The configured host is used as the
 * operator set it (local and private self-hosted APIs stay reachable); a
 * regional host in front of it must resolve to public addresses only.
 */
export async function assertExplorerProxyTarget(
  requestUrl: string,
  endpoint: string,
  resolve?: AddressLookup,
): Promise<void> {
  assertAllowedExplorerRequestUrl(requestUrl, endpoint)

  const { hostname } = new URL(requestUrl)
  if (hostname === new URL(endpoint).hostname) return

  if (!(await resolvesToPublicAddresses(hostname, resolve))) {
    throw new Error('Explorer request URL host is not allowed')
  }
}
