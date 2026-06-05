import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Card } from '@/components/ui/card'
import { cn } from '@/lib/utils'

const SCALE_QUOTE = {
  lineOne: 'The switch to using Appwrite brought',
  lineTwo: 'infinite value that I\u2019m still discovering today.',
  name: 'Ryan O\u2019Connor',
  title: 'Founder',
  company: 'K-Collect',
  avatar: '/images/testimonials/ryan-oconner-testimonial.avif',
} as const

function ScaleQuoteBelowChart() {
  return (
    <figure className="mx-auto flex w-full max-w-[21rem] flex-col items-center text-center sm:max-w-[24rem]">
      <span
        className="font-aeonik-pro text-[3.5rem] leading-none text-muted-foreground/30 sm:text-[4rem]"
        aria-hidden
      >
        &ldquo;
      </span>
      <blockquote className="mt-3 text-sm leading-snug text-muted-foreground sm:text-[15px] sm:leading-6">
        <span className="block">{SCALE_QUOTE.lineOne}</span>
        <span className="mt-1 block">{SCALE_QUOTE.lineTwo}</span>
      </blockquote>
      <figcaption className="mt-7 flex items-center justify-center gap-2.5 sm:mt-8">
        <Avatar className="size-8">
          <AvatarImage src={SCALE_QUOTE.avatar} alt="" />
          <AvatarFallback className="text-xs">RO</AvatarFallback>
        </Avatar>
        <p className="text-left text-sm leading-snug">
          <span className="font-medium text-foreground">{SCALE_QUOTE.name}</span>
          <span className="text-muted-foreground">
            {' '}
            · {SCALE_QUOTE.title}, {SCALE_QUOTE.company}
          </span>
        </p>
      </figcaption>
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

function ScaleStatValue({
  value,
  suffix,
  label,
  size = 'default',
}: {
  value: number
  suffix: string
  label: string
  size?: 'default' | 'large'
}) {
  return (
    <div className="min-w-0 space-y-1.5">
      <p
        className={cn(
          'font-semibold tabular-nums tracking-tight text-foreground',
          size === 'large'
            ? 'text-xl sm:text-2xl xl:text-3xl'
            : 'text-xl sm:text-2xl',
        )}
      >
        {value}
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

function ScaleStatsChart() {
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
          {SCALE_STATS.map((stat) => (
            <div
              key={stat.label}
              className="min-w-0 border-l border-border px-2 first:border-l-0 sm:px-3"
            >
              <ScaleStatValue
                value={stat.value}
                suffix={stat.suffix}
                label={stat.label}
                size="large"
              />
            </div>
          ))}
        </div>
        <div className="min-h-[16rem] sm:min-h-[18rem] lg:min-h-[20rem]" aria-hidden />
      </div>

      <div className="relative z-10 lg:hidden">
        <div className="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 sm:gap-5 sm:p-6">
          {SCALE_STATS.map((stat) => (
            <div key={stat.label} className="min-w-0 px-0.5">
              <ScaleStatValue
                value={stat.value}
                suffix={stat.suffix}
                label={stat.label}
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
  return (
    <section className="border-t border-border bg-background py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <h2 className="font-aeonik-pro max-w-3xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
          Over half a million developers scale with Appwrite
          <span className="text-[var(--brand-cta)]">_</span>
        </h2>

        <div className="mt-10 w-full sm:mt-12">
          <ScaleStatsChart />
        </div>

        <div className="mt-10 sm:mt-12">
          <ScaleQuoteBelowChart />
        </div>
      </div>
    </section>
  )
}
