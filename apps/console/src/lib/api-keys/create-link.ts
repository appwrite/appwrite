/**
 * Links that open a project's API keys page with the create drawer filled in.
 * `/setup.md` has coding agents hand these out, so the user only reviews the
 * name and scopes, creates the key, and copies it.
 */

/** Search param for the key name. */
export const API_KEY_PREFILL_NAME_PARAM = 'name'
/** Search param for the scopes, comma-separated. */
export const API_KEY_PREFILL_SCOPES_PARAM = 'scopes'

/** Matches the server's limit on scopes per key. */
const MAX_SCOPES = 200
const MAX_NAME_LENGTH = 128

export type ApiKeyPrefill = {
  name?: string
  scopes?: string[]
}

/** Search values as the router parses them: numeric, boolean and JSON-looking values arrive typed. */
export type ApiKeyPrefillSearch = {
  name?: string | number | boolean
  scopes?: string | string[]
}

/** Normalizes `name` and `scopes` from the URL; null when neither is present. */
export function parseApiKeyPrefill(
  search: ApiKeyPrefillSearch,
): ApiKeyPrefill | null {
  const name =
    search.name === undefined
      ? ''
      : String(search.name).trim().slice(0, MAX_NAME_LENGTH)
  const rawScopes = Array.isArray(search.scopes)
    ? search.scopes
    : (search.scopes ?? '').split(',')
  const scopes = [
    ...new Set(rawScopes.map((scope) => String(scope).trim()).filter(Boolean)),
  ].slice(0, MAX_SCOPES)

  if (!name && scopes.length === 0) return null

  return {
    ...(name ? { name } : {}),
    ...(scopes.length > 0 ? { scopes } : {}),
  }
}

/** `<origin>/projects/<projectId>/api-keys?name=…&scopes=a,b` */
export function buildApiKeyCreateUrl(options: {
  origin: string
  projectId: string
  name?: string
  scopes?: string[]
}): string {
  const { origin, projectId, name, scopes } = options
  const params = new URLSearchParams()
  if (name) params.set(API_KEY_PREFILL_NAME_PARAM, name)
  if (scopes?.length) params.set(API_KEY_PREFILL_SCOPES_PARAM, scopes.join(','))
  const query = params.toString()
  return `${origin}/projects/${encodeURIComponent(projectId)}/api-keys${query ? `?${query}` : ''}`
}
