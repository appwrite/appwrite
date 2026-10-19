/**
 * DNS record forms keep their numeric fields as text so an untouched field can stay empty. Reading
 * that text back has to preserve a typed zero: `0` is the highest priority an MX record can be
 * given, and SRV takes `0` for priority, weight and port. `parseInt(field) || undefined` reported
 * a zero as "not supplied", which is why an MX record with priority 0 could not be saved.
 */

/** Priority, weight and port are two octets on the wire, so the API accepts 0 through 65535. */
export const RECORD_NUMBER_MAX = 65535

/** RFC 2181 gives TTL a 32-bit field with the top bit reserved. */
export const RECORD_TTL_MAX = 2147483647

/**
 * Reads a form field holding a whole number. Returns `undefined` only when the field is empty or
 * holds something that is not a whole number -- never for a value the user actually typed.
 */
export function parseRecordNumber(field: string): number | undefined {
  const trimmed = field.trim()

  if (trimmed === '') {
    return undefined
  }

  const parsed = Number(trimmed)

  return Number.isInteger(parsed) ? parsed : undefined
}
