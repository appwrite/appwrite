import type { LaunchEventGrandPrize, LaunchEventOnlineUser } from '@/lib/init/types'
import { useInitThemeImageSrc } from '@/lib/init/use-init-theme-image'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Sparkles, Trophy, X } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useInitGiveawayRaffleContext } from './init-giveaway-raffle-context'
import { PRIZE_CARD_BG } from './prize-image-styles'

const PLATFORM_META = {
  youtube: { label: 'YouTube', icon: '/icons/youtube.svg' },
  discord: { label: 'Discord', icon: '/icons/discord-simple.svg' },
  reddit: { label: 'Reddit', icon: '/icons/reddit.svg' },
} as const

interface InitGrandPrizeRevealBackProps {
  grandPrize: LaunchEventGrandPrize
  participants: LaunchEventOnlineUser[]
  loadingParticipants: boolean
  onClose: () => void
}

export function InitGrandPrizeRevealBack({
  grandPrize,
  participants,
  loadingParticipants,
  onClose,
}: InitGrandPrizeRevealBackProps) {
  const prizeImageSrc = useInitThemeImageSrc(
    grandPrize.visual?.imageSrcLight ?? '',
    grandPrize.visual?.imageSrcDark ?? '',
  )
  const hasPrizeImage = Boolean(
    grandPrize.visual?.imageSrcLight && grandPrize.visual?.imageSrcDark,
  )
  const platformMeta = grandPrize.platform ? PLATFORM_META[grandPrize.platform] : null

  const [winner, setWinner] = useState<LaunchEventOnlineUser | null>(null)
  const raffleContext = useInitGiveawayRaffleContext()

  const canReveal = participants.length > 0 && !loadingParticipants

  const handleReveal = useCallback(() => {
    if (!canReveal) return

    const winnerIndex = Math.floor(Math.random() * participants.length)
    const nextWinner = participants[winnerIndex]

    setWinner(nextWinner)
    raffleContext?.celebrateRaffleWinner(nextWinner)
  }, [canReveal, participants, raffleContext])

  const handleRevealAgain = useCallback(() => {
    if (!canReveal) return

    const winnerIndex = Math.floor(Math.random() * participants.length)
    const nextWinner = participants[winnerIndex]

    raffleContext?.clearRaffleWinner()
    setWinner(nextWinner)
    raffleContext?.celebrateRaffleWinner(nextWinner)
  }, [canReveal, participants, raffleContext])

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
            Grand prize · Day {grandPrize.day} · {grandPrize.dateLabel}
          </p>
          <h4 className="text-[18px] font-semibold tracking-tight text-foreground">
            Live winner reveal
          </h4>
          <p className="text-[13px] text-muted-foreground">
            {grandPrize.sessionTitle
              ? `Announced during ${grandPrize.sessionTitle.toLowerCase()}.`
              : 'Announced live during Init day 5.'}
          </p>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-8 shrink-0"
          onClick={onClose}
          aria-label="Close winner reveal"
        >
          <X className="size-4" />
        </Button>
      </div>

      <div className="grid flex-1 gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_280px] xl:grid-cols-[minmax(0,1fr)_320px] lg:items-center">
        <div className="flex flex-col items-center justify-center gap-5 py-2">
          <div
            className={cn(
              'w-full max-w-md overflow-hidden rounded-xl border border-border bg-card',
              winner &&
                'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))]',
            )}
          >
            {hasPrizeImage ? (
              <div className="relative aspect-[4/3] overflow-hidden bg-muted/20">
                <img
                  src={prizeImageSrc}
                  alt={grandPrize.visual?.imageAlt ?? grandPrize.title}
                  className="absolute inset-0 size-full object-cover object-center"
                />
              </div>
            ) : null}
            <div className="space-y-2 p-4">
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                Grand prize
              </p>
              <p className="text-[15px] font-semibold text-foreground">{grandPrize.title}</p>
              <p className="text-[13px] text-muted-foreground">{grandPrize.description}</p>
              {grandPrize.sessionTitle && platformMeta ? (
                <div className="flex min-w-0 items-center gap-1.5 pt-1 text-[11px] text-muted-foreground">
                  <img src={platformMeta.icon} alt="" className="size-3 shrink-0 opacity-70" aria-hidden />
                  <span className="truncate">
                    {platformMeta.label} · {grandPrize.sessionTitle} · {grandPrize.timeLabel}
                  </span>
                </div>
              ) : null}
            </div>
          </div>

          <p className="text-center text-[12px] text-muted-foreground">
            {loadingParticipants
              ? 'Loading online participants…'
              : `${participants.length} participant${participants.length === 1 ? '' : 's'} online`}
          </p>

          <Button
            type="button"
            className="h-10 min-w-[180px] text-[13px]"
            disabled={!canReveal}
            onClick={winner ? handleRevealAgain : handleReveal}
          >
            {winner ? 'Reveal again' : 'Reveal winner'}
          </Button>
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
                  Grand prize winner
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
                Press reveal winner when you are ready to announce the grand prize.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
