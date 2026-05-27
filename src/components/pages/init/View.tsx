import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { getActiveLaunchEvent } from '@/lib/init/events'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { isLaunchEventDayLocked } from '@/lib/init/types'
import { InitPresenceProvider, useInitPresence } from '@/lib/init/init-presence-context'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { cn } from '@/lib/utils'
import { EventHero } from './_components/EventHero'
import { EventSchedule } from './_components/EventSchedule'
import { InitTicketSection } from './_components/InitTicketSection'
import { GiveawayPromoCard } from './_components/GiveawayPromoCard'
import { GetInvolvedCards } from './_components/GetInvolvedCards'
import { LiveBannerBar } from './_components/LiveBannerBar'
import { DayDetailCard } from './_components/DayDetailCard'
import { LockedDayDetailCard } from './_components/LockedDayDetailCard'
import { EventSchedulePanel } from './_components/EventSchedulePanel'
import { OnlineUsersNav, hasOnlineUsersNav } from './_components/OnlineUsersNav'
import { InitTicketVideoRecordingProvider } from '@/lib/init/init-ticket-video-recording-context'
import {
  INIT_TICKET_VIDEO_DOT_PATTERN_CLASS,
  INIT_TICKET_VIDEO_SURFACE_CLASS,
} from '@/lib/init/ticket-video-capture'

import type { Models } from '@appwrite.io/console'
import type { InitDisplayEvent } from '@/lib/init/types'

function InitPageContent({
  baseEvent,
  account,
}: {
  baseEvent: InitDisplayEvent
  account?: Models.User | null
}) {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [onlineNavOpen, setOnlineNavOpen] = useState(false)
  const presence = useInitPresence()

  const event = useMemo(() => {
    if (!baseEvent.presenceEnabled || !account) {
      return baseEvent
    }
    return {
      ...baseEvent,
      onlineUsers: presence.onlineUsers,
      recentlyOnlineUsers: presence.recentlyOnlineUsers,
      onlineCount: presence.onlineCount,
      othersOnlineCount: presence.othersOnlineCount,
    }
  }, [account, baseEvent, presence])

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true))
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true))

  const showOnlineNav = hasOnlineUsersNav(event, {
    presenceEnabled: event.presenceEnabled,
    isAuthenticated: Boolean(account),
  })

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
                    showPanel
                    mobileOpen={onlineNavOpen}
                    onMobileClose={() => setOnlineNavOpen(false)}
                  />
                ),
              }
            : undefined
        }
        showFooter
      >
        <InitTicketVideoRecordingProvider>
          {event.liveBanner ? <LiveBannerBar liveBanner={event.liveBanner} /> : null}

          <EventHero event={event} liveBanner={event.liveBanner} />

          <div className="mx-auto w-full max-w-7xl px-4 pb-8 pt-8 sm:px-6">
            <EventSchedule event={event} fullWidth />
          </div>

          <div className="border-t border-border" aria-hidden />

          <div className={cn('relative overflow-hidden', INIT_TICKET_VIDEO_SURFACE_CLASS)}>
            <div
              className={cn(
                'pointer-events-none absolute inset-0',
                INIT_TICKET_VIDEO_DOT_PATTERN_CLASS,
              )}
              aria-hidden
            />
            <div className="relative z-10 mx-auto w-full max-w-7xl px-4 pb-4 pt-2 sm:px-6 sm:pb-6">
              <InitTicketSection event={event} account={account} />
            </div>
          </div>

          <div className="border-t border-border" aria-hidden />

          <div className="mx-auto w-full max-w-7xl space-y-8 px-4 pb-8 pt-8 sm:px-6 sm:pb-10">
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px]">
              <div className="space-y-6">
                {event.days.map((day) =>
                  isLaunchEventDayLocked(day) ? (
                    <LockedDayDetailCard key={day.day} day={day} />
                  ) : (
                    <DayDetailCard key={day.day} day={day} />
                  ),
                )}
              </div>
              <div className="flex flex-col gap-6 lg:sticky lg:top-20 lg:self-start">
                <EventSchedulePanel event={event} embedded />
                {event.giveaway ? (
                  <GiveawayPromoCard giveaway={event.giveaway} />
                ) : null}
              </div>
            </div>

            <GetInvolvedCards event={event} />

            <p className="text-center text-[12px] text-muted-foreground">
              Hero particle animation by{' '}
              <a
                href="https://animejs.com/"
                target="_blank"
                rel="noopener noreferrer"
                className="font-medium text-foreground underline-offset-4 hover:underline"
              >
                Anime.js
              </a>
            </p>
          </div>
        </InitTicketVideoRecordingProvider>
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}

export function View() {
  const { mockInitCurrentDay } = useDebugOverrides()
  const baseEvent = useMemo(() => {
    const active = getActiveLaunchEvent()
    if (!active) return undefined
    return applyInitEventVisibility(active, { mockCurrentDay: mockInitCurrentDay })
  }, [mockInitCurrentDay])

  const { data: account } = useQuery(consoleAccountQueryOptions())

  if (!baseEvent) {
    return <InitEmptyState />
  }

  return (
    <InitPresenceProvider
      eventId={baseEvent.id}
      enabled={Boolean(baseEvent.presenceEnabled && account)}
    >
      <InitPageContent baseEvent={baseEvent} account={account} />
    </InitPresenceProvider>
  )
}

function InitEmptyState() {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

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
