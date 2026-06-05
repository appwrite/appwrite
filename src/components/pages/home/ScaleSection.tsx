import { useEffect, useRef, useState } from 'react'
import { useReducedMotion } from 'motion/react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Card } from '@/components/ui/card'
import { cn } from '@/lib/utils'

const SCALE_QUOTE = {
  text: 'The switch to using Appwrite brought infinite value that I\u2019m still discovering today.',
  emphasis: 'infinite value that I\u2019m still discovering today.',
  name: 'Ryan O\u2019Connor',
  title: 'Founder',
  company: 'K-Collect',
  avatar: '/images/testimonials/ryan-oconner-testimonial.avif',
} as const

function ScaleQuoteBesideTitle() {
  const emphasisStart = SCALE_QUOTE.text.indexOf(SCALE_QUOTE.emphasis)
  const quoteLead =
    emphasisStart > 0 ? SCALE_QUOTE.text.slice(0, emphasisStart) : ''
  const quoteEmphasis =
    emphasisStart >= 0 ? SCALE_QUOTE.text.slice(emphasisStart) : SCALE_QUOTE.text

  return (
    <figure className="flex h-full gap-3 sm:gap-4 lg:max-w-md lg:justify-self-end xl:max-w-lg">
      <span
        className="font-aeonik-pro shrink-0 self-start text-[3.5rem] leading-none text-muted-foreground/30 sm:text-[4rem] lg:text-[4.25rem]"
        aria-hidden
      >
        &ldquo;
      </span>
      <div className="flex min-h-full min-w-0 flex-1 flex-col justify-between gap-6 lg:gap-8">
        <blockquote className="text-sm leading-relaxed text-muted-foreground sm:text-[15px] sm:leading-7 lg:pt-1">
          {quoteLead}
          <span className="font-medium text-foreground">{quoteEmphasis}</span>
        </blockquote>
        <figcaption className="flex items-center gap-2.5 lg:pb-0.5">
          <Avatar className="size-8">
            <AvatarImage src={SCALE_QUOTE.avatar} alt="" />
            <AvatarFallback className="text-xs">RO</AvatarFallback>
          </Avatar>
          <p className="text-sm leading-snug">
            <span className="font-medium text-foreground">{SCALE_QUOTE.name}</span>
            <span className="text-muted-foreground">
              {' '}
              · {SCALE_QUOTE.title}, {SCALE_QUOTE.company}
            </span>
          </p>
        </figcaption>
      </div>
    </figure>
  )
}

const SCALE_STATS = [
  { value: 9, suffix: '+', label: 'Network edges' },
  { value: 24, suffix: 'K+', label: 'Discord members' },
  { value: 56, suffix: 'K+', label: 'GitHub stars' },
  { value: 300, suffix: '+', label: 'PoP locations' },
  { value: 300, suffix: 'K+', label: 'Cloud projects' },
  { value: 500, suffix: 'K+', label: 'Developers' },
  { value: 20, suffix: 'B+', label: 'DB operations / month' },
] as const

const SCALE_STAT_COUNT = SCALE_STATS.length

const STAT_STAGGER_MS = 450
const COUNT_DURATION_MS = 1200

function easeOutCubic(t: number) {
  return 1 - (1 - t) ** 3
}

function useStaggeredCountUp(
  target: number,
  started: boolean,
  delayMs: number,
  prefersReducedMotion: boolean,
) {
  const [display, setDisplay] = useState(prefersReducedMotion ? target : 0)

  useEffect(() => {
    if (prefersReducedMotion) {
      setDisplay(target)
      return
    }

    if (!started) {
      setDisplay(0)
      return
    }

    let frame = 0
    let startTime = 0
    const delayTimeout = window.setTimeout(() => {
      const tick = (timestamp: number) => {
        if (!startTime) startTime = timestamp
        const elapsed = timestamp - startTime
        const progress = Math.min(1, elapsed / COUNT_DURATION_MS)
        setDisplay(Math.round(target * easeOutCubic(progress)))
        if (progress < 1) {
          frame = requestAnimationFrame(tick)
        }
      }
      frame = requestAnimationFrame(tick)
    }, delayMs)

    return () => {
      window.clearTimeout(delayTimeout)
      cancelAnimationFrame(frame)
    }
  }, [target, started, delayMs, prefersReducedMotion])

  return display
}

function ScaleStatValue({
  value,
  suffix,
  label,
  started,
  index,
  prefersReducedMotion,
  size = 'default',
}: {
  value: number
  suffix: string
  label: string
  started: boolean
  index: number
  prefersReducedMotion: boolean
  size?: 'default' | 'large'
}) {
  const displayValue = useStaggeredCountUp(
    value,
    started,
    (index * STAT_STAGGER_MS) / SCALE_STATS.length,
    prefersReducedMotion,
  )

  return (
    <div className="min-w-0 space-y-1.5">
      <p
        className={cn(
          'font-semibold tabular-nums tracking-tight text-foreground',
          size === 'large'
            ? 'text-2xl sm:text-3xl xl:text-4xl'
            : 'text-2xl sm:text-3xl',
        )}
      >
        {displayValue}
        {suffix}
      </p>
      <p
        className={cn(
          'leading-snug text-muted-foreground',
          size === 'large'
            ? 'text-[10px] sm:text-[11px]'
            : 'text-xs',
        )}
      >
        {label}
      </p>
    </div>
  )
}

function ScaleAreaCurve({ className }: { className?: string }) {
  return (
    <svg
      className={cn('absolute inset-x-0 bottom-0 h-[88%] w-full', className)}
      viewBox="0 0 400 280"
      preserveAspectRatio="none"
      aria-hidden
    >
      <defs>
        <linearGradient id="scale-area-fill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--chart-brand)" stopOpacity={0.2} />
          <stop offset="100%" stopColor="var(--chart-brand)" stopOpacity={0} />
        </linearGradient>
      </defs>
      <path
        d="M0 280 L0 228 C28 220 56 210 86 198 C114 186 142 170 172 152 C200 134 228 118 256 100 C286 82 314 64 342 46 C368 30 386 22 400 14 L400 280 Z"
        fill="url(#scale-area-fill)"
      />
      <path
        d="M0 228 C28 220 56 210 86 198 C114 186 142 170 172 152 C200 134 228 118 256 100 C286 82 314 64 342 46 C368 30 386 22 400 14"
        fill="none"
        stroke="var(--chart-brand)"
        strokeWidth={2}
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  )
}

function ScaleChartBackground() {
  return (
    <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden>
      {[20, 40, 60, 80].map((top) => (
        <div
          key={top}
          className="absolute inset-x-0 border-t border-border/80"
          style={{ top: `${top}%` }}
        />
      ))}
    </div>
  )
}

function ScaleStatsChart({
  started,
  prefersReducedMotion,
}: {
  started: boolean
  prefersReducedMotion: boolean
}) {
  return (
    <Card className="relative min-h-[24rem] w-full gap-0 overflow-hidden py-0 sm:min-h-[28rem] lg:min-h-[30rem]">
      <div className="pointer-events-none absolute inset-0 z-0" aria-hidden>
        <ScaleChartBackground />
        <ScaleAreaCurve className="opacity-80" />
      </div>

      <div className="relative z-10 hidden lg:block">
        <div
          className="grid gap-4 px-6 py-5"
          style={{
            gridTemplateColumns: `repeat(${SCALE_STAT_COUNT}, minmax(0, 1fr))`,
          }}
        >
          {SCALE_STATS.map((stat, index) => (
            <div
              key={stat.label}
              className="min-w-0 border-l border-border px-2 first:border-l-0 sm:px-3"
            >
              <ScaleStatValue
                value={stat.value}
                suffix={stat.suffix}
                label={stat.label}
                started={started}
                index={index}
                prefersReducedMotion={prefersReducedMotion}
                size="large"
              />
            </div>
          ))}
        </div>
        <div className="min-h-[16rem] sm:min-h-[18rem] lg:min-h-[20rem]" aria-hidden />
      </div>

      <div className="relative z-10 lg:hidden">
        <div className="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 sm:gap-5 sm:p-6">
          {SCALE_STATS.map((stat, index) => (
            <div key={stat.label} className="min-w-0 px-0.5">
              <ScaleStatValue
                value={stat.value}
                suffix={stat.suffix}
                label={stat.label}
                started={started}
                index={index}
                prefersReducedMotion={prefersReducedMotion}
              />
            </div>
          ))}
        </div>
        <div className="min-h-[10rem] sm:min-h-[12rem]" aria-hidden />
      </div>
    </Card>
  )
}

export function ScaleSection() {
  const sectionRef = useRef<HTMLElement>(null)
  const [hasAnimated, setHasAnimated] = useState(false)
  const prefersReducedMotion = useReducedMotion() ?? false

  useEffect(() => {
    const node = sectionRef.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) {
          setHasAnimated(true)
          observer.disconnect()
        }
      },
      { threshold: 0.25 },
    )

    observer.observe(node)
    return () => observer.disconnect()
  }, [])

  return (
    <section
      ref={sectionRef}
      className="border-t border-border bg-background py-16 sm:py-20"
    >
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:items-stretch lg:gap-10 xl:gap-14">
          <h2 className="font-aeonik-pro max-w-xl self-start text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px] lg:max-w-none">
            Over half a million developers scale with Appwrite
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <ScaleQuoteBesideTitle />
        </div>

        <div className="mt-10 w-full sm:mt-12">
          <ScaleStatsChart
            started={hasAnimated}
            prefersReducedMotion={prefersReducedMotion}
          />
        </div>
      </div>
    </section>
  )
}
