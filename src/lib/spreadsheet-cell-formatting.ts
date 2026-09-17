/** Shared cell formatting for read-only data grids (matches database spreadsheet display rules). */

import { formatTimeZoneLabel } from '@/lib/timezones/zoned-time'

export function stringifySpreadsheetCellValue(value: unknown): string {
  if (value === null || value === undefined) return 'null'
  if (typeof value === 'string') return value
  if (
    typeof value === 'number' ||
    typeof value === 'boolean' ||
    typeof value === 'bigint'
  ) {
    return String(value)
  }
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

type SpreadsheetCellText = {
  full: string
  display: string
  isNull: boolean
}

export function formatSpreadsheetCellValue(
  value: unknown,
): SpreadsheetCellText {
  if (value === null || value === undefined) {
    return { full: 'null', display: 'null', isNull: true }
  }
  const stringValue = stringifySpreadsheetCellValue(value)
  const trimmed =
    stringValue.length > 80 ? `${stringValue.slice(0, 77)}…` : stringValue
  return { full: stringValue, display: trimmed, isNull: false }
}

// Only strings with an explicit Z or offset are instants; naive values stay raw.
const INSTANT_ISO =
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})$/i

/** Formats Appwrite instants as short date+time in `timeZone`. */
export function createInstantCellFormatter(
  timeZone: string,
): (value: unknown) => SpreadsheetCellText {
  const wall = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'short',
    timeStyle: 'short',
    timeZone,
  })
  const parse = (value: unknown): Date | null => {
    if (typeof value !== 'string' || !INSTANT_ISO.test(value)) return null
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? null : date
  }

  return (value) => {
    if (value === null || value === undefined) {
      return { full: 'null', display: 'null', isNull: true }
    }
    if (typeof value === 'string') {
      const date = parse(value)
      if (date) {
        return {
          full: `${value}\n${formatTimeZoneLabel(timeZone, date)}`,
          display: wall.format(date),
          isNull: false,
        }
      }
    }
    if (Array.isArray(value)) {
      const dates = value.map(parse)
      const firstInstant = dates.find((date) => date !== null)
      const items = value.map((item, index) => {
        const date = dates[index]
        return date ? wall.format(date) : stringifySpreadsheetCellValue(item)
      })
      const joined = `[${items.join(', ')}]`
      const raw = `[${value.map((item) => stringifySpreadsheetCellValue(item)).join(', ')}]`
      return {
        full: firstInstant
          ? `${raw}\n${formatTimeZoneLabel(timeZone, firstInstant)}`
          : raw,
        display: joined.length > 80 ? `${joined.slice(0, 77)}…` : joined,
        isNull: false,
      }
    }
    return formatSpreadsheetCellValue(value)
  }
}

export function isSpreadsheetRtlText(text: string | null | undefined): boolean {
  if (!text || typeof text !== 'string') return false
  const rtlPattern =
    /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
  return rtlPattern.test(text)
}

export function isSpreadsheetCellValueTrimmed(
  full: string,
  display: string,
): boolean {
  return full !== display
}

export function copySpreadsheetCellValue(value: unknown): string {
  return stringifySpreadsheetCellValue(value)
}

export function formatSpreadsheetCellValueForDialog(
  value: unknown,
  full: string,
): string {
  if (value !== null && typeof value === 'object') {
    try {
      return JSON.stringify(value, null, 2)
    } catch {
      return full
    }
  }

  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (
      (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
      (trimmed.startsWith('[') && trimmed.endsWith(']'))
    ) {
      try {
        return JSON.stringify(JSON.parse(trimmed), null, 2)
      } catch {
        return full
      }
    }
  }

  return full
}
