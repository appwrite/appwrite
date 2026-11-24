import { Play } from 'lucide-react'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { CoverCard, CoverLine, InitDayCover } from './InitDayCover'

const RENDITIONS = ['2160p', '1080p', '720p', '480p'] as const

/** Init 5 day 2: one upload, transcoded, streamed and captioned. */
export function VideosDayVisual() {
  const Icon = VIDEOS_PRODUCT_ICON

  return (
    <InitDayCover headline="Video, built in" mockClassName="top-[13cqw]">
      <CoverCard className="overflow-hidden p-[0.8em]">
        <div className="mb-[0.7em] flex items-center gap-[0.6em]">
          <Icon className="size-[1.3em] text-[var(--brand-cta)]" aria-hidden />
          <span className="font-mono text-white">launch-keynote.mp4</span>
        </div>
        <div className="relative flex aspect-video items-center justify-center overflow-hidden rounded-[0.6em] bg-[#121214]">
          <div className="absolute inset-0 bg-[radial-gradient(ellipse_70%_90%_at_70%_70%,rgb(253_54_110/0.45),transparent_70%)]" />
          <div className="absolute inset-0 bg-[radial-gradient(ellipse_60%_70%_at_20%_20%,rgb(124_103_254/0.35),transparent_70%)]" />
          <span className="relative flex size-[3.2em] items-center justify-center rounded-full bg-white text-black">
            <Play
              className="ms-[0.15em] size-[1.3em] fill-current"
              aria-hidden
            />
          </span>
          <span className="absolute inset-x-0 bottom-[1.6em] text-center">
            <span className="rounded-[0.3em] bg-black/70 px-[0.6em] py-[0.2em] text-[0.9em] text-white">
              Five days, five launches.
            </span>
          </span>
          <span className="absolute inset-x-[0.8em] bottom-[0.6em] h-[0.25em] overflow-hidden rounded-full bg-white/20">
            <span className="block h-full w-2/5 rounded-full bg-[var(--brand-cta)]" />
          </span>
        </div>
        <div className="mt-[0.7em] flex items-center gap-[0.4em]">
          {RENDITIONS.map((rendition) => (
            <span
              key={rendition}
              className="rounded-[0.35em] border border-white/12 px-[0.45em] py-[0.15em] font-mono text-[0.85em] text-white/80"
            >
              {rendition}
            </span>
          ))}
          <CoverLine className="ms-[0.4em] w-[4em]" />
        </div>
      </CoverCard>
    </InitDayCover>
  )
}
