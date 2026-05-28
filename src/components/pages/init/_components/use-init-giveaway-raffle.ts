import { fetchInitRaffleParticipants } from '@/lib/init/fetch-init-raffle-participants'
import type { InitDisplayEvent, LaunchEventDailyPrize } from '@/lib/init/types'
import { shouldSuppressGlobalShortcuts } from '@/lib/global-shortcut-suppress'
import { useCallback, useEffect, useState } from 'react'

const GIVEAWAY_RAFFLE_DAYS = [1, 2, 3, 4] as const

/** Host shortcut: Ctrl+Shift+1…4 (physical number keys). */
function isGiveawayRaffleShortcut(event: KeyboardEvent) {
  if (!event.shiftKey || !event.ctrlKey) return false
  if (event.altKey || event.metaKey) return false

  const dayMatch = event.code.match(/^Digit([1-4])$/)
  if (!dayMatch) return false

  return Number(dayMatch[1])
}

export function useInitGiveawayRaffle(
  event: InitDisplayEvent,
  dailyGiveaways: LaunchEventDailyPrize[],
  onClose?: () => void,
) {
  const [activeDay, setActiveDay] = useState<number | null>(null)
  const [participants, setParticipants] = useState<Awaited<
    ReturnType<typeof fetchInitRaffleParticipants>
  >>([])
  const [loadingParticipants, setLoadingParticipants] = useState(false)

  const enabled = Boolean(
    event.presenceEnabled && !event.isRecapMode && dailyGiveaways.length > 0,
  )

  const close = useCallback(() => {
    setActiveDay(null)
    setParticipants([])
    setLoadingParticipants(false)
    onClose?.()
  }, [onClose])

  const openForDay = useCallback(
    async (day: number) => {
      const giveaway = dailyGiveaways.find((entry) => entry.day === day)
      if (!giveaway) return

      if (activeDay === day) {
        close()
        return
      }

      setActiveDay(day)
      setLoadingParticipants(true)

      try {
        const users = await fetchInitRaffleParticipants(event)
        setParticipants(users)
      } catch {
        setParticipants([])
      } finally {
        setLoadingParticipants(false)
      }
    },
    [activeDay, close, dailyGiveaways, event],
  )

  const activeGiveaway =
    activeDay == null
      ? undefined
      : dailyGiveaways.find((entry) => entry.day === activeDay)

  useEffect(() => {
    if (!enabled) return

    const handleKeyDown = (keyboardEvent: KeyboardEvent) => {
      if (shouldSuppressGlobalShortcuts(document.activeElement)) return

      if (keyboardEvent.key === 'Escape' && activeDay != null) {
        keyboardEvent.preventDefault()
        close()
        return
      }

      const day = isGiveawayRaffleShortcut(keyboardEvent)
      if (!day) return
      if (!dailyGiveaways.some((entry) => entry.day === day)) return

      keyboardEvent.preventDefault()
      keyboardEvent.stopPropagation()
      void openForDay(day)
    }

    window.addEventListener('keydown', handleKeyDown, true)
    return () => window.removeEventListener('keydown', handleKeyDown, true)
  }, [activeDay, close, dailyGiveaways, enabled, openForDay])

  return {
    enabled,
    activeDay,
    activeGiveaway,
    participants,
    loadingParticipants,
    openForDay,
    close,
    raffleDays: GIVEAWAY_RAFFLE_DAYS,
  }
}
