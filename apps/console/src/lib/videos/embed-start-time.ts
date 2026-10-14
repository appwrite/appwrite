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

export function parseEmbedStartParam(
  value: string | number | undefined | null,
): number | undefined {
  if (value == null || value === '') return undefined
  const parsed = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(parsed) || parsed <= 0) return undefined
  return parsed
}
