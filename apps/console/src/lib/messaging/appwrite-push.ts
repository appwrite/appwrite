/**
 * Appwrite push provider option parsing (QoS + message expiry).
 *
 * Both controls keep their value as text so an untouched field can stay empty.
 * Two subtleties the create wizard and the provider settings page share:
 *
 * 1. Expiry is read with `Number(...)` + `Number.isInteger`, never `parseInt`.
 *    `parseInt('1e3', 10)` is 1 (it stops at the `e`) and `parseInt('1.5', 10)`
 *    is 1, so `parseInt` would silently save a different number of seconds than
 *    the user typed. `Number('1e3')` is 1000, and `Number('1.5')` is 1.5 which
 *    is rejected as not a whole number.
 * 2. On update, returning QoS to "let the subscriber choose" or clearing expiry
 *    must send an explicit `null` so the API clears the stored value. Sending
 *    `undefined` omits the parameter and leaves the old value in place.
 */

/** QoS select value meaning "let the subscriber choose" (no provider default). */
export const APPWRITE_PUSH_QOS_DEFAULT = 'default'

/**
 * Parse a QoS select value. `'default'` (or an empty / non-numeric value) means
 * "let the subscriber choose" and maps to `null`; `'0'` and `'1'` map to the
 * number. `null` is the API's explicit reset representation.
 */
export function parseAppwritePushQos(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '' || trimmed === APPWRITE_PUSH_QOS_DEFAULT) return null
  const n = Number(trimmed)
  return Number.isInteger(n) ? n : null
}

/** Server cap on message expiry: 7 days, in seconds. */
export const APPWRITE_PUSH_EXPIRY_MAX = 604800

export type ParsedExpiry = { ok: true; value: number | null } | { ok: false }

/**
 * Parse a message-expiry input (seconds). An empty field maps to `null` (reset
 * to the default). A supplied value must be a whole number from 0 to
 * `APPWRITE_PUSH_EXPIRY_MAX`; anything else (fractional, negative, too large,
 * or unparseable) is reported as invalid
 * so the caller can reject it rather than silently save a different value.
 */
export function parseAppwritePushExpiry(raw: string): ParsedExpiry {
  const trimmed = raw.trim()
  if (trimmed === '') return { ok: true, value: null }
  const n = Number(trimmed)
  if (!Number.isInteger(n) || n < 0 || n > APPWRITE_PUSH_EXPIRY_MAX) {
    return { ok: false }
  }
  return { ok: true, value: n }
}
