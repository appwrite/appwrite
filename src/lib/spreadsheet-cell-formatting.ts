/** Shared cell formatting for read-only data grids (matches database spreadsheet display rules). */

export function stringifySpreadsheetCellValue(value: unknown): string {
  if (value === null || value === undefined) return 'null'
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') {
    return String(value)
  }
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

export function formatSpreadsheetCellValue(value: unknown): {
  full: string
  display: string
  isNull: boolean
} {
  if (value === null || value === undefined) {
    return { full: 'null', display: 'null', isNull: true }
  }
  const stringValue = stringifySpreadsheetCellValue(value)
  const trimmed =
    stringValue.length > 80 ? `${stringValue.slice(0, 77)}…` : stringValue
  return { full: stringValue, display: trimmed, isNull: false }
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
