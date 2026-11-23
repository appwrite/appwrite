/**
 * Appwrite CLI install scripts served at /cli/install.sh and /cli/install.ps1.
 * Proxied from sdk-for-cli so release versions stay in sync; tracked via SSR Plausible.
 */

const SDK_FOR_CLI_RAW_BASE =
  'https://raw.githubusercontent.com/appwrite/sdk-for-cli/master'

export const CLI_INSTALL_SH_PATH = '/cli/install.sh'
export const CLI_INSTALL_PS1_PATH = '/cli/install.ps1'

export const CLI_INSTALL_SH_UPSTREAM = `${SDK_FOR_CLI_RAW_BASE}/install.sh`
export const CLI_INSTALL_PS1_UPSTREAM = `${SDK_FOR_CLI_RAW_BASE}/install.ps1`

type CachedScript = {
  body: string
  fetchedAt: number
}

const CACHE_TTL_MS = 5 * 60 * 1000
const cache = new Map<string, CachedScript>()

async function fetchUpstreamScript(upstreamUrl: string): Promise<string> {
  const cached = cache.get(upstreamUrl)
  if (cached && Date.now() - cached.fetchedAt < CACHE_TTL_MS) {
    return cached.body
  }

  const response = await fetch(upstreamUrl, {
    headers: { Accept: 'text/plain' },
  })
  if (!response.ok) {
    throw new Error(
      `Failed to fetch CLI install script (${response.status}): ${upstreamUrl}`,
    )
  }

  const body = await response.text()
  cache.set(upstreamUrl, { body, fetchedAt: Date.now() })
  return body
}

export async function getCliInstallSh(): Promise<string> {
  return fetchUpstreamScript(CLI_INSTALL_SH_UPSTREAM)
}

export async function getCliInstallPs1(): Promise<string> {
  return fetchUpstreamScript(CLI_INSTALL_PS1_UPSTREAM)
}

export function cliInstallScriptResponse(body: string): Response {
  return new Response(body, {
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'public, max-age=300, stale-while-revalidate=3600',
      'X-Content-Type-Options': 'nosniff',
    },
  })
}
