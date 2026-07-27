import type { LaunchEventDailyPrize, LaunchEventOnlineUser } from '@/lib/init/types'
import { buildInitSpinningGiveawayRaffleActivity } from '@/lib/init/init-presence-activity'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import { useInitThemeImageSrc } from '@/lib/init/use-init-theme-image'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Loader2, Sparkles, Trophy, X } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import {
  computeRaffleWheelRotation,
  INIT_GIVEAWAY_RAFFLE_SPIN_MS,
  INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE,
  InitGiveawayRaffleWheel,
} from './InitGiveawayRaffleWheel'
import { useInitGiveawayRaffleContext } from './init-giveaway-raffle-context'
import { PRIZE_CARD_BG } from './prize-image-styles'

interface InitGiveawayRaffleBackProps {
  giveaway: LaunchEventDailyPrize
  participants: LaunchEventOnlineUser[]
  loadingParticipants: boolean
  onClose: () => void
}

export function InitGiveawayRaffleBack({
  giveaway,
  participants,
  loadingParticipants,
  onClose,
}: InitGiveawayRaffleBackProps) {
  const prizeImageSrc = useInitThemeImageSrc(
    giveaway.visual?.imageSrcLight ?? '',
    giveaway.visual?.imageSrcDark ?? '',
  )
  const hasPrizeImage = Boolean(giveaway.visual?.imageSrcLight && giveaway.visual?.imageSrcDark)

  const [rotation, setRotation] = useState(0)
  const [isSpinning, setIsSpinning] = useState(false)
  const [winner, setWinner] = useState<LaunchEventOnlineUser | null>(null)
  const raffleContext = useInitGiveawayRaffleContext()
  const { setTransientActivity } = useInitPresenceActivity()

  useEffect(() => {
    if (!isSpinning) {
      setTransientActivity(null)
      return
    }

    setTransientActivity(buildInitSpinningGiveawayRaffleActivity())
    return () => setTransientActivity(null)
  }, [isSpinning, setTransientActivity])

  const canRaffle = participants.length > 0 && !isSpinning && !loadingParticipants

  const handleRaffle = useCallback(() => {
    if (!canRaffle) return

    const winnerIndex = Math.floor(Math.random() * participants.length)
    const nextWinner = participants[winnerIndex]
    const nextRotation = computeRaffleWheelRotation(winnerIndex, participants.length, rotation)

    setWinner(null)
    raffleContext?.clearRaffleWinner()
    setIsSpinning(true)
    setRotation(nextRotation)

    window.setTimeout(() => {
      setWinner(nextWinner)
      setIsSpinning(false)
      raffleContext?.celebrateRaffleWinner(nextWinner)
    }, INIT_GIVEAWAY_RAFFLE_SPIN_MS)
  }, [canRaffle, participants, raffleContext, rotation])

  return (
    <div
      className={cn(
        'flex h-full min-h-[min(100%,640px)] flex-col overflow-hidden rounded-xl border border-border',
        PRIZE_CARD_BG,
      )}
    >
      <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4 sm:px-6">
        <div className="min-w-0 space-y-1">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
            Day {giveaway.day} giveaway · {giveaway.dateLabel}
          </p>
          <h4 className="text-[18px] font-semibold tracking-tight text-foreground">
            Live giveaway draw
          </h4>
          <p className="text-[13px] text-muted-foreground">
            One random winner from everyone here live on Init takes home today&apos;s prize.
          </p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-8 shrink-0"
          onClick={onClose}
          aria-label="Close raffle"
        >
          <X className="size-4" />
        </Button>
      </div>

      <div className="grid flex-1 gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_280px] xl:grid-cols-[minmax(0,1fr)_320px] lg:items-center">
        <div className="flex flex-col items-center justify-center gap-5 py-2">
          {loadingParticipants ? (
            <div
              className="flex items-center justify-center rounded-full border border-border bg-muted/20"
              style={{ width: INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE, height: INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE }}
            >
              <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden />
            </div>
          ) : (
            <InitGiveawayRaffleWheel participants={participants} rotation={rotation} />
          )}

          <p className="text-center text-[12px] text-muted-foreground">
            {loadingParticipants
              ? 'Loading online participants…'
              : `${participants.length} participant${participants.length === 1 ? '' : 's'} online`}
          </p>

          <Button
            type="button"
            className="h-10 min-w-[180px] text-[13px]"
            disabled={!canRaffle}
            onClick={handleRaffle}
          >
            {isSpinning ? (
              <>
                <Loader2 className="size-4 animate-spin" aria-hidden />
                Spinning…
              </>
            ) : winner ? (
              'Raffle again'
            ) : (
              'Raffle winner'
            )}
          </Button>
        </div>

        <div className="flex flex-col gap-4">
          <div className="overflow-hidden rounded-xl border border-border bg-card">
            {hasPrizeImage ? (
              <div className="relative aspect-[3/2] overflow-hidden bg-muted/20">
                <img
                  src={prizeImageSrc}
                  alt={giveaway.visual?.imageAlt ?? giveaway.prizeDescription}
                  className="absolute inset-0 size-full object-cover object-center"
                />
              </div>
            ) : null}
            <div className="space-y-2 p-4">
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                Today&apos;s prize
              </p>
              <p className="text-[15px] font-semibold text-foreground">{giveaway.prizeDescription}</p>
              <p className="text-[13px] text-muted-foreground">{giveaway.sessionTitle}</p>
            </div>
          </div>

          <div
            className={cn(
              'rounded-xl border border-border bg-muted/20 p-4 transition-colors',
              winner &&
                'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_8%,transparent)]',
            )}
          >
            {winner ? (
              <div className="flex items-center gap-3">
                <InitialsAvatar name={winner.name} size="md" className="rounded-full" />
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--brand-cta)]">
                    <Trophy className="size-3.5" aria-hidden />
                    Winner
                  </div>
                  <p className="truncate text-[15px] font-semibold text-foreground">{winner.name}</p>
                </div>
              </div>
            ) : (
              <div className="flex items-start gap-3 text-muted-foreground">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background/70">
                  <Sparkles className="size-4" aria-hidden />
                </span>
                <p className="text-[13px] leading-relaxed">
                  {isSpinning
                    ? 'Hold tight - the wheel is spinning.'
                    : 'Press raffle winner when you are ready to draw.'}
                </p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
