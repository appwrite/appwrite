import { useMemo, useState } from 'react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { getActiveLaunchEvent } from '@/lib/init/events'
import { EventHero } from './_components/EventHero'
import { EventSchedule } from './_components/EventSchedule'
import { GetInvolvedCards } from './_components/GetInvolvedCards'
import { LiveBannerBar } from './_components/LiveBannerBar'
import { DayDetailCard } from './_components/DayDetailCard'
import { EventSchedulePanel } from './_components/EventSchedulePanel'
import { OnlineUsersNav, hasOnlineUsersNav } from './_components/OnlineUsersNav'

export function View() {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [onlineNavOpen, setOnlineNavOpen] = useState(false)
  const event = useMemo(() => getActiveLaunchEvent(), [])

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true))
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true))

  if (!event) {
    return (
      <>
        <ConsoleLayout
          header={{ onCommandCenterOpen: () => setCommandCenterOpen(true) }}
          showFooter
        >
          <div className="mx-auto flex w-full max-w-7xl flex-col items-center justify-center px-4 py-24 sm:px-6">
            <p className="text-[15px] font-semibold text-foreground">
              No launch events scheduled
            </p>
            <p className="mt-2 text-[13px] text-muted-foreground">
              Check back soon for the next Appwrite product launch week.
            </p>
          </div>
        </ConsoleLayout>

        <CommandCenter
          open={commandCenterOpen}
          onOpenChange={setCommandCenterOpen}
          context="account"
        />
      </>
    )
  }

  const showOnlineNav = hasOnlineUsersNav(event)

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => setCommandCenterOpen(true),
        }}
        leftSidebar={
          showOnlineNav
            ? {
                mobileOpen: onlineNavOpen,
                onMobileClose: () => setOnlineNavOpen(false),
                onMenuClick: () => setOnlineNavOpen(true),
                content: (
                  <OnlineUsersNav
                    event={event}
                    mobileOpen={onlineNavOpen}
                    onMobileClose={() => setOnlineNavOpen(false)}
                  />
                ),
              }
            : undefined
        }
        showFooter
      >
        {event.liveBanner ? <LiveBannerBar liveBanner={event.liveBanner} /> : null}

        <EventHero event={event} liveBanner={event.liveBanner} />

        <div className="mx-auto w-full max-w-7xl space-y-8 px-4 pb-8 pt-8 sm:px-6 sm:pb-10">
          <EventSchedule event={event} fullWidth />

          <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="space-y-6">
              {event.days.map((day) => (
                <DayDetailCard key={day.day} day={day} />
              ))}
            </div>
            <EventSchedulePanel event={event} />
          </div>

          <GetInvolvedCards event={event} />
        </div>
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}
