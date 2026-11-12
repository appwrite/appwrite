// Replay metadata is only for raw requests this tab actually received. Native
// grants and externally created PAR handles omit fields needed to replay PKCE.
const STORAGE_PREFIX = 'oauth2-account-switch:v1:'
const MAX_AGE_MS = 10 * 60 * 1000
const MAX_URL_LENGTH = 32768
const MAX_ENTRIES = 32
const AUTHORIZE_FIELDS = new Set([
  'client_id',
  'redirect_uri',
  'response_type',
  'scope',
  'state',
  'nonce',
  'code_challenge',
  'code_challenge_method',
  'prompt',
  'max_age',
  'authorization_details',
  'resource',
])

export type AccountSwitchRequest = {
  url: string
  expiresAt: number
}

function rawConsentUrl(value: string): string | null {
  if (
    value.length > MAX_URL_LENGTH ||
    !value.startsWith('/oauth2/consent?') ||
    /[\u0000-\u0020\u007f\\#]/.test(value)
  ) {
    return null
  }
  const params = new URLSearchParams(value.slice('/oauth2/consent?'.length))
  if (!params.get('client_id') || !params.get('redirect_uri')) return null
  for (const key of params.keys()) {
    if (!AUTHORIZE_FIELDS.has(key)) return null
    if (key !== 'resource' && params.getAll(key).length !== 1) return null
  }
  // Encode nested redirect URIs while keeping every authorize value intact.
  // The result is always the exact local consent route, never an external URL.
  const url = `/oauth2/consent?${params.toString()}`
  return url.length <= MAX_URL_LENGTH ? url : null
}

export function createAccountSwitchRequest(
  url: string,
): AccountSwitchRequest | null {
  const safeUrl = rawConsentUrl(url)
  return safeUrl ? { url: safeUrl, expiresAt: Date.now() + MAX_AGE_MS } : null
}

export function accountSwitchUrl(
  request: AccountSwitchRequest | null,
  clientId?: string | null,
): string | null {
  if (
    !request ||
    typeof request.url !== 'string' ||
    !Number.isSafeInteger(request.expiresAt) ||
    request.expiresAt <= Date.now() ||
    request.expiresAt > Date.now() + MAX_AGE_MS
  ) {
    return null
  }
  const url = rawConsentUrl(request.url)
  if (!url) return null
  const params = new URLSearchParams(url.slice(url.indexOf('?') + 1))
  if (clientId && params.get('client_id') !== clientId) return null
  return url
}

export function rememberAccountSwitchRequest(
  key: string,
  request: AccountSwitchRequest,
): void {
  if (!key || key.length > 2048 || !accountSwitchUrl(request)) return
  try {
    const keys = Object.keys(sessionStorage).filter((entry) =>
      entry.startsWith(STORAGE_PREFIX),
    )
    for (const entry of keys) {
      if (!readAccountSwitchRequest(entry.slice(STORAGE_PREFIX.length))) {
        sessionStorage.removeItem(entry)
      }
    }
    const remaining = Object.keys(sessionStorage).filter((entry) =>
      entry.startsWith(STORAGE_PREFIX),
    )
    while (remaining.length >= MAX_ENTRIES) {
      sessionStorage.removeItem(remaining.shift()!)
    }
    sessionStorage.setItem(`${STORAGE_PREFIX}${key}`, JSON.stringify(request))
  } catch {
    // Storage unavailable: switching is possible only while raw input is held.
  }
}

export function readAccountSwitchRequest(
  key: string,
): AccountSwitchRequest | null {
  if (!key || key.length > 2048) return null
  try {
    const raw = sessionStorage.getItem(`${STORAGE_PREFIX}${key}`)
    if (!raw || raw.length > MAX_URL_LENGTH * 2) return null
    const request: AccountSwitchRequest = JSON.parse(raw)
    return accountSwitchUrl(request) ? request : null
  } catch {
    return null
  }
}
