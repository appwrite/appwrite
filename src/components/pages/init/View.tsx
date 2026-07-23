import { useMemo, useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { getActiveLaunchEvent } from '@/lib/init/events'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { isLaunchEventDayLocked } from '@/lib/init/types'
import { InitPresenceProvider, useInitPresence } from '@/lib/init/init-presence-context'
import { scrollToInitDayFromHash } from '@/lib/init/scroll-to-day-card'
import { hasLikelyConsoleSession } from '@/lib/console-account-get'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { cn } from '@/lib/utils'
import { EventHero } from './_components/EventHero'
import { EventSchedule } from './_components/EventSchedule'
import { InitTicketSection } from './_components/InitTicketSection'
import { GiveawayPromoCard } from './_components/GiveawayPromoCard'
import { GetInvolvedCards } from './_components/GetInvolvedCards'
import { InitPrizesSection } from './_components/InitPrizesSection'
import { InitPageCredits } from './_components/InitPageCredits'
import { InitGlobalCommunitySection } from './_components/InitGlobalCommunitySection'
import { InitPresenceBaselineSync } from './_components/InitPresenceBaselineSync'
import { useInitCommunityGlobeData } from '@/lib/init/use-init-community-globe-data'
import { InitGiveawayRaffleConfetti } from './_components/InitGiveawayRaffleConfetti'
import { InitReactionConfetti } from './_components/InitReactionConfetti'
import {
  InitGiveawayRaffleProvider,
  useInitGiveawayRaffleContext,
} from './_components/init-giveaway-raffle-context'
import { LiveBannerBar } from './_components/LiveBannerBar'
import { InitRecapBanner } from './_components/InitRecapBanner'
import { InitRecapIntro } from './_components/InitRecapIntro'
import { DayDetailCard } from './_components/DayDetailCard'
import { LockedDayDetailCard } from './_components/LockedDayDetailCard'
import { InitReleaseAvailabilityStage } from './_components/InitReleaseAvailabilityStage'
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
  const raffle = useInitGiveawayRaffleContext()
  const communityGlobe = useInitCommunityGlobeData(baseEvent, {
    enabled: Boolean(baseEvent.presenceEnabled && !baseEvent.isRecapMode),
  })

  const event = useMemo(() => {
    if (!baseEvent.presenceEnabled) {
      return baseEvent
    }
    return {
      ...baseEvent,
      onlineUsers: presence.onlineUsers,
      recentlyOnlineUsers: presence.recentlyOnlineUsers,
      onlineCount: presence.onlineCount,
      othersOnlineCount: presence.othersOnlineCount,
    }
  }, [baseEvent, presence])

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true), OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS)
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true), OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS)

  useEffect(() => {
    const frame = requestAnimationFrame(() => scrollToInitDayFromHash())
    return () => cancelAnimationFrame(frame)
  }, [event.days.length])

  const showOnlineNav =
    !event.isRecapMode &&
    hasOnlineUsersNav(event, {
      presenceEnabled: baseEvent.presenceEnabled,
    })

  return (
    <>
      {baseEvent.presenceEnabled && !baseEvent.isRecapMode && account ? (
        <InitPresenceBaselineSync event={baseEvent} />
      ) : null}
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
          {event.isRecapMode ? (
            <InitRecapBanner event={event} />
          ) : event.liveBanner ? (
            <LiveBannerBar liveBanner={event.liveBanner} />
          ) : null}

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
              <InitTicketSection
                event={event}
                account={account}
              />
            </div>
          </div>

          <div className="border-t border-border" aria-hidden />

          <div className="mx-auto w-full max-w-7xl space-y-8 px-4 pb-8 pt-8 sm:px-6 sm:pb-10">
            <InitRecapIntro event={event} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px]">
              <div className="space-y-6">
                {event.days.map((day) =>
                  isLaunchEventDayLocked(day) ? (
                    <LockedDayDetailCard
                      key={day.day}
                      day={day}
                      eventStartDate={event.startDate}
                      currentDay={event.currentDay}
                    />
                  ) : (
                    <DayDetailCard
                      key={day.day}
                      event={event}
                      day={day}
                      scheduleItems={event.schedule.filter(
                        (item) => item.day === day.day,
                      )}
                      currentDay={event.currentDay}
                      isRecapMode={event.isRecapMode}
                    />
                  ),
                )}
              </div>
              <div className="flex flex-col gap-6 lg:sticky lg:top-20 lg:self-start">
                <EventSchedulePanel event={event} embedded />
                {event.giveaway && !event.isRecapMode ? (
                  <GiveawayPromoCard giveaway={event.giveaway} />
                ) : null}
              </div>
            </div>
          </div>

          <InitReleaseAvailabilityStage event={event} />

          <InitPrizesSection event={event} />

          {event.presenceEnabled && !event.isRecapMode ? (
            <InitGlobalCommunitySection
              countries={communityGlobe.countries}
              developerCount={communityGlobe.developerCount}
              isLive={communityGlobe.isLive}
              isAuthenticated={Boolean(account)}
            />
          ) : null}

          <div className="mx-auto w-full max-w-7xl space-y-8 px-4 pb-8 pt-8 sm:px-6 sm:pb-10">
            <GetInvolvedCards event={event} account={account} />

            <InitPageCredits
              showPresenceCredit={Boolean(event.presenceEnabled && account)}
              showGlobeCredit={Boolean(event.presenceEnabled && !event.isRecapMode)}
            />
          </div>
        </InitTicketVideoRecordingProvider>
      </ConsoleLayout>

      {event.presenceEnabled && account && !event.isRecapMode ? (
        <>
          <InitReactionConfetti onlineUsers={event.onlineUsers} />
          {raffle ? (
            <InitGiveawayRaffleConfetti pulse={raffle.raffleWinnerPulse} />
          ) : null}
        </>
      ) : null}

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

  const {
    data: account,
    isSuccess: isAccountSuccess,
    isError: isAccountError,
  } = useQuery(consoleAccountQueryOptions())
  // No session: treat as ready immediately so guest ticket / presence UI are not
  // blocked on an account query that can remain pending after cache purge.
  const isAccountReady =
    isAccountSuccess || isAccountError || !hasLikelyConsoleSession()

  if (!baseEvent) {
    return <InitEmptyState />
  }

  return (
    <InitPresenceProvider
      event={baseEvent}
      enabled={Boolean(
        baseEvent.presenceEnabled && !baseEvent.isRecapMode && isAccountReady,
      )}
    >
      <InitGiveawayRaffleProvider event={baseEvent}>
        <InitPageContent
          baseEvent={baseEvent}
          account={account}
        />
      </InitGiveawayRaffleProvider>
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
