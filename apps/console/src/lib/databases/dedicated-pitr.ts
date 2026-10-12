import type { Models } from '@appwrite.io/console'
import { isNativeDatabaseTypeValue } from '@/lib/databases/database-type'
import {
  matchesNativeEngine,
  type NativeDatabaseEngine,
} from '@/lib/databases/native-database-engines'
import { zonedPartsToDate } from '@/lib/timezones/zoned-time'

export function parsePitrInstant(
  value: string | number | null | undefined,
): Date | null {
  if (value == null || value === '') return null
  if (typeof value === 'number') {
    const ms = value < 1e12 ? value * 1000 : value
    const date = new Date(ms)
    return Number.isNaN(date.getTime()) ? null : date
  }

  const trimmed = String(value).trim()
  if (!trimmed) return null
  if (/^\d+$/.test(trimmed)) {
    const numeric = Number(trimmed)
    const ms = numeric < 1e12 ? numeric * 1000 : numeric
    const date = new Date(ms)
    return Number.isNaN(date.getTime()) ? null : date
  }

  const date = new Date(trimmed)
  return Number.isNaN(date.getTime()) ? null : date
}

export function pitrWindowFromResponse(
  windows: Models.DedicatedDatabasePITRWindows | null | undefined,
): { earliest: Date; latest: Date } | null {
  if (!windows) return null
  const earliest = parsePitrInstant(windows.earliest)
  const latest = parsePitrInstant(windows.latest)
  if (!earliest || !latest) return null
  if (earliest.getTime() > latest.getTime()) return null
  return { earliest, latest }
}

export function isTimeInPitrWindow(
  target: Date,
  window: { earliest: Date; latest: Date },
): boolean {
  const time = target.getTime()
  return time >= window.earliest.getTime() && time <= window.latest.getTime()
}

export type PitrOutOfRangeReason = 'before' | 'after'

export function pitrOutOfRangeReason(
  target: Date,
  window: { earliest: Date; latest: Date },
): PitrOutOfRangeReason | null {
  const time = target.getTime()
  if (time < window.earliest.getTime()) return 'before'
  if (time > window.latest.getTime()) return 'after'
  return null
}

export function getPitrDayBounds(
  day: Date,
  window: { earliest: Date; latest: Date },
  timeZone: string,
): { min: Date; max: Date } | null {
  const start = zonedPartsToDate(
    {
      year: day.getFullYear(),
      month: day.getMonth() + 1,
      day: day.getDate(),
      hour: 0,
      minute: 0,
      second: 0,
    },
    timeZone,
  )
  const end = zonedPartsToDate(
    {
      year: day.getFullYear(),
      month: day.getMonth() + 1,
      day: day.getDate(),
      hour: 23,
      minute: 59,
      second: 59,
    },
    timeZone,
  )
  if (end.getTime() < window.earliest.getTime()) return null
  if (start.getTime() > window.latest.getTime()) return null
  return {
    min: new Date(Math.max(start.getTime(), window.earliest.getTime())),
    max: new Date(Math.min(end.getTime(), window.latest.getTime())),
  }
}

export function nativeEngineFromDedicatedDatabase(
  database: Pick<Models.DedicatedDatabase, 'engine' | 'api'>,
): NativeDatabaseEngine {
  if (matchesNativeEngine(database.engine, 'mysql')) return 'mysql'
  if (matchesNativeEngine(database.engine, 'mongo')) return 'mongo'
  return 'postgres'
}

export function isEligiblePitrRestoreTarget(
  source: Models.DedicatedDatabase,
  candidate: Models.DedicatedDatabase,
): boolean {
  if (candidate.$id === source.$id) return false
  if (candidate.status !== 'ready') return false
  if (!isNativeDatabaseTypeValue(candidate.api)) return false
  const sourceEngine = nativeEngineFromDedicatedDatabase(source)
  if (!matchesNativeEngine(candidate.engine, sourceEngine)) return false
  const sourceVersion = (source.version ?? '').trim()
  const candidateVersion = (candidate.version ?? '').trim()
  if (sourceVersion && candidateVersion && sourceVersion !== candidateVersion) {
    return false
  }
  return true
}

export function dedicatedPitrRestorationIsActive(
  status: string | null | undefined,
): boolean {
  const normalized = (status ?? '').toLowerCase()
  return normalized === 'pending' || normalized === 'running'
}
