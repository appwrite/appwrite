import { fetchInitRaffleParticipants } from '@/lib/init/fetch-init-raffle-participants'
import { isInitDailyPrizeRevealed } from '@/lib/init/prize-visibility'
import type { InitDisplayEvent, LaunchEventDailyPrize, LaunchEventGrandPrize } from '@/lib/init/types'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'
import { useCallback, useEffect, useState } from 'react'

const GIVEAWAY_RAFFLE_DAYS = [1, 2, 3, 4] as const
const GRAND_PRIZE_REVEAL_DAY = 5

/** Host shortcut: Ctrl+Shift+1…4 opens daily raffles; Ctrl+Shift+5 opens grand prize reveal. */
function parseGiveawayHostShortcut(event: KeyboardEvent): number | null {
  if (!event.shiftKey || !event.ctrlKey) return null
  if (event.altKey || event.metaKey) return null

  const dayMatch = event.code.match(/^Digit([1-5])$/)
  if (!dayMatch) return null

  return Number(dayMatch[1])
}

export function useInitGiveawayRaffle(
  event: InitDisplayEvent,
  dailyGiveaways: LaunchEventDailyPrize[],
  grandPrize: LaunchEventGrandPrize | undefined,
  onClose?: () => void,
) {
  const [activeDay, setActiveDay] = useState<number | null>(null)
  const [isGrandPrizeRevealOpen, setIsGrandPrizeRevealOpen] = useState(false)
  const [participants, setParticipants] = useState<Awaited<
    ReturnType<typeof fetchInitRaffleParticipants>
  >>([])
  const [loadingParticipants, setLoadingParticipants] = useState(false)

  const enabled = Boolean(
    event.presenceEnabled && !event.isRecapMode && dailyGiveaways.length > 0,
  )

  const close = useCallback(() => {
    setActiveDay(null)
    setIsGrandPrizeRevealOpen(false)
    setParticipants([])
    setLoadingParticipants(false)
    onClose?.()
  }, [onClose])

  const loadParticipants = useCallback(async () => {
    setLoadingParticipants(true)

    try {
      const users = await fetchInitRaffleParticipants(event)
      setParticipants(users)
    } catch {
      setParticipants([])
    } finally {
      setLoadingParticipants(false)
    }
  }, [event])

  const openForDay = useCallback(
    async (day: number) => {
      if (!isInitDailyPrizeRevealed(event.currentDay, day)) return

      const giveaway = dailyGiveaways.find((entry) => entry.day === day)
      if (!giveaway) return

      if (activeDay === day && !isGrandPrizeRevealOpen) {
        close()
        return
      }

      setIsGrandPrizeRevealOpen(false)
      setActiveDay(day)
      await loadParticipants()
    },
    [activeDay, close, dailyGiveaways, event.currentDay, isGrandPrizeRevealOpen, loadParticipants],
  )

  const openGrandPrizeReveal = useCallback(async () => {
    if (!grandPrize) return
    if (!isInitDailyPrizeRevealed(event.currentDay, grandPrize.day)) return

    if (isGrandPrizeRevealOpen) {
      close()
      return
    }

    setActiveDay(null)
    setIsGrandPrizeRevealOpen(true)
    await loadParticipants()
  }, [close, event.currentDay, grandPrize, isGrandPrizeRevealOpen, loadParticipants])

  const activeGiveaway =
    activeDay == null
      ? undefined
      : dailyGiveaways.find((entry) => entry.day === activeDay)

  useEffect(() => {
    if (!enabled) return

    const handleKeyDown = (keyboardEvent: KeyboardEvent) => {
      if (shouldSuppressGlobalShortcuts(document.activeElement)) return

      if (keyboardEvent.key === 'Escape' && (activeDay != null || isGrandPrizeRevealOpen)) {
        keyboardEvent.preventDefault()
        close()
        return
      }

      const day = parseGiveawayHostShortcut(keyboardEvent)
      if (!day) return

      if (day === GRAND_PRIZE_REVEAL_DAY) {
        if (!grandPrize) return
        keyboardEvent.preventDefault()
        keyboardEvent.stopPropagation()
        void openGrandPrizeReveal()
        return
      }

      if (!dailyGiveaways.some((entry) => entry.day === day)) return

      keyboardEvent.preventDefault()
      keyboardEvent.stopPropagation()
      void openForDay(day)
    }

    window.addEventListener('keydown', handleKeyDown, true)
    return () => window.removeEventListener('keydown', handleKeyDown, true)
  }, [
    activeDay,
    close,
    dailyGiveaways,
    enabled,
    grandPrize,
    isGrandPrizeRevealOpen,
    openForDay,
    openGrandPrizeReveal,
  ])

  return {
    enabled,
    activeDay,
    activeGiveaway,
    isGrandPrizeRevealOpen,
    grandPrize,
    participants,
    loadingParticipants,
    openForDay,
    openGrandPrizeReveal,
    close,
    raffleDays: GIVEAWAY_RAFFLE_DAYS,
  }
}
