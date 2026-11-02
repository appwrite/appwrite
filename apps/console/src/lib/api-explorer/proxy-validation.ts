import {
  DEFAULT_CLOUD_APPWRITE_ENDPOINT,
  isSelfHostedConsoleProfile,
  type RuntimeConfig,
} from '@/lib/runtime-config-shared'

/** Methods the explorer sends through the proxy. */
export const EXPLORER_PROXY_METHODS = [
  'GET',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
] as const

export type ExplorerProxyMethod = (typeof EXPLORER_PROXY_METHODS)[number]

/** Headers the explorer sends; the proxy forwards nothing else. */
export type ExplorerRequestHeaders = {
  'X-Appwrite-Project': string
  'X-Appwrite-Key'?: string
  'X-Appwrite-JWT'?: string
  'Content-Type'?: 'application/json'
}

export function isExplorerProxyMethod(
  method: string,
): method is ExplorerProxyMethod {
  return (EXPLORER_PROXY_METHODS as readonly string[]).includes(method)
}

/** Hosts whose regional subdomains (`fra.cloud.appwrite.io`) serve the same API. */
const MULTI_REGION_HOSTS = ['cloud.appwrite.io', 'cloud.staging.appwrite.io']

/**
 * API endpoint the explorer proxy forwards to, read from the server's own
 * config. Same-origin consoles (self-hosted without an endpoint) have none: the
 * browser reaches the API directly there.
 */
export function getExplorerProxyEndpoint(
  config: Pick<RuntimeConfig, 'appwriteEndpoint' | 'consoleProfile'>,
): string | null {
  if (config.appwriteEndpoint) return config.appwriteEndpoint
  if (isSelfHostedConsoleProfile(config.consoleProfile)) return null
  return DEFAULT_CLOUD_APPWRITE_ENDPOINT
}

/** Ensures explorer requests target only the configured Appwrite API host/path. */
export function assertAllowedExplorerRequestUrl(
  requestUrl: string,
  allowedEndpoint: string,
): void {
  let target: URL
  let allowed: URL

  try {
    target = new URL(requestUrl)
    allowed = new URL(allowedEndpoint)
  } catch {
    throw new Error('Invalid explorer request URL')
  }

  if (target.protocol !== 'http:' && target.protocol !== 'https:') {
    throw new Error('Invalid explorer request URL protocol')
  }

  if (target.username || target.password) {
    throw new Error('Credentials in URL are not allowed')
  }

  if (
    target.protocol !== allowed.protocol ||
    target.port !== allowed.port ||
    !isAllowedHost(target.hostname, allowed.hostname)
  ) {
    throw new Error('Explorer request URL origin is not allowed')
  }

  const allowedPrefix = allowed.pathname.replace(/\/$/, '') || '/'
  if (!pathnameMatchesAllowedPrefix(target.pathname, allowedPrefix)) {
    throw new Error('Explorer request URL path is not allowed')
  }
}

function isAllowedHost(host: string, allowedHost: string): boolean {
  if (host === allowedHost) return true
  if (!MULTI_REGION_HOSTS.includes(allowedHost)) return false

  const suffix = `.${allowedHost}`
  if (!host.endsWith(suffix)) return false

  const region = host.slice(0, -suffix.length)
  return region.length > 0 && isRegionLabel(region)
}

function isRegionLabel(label: string): boolean {
  for (const character of label) {
    const isLetter = character >= 'a' && character <= 'z'
    const isDigit = character >= '0' && character <= '9'
    if (!isLetter && !isDigit) return false
  }
  return true
}

function pathnameMatchesAllowedPrefix(
  pathname: string,
  allowedPrefix: string,
): boolean {
  if (allowedPrefix === '/') return true
  if (pathname === allowedPrefix) return true
  return pathname.startsWith(`${allowedPrefix}/`)
}
