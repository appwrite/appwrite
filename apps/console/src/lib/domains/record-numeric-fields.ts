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

const RECORD_NUMBER_FIELD_MESSAGES = {
  priority: {
    required: 'Priority is required',
    range: 'Priority must be between 0 and 65535',
  },
  weight: {
    required: 'Weight is required',
    range: 'Weight must be between 0 and 65535',
  },
  port: {
    required: 'Port is required',
    range: 'Port must be between 0 and 65535',
  },
} as const

export type RecordNumberField = keyof typeof RECORD_NUMBER_FIELD_MESSAGES

/**
 * Message for a required priority, weight, or port. Blank is missing. Any other
 * value that is not a whole number from 0 through 65535 is out of range.
 */
export function recordNumberFieldMessage(
  field: string,
  name: RecordNumberField,
): string | null {
  const trimmed = field.trim()

  if (trimmed === '') {
    return RECORD_NUMBER_FIELD_MESSAGES[name].required
  }

  const parsed = parseRecordNumber(field)

  if (parsed === undefined || parsed < 0 || parsed > RECORD_NUMBER_MAX) {
    return RECORD_NUMBER_FIELD_MESSAGES[name].range
  }

  return null
}
