/**
 * Display formatting for raw team/user preference values.
 *
 * Prefs are edited as strings in the console, but the API returns whatever JSON
 * was stored, so a value can be an object, an array, a number or a boolean.
 * `String(value)` is not safe for those: an object that shadows `toString` with
 * a non-callable value (or has a null prototype) throws
 * `TypeError: Cannot convert object to primitive value`.
 *
 * Every value the API can return maps to a non-empty string, because both
 * editors drop empty rows from the prefs object they submit — blanking a stored
 * value would silently delete the key on the next save.
 */

import { stringifyJsonForDisplay } from '@/lib/json-display'

/** Convert a raw pref value into the string shown in the prefs editor. */
export function formatPrefValue(value: unknown): string {
  // `undefined` is not representable in stored prefs, so there is nothing to
  // keep; a stored `null` is a real value and stays editable as `null`.
  if (value === undefined) {
    return ''
  }

  if (value === null) {
    return 'null'
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
