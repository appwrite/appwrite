/**
 * Display formatting for raw team/user preference values.
 *
 * Prefs are edited as strings in the console, but the API returns whatever JSON
 * was stored, so a value can be an object, an array, a number or a boolean.
 * `String(value)` is not safe for those: an object that shadows `toString` with
 * a non-callable value (or has a null prototype) throws
 * `TypeError: Cannot convert object to primitive value`.
 */

import { stringifyJsonForDisplay } from '@/lib/json-display'

/** Convert a raw pref value into the string shown in the prefs editor. */
export function formatPrefValue(value: unknown): string {
  if (value === null || value === undefined) {
    return ''
  }

  if (typeof value === 'string') {
    return value
  }

  if (typeof value === 'object' || typeof value === 'function') {
    try {
      return stringifyJsonForDisplay(value) ?? ''
    } catch {
      return ''
    }
  }

  return String(value)
}

/** Normalize a prefs object to the `key -> string` shape the editor submits. */
export function formatPrefsForEditor(
  prefs: Record<string, unknown>,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(prefs).map(([key, value]) => [key, formatPrefValue(value)]),
  )
}
