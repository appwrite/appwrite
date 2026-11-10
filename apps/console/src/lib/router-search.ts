/**
 * Search param (de)serialization for the router.
 *
 * TanStack's default turns `?teamName=2024` into the number `2024` and `true`/`null`
 * into non-strings, so links built outside the Console (emails, other sites) fail
 * routes that expect text. Here plain values always stay strings; only values that
 * look like JSON objects, arrays or quoted strings are parsed, and strings of that
 * shape are quoted on the way out so they read back unchanged.
 */

function isJsonShaped(value: string): boolean {
  return value.startsWith('{') || value.startsWith('[') || value.startsWith('"')
}

function parseValue(value: string): unknown {
  if (!isJsonShaped(value)) return value
  try {
    return JSON.parse(value)
  } catch {
    return value
  }
}

function stringifyValue(value: unknown): string {
  if (typeof value === 'string') {
    return isJsonShaped(value) ? JSON.stringify(value) : value
  }
  if (value !== null && typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export function parseSearch(searchStr: string): Record<string, unknown> {
  const search: Record<string, unknown> = {}
  for (const [key, raw] of new URLSearchParams(searchStr)) {
    const value = parseValue(raw)
    const previous = search[key]
    if (previous === undefined) search[key] = value
    else
      search[key] = Array.isArray(previous)
        ? [...previous, value]
        : [previous, value]
  }
  return search
}

export function stringifySearch(search: Record<string, unknown>): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(search)) {
    if (value !== undefined) params.set(key, stringifyValue(value))
  }
  const searchStr = params.toString()
  return searchStr ? `?${searchStr}` : ''
}
