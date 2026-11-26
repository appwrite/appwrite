import type { TimeInputValue } from '@/components/global/shared/TimeInput'

export function secondsToTimeInputValue(totalSeconds: number): TimeInputValue {
  const clamped = Math.max(0, Math.floor(totalSeconds))
  return {
    hour: Math.floor(clamped / 3600),
    minute: Math.floor((clamped % 3600) / 60),
    second: clamped % 60,
  }
}

export function timeInputValueToSeconds(value: TimeInputValue): number {
  return value.hour * 3600 + value.minute * 60 + value.second
}

export function maxEmbedStartSeconds(
  durationMs: number | undefined | null,
): number | null {
  if (!durationMs || durationMs <= 0 || !Number.isFinite(durationMs)) {
    return null
  }
  return Math.floor(durationMs / 1000)
}

export function clampSecondsToDuration(
  seconds: number,
  durationMs: number | undefined | null,
): number {
  const safe = Math.max(0, Math.floor(seconds))
  const max = maxEmbedStartSeconds(durationMs)
  if (max == null) return safe
  return Math.min(safe, max)
}

export function clampTimeInputToDuration(
  value: TimeInputValue,
  durationMs: number | undefined | null,
): TimeInputValue {
  const seconds = clampSecondsToDuration(
    timeInputValueToSeconds(value),
    durationMs,
  )
  return secondsToTimeInputValue(seconds)
}

const DIGITS = '0123456789.'
const UNIT_SECONDS: Record<string, number> = { h: 3600, m: 60, s: 1 }

function isNumber(text: string): boolean {
  return text !== '' && [...text].every((char) => DIGITS.includes(char))
}

/** `1:30` or `1:02:03`. */
function parseClock(text: string): number | undefined {
  const parts = text.split(':')
  if (parts.length > 3 || !parts.every(isNumber)) return undefined
  return parts.reduce((total, part) => total * 60 + Number(part), 0)
}

/** `90s`, `1m30s` or `1h2m3s`, the way video links write times. */
function parseUnits(text: string): number | undefined {
  let total = 0
  let number = ''
  for (const char of text) {
    if (DIGITS.includes(char)) {
      number += char
      continue
    }
    const unit = UNIT_SECONDS[char]
    if (unit == null || !isNumber(number)) return undefined
    total += Number(number) * unit
    number = ''
  }
  return number === '' ? total : undefined
}

/** Start time in seconds from `?start=`: `90`, `90s`, `1m30s` or `1:30`. */
export function parseEmbedStartParam(
  value: string | number | undefined | null,
): number | undefined {
  if (value == null || value === '') return undefined
  let parsed: number | undefined
  if (typeof value === 'number') {
    parsed = value
  } else {
    const text = value.trim().toLowerCase()
    parsed = text.includes(':')
      ? parseClock(text)
      : isNumber(text)
        ? Number(text)
        : parseUnits(text)
  }
  if (parsed == null || !Number.isFinite(parsed) || parsed <= 0) {
    return undefined
  }
  return parsed
}
