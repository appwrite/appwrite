import type { LaunchEvent, LaunchEventScheduleItem } from '@/lib/init/types'
import {
  buildGoogleCalendarScheduleItemEventUrl,
  downloadInitScheduleItemCalendar,
  openGoogleCalendarEventUrl,
} from '@/lib/init/init-calendar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { CalendarPlus, Download } from 'lucide-react'

export function InitScheduleCalendarButton({
  event,
  item,
}: {
  event: LaunchEvent
  item: LaunchEventScheduleItem
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className="flex shrink-0 cursor-pointer items-center text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
          aria-label={`Add ${item.title} to calendar`}
        >
          <CalendarPlus className="size-3.5" aria-hidden />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[220px]">
        <DropdownMenuItem
          onClick={() =>
            openGoogleCalendarEventUrl(
              buildGoogleCalendarScheduleItemEventUrl(event, item),
            )
          }
        >
          Google Calendar
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => downloadInitScheduleItemCalendar(event, item)}
        >
          Apple & Outlook (.ics)
          <Download className="ms-auto size-4" />
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
