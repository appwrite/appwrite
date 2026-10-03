import { Play } from 'lucide-react'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SPRITE_COUNT = 12
const ACTIVE_SPRITE = 5
const SCRUB_PERCENT = ((ACTIVE_SPRITE + 0.5) / SPRITE_COUNT) * 100

function spriteShade(index: number) {
  return 0.25 + ((index * 37) % 50) / 100
}

export function VideosTimelineVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Scrubbing preview')}>
      <div className="space-y-4">
        <div dir="ltr" className="relative">
          <div className="relative aspect-video overflow-hidden rounded-lg border border-border bg-gradient-to-br from-muted to-muted/30">
            <span className="absolute left-1/2 top-1/2 flex size-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background/80">
              <Play className="size-4 text-foreground" aria-hidden />
            </span>
            <div className="absolute inset-x-3 bottom-3">
              <div
                className="absolute bottom-4 w-20 -translate-x-1/2 overflow-hidden rounded-md border border-border bg-background shadow-sm"
                style={{ left: `${SCRUB_PERCENT}%` }}
              >
                <div
                  className="aspect-video bg-foreground"
                  style={{ opacity: spriteShade(ACTIVE_SPRITE) }}
                />
                <p className="py-0.5 text-center font-mono text-[9px] text-muted-foreground">
                  01:02
                </p>
              </div>
              <div className="h-1 overflow-hidden rounded-full bg-background/60">
                <div
                  className="h-full rounded-full bg-foreground/70"
                  style={{ width: `${SCRUB_PERCENT}%` }}
                />
              </div>
            </div>
          </div>
        </div>

        <div>
          <div className="flex items-center justify-between gap-2">
            <p className="text-[12px] font-semibold text-foreground">{t('Sprite sheet')}</p>
            <code className="font-mono text-[10px] text-muted-foreground">timeline.vtt</code>
          </div>
          <div dir="ltr" className="mt-2 grid grid-cols-6 gap-1">
            {Array.from({ length: SPRITE_COUNT }, (_, index) => (
              <div
                key={index}
                className={cn(
                  'aspect-video rounded-sm border',
                  index === ACTIVE_SPRITE ? 'border-foreground/40' : 'border-border',
                )}
              >
                <div
                  className="size-full rounded-[1px] bg-foreground"
                  style={{ opacity: spriteShade(index) * 0.6 }}
                />
              </div>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
