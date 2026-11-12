import { useMemo } from 'react'
import { startOfYear, endOfYear, eachDayOfInterval, getDay, getMonth } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { Models } from '@appwrite.io/console'

interface ActivityPunchcardProps {
  sessions: Models.Session[]
  className?: string
}

export function ActivityPunchcard({
  sessions,
  className,
}: ActivityPunchcardProps) {
  const t = useT()
  // Get all days in the current year
  const yearStart = startOfYear(new Date())
  const yearEnd = endOfYear(new Date())
  const daysInYear = eachDayOfInterval({ start: yearStart, end: yearEnd })

  // Create a map of dates to session counts
  const activityMap = useMemo(() => {
    const map = new Map<string, number>()

    sessions.forEach((session) => {
      // Use $createdAt which is standard in Appwrite models
      const dateString = (session as unknown).$createdAt
      if (dateString) {
        try {
          const date = new Date(dateString)
          // Only count valid dates
          if (!isNaN(date.getTime())) {
            const dateKey = format(date, 'yyyy-MM-dd')
            const count = map.get(dateKey) || 0
            map.set(dateKey, count + 1)
          }
        } catch {
          // Skip invalid dates
        }
      }
    })

    return map
  }, [sessions])

  // Group days by week (starting from Sunday)
  const weeks = useMemo(() => {
    const weeksArray: (Date | null)[][] = []
    let currentWeek: (Date | null)[] = []

    // Start with Sunday (0)
    const firstDayOfYear = getDay(yearStart)

    // Add empty cells for days before the first day of the year
    for (let i = 0; i < firstDayOfYear; i++) {
      currentWeek.push(null)
    }

    daysInYear.forEach((day) => {
      currentWeek.push(day)

      // If we've filled a week (7 days), start a new week
      if (currentWeek.length === 7) {
        weeksArray.push(currentWeek)
        currentWeek = []
      }
    })

    // Add remaining days to the last week
    if (currentWeek.length > 0) {
      // Fill remaining days with null
      while (currentWeek.length < 7) {
        currentWeek.push(null)
      }
      weeksArray.push(currentWeek)
    }

    return weeksArray
  }, [daysInYear, yearStart])

  // Calculate month positions for labels
  const monthLabels = useMemo(() => {
    const labels: Array<{ month: string; weekIndex: number }> = []
    const monthsSeen = new Set<number>()

    // Find which week each month starts in
    weeks.forEach((week, weekIndex) => {
      week.forEach((day) => {
        if (day) {
          const dayOfMonth = day.getDate()
          const month = getMonth(day)

          // If this is the first day of the month and we haven't seen this month yet
          if (dayOfMonth === 1 && !monthsSeen.has(month)) {
            monthsSeen.add(month)
            labels.push({
              month: formatLocalizedDate(day, 'MMM'),
              weekIndex,
            })
          }
        }
      })
    })

    return labels.sort((a, b) => a.weekIndex - b.weekIndex)
  }, [weeks])

  // Get activity level for a date
  const getActivityLevel = (date: Date | null): number => {
    if (!date) return 0
    const dateKey = format(date, 'yyyy-MM-dd')
    const count = activityMap.get(dateKey) || 0

    // Return activity level (0-4) based on session count
    if (count === 0) return 0
    if (count === 1) return 1
    if (count <= 3) return 2
    if (count <= 5) return 3
    return 4
  }

  // Get color class based on activity level
  const getColorClass = (level: number): string => {
    switch (level) {
      case 0:
        return 'bg-muted'
      case 1:
        return 'bg-primary/20'
      case 2:
        return 'bg-primary/40'
      case 3:
        return 'bg-primary/60'
      case 4:
        return 'bg-primary'
      default:
        return 'bg-muted'
    }
  }

  // Get tooltip text for a date
  const getTooltipText = (date: Date | null): string => {
    if (!date) return ''
    const dateKey = format(date, 'yyyy-MM-dd')
    const count = activityMap.get(dateKey) || 0
    const formattedDate = formatLocalizedDate(date, 'MMM d, yyyy')

    if (count === 0) {
      return `${t('No activity on')} ${formattedDate}`
    }
    return `${count} ${count === 1 ? t('session on') : t('sessions on')} ${formattedDate}`
  }

  // Calculate total active days
  const totalActiveDays = useMemo(() => {
    return activityMap.size
  }, [activityMap])

  return (
    <div
      className={cn(
        'rounded-xl border border-border bg-card/50 overflow-hidden',
        className,
      )}
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Activity')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Days with activity in')} {format(new Date(), 'yyyy')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          {/* Stats */}
          <div className="flex items-center gap-6 text-[13px]">
            <div>
              <span className="text-muted-foreground">
                {t('Total active days:')}
              </span>
              <span className="ms-1.5 font-medium text-foreground">
                {totalActiveDays}
              </span>
            </div>
            <div>
              <span className="text-muted-foreground">
                {t('Total sessions:')}
              </span>
              <span className="ms-1.5 font-medium text-foreground">
                {sessions.length}
              </span>
            </div>
          </div>

          {/* Punchcard Grid */}
          <div className="w-full">
            <div className="flex items-start">
              {/* Week day labels */}
              <div
                className="flex flex-col pe-3 shrink-0 gap-[0.25rem]"
                style={{ paddingTop: '1.25rem' }}
              >
                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(
                  (day, index) => (
                    <div
                      key={day}
                      className={cn(
                        'h-[11px] w-8 flex items-center text-[10px] text-muted-foreground',
                        index % 2 === 0 ? 'opacity-100' : 'opacity-0',
                      )}
                    >
                      {index % 2 === 0 ? t(day) : ''}
                    </div>
                  ),
                )}
              </div>

              {/* Month labels and weeks */}
              <div className="flex-1 min-w-0">
                {/* Month labels */}
                <div className="relative h-4 mb-1 w-full">
                  {monthLabels.map(({ month, weekIndex }) => {
                    // Calculate position as percentage of total weeks
                    const percentage = (weekIndex / weeks.length) * 100
                    return (
                      <div
                        key={`${month}-${weekIndex}`}
                        className="absolute text-[10px] text-muted-foreground whitespace-nowrap"
                        style={{
                          left: `${percentage}%`,
                        }}
                      >
                        {month}
                      </div>
                    )
                  })}
                </div>

                {/* Weeks grid */}
                <div className="flex w-full justify-between gap-[0.125rem]">
                  {weeks.map((week, weekIndex) => (
                    <div
                      key={weekIndex}
                      className="flex flex-col gap-[0.25rem]"
                    >
                      {week.map((day, dayIndex) => {
                        if (!day) {
                          return (
                            <div
                              key={`${weekIndex}-${dayIndex}`}
                              className="h-[11px] w-[11px]"
                            />
                          )
                        }

                        const activityLevel = getActivityLevel(day)
                        const tooltipText = getTooltipText(day)

                        return (
                          <Tooltip key={`${weekIndex}-${dayIndex}`}>
                            <TooltipTrigger asChild>
                              <div
                                className={cn(
                                  'h-[11px] w-[11px] rounded-sm border border-border/50 transition-colors cursor-pointer',
                                  getColorClass(activityLevel),
                                )}
                              />
                            </TooltipTrigger>
                            <TooltipContent>
                              <p className="text-[12px]">{tooltipText}</p>
                            </TooltipContent>
                          </Tooltip>
                        )
                      })}
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>

          {/* Legend */}
          <div className="flex items-center gap-3 text-[12px] text-muted-foreground">
            <span className="text-[12px]">{t('Less')}</span>
            <div className="flex items-center gap-[0.125rem]">
              <div className="h-[11px] w-[11px] rounded-sm bg-muted border border-border/50" />
              <div className="h-[11px] w-[11px] rounded-sm bg-primary/20 border border-border/50" />
              <div className="h-[11px] w-[11px] rounded-sm bg-primary/40 border border-border/50" />
              <div className="h-[11px] w-[11px] rounded-sm bg-primary/60 border border-border/50" />
              <div className="h-[11px] w-[11px] rounded-sm bg-primary border border-border/50" />
            </div>
            <span className="text-[12px]">{t('More')}</span>
          </div>
        </div>
      </div>
    </div>
  )
}
