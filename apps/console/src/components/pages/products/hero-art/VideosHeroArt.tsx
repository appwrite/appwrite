import { Captions, FileVideo } from 'lucide-react'
import { useCallback, useState, type CSSProperties } from 'react'
import {
  ArtChip,
  ArtIconBadge,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { HeadlessVideoPlayer } from '@/components/global/shared/HeadlessVideoPlayer'
import { Badge } from '@/components/ui/badge'
import { VIDEOS_HERO_DEMO } from '@/lib/videos/demo-videos'
import { useT } from '@/lib/i18n/translate'

const LADDER = [
  { name: '1080p', status: 'ready' },
  { name: '720p', status: 'ready' },
  { name: '480p', status: 'encoding' },
] as const

function formatClock(seconds: number) {
  if (!Number.isFinite(seconds) || seconds < 0) return '00:00'
  const total = Math.floor(seconds)
  const minutes = Math.floor(total / 60)
  const remainder = total % 60
  return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
}

export function VideosHeroArt() {
  const t = useT()
  const [playback, setPlayback] = useState({ current: 0, duration: 0 })
  const handleTimeUpdate = useCallback((current: number, duration: number) => {
    setPlayback((prev) =>
      prev.current === current && prev.duration === duration
        ? prev
        : { current, duration },
    )
  }, [])
  const progress =
    playback.duration > 0 ? (playback.current / playback.duration) * 100 : 0

  return (
    <div className="relative isolate px-3 pb-12 pt-12 sm:px-16 sm:pb-8">
      <div className="relative">
        <HeadlessVideoPlayer
          src={VIDEOS_HERO_DEMO.src}
          poster={VIDEOS_HERO_DEMO.poster}
          startAt={VIDEOS_HERO_DEMO.startAt}
          variant="ambient"
          className="-inset-10 overflow-visible rounded-[2rem] blur-xl sm:-inset-16 sm:rounded-[2.5rem] [mask-image:linear-gradient(to_right,transparent,black_32%,black_68%,transparent),linear-gradient(to_bottom,transparent,black_28%,black_72%,transparent)] [mask-composite:intersect] [-webkit-mask-composite:source-in]"
        />
        <ArtWindow
          className="product-hero-rise relative z-[1] rounded-2xl sm:rounded-3xl"
          style={riseStyle(80)}
          title={<span dir="ltr">{VIDEOS_HERO_DEMO.fileName}</span>}
          trailing={
            <span className="flex items-center gap-1.5">
              <ArtLiveDot className="size-1.5" />
              <span className="rounded bg-[rgb(var(--tone-rgb)/0.16)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]">
                {t('Auto')} · 1080p
              </span>
            </span>
          }
          bodyClassName="p-3 sm:p-4"
        >
          <div className="relative">
            <div className="relative aspect-[16/9] overflow-hidden rounded-lg bg-[#121214]">
              <HeadlessVideoPlayer
                src={VIDEOS_HERO_DEMO.src}
                poster={VIDEOS_HERO_DEMO.poster}
                startAt={VIDEOS_HERO_DEMO.startAt}
                fit="contain"
                plain
                maxQualityForPlayer
                onTimeUpdate={handleTimeUpdate}
              />
            </div>
            <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 pb-2.5 pt-8">
              <div className="flex items-center gap-2" dir="ltr">
                <span className="font-mono text-[10px] text-white/80">
                  {formatClock(playback.current)}
                </span>
                <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/25">
                  <div
                    className="h-full rounded-full bg-white"
                    style={{ width: `${progress}%` }}
                  />
                </div>
                <span className="font-mono text-[10px] text-white/80">
                  {formatClock(playback.duration)}
                </span>
                <Captions className="size-3.5 text-white" aria-hidden />
              </div>
            </div>
          </div>
        </ArtWindow>
      <p className="relative z-[1] mt-3 text-center text-[10px] leading-4 text-muted-foreground">
        <a
          href={VIDEOS_HERO_DEMO.sourceHref}
          title={VIDEOS_HERO_DEMO.attribution}
          target="_blank"
          rel="noreferrer"
          className="underline-offset-2 hover:underline"
        >
          {VIDEOS_HERO_DEMO.title}
        </a>
        {' · '}
        {t('Demo video by McPix22')}
        {' · '}
        <a
          href={VIDEOS_HERO_DEMO.licenseHref}
          target="_blank"
          rel="noreferrer"
          className="underline-offset-2 hover:underline"
        >
          {VIDEOS_HERO_DEMO.license}
        </a>
      </p>
      </div>

      <ArtChip className="start-0 top-0 w-[210px]" delayMs={520}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={FileVideo} tone="secondary" />
          <div className="min-w-0">
            <p dir="ltr" className="truncate text-[11px] font-medium text-foreground">
              {VIDEOS_HERO_DEMO.fileName}
            </p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              media · {VIDEOS_HERO_DEMO.sizeLabel}
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[10%] hidden w-[168px] sm:block" delayMs={820} floatDelayMs={700}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Renditions')}
        </p>
        <ul className="mt-1.5 space-y-1.5">
          {LADDER.map((rung) => (
            <li key={rung.name} className="flex items-center justify-between gap-2">
              <span dir="ltr" className="font-mono text-[11px] text-foreground">
                {rung.name}
              </span>
              {rung.status === 'encoding' ? (
                <Badge variant="processing" className="text-[10px]">
                  {t('Encoding')}
                </Badge>
              ) : (
                <Badge variant="success" className="text-[10px]">
                  {t('Ready')}
                </Badge>
              )}
            </li>
          ))}
        </ul>
        <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted">
          <div
            className="product-hero-fill h-full origin-left rounded-full bg-[var(--tone-ink)]"
            style={{ '--fill-delay': '1100ms', '--fill-duration': '2s', width: '64%' } as CSSProperties}
          />
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-[6%]" delayMs={1100} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Captions} />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Subtitles')}</p>
            <p className="text-[10px] text-muted-foreground">
              {t('English')} · {t('Default')}
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-1 end-0 hidden sm:block" delayMs={1300} floatDelayMs={400}>
        <div className="flex items-center gap-2">
          <ArtLiveDot className="size-1.5" />
          <div>
            <p className="text-[11px] font-medium text-foreground">HLS</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              master.m3u8
            </p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
