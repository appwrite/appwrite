import type { InitDisplayEvent, LaunchEventOnlineUser } from '@/lib/init/types'
import { buildInitRunningGiveawayRaffleActivity, buildInitRunningGrandPrizeRevealActivity } from '@/lib/init/init-presence-activity'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useInitGiveawayRaffle } from './use-init-giveaway-raffle'

type InitGiveawayRaffleContextValue = ReturnType<typeof useInitGiveawayRaffle> & {
  raffleWinnerId: string | null
  raffleWinnerPulse: number
  celebrateRaffleWinner: (user: LaunchEventOnlineUser) => void
  clearRaffleWinner: () => void
}

const InitGiveawayRaffleContext = createContext<InitGiveawayRaffleContextValue | null>(
  null,
)

export function InitGiveawayRaffleProvider({
  event,
  children,
}: {
  event: InitDisplayEvent
  children: ReactNode
}) {
  const [raffleWinnerId, setRaffleWinnerId] = useState<string | null>(null)
  const [raffleWinnerPulse, setRaffleWinnerPulse] = useState(0)

  const clearRaffleWinner = useCallback(() => {
    setRaffleWinnerId(null)
  }, [])

  const raffle = useInitGiveawayRaffle(
    event,
    event.prizes?.dailyGiveaways ?? [],
    event.prizes?.grandPrize,
    clearRaffleWinner,
  )
  const { setPriorityActivity } = useInitPresenceActivity()

  useEffect(() => {
    if (raffle.isGrandPrizeRevealOpen) {
      setPriorityActivity(buildInitRunningGrandPrizeRevealActivity())
      return () => setPriorityActivity(null)
    }

    if (raffle.activeDay == null) {
      setPriorityActivity(null)
      return
    }

    setPriorityActivity(buildInitRunningGiveawayRaffleActivity(raffle.activeDay))
    return () => setPriorityActivity(null)
  }, [raffle.activeDay, raffle.isGrandPrizeRevealOpen, setPriorityActivity])

  const celebrateRaffleWinner = useCallback((user: LaunchEventOnlineUser) => {
    setRaffleWinnerId(user.id)
    setRaffleWinnerPulse((pulse) => pulse + 1)
  }, [])

  const value = useMemo(
    (): InitGiveawayRaffleContextValue => ({
      ...raffle,
      raffleWinnerId,
      raffleWinnerPulse,
      celebrateRaffleWinner,
      clearRaffleWinner,
    }),
    [celebrateRaffleWinner, clearRaffleWinner, raffle, raffleWinnerId, raffleWinnerPulse],
  )

  return (
    <InitGiveawayRaffleContext.Provider value={value}>
      {children}
    </InitGiveawayRaffleContext.Provider>
  )
}

export function useInitGiveawayRaffleContext(): InitGiveawayRaffleContextValue | null {
  return useContext(InitGiveawayRaffleContext)
}
