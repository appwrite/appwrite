/**
 * Activity list: merge plan retention with URL filter map (`time` bounds) and
 * collect non-time Appwrite query strings for the activities API.
 */

import { buildFilterQueryString } from './operators'
import type { CompactFilterKey, FilterMap } from './types'

function normalizeActivityCountryFilterKey(
  key: CompactFilterKey,
): CompactFilterKey {
  if (key.c !== 'country' || key.v == null || key.v === '') return key
  if (Array.isArray(key.v)) {
    return { ...key, v: key.v.map((item) => String(item).toLowerCase()) }
  }
  return { ...key, v: String(key.v).toLowerCase() }
}

function activityFilterQueryString(key: CompactFilterKey): string {
  const normalized =
    key.c === 'country' ? normalizeActivityCountryFilterKey(key) : key
  return buildFilterQueryString(normalized.o, normalized.c, normalized.v)
}

export function maxIso(a: string, b: string): string {
  return a >= b ? a : b
}

export function minIso(a: string, b: string): string {
  return a <= b ? a : b
}

export function getActivityFilterQueryParts(
  filterMap: FilterMap,
  planSinceIso: string,
): { mergedSince: string; until?: string; extraQueries: string[] } {
  let userLo: string | undefined
  let userHi: string | undefined

  for (const [k] of filterMap) {
    if (k.c !== 'time') continue
    const v = k.v
    if (k.o === 'between' && v != null) {
      const raw = Array.isArray(v) ? v.join(',') : String(v)
      const parts = raw
        .split(',')
        .map((p) => p.trim())
        .filter(Boolean)
      if (parts.length >= 2) {
        userLo = userLo ? maxIso(userLo, parts[0]) : parts[0]
        userHi = userHi ? minIso(userHi, parts[1]) : parts[1]
      }
    } else if (
      (k.o === 'greaterThanEqual' || k.o === 'greaterThan') &&
      v != null &&
      v !== ''
    ) {
      const s = String(v)
      userLo = userLo ? maxIso(userLo, s) : s
    } else if (
      (k.o === 'lessThanEqual' || k.o === 'lessThan') &&
      v != null &&
      v !== ''
    ) {
      const s = String(v)
      userHi = userHi ? minIso(userHi, s) : s
    }
  }

  const mergedSince = userLo ? maxIso(planSinceIso, userLo) : planSinceIso
  const nowIso = new Date().toISOString()
  const until = userHi ? minIso(nowIso, userHi) : undefined

  const extraQueries = [...filterMap.entries()]
    .filter(([key]) => key.c !== 'time')
    .map(([key]) => activityFilterQueryString(key))

  return { mergedSince, until, extraQueries }
}
