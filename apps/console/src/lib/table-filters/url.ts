/**
 * Table filters and search – URL helpers (see TABLE_FILTERS_AND_SEARCH.md).
 *
 * Search uses param `search`; filters use param `query` (JSON-encoded array of CompactFilterKey only; no tag).
 * Query strings are rebuilt from keys on decode.
 */

import { buildFilterQueryString } from './operators'
import type { CompactFilterKey, FilterMap } from './types'

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
 * Value equality for compact keys. Map keys are objects, so `Map.has` / `Map.delete`
 * only match by reference; use this after `queryParamToMap` rebuilds the map.
 */
export function compactFilterKeysEqual(
  a: CompactFilterKey,
  b: CompactFilterKey,
): boolean {
  if (a.c !== b.c || a.o !== b.o) return false
  const av = a.v
  const bv = b.v
  if (av === bv) return true
  if (av === undefined && bv === undefined) return true
  if (av === undefined || bv === undefined) return false
  if (Array.isArray(av) && Array.isArray(bv)) {
    if (av.length !== bv.length) return false
    return av.every((x, i) => x === bv[i])
  }
  return av === bv
}

/** Returns the key object actually stored in the map (for Map.delete), or undefined. */
export function findCompactFilterKeyInMap(
  map: FilterMap,
  key: CompactFilterKey,
): CompactFilterKey | undefined {
  for (const k of map.keys()) {
    if (compactFilterKeysEqual(k, key)) return k
  }
  return undefined
}

/**
 * Decode `query` param to FilterMap (compact keys → query strings).
 * Rebuilds query strings from keys; no tag stored in URL.
 */
export function queryParamToMap(
  param: string | null | undefined,
): Map<CompactFilterKey, string> {
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
export function parseSort(
  param: string | null | undefined,
): ListSortParams | undefined {
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

type RouterLocationLike = {
  pathname: string
  href?: string
  searchStr?: string
  search?: unknown
}

/**
 * Build a URL from TanStack Router location. Safe when `validateSearch` is set -
 * `location.search` is then the parsed object, not a query string.
 */
export function urlFromRouterLocation(
  location: RouterLocationLike,
  base = 'http://localhost',
): URL {
  if (location.href) {
    try {
      return new URL(location.href)
    } catch {
      // fall through
    }
  }
  if (typeof location.searchStr === 'string') {
    return new URL(location.pathname + location.searchStr, base)
  }
  if (typeof location.search === 'string') {
    const search = location.search.startsWith('?')
      ? location.search
      : location.search
        ? `?${location.search}`
        : ''
    return new URL(location.pathname + search, base)
  }
  if (location.search instanceof URLSearchParams) {
    const qs = location.search.toString()
    return new URL(location.pathname + (qs ? `?${qs}` : ''), base)
  }
  if (location.search && typeof location.search === 'object') {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(
      location.search as Record<string, unknown>,
    )) {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, String(value))
      }
    }
    const qs = params.toString()
    return new URL(location.pathname + (qs ? `?${qs}` : ''), base)
  }
  return new URL(location.pathname, base)
}

/** URLSearchParams from TanStack Router location (safe with `validateSearch`). */
export function searchParamsFromRouterLocation(
  location: RouterLocationLike,
  base = 'http://localhost',
): URLSearchParams {
  return urlFromRouterLocation(location, base).searchParams
}

/**
 * Read list search params from TanStack Router validated `search` (not `location.search`,
 * which is the parsed object when `validateSearch` is set - not a query string).
 */
export function parseListSearch(
  routeSearch: ListSearchParams | undefined,
  defaults: { page?: number; limit: number },
): {
  search?: string
  page: number
  limit: number
  filterMap: FilterMap
  filterQueries?: string[]
  sort?: ListSortParams
} {
  const page =
    routeSearch?.page != null &&
    Number.isInteger(routeSearch.page) &&
    routeSearch.page >= 1
      ? routeSearch.page
      : (defaults.page ?? 1)
  const limit =
    routeSearch?.limit != null &&
    Number.isInteger(routeSearch.limit) &&
    routeSearch.limit >= 1
      ? routeSearch.limit
      : defaults.limit
  const searchText = routeSearch?.search?.trim()
  const filterMap = queryParamToMap(routeSearch?.query ?? null)
  const filterQueries =
    filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
  const sort = parseSort(routeSearch?.sort)
  return {
    search: searchText === '' ? undefined : searchText,
    page,
    limit,
    filterMap,
    filterQueries,
    sort,
  }
}

/** Returns a minimal object for router navigate(); omit defaults so URLs stay clean. */
export function buildListSearchParams(
  params: ListSearchParams,
): Record<string, string | number> {
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
