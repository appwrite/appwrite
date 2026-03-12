/**
 * Table filters and search – URL helpers (see TABLE_FILTERS_AND_SEARCH.md).
 *
 * Search uses param `search`; filters use param `query` (JSON-encoded array of CompactFilterKey only; no tag).
 * Query strings are rebuilt from keys on decode.
 */

import { buildFilterQueryString } from './operators'
import type { CompactFilterKey } from './types'

const PARAM_SEARCH = 'search'
const PARAM_QUERY = 'query'
const PARAM_PAGE = 'page'
const PARAM_LIMIT = 'limit'
const PARAM_SORT = 'sort'

/** Read search from URL (query string). Returns undefined if missing or empty after trim. */
export function getSearch(url: URL): string | undefined {
  const v = url.searchParams.get(PARAM_SEARCH)?.trim()
  return v === '' ? undefined : v
}

/** Read page from URL (1-based). Returns 1 if missing or invalid. */
export function getPage(url: URL, defaultPage = 1): number {
  const p = url.searchParams.get(PARAM_PAGE)
  if (p == null || p === '') return defaultPage
  const n = Number(p)
  return Number.isInteger(n) && n >= 1 ? n : defaultPage
}

/** Read limit from URL. Returns defaultLimit if missing or invalid. */
export function getLimit(url: URL, defaultLimit: number): number {
  const p = url.searchParams.get(PARAM_LIMIT)
  if (p == null || p === '') return defaultLimit
  const n = Number(p)
  return Number.isInteger(n) && n >= 1 ? n : defaultLimit
}

/** Read raw query param (encoded filter map). */
export function getQueryParam(url: URL): string | null {
  return url.searchParams.get(PARAM_QUERY)
}

/**
 * Decode `query` param to FilterMap (compact keys → query strings).
 * Rebuilds query strings from keys; no tag stored in URL.
 */
export function queryParamToMap(param: string | null | undefined): Map<CompactFilterKey, string> {
  if (param == null || param === '') return new Map()
  try {
    const decoded = decodeURIComponent(param)
    const keys = JSON.parse(decoded) as CompactFilterKey[]
    if (!Array.isArray(keys)) return new Map()
    return new Map(keys.map((k) => [k, buildFilterQueryString(k.o, k.c, k.v)]))
  } catch {
    return new Map()
  }
}

/**
 * Encode FilterMap to string for URL (compact keys only; no tag, no query string).
 */
export function mapToQueryParam(map: Map<CompactFilterKey, string>): string {
  if (map.size === 0) return ''
  const keys = Array.from(map.keys())
  return encodeURIComponent(JSON.stringify(keys))
}

/** Parsed sort from URL (e.g. sort=name_asc → { sortBy: 'name', sortOrder: 'asc' }). */
export interface ListSortParams {
  sortBy: string
  sortOrder: 'asc' | 'desc'
}

/** Parse sort param: "field_asc" or "field_desc". Returns undefined if missing/invalid. */
export function parseSort(param: string | null | undefined): ListSortParams | undefined {
  const v = param?.trim()
  if (!v) return undefined
  const lastUnderscore = v.lastIndexOf('_')
  if (lastUnderscore <= 0 || lastUnderscore === v.length - 1) return undefined
  const sortBy = v.slice(0, lastUnderscore)
  const order = v.slice(lastUnderscore + 1).toLowerCase()
  if (order !== 'asc' && order !== 'desc') return undefined
  return { sortBy, sortOrder: order as 'asc' | 'desc' }
}

/** Encode sort to URL value: "field_asc" or "field_desc". */
export function encodeSort(sortBy: string, sortOrder: 'asc' | 'desc'): string {
  return `${sortBy}_${sortOrder}`
}

/** Read sort from URL. Returns undefined if missing or invalid. */
export function getSort(url: URL): ListSortParams | undefined {
  return parseSort(url.searchParams.get(PARAM_SORT))
}

/**
 * Build URL search params for list view: search, query, page, limit, sort.
 * Omit keys when value is default (e.g. no search, page 1) so URLs stay clean.
 */
export interface ListSearchParams {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

/** Minimum number of characters before search is applied (avoids API calls for 1–2 chars). */
export const MIN_SEARCH_LENGTH = 3

/** Returns a minimal object for router navigate(); omit defaults so URLs stay clean. */
export function buildListSearchParams(params: ListSearchParams): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  const search = params.search?.trim()
  if (search) out[PARAM_SEARCH] = search
  if (params.query) out[PARAM_QUERY] = params.query
  if (params.page != null && params.page > 1) out[PARAM_PAGE] = params.page
  if (params.limit != null && params.limit > 0) out[PARAM_LIMIT] = params.limit
  if (params.sort) out[PARAM_SORT] = params.sort
  return out
}

export { PARAM_SEARCH, PARAM_QUERY, PARAM_PAGE, PARAM_LIMIT, PARAM_SORT }
