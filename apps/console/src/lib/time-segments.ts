export type TimeSegment = 'hour' | 'minute' | 'second'

export type HourMinute = { hour: number; minute: number }

export const TIME_SEGMENT_MAX: Record<TimeSegment, number> = {
  hour: 23,
  minute: 59,
  second: 59,
}

export const TIME_SEPARATOR_CHARS: ReadonlySet<string> = new Set([
  ':',
  '.',
  ',',
  ' ',
  'h',
  'H',
])

export function pad2(value: number): string {
  return String(value).padStart(2, '0')
}

export type SegmentDigitResult = {
  value: number
  buffer: string
  complete: boolean
}

/**
 * Feeds one digit into a segment. When the combined digits exceed the segment
 * max, entry restarts with the digit. A segment is complete after two digits
 * or when no second digit could keep it in range (e.g. hour 3).
 */
export function applySegmentDigit(
  segment: TimeSegment,
  buffer: string,
  digit: number,
): SegmentDigitResult {
  const max = TIME_SEGMENT_MAX[segment]
  let combined = `${buffer}${digit}`
  if (Number(combined) > max) combined = String(digit)
  const value = Number(combined)
  const complete = combined.length >= 2 || value * 10 > max
  return { value, buffer: complete ? '' : combined, complete }
}

/** Steps within 0..max, wrapping without carrying into other segments. */
export function stepSegmentValue(
  segment: TimeSegment,
  value: number,
  delta: number,
): number {
  const size = TIME_SEGMENT_MAX[segment] + 1
  return (((value + delta) % size) + size) % size
}

/** Folds full-width digits and separators to ASCII. */
export function normalizeTimeText(text: string): string {
  return text.normalize('NFKC')
}

/**
 * Parses pasted 24h times: `9:45`, `09.45`, `9h45`, `15:30:12`, `0945`, or a
 * single number for the focused segment. Seconds are ignored; am/pm and ISO
 * strings are rejected.
 */
export function parsePastedTime(
  text: string,
  focused: 'hour' | 'minute',
): Partial<HourMinute> | null {
  const trimmed = normalizeTimeText(text).trim()
  let match =
    /^(\d{1,2})\s*[:.hH]\s*(\d{1,2})(?:\s*[:.]\s*\d{1,2}(?:[.,]\d+)?)?$/.exec(
      trimmed,
    )
  if (match) {
    const hour = Number(match[1])
    const minute = Number(match[2])
    return hour <= 23 && minute <= 59 ? { hour, minute } : null
  }
  match = /^(\d{3,4})$/.exec(trimmed)
  if (match) {
    const hour = Number(match[1].slice(0, -2))
    const minute = Number(match[1].slice(-2))
    return hour <= 23 && minute <= 59 ? { hour, minute } : null
  }
  match = /^(\d{1,2})$/.exec(trimmed)
  if (match) {
    const value = Number(match[1])
    return value <= TIME_SEGMENT_MAX[focused] ? { [focused]: value } : null
  }
  return null
}

/** Text inserted between `before` and `after` (common prefix and suffix stripped). */
export function extractInsertedText(before: string, after: string): string {
  let start = 0
  while (
    start < before.length &&
    start < after.length &&
    before[start] === after[start]
  ) {
    start++
  }
  let endBefore = before.length
  let endAfter = after.length
  while (
    endBefore > start &&
    endAfter > start &&
    before[endBefore - 1] === after[endAfter - 1]
  ) {
    endBefore--
    endAfter--
  }
  return after.slice(start, endAfter)
}
