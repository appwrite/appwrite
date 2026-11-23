import { Play } from 'lucide-react'
import type { CSSProperties, ReactNode } from 'react'
import {
  ArtChip,
  ArtLiveDot,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Timeline domain in ms. Wider than the budget so what happens after the first frame is visible. */
const DOMAIN_MS = 125
const BUDGET_MS = 100
const FIRST_FRAME_MS = 86
/** Animation replays the session this many times slower than real time. */
const SLOWDOWN = 20
const BASE_DELAY_MS = 500

const STREAM_REQUESTS = [
  { id: 'master', file: 'master.m3u8', hint: 'Master manifest', size: '1.4 KB', start: 0, end: 18 },
  { id: 'playlist', file: '360p/index.m3u8', hint: 'Media playlist', size: '0.6 KB', start: 20, end: 32 },
  { id: 'init', file: '360p/init.mp4', hint: 'Init segment', size: '0.9 KB', start: 34, end: 44 },
  { id: 'segment', file: '360p/seg_0.m4s', hint: 'First segment', size: '180 KB', start: 46, end: 80 },
] as const

/** After the first frame, the player climbs the ladder while the picture keeps playing. */
const PLAYBACK_RUNGS = [
  { id: '360p', start: FIRST_FRAME_MS, end: 100, opacity: 0.45 },
  { id: '720p', start: 100, end: 112, opacity: 0.7 },
  { id: '1080p', start: 112, end: DOMAIN_MS, opacity: 1 },
] as const

const FACTS = [
  { id: 'manifest', value: '1.4 KB', label: 'Manifest to map the stream' },
  { id: 'rung', value: '360p', label: 'Starting rung, 1080p next' },
  { id: 'segment', value: '1', label: 'Segment to paint a frame' },
] as const

const AXIS_TICKS = [0, 25, 50, 75, 100] as const

const ROW_GRID = 'grid grid-cols-[104px_minmax(0,1fr)] gap-x-4 sm:grid-cols-[156px_minmax(0,1fr)_52px]'

function pct(ms: number) {
  return `${(ms / DOMAIN_MS) * 100}%`
}

function at(ms: number) {
  return BASE_DELAY_MS + ms * SLOWDOWN
}

function fillStyle(start: number, end: number): CSSProperties {
  return {
    '--fill-delay': `${at(start)}ms`,
    '--fill-duration': `${(end - start) * SLOWDOWN}ms`,
    animationTimingFunction: 'linear',
  } as CSSProperties
}

function TimelineBar({ start, end, className }: { start: number; end: number; className?: string }) {
  return (
    <div className="absolute inset-y-0" style={{ insetInlineStart: pct(start), width: pct(end - start) }}>
      <div className={cn('product-hero-fill h-full rounded-full', className)} style={fillStyle(start, end)} />
    </div>
  )
}

function GroupLabel({ children }: { children: ReactNode }) {
  return (
    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{children}</p>
  )
}

function RowLabel({ file, hint, muted }: { file: string; hint: string; muted?: boolean }) {
  return (
    <div className="min-w-0">
      <p
        dir="ltr"
        className={cn('truncate text-start font-mono text-[11px]', muted ? 'text-muted-foreground' : 'text-foreground')}
      >
        {file}
      </p>
      <p className="truncate text-[10px] text-muted-foreground">{hint}</p>
    </div>
  )
}

function FirstFrameStat() {
  const t = useT()

  return (
    <div className="flex flex-col">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <GroupLabel>{t('Time to first frame')}</GroupLabel>
        <p dir="ltr" className="mt-3 flex items-baseline gap-2 font-aeonik-pro leading-none tracking-tight">
          <span className="bg-[linear-gradient(180deg,var(--foreground)_30%,rgb(var(--tone-rgb)))] bg-clip-text text-[112px] tabular-nums text-transparent sm:text-[136px]">
            {FIRST_FRAME_MS}
          </span>
          <span className="text-[28px] text-muted-foreground sm:text-[34px]">ms</span>
        </p>
      </div>

      <div className="product-hero-rise mt-6" style={riseStyle(160)}>
        <div className="relative h-1.5 overflow-hidden rounded-full bg-muted">
          <div
            className="product-hero-fill h-full rounded-full bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb))]"
            style={
              {
                width: `${FIRST_FRAME_MS}%`,
                '--fill-delay': `${BASE_DELAY_MS}ms`,
                '--fill-duration': `${FIRST_FRAME_MS * SLOWDOWN}ms`,
                animationTimingFunction: 'linear',
              } as CSSProperties
            }
          />
        </div>
        <div className="mt-2 flex items-center justify-between text-[11px]">
          <span className="text-muted-foreground">
            <span dir="ltr">100 ms</span> {t('budget')}
          </span>
          <span className="font-medium text-[var(--tone-ink)]">
            <span dir="ltr">{BUDGET_MS - FIRST_FRAME_MS} ms</span> {t('to spare')}
          </span>
        </div>
      </div>

      <dl className="mt-8 divide-y divide-border border-y border-border">
        {FACTS.map((fact, index) => (
          <div
            key={fact.id}
            className="product-hero-rise flex items-baseline gap-4 py-3"
            style={riseStyle(260 + index * 90)}
          >
            <dt dir="ltr" className="w-[72px] shrink-0 font-aeonik-pro text-[22px] leading-none tracking-tight text-foreground">
              {fact.value}
            </dt>
            <dd className="text-[12.5px] text-muted-foreground">{t(fact.label)}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

function FramePreview() {
  const t = useT()

  return (
    <div className="w-[136px]">
      <div className="relative aspect-video overflow-hidden rounded-md bg-[linear-gradient(165deg,rgb(var(--tone2-rgb)/0.75),rgb(var(--tone-rgb)/0.5)_55%,#17171b)]">
        <span className="absolute left-1/2 top-1/2 flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-background/80">
          <Play className="size-3 text-foreground" aria-hidden />
        </span>
        <div className="absolute inset-x-2 bottom-2 h-0.5 overflow-hidden rounded-full bg-white/25" dir="ltr">
          <div
            className="product-hero-fill h-full rounded-full bg-white"
            style={
              {
                '--fill-delay': `${at(FIRST_FRAME_MS)}ms`,
                '--fill-duration': '6s',
                animationTimingFunction: 'linear',
              } as CSSProperties
            }
          />
        </div>
      </div>
      <div className="mt-2 flex items-center gap-1.5">
        <ArtLiveDot className="size-1.5" />
        <p className="text-[11px] font-medium text-foreground">{t('Picture on screen')}</p>
      </div>
    </div>
  )
}

function StartupWaterfall() {
  const t = useT()

  return (
    <div className="relative md:pt-28">
      <ArtChip className="end-0 top-0 hidden md:block" delayMs={at(FIRST_FRAME_MS)} floatDelayMs={400}>
        <FramePreview />
      </ArtChip>

      <div className="relative">
        {/* Budget line, first-frame marker and the post-frame zone span every row of the track column. */}
        <div className={cn(ROW_GRID, 'pointer-events-none absolute inset-0')} aria-hidden>
          <div />
          <div className="relative">
            <div
              className="absolute inset-y-0 end-0 bg-[linear-gradient(90deg,rgb(var(--tone-rgb)/0.10),transparent)] rtl:bg-[linear-gradient(270deg,rgb(var(--tone-rgb)/0.10),transparent)]"
              style={{ insetInlineStart: pct(FIRST_FRAME_MS) }}
            />
            <div
              className="absolute bottom-6 top-0 border-s border-dashed border-foreground/25"
              style={{ insetInlineStart: pct(BUDGET_MS) }}
            />
            <div
              className="product-hero-rise absolute bottom-6 top-0 w-px bg-[var(--tone-ink)] shadow-[0_0_14px_rgb(var(--tone-rgb))]"
              style={riseStyle(at(FIRST_FRAME_MS), { insetInlineStart: pct(FIRST_FRAME_MS) })}
            />
          </div>
          <div className="hidden sm:block" />
        </div>

        <div className={cn(ROW_GRID, 'relative h-7')}>
          <div className="self-end whitespace-nowrap">
            <GroupLabel>{t('Appwrite stream')}</GroupLabel>
          </div>
          <div className="relative">
            <span
              className="product-hero-rise absolute top-0 -translate-x-1/2 whitespace-nowrap rtl:translate-x-1/2"
              style={riseStyle(at(FIRST_FRAME_MS), { insetInlineStart: pct(FIRST_FRAME_MS) })}
            >
              <span className="inline-flex items-center gap-1 rounded-full bg-[var(--tone-ink)] px-2 py-0.5 text-[10px] font-semibold text-white shadow-[0_6px_18px_-6px_rgb(var(--tone-rgb))]">
                {t('First frame')}
                <span dir="ltr" className="font-mono font-medium opacity-80">{FIRST_FRAME_MS} ms</span>
              </span>
            </span>
          </div>
        </div>

        <div className="relative mt-2 space-y-1">
          {STREAM_REQUESTS.map((request, index) => (
            <div
              key={request.id}
              className={cn(ROW_GRID, 'product-hero-rise items-center py-1.5')}
              style={riseStyle(120 + index * 70)}
            >
              <RowLabel file={request.file} hint={t(request.hint)} />
              <div className="relative h-2.5 rounded-full bg-muted/50">
                <TimelineBar
                  start={request.start}
                  end={request.end}
                  className="bg-[var(--tone-ink)] shadow-[0_0_12px_rgb(var(--tone-rgb)/0.45)]"
                />
              </div>
              <p dir="ltr" className="hidden text-end font-mono text-[10px] tabular-nums text-muted-foreground sm:block">
                {request.size}
              </p>
            </div>
          ))}

          <div className={cn(ROW_GRID, 'product-hero-rise items-center py-1.5')} style={riseStyle(400)}>
            <RowLabel file={t('Playback')} hint={t('Climbs the ladder')} />
            <div className="relative h-5">
              {PLAYBACK_RUNGS.map((rung) => (
                <div
                  key={rung.id}
                  className="absolute inset-y-0 px-px"
                  style={{ insetInlineStart: pct(rung.start), width: pct(rung.end - rung.start) }}
                >
                  <div
                    className="product-hero-fill flex h-full items-center justify-center overflow-hidden rounded-[4px] bg-[var(--tone-ink)]"
                    style={{ ...fillStyle(rung.start, rung.end), opacity: rung.opacity }}
                  >
                    <span dir="ltr" className="font-mono text-[9px] font-semibold text-white">
                      {rung.id}
                    </span>
                  </div>
                </div>
              ))}
            </div>
            <span className="hidden sm:block" />
          </div>
        </div>

        <div className={cn(ROW_GRID, 'relative mt-4 border-t border-border pt-4')}>
          <div className="col-span-full">
            <GroupLabel>{t('Progressive download')}</GroupLabel>
          </div>
        </div>

        <div className={cn(ROW_GRID, 'product-hero-rise relative items-center py-1.5')} style={riseStyle(460)}>
          <RowLabel file="source.mp4" hint={t('Whole file first')} muted />
          <div className="relative h-2.5 rounded-full bg-muted/50">
            <TimelineBar start={0} end={DOMAIN_MS} className="bg-muted-foreground/35" />
            <span
              className="product-hero-rise absolute -top-5 end-0 whitespace-nowrap text-[10px] font-medium text-muted-foreground"
              style={riseStyle(at(DOMAIN_MS))}
            >
              {t('Still downloading, no picture yet')}
            </span>
          </div>
          <p dir="ltr" className="hidden text-end font-mono text-[10px] tabular-nums text-muted-foreground sm:block">
            48 MB
          </p>
        </div>

        <div className={cn(ROW_GRID, 'relative mt-2 h-5')} aria-hidden>
          <div />
          <div className="relative">
            {AXIS_TICKS.map((tick) => (
              <span
                key={tick}
                dir="ltr"
                className={cn(
                  'absolute top-1 whitespace-nowrap font-mono text-[10px] tabular-nums',
                  tick === 0 ? 'translate-x-0' : '-translate-x-1/2 rtl:translate-x-1/2',
                  tick === BUDGET_MS ? 'font-semibold text-foreground' : 'text-muted-foreground',
                )}
                style={{ insetInlineStart: pct(tick) }}
              >
                {tick === BUDGET_MS ? `${tick} ms` : tick}
              </span>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

export function VideosPerformanceCatalog() {
  return (
    <div className="grid items-center gap-12 lg:grid-cols-[minmax(0,300px)_minmax(0,1fr)] lg:gap-16">
      <FirstFrameStat />
      <StartupWaterfall />
    </div>
  )
}
