import { CheckCircle2, Captions, Play } from 'lucide-react'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import {
  productBentoContainer,
  productBentoIdle,
} from '@/components/pages/home/product-bento/MockSyntax'
import { cn } from '@/lib/utils'

const RENDITIONS = ['2160p', '1080p', '720p', '480p'] as const

/** Init 5 day 2: one upload, transcoded, streamed and captioned. */
export function VideosDayVisual() {
  const Icon = VIDEOS_PRODUCT_ICON

  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden">
      <div
        className={cn(
          'mx-auto flex h-full min-h-0 w-full max-w-[22rem] flex-col',
          productBentoContainer.shell,
        )}
      >
        <div className={cn(productBentoContainer.header, 'px-3 py-2')}>
          <div className="flex items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
              <Icon
                className={cn('size-3.5', productBentoIdle.brandIcon)}
                aria-hidden
              />
            </span>
            <div className="min-w-0 flex-1">
              <p
                className={cn(
                  'truncate font-mono text-[11px] font-medium sm:text-[12px]',
                  productBentoIdle.text,
                )}
              >
                launch-keynote.mp4
              </p>
              <p className="truncate text-[10px] text-muted-foreground">
                Videos · 4K source
              </p>
            </div>
            <span className="flex items-center gap-1 text-[9px] text-muted-foreground sm:text-[10px]">
              <CheckCircle2
                className={cn('size-3', productBentoIdle.emeraldIcon)}
                aria-hidden
              />
              Ready
            </span>
          </div>
        </div>

        <div className="min-h-0 flex-1 p-2.5">
          <div className="relative flex h-full min-h-[96px] items-center justify-center overflow-hidden rounded-md border border-border bg-[#141416]">
            {/* The frame: a dark stage lit in the hero's tones on hover. */}
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_60%_80%_at_30%_40%,rgb(255_255_255/0.06),transparent_70%)]" />
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_70%_90%_at_70%_60%,color-mix(in_srgb,var(--brand-cta)_28%,transparent),transparent_70%)] opacity-30 transition-opacity duration-300 group-hover:opacity-100" />
            <span className="relative flex size-8 items-center justify-center rounded-full bg-white/90 text-black shadow-sm">
              <Play className="ms-0.5 size-3.5 fill-current" aria-hidden />
            </span>
            <p className="absolute inset-x-0 bottom-5 text-center text-[9px] font-medium text-white sm:text-[10px]">
              <span className="rounded bg-black/60 px-1.5 py-0.5">
                Five days, five launches.
              </span>
            </p>
            <div className="absolute inset-x-2.5 bottom-2 h-0.5 overflow-hidden rounded-full bg-white/20">
              <span className="block h-full w-2/5 rounded-full bg-white/60 transition-colors duration-300 group-hover:bg-[var(--brand-cta)]" />
            </div>
          </div>
        </div>

        <div className="flex items-center gap-1.5 border-t border-border px-2.5 py-2">
          {RENDITIONS.map((rendition, index) => (
            <span
              key={rendition}
              className={cn(
                'rounded border border-border/70 px-1.5 py-0.5 font-mono text-[9px] tabular-nums transition-[border-color,color] duration-300 sm:text-[10px]',
                productBentoIdle.text,
                'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_30%,var(--border))]',
              )}
              style={{ transitionDelay: `${index * 40}ms` }}
            >
              {rendition}
            </span>
          ))}
          <span className="ms-auto flex items-center gap-1 text-[9px] text-muted-foreground sm:text-[10px]">
            <Captions
              className={cn('size-3', productBentoIdle.brandIcon)}
              aria-hidden
            />
            HLS · Captions
          </span>
        </div>
      </div>
    </div>
  )
}
