import { Play } from 'lucide-react'
import type { CSSProperties } from 'react'
import { ArtChip, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SPRITE_COUNT = 12
const ACTIVE_SPRITE = 5
const SCRUB_PERCENT = ((ACTIVE_SPRITE + 0.5) / SPRITE_COUNT) * 100

function spriteShade(index: number) {
  return 0.22 + ((index * 37) % 50) / 100
}

export function VideosTimelineVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[500px] pb-14">
      <ArtPanel className="relative z-[1]" innerClassName="product-tone-shadow p-3" delayMs={60}>
        <div dir="ltr" className="relative aspect-video overflow-hidden rounded-lg bg-[linear-gradient(165deg,rgb(var(--tone2-rgb)/0.7),rgb(var(--tone-rgb)/0.45)_55%,#17171b)]">
          <span className="absolute left-1/2 top-1/2 flex size-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-background/80">
            <Play className="size-4 text-foreground" aria-hidden />
          </span>
          <div className="absolute inset-x-3 bottom-3">
            <div
              className="absolute bottom-4 w-[76px] -translate-x-1/2 overflow-hidden rounded-md border border-white/20 bg-background shadow-sm"
              style={{ left: `${SCRUB_PERCENT}%` }}
            >
              <div
                className="aspect-video bg-[var(--tone-ink)]"
                style={{ opacity: spriteShade(ACTIVE_SPRITE) }}
              />
              <p className="py-0.5 text-center font-mono text-[9px] text-muted-foreground">01:02</p>
            </div>
            <div className="h-1 overflow-hidden rounded-full bg-white/25">
              <div
                className="product-hero-fill h-full origin-left rounded-full bg-white"
                style={
                  {
                    '--fill-delay': '500ms',
                    '--fill-duration': '1.4s',
                    width: `${SCRUB_PERCENT}%`,
                  } as CSSProperties
                }
              />
            </div>
          </div>
        </div>
      </ArtPanel>

      <ArtPanel className="mt-3" innerClassName="p-3.5" delayMs={320}>
        <div className="flex items-center justify-between gap-2">
          <p className="text-[12px] font-semibold text-foreground">{t('Sprite sheet')}</p>
          <code dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            timeline.vtt
          </code>
        </div>
        <div dir="ltr" className="mt-2.5 grid grid-cols-6 gap-1">
          {Array.from({ length: SPRITE_COUNT }, (_, index) => (
            <div
              key={index}
              className={cn(
                'product-hero-rise aspect-video overflow-hidden rounded-sm border',
                index === ACTIVE_SPRITE
                  ? 'border-[var(--tone-ink)] ring-2 ring-[rgb(var(--tone-rgb)/0.25)]'
                  : 'border-border',
              )}
              style={riseStyle(420 + index * 50)}
            >
              <div
                className="size-full bg-foreground"
                style={{ opacity: spriteShade(index) * 0.55 }}
              />
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="bottom-0 start-[8%]" delayMs={1100} floatDelayMs={900}>
        <p className="text-[11px] font-medium text-foreground">{t('Poster frame included')}</p>
        <p className="text-[10px] text-muted-foreground">{t('Same sprites, same index')}</p>
      </ArtChip>
    </div>
  )
}
