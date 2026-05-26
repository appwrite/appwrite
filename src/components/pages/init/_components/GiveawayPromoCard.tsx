import type { LaunchEventGiveaway } from '@/lib/init/types'
import { useInitThemeImageSrc, useInitThemeUsesDarkImage } from '@/lib/init/use-init-theme-image'
import { cn } from '@/lib/utils'
import { Gift } from 'lucide-react'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface GiveawayPromoCardProps {
  giveaway: LaunchEventGiveaway
}

export function GiveawayPromoCard({ giveaway }: GiveawayPromoCardProps) {
  const usesDarkImage = useInitThemeUsesDarkImage()
  const imageSrc = useInitThemeImageSrc(
    giveaway.imageSrcLight,
    giveaway.imageSrcDark,
  )

  return (
    <section className={CARD_SHELL} aria-labelledby="init-giveaway-heading">
      <div
        className={cn(
          'relative aspect-[4/3]',
          usesDarkImage ? 'bg-[#0a0a0a]' : 'bg-muted/40',
        )}
      >
        <img
          src={imageSrc}
          alt={giveaway.imageAlt}
          className="h-full w-full object-cover object-center"
          loading="lazy"
          decoding="async"
        />
      </div>

      <div className="flex flex-col gap-3 px-6 py-5">
        <div className="flex items-center gap-2">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_srgb,var(--brand-cta)_12%,transparent)] text-[var(--brand-cta)]">
            <Gift className="size-3.5" aria-hidden />
          </span>
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
            Giveaway
          </p>
        </div>
        <div className="space-y-2">
          <h3
            id="init-giveaway-heading"
            className="text-[17px] font-semibold leading-snug tracking-tight text-foreground"
          >
            {giveaway.title}
          </h3>
          <p className="text-[13px] leading-relaxed text-muted-foreground">
            {giveaway.description}
          </p>
        </div>
      </div>
    </section>
  )
}
