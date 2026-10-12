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
    Query.or(
      attributes.map((attribute) => Query.startsWith(attribute, prefix)),
    ),
  ]
}

/**
 * Max serialized length of one `queries[]` entry accepted by the API.
 * Lookups pack values into `or(equal(...))` batches up to this length, so the
 * batch size adapts to how long the values are (IDs are short, names are not).
 */
export const QUERY_MAX_LENGTH = 4096

/** Trim, drop empty values, and dedupe while keeping first-seen order. */
export function normalizeIds(ids: readonly string[]): string[] {
  return [
    ...new Set(
      ids
        .filter((id) => typeof id === 'string')
        .map((id) => id.trim())
        .filter(Boolean),
    ),
  ]
}

function buildMatchQuery(attribute: string, values: readonly string[]): string {
  return values.length === 1
    ? Query.equal(attribute, values[0]!)
    : Query.or(values.map((value) => Query.equal(attribute, value)))
}

/**
 * Split `values` into `queries[]` arrays, each matching one batch on
 * `attribute` and sized to return every match. Batches are packed by
 * serialized query length so they stay under the API limit whatever the
 * values are. Callers fan the batches out in parallel so a lookup resolves
 * every value it is given instead of truncating to one request.
 */
export function buildLookupQueryBatches(
  attribute: string,
  values: readonly string[],
  maxLength = QUERY_MAX_LENGTH,
): string[][] {
  const batches: string[][] = []
  let batch: string[] = []

  for (const value of normalizeIds(values)) {
    if (
      batch.length > 0 &&
      buildMatchQuery(attribute, [...batch, value]).length > maxLength
    ) {
      batches.push([
        buildMatchQuery(attribute, batch),
        Query.limit(batch.length),
      ])
      batch = []
    }
    batch.push(value)
  }

  if (batch.length > 0) {
    batches.push([buildMatchQuery(attribute, batch), Query.limit(batch.length)])
  }

  return batches
}

export function buildIdLookupQueryBatches(ids: readonly string[]): string[][] {
  return buildLookupQueryBatches('$id', ids)
}

/**
 * Run one list request per batch in parallel and merge the results. A failed
 * batch drops only its own rows, so the names from every other batch survive.
 */
export async function fetchLookupBatches<T>(
  batches: readonly string[][],
  list: (queries: string[]) => Promise<T[]>,
): Promise<T[]> {
  const settled = await Promise.allSettled(
    batches.map((queries) => list(queries)),
  )
  return settled.flatMap((result) =>
    result.status === 'fulfilled' ? result.value : [],
  )
}
