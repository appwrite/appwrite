/**
 * Appwrite list-search helpers for resource pickers and list pages.
 *
 * Prefer `queries` with attribute filters (`startsWith` / `contains` / `equal`)
 * over a separate `get` call. List `search` does not reliably match `$id`.
 */

import { Query } from '@appwrite.io/console'

/** Max length for prefix passed to startsWith (API query size limits). */
export const APPWRITE_SEARCH_PREFIX_MAX = 128

/**
 * Build a single list query that matches any of `attributes` by prefix.
 * Returns `[]` when search is empty so callers can fall back to an unfiltered list.
 */
export function buildAttributePrefixSearchQueries(
  attributes: readonly string[],
  search: string | undefined,
  options?: { maxPrefixLength?: number },
): string[] {
  const trimmed = search?.trim() || ''
  if (!trimmed || attributes.length === 0) return []

  const prefix = trimmed.slice(
    0,
    options?.maxPrefixLength ?? APPWRITE_SEARCH_PREFIX_MAX,
  )
  if (attributes.length === 1) {
    return [Query.startsWith(attributes[0]!, prefix)]
  }
  return [
    Query.or(attributes.map((attribute) => Query.startsWith(attribute, prefix))),
  ]
}
