import { useMemo } from 'react'
import { getActiveLaunchEvent } from '@/lib/init/events'
import {
  buildGoogleCalendarDayEventUrl,
  buildGoogleCalendarWeekEventUrl,
  downloadInitEventCalendar,
  getInitCalendarDayMenuLabel,
  openGoogleCalendarEventUrl,
  type InitCalendarVisibilityOptions,
} from '@/lib/init/init-calendar'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { CalendarPlus, ChevronDown, Download } from 'lucide-react'

export function InitAddToCalendarButton() {
  const { mockInitCurrentDay } = useDebugOverrides()
  const event = getActiveLaunchEvent()
  const calendarOptions = useMemo<InitCalendarVisibilityOptions>(
    () => ({ mockCurrentDay: mockInitCurrentDay }),
    [mockInitCurrentDay],
  )
  const sortedDays = useMemo(
    () => [...(event?.days ?? [])].sort((a, b) => a.day - b.day),
    [event?.days],
  )

  if (!event || sortedDays.length === 0) return null

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="lg"
          className="h-10 text-[14px]"
        >
          <CalendarPlus className="size-4" />
          Add to calendar
          <ChevronDown className="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="center" className="min-w-[240px]">
        <DropdownMenuSub>
          <DropdownMenuSubTrigger>Google Calendar</DropdownMenuSubTrigger>
          <DropdownMenuSubContent className="min-w-[280px]">
            <DropdownMenuItem
              onClick={() =>
                openGoogleCalendarEventUrl(
                  buildGoogleCalendarWeekEventUrl(event, calendarOptions),
                )
              }
            >
              Full week ({event.dateRangeLabel})
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            {sortedDays.map((day) => (
              <DropdownMenuItem
                key={day.day}
                onClick={() =>
                  openGoogleCalendarEventUrl(
                    buildGoogleCalendarDayEventUrl(
                      event,
                      day.day,
                      calendarOptions,
                    ),
                  )
                }
              >
                {getInitCalendarDayMenuLabel(event, day.day, calendarOptions)}
              </DropdownMenuItem>
            ))}
          </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuItem
          onClick={() => downloadInitEventCalendar(event, calendarOptions)}
        >
          Apple & Outlook (.ics)
          <Download className="ml-auto size-4" />
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
