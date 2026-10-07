import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { useUpdateMysqlDatabaseMaintenance } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'

export const MAINTENANCE_DAYS = [
  { value: 'sun', label: 'Sunday', short: 'Sun' },
  { value: 'mon', label: 'Monday', short: 'Mon' },
  { value: 'tue', label: 'Tuesday', short: 'Tue' },
  { value: 'wed', label: 'Wednesday', short: 'Wed' },
  { value: 'thu', label: 'Thursday', short: 'Thu' },
  { value: 'fri', label: 'Friday', short: 'Fri' },
  { value: 'sat', label: 'Saturday', short: 'Sat' },
] as const

export type MaintenanceDayValue = (typeof MAINTENANCE_DAYS)[number]['value']

const DAY_VALUE_TO_UTC_DOW: Record<MaintenanceDayValue, number> = {
  sun: 0,
  mon: 1,
  tue: 2,
  wed: 3,
  thu: 4,
  fri: 5,
  sat: 6,
}

const UTC_DOW_TO_DAY_VALUE: MaintenanceDayValue[] = [
  'sun',
  'mon',
  'tue',
  'wed',
  'thu',
  'fri',
  'sat',
]

export type MaintenanceWindowTimePreview = {
  utcDayLabel: string
  utcTimeLabel: string
  localDayLabel: string
  localTimeLabel: string
  timeZone: string
  timeZoneLabel: string
  dayDiffersInLocalTime: boolean
}

export function getUserTimeZone(): string {
  if (typeof Intl === 'undefined') return 'UTC'
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

export function buildMaintenanceWindowUtcInstant(
  day: MaintenanceDayValue,
  hourUtc: number,
): Date {
  const targetDow = DAY_VALUE_TO_UTC_DOW[day]
  const now = new Date()
  const candidate = new Date(
    Date.UTC(
      now.getUTCFullYear(),
      now.getUTCMonth(),
      now.getUTCDate(),
      hourUtc,
      0,
      0,
    ),
  )
  const daysUntil = (targetDow - candidate.getUTCDay() + 7) % 7
  candidate.setUTCDate(candidate.getUTCDate() + daysUntil)
  return candidate
}

function getLocalWeekdayIndex(date: Date, timeZone: string): number {
  const weekday = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    timeZone,
  }).format(date)
  const map: Record<string, number> = {
    Sun: 0,
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
  }
  return map[weekday] ?? 0
}

export function getMaintenanceWindowTimePreview(
  day: MaintenanceDayValue,
  hourUtc: number,
  t: (text: string) => string,
): MaintenanceWindowTimePreview {
  const instant = buildMaintenanceWindowUtcInstant(day, hourUtc)
  const timeZone = getUserTimeZone()
  const utcDayMeta = getMaintenanceDayMeta(day)
  const localDayValue = UTC_DOW_TO_DAY_VALUE[getLocalWeekdayIndex(instant, timeZone)]
  const localDayMeta = getMaintenanceDayMeta(localDayValue)

  const localTimeLabel = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    hour12: true,
    timeZone,
  }).format(instant)

  const timeZoneLabel =
    new Intl.DateTimeFormat(undefined, {
      timeZone,
      timeZoneName: 'short',
    })
      .formatToParts(instant)
      .find((part) => part.type === 'timeZoneName')?.value ?? timeZone

  return {
    utcDayLabel: t(utcDayMeta.label),
    utcTimeLabel: `${formatMaintenanceHourAmPmLabel(hourUtc, t)} UTC`,
    localDayLabel: t(localDayMeta.label),
    localTimeLabel,
    timeZone,
    timeZoneLabel,
    dayDiffersInLocalTime: day !== localDayValue,
  }
}

export const MAINTENANCE_HOURS_AM = Array.from({ length: 12 }, (_, hour) => hour)
export const MAINTENANCE_HOURS_PM = Array.from(
  { length: 12 },
  (_, index) => index + 12,
)

export function formatMaintenanceHourAmPm(hour: number) {
  const period = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour === 0 ? 12 : hour > 12 ? hour - 12 : hour

  return { displayHour, period }
}

export function formatMaintenanceHourAmPmLabel(
  hour: number,
  t: (text: string) => string,
) {
  const { displayHour, period } = formatMaintenanceHourAmPm(hour)
  return `${displayHour} ${t(period)}`
}

export function getMaintenanceDayMeta(day: string) {
  return (
    MAINTENANCE_DAYS.find((entry) => entry.value === day) ?? MAINTENANCE_DAYS[0]
  )
}

export function useMysqlMaintenanceWindow({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const t = useT()
  const maintenanceMutation = useUpdateMysqlDatabaseMaintenance(
    projectId,
    databaseId,
  )
  const [maintenanceDay, setMaintenanceDay] = useState<MaintenanceDayValue>(
    (database.maintenanceWindowDay as MaintenanceDayValue) || 'sun',
  )
  const [maintenanceHourUtc, setMaintenanceHourUtc] = useState(
    String(database.maintenanceWindowHourUtc ?? 0),
  )

  const writeDisabled = !canWrite || maintenanceMutation.isPending
  const writeTooltip = !canWrite
    ? t("You don't have permission to change database settings.")
    : undefined

  useEffect(() => {
    setMaintenanceDay(
      (database.maintenanceWindowDay as MaintenanceDayValue) || 'sun',
    )
    setMaintenanceHourUtc(String(database.maintenanceWindowHourUtc ?? 0))
  }, [database.maintenanceWindowDay, database.maintenanceWindowHourUtc])

  const savedDay =
    (database.maintenanceWindowDay as MaintenanceDayValue) || 'sun'
  const savedHour = String(database.maintenanceWindowHourUtc ?? 0)

  const maintenanceDirty =
    maintenanceDay !== savedDay || maintenanceHourUtc !== savedHour

  const maintenanceHour = useMemo(() => {
    const hour = Number.parseInt(maintenanceHourUtc, 10)
    return Number.isFinite(hour) ? hour : 0
  }, [maintenanceHourUtc])

  const setMaintenanceHour = (hour: number) => {
    const clamped = Math.max(0, Math.min(23, hour))
    setMaintenanceHourUtc(String(clamped))
  }

  const handleMaintenanceUpdate = () => {
    const hourUtc = Number.parseInt(maintenanceHourUtc, 10)
    if (!Number.isFinite(hourUtc) || hourUtc < 0 || hourUtc > 23) {
      toast.error(t('Enter an hour between 0 and 23 (UTC).'))
      return
    }
    maintenanceMutation.mutate(
      { day: maintenanceDay, hourUtc },
      {
        onSuccess: () => toast.success(t('Maintenance window updated')),
        onError: (error) =>
          toast.error(
            getErrorMessage(error, t('Failed to update maintenance window')),
          ),
      },
    )
  }

  const dayMeta = getMaintenanceDayMeta(maintenanceDay)

  return {
    t,
    maintenanceDay,
    setMaintenanceDay,
    maintenanceHour,
    setMaintenanceHour,
    writeDisabled,
    writeTooltip,
    maintenanceDirty,
    handleMaintenanceUpdate,
    dayMeta,
  }
}
