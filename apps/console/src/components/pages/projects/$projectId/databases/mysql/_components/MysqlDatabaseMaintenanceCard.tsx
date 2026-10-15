import { useEffect, useMemo, useState } from 'react'
import { Check, Globe } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import type { MysqlDatabaseSettingsCardProps } from './mysql-database-settings-types'
import {
  MAINTENANCE_DAYS,
  MAINTENANCE_HOURS_AM,
  MAINTENANCE_HOURS_PM,
  formatMaintenanceHourAmPm,
  formatMaintenanceHourAmPmLabel,
  getMaintenanceWindowTimePreview,
  getUserTimeZone,
  useMysqlMaintenanceWindow,
} from './mysql-maintenance-shared'

type MaintenanceWeekHourGridProps = {
  maintenanceDay: string
  maintenanceHour: number
  writeDisabled: boolean
  onSelect: (day: (typeof MAINTENANCE_DAYS)[number]['value'], hour: number) => void
  t: ReturnType<typeof useMysqlMaintenanceWindow>['t']
}

function MaintenanceTimezoneNotice({
  t,
}: {
  t: ReturnType<typeof useMysqlMaintenanceWindow>['t']
}) {
  const timeZone = getUserTimeZone()

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2.5">
      <p className="text-[12px] text-muted-foreground">
        {t('All times in the grid use UTC (Coordinated Universal Time).')}
      </p>
      <Badge variant="outline" className="gap-1.5 text-[11px] font-normal">
        <Globe className="h-3 w-3 shrink-0" aria-hidden />
        {t('Your timezone')}: {timeZone}
      </Badge>
    </div>
  )
}

function MaintenanceWeekHourGrid({
  maintenanceDay,
  maintenanceHour,
  writeDisabled,
  onSelect,
  t,
}: MaintenanceWeekHourGridProps) {
  const [activePeriod, setActivePeriod] = useState<'AM' | 'PM'>(
    maintenanceHour >= 12 ? 'PM' : 'AM',
  )

  useEffect(() => {
    setActivePeriod(maintenanceHour >= 12 ? 'PM' : 'AM')
  }, [maintenanceHour])

  const visibleHours =
    activePeriod === 'AM' ? MAINTENANCE_HOURS_AM : MAINTENANCE_HOURS_PM

  return (
    <div className="overflow-hidden rounded-lg border border-border p-3">
      <div className="mb-3 flex items-center justify-between gap-3">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('UTC')}
        </p>
        <div
          className="inline-flex rounded-md border border-border bg-muted/30 p-0.5"
          role="group"
          aria-label={t('Time of day in UTC')}
        >
          {(['AM', 'PM'] as const).map((period) => {
            const selected = activePeriod === period
            return (
              <button
                key={period}
                type="button"
                disabled={writeDisabled}
                onClick={() => setActivePeriod(period)}
                className={cn(
                  'h-7 min-w-[4.5rem] rounded px-3 text-[11px] font-medium transition-colors',
                  selected
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                  writeDisabled && 'cursor-not-allowed opacity-60',
                )}
                aria-pressed={selected}
              >
                {t(period)} UTC
              </button>
            )
          })}
        </div>
      </div>

      <div className="grid grid-cols-[2.25rem_repeat(7,minmax(0,1fr))] gap-1">
        <div />
        {MAINTENANCE_DAYS.map((day) => (
          <div
            key={day.value}
            className="flex h-6 items-center justify-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
          >
            {t(day.short)}
          </div>
        ))}

        {visibleHours.map((hour) => {
          const { displayHour } = formatMaintenanceHourAmPm(hour)

          return (
            <div key={hour} className="contents">
              <div className="flex h-7 items-center justify-end pe-1 text-[11px] font-medium tabular-nums text-muted-foreground">
                {displayHour}
              </div>
              {MAINTENANCE_DAYS.map((day) => {
                const selected =
                  maintenanceDay === day.value && maintenanceHour === hour

                return (
                  <button
                    key={`${day.value}-${hour}`}
                    type="button"
                    disabled={writeDisabled}
                    onClick={() => onSelect(day.value, hour)}
                    className={cn(
                      'flex h-7 items-center justify-center rounded-md border transition-colors',
                      selected
                        ? 'border-primary/50 bg-primary/10 text-primary ring-1 ring-primary/30'
                        : 'border-border/70 bg-card text-transparent hover:bg-muted/40 hover:text-muted-foreground/40',
                      writeDisabled && 'cursor-not-allowed opacity-60',
                    )}
                    aria-label={`${t(day.label)} ${formatMaintenanceHourAmPmLabel(hour, t)} UTC`}
                    aria-pressed={selected}
                  >
                    <Check
                      className={cn(
                        'h-3.5 w-3.5',
                        selected ? 'opacity-100' : 'opacity-0',
                      )}
                      aria-hidden
                    />
                  </button>
                )
              })}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function MaintenanceScheduleSummary({
  day,
  hour,
  t,
}: {
  day: (typeof MAINTENANCE_DAYS)[number]['value']
  hour: number
  t: ReturnType<typeof useMysqlMaintenanceWindow>['t']
}) {
  const preview = useMemo(
    () => getMaintenanceWindowTimePreview(day, hour, t),
    [day, hour, t],
  )

  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-lg border border-border bg-muted/25 px-4 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('In UTC')}
          </p>
          <p className="mt-1 text-[14px] font-medium text-foreground">
            {t('Scheduled every')} {preview.utcDayLabel} {t('starting at')}{' '}
            {preview.utcTimeLabel}
          </p>
        </div>

        <div className="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('In your local time')} ({preview.timeZoneLabel})
          </p>
          <p className="mt-1 text-[14px] font-medium text-foreground">
            {t('Scheduled every')} {preview.localDayLabel} {t('starting at')}{' '}
            {preview.localTimeLabel}
          </p>
        </div>
      </div>

      {preview.dayDiffersInLocalTime ? (
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {t(
            'Maintenance may start on a different weekday in your timezone than in UTC.',
          )}
        </p>
      ) : null}
    </div>
  )
}

export function MysqlDatabaseMaintenanceCard({
  projectId,
  databaseId,
  database,
  canWrite,
}: MysqlDatabaseSettingsCardProps) {
  const {
    t,
    maintenanceDay,
    setMaintenanceDay,
    maintenanceHour,
    setMaintenanceHour,
    writeDisabled,
    writeTooltip,
    maintenanceDirty,
    handleMaintenanceUpdate,
  } = useMysqlMaintenanceWindow({
    projectId,
    databaseId,
    database,
    canWrite,
  })

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Maintenance window')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Pick a weekly window in UTC. We also show what that means in your local timezone.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="space-y-4 px-6 py-4">
        <MaintenanceTimezoneNotice t={t} />

        <MaintenanceWeekHourGrid
          maintenanceDay={maintenanceDay}
          maintenanceHour={maintenanceHour}
          writeDisabled={writeDisabled}
          onSelect={(day, hour) => {
            setMaintenanceDay(day)
            setMaintenanceHour(hour)
          }}
          t={t}
        />

        <MaintenanceScheduleSummary
          day={maintenanceDay}
          hour={maintenanceHour}
          t={t}
        />
      </div>
      <div className="border-t border-border bg-muted/30 px-6 py-4">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={writeDisabled || !maintenanceDirty}
          title={writeTooltip}
          onClick={handleMaintenanceUpdate}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
