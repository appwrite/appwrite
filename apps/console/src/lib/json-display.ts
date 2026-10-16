/** JSON.stringify replacer for values JSON cannot encode natively. */
export function jsonDisplayReplacer(_key: string, value: unknown): unknown {
  if (typeof value === 'bigint') {
    return value.toString()
  }
  return value
}

/** Safe JSON.stringify for console display (handles BigInt from SQL API payloads). */
export function stringifyJsonForDisplay(
  value: unknown,
  space?: number | string,
): string {
  return JSON.stringify(value, jsonDisplayReplacer, space)
}

/** Recursively coerce BigInt values to strings for in-memory plan trees. */
export function sanitizeJsonValue(value: unknown): unknown {
  if (value === null || value === undefined) {
    return value
  }

  if (typeof value === 'bigint') {
    return value.toString()
  }

  if (Array.isArray(value)) {
    return value.map((entry) => sanitizeJsonValue(entry))
  }

  if (typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([key, entry]) => [
        key,
        sanitizeJsonValue(entry),
      ]),
    )
  }

  return value
}
