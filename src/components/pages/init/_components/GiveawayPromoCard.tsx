import { INIT_PRIZES_SECTION_ID } from '@/lib/init/init-section-ids'
import type { LaunchEventGiveaway } from '@/lib/init/types'
import { useInitThemeImageSrc } from '@/lib/init/use-init-theme-image'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { ChevronRight, Gift } from 'lucide-react'
import {
  PRIZE_CARD_BG,
  PRIZE_IMAGE_FRAME,
  PRIZE_IMAGE_HOVER_ZOOM_COMPACT,
  PRIZE_IMAGE_INSET,
} from './prize-image-styles'

interface GiveawayPromoCardProps {
  giveaway: LaunchEventGiveaway
}

export function GiveawayPromoCard({ giveaway }: GiveawayPromoCardProps) {
  const imageSrc = useInitThemeImageSrc(
    giveaway.imageSrcLight,
    giveaway.imageSrcDark,
  )
  const ctaLabel = giveaway.ctaLabel ?? 'View all prizes'
  const ctaHref = giveaway.ctaHref ?? `#${INIT_PRIZES_SECTION_ID}`

  return (
    <section
      className={cn(
        'group overflow-hidden rounded-xl border border-border',
        PRIZE_CARD_BG,
      )}
      aria-labelledby="init-giveaway-heading"
    >
      <div className={cn('w-full shrink-0 border-b border-border', PRIZE_IMAGE_INSET)}>
        <div className={cn(PRIZE_IMAGE_FRAME, 'aspect-[3/2]')}>
          <img
            src={imageSrc}
            alt={giveaway.imageAlt}
            className={cn(
              'absolute inset-0 size-full object-cover object-center',
              PRIZE_IMAGE_HOVER_ZOOM_COMPACT,
            )}
            loading="lazy"
            decoding="async"
          />
        </div>
      </div>

      <div className="flex flex-col gap-4 px-6 py-5">
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[color-mix(in_srgb,var(--brand-cta)_12%,transparent)] text-[var(--brand-cta)]">
              <Gift className="size-3.5" aria-hidden />
            </span>
            <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
              Giveaways
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

        <Button variant="outline" size="sm" className="h-9 w-full text-[13px]" asChild>
          <a href={ctaHref}>
            {ctaLabel}
            <ChevronRight className="size-4" aria-hidden />
          </a>
        </Button>
      </div>
    </section>
  )
}
