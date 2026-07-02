import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { useT } from '@/lib/i18n/translate'
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
  const t = useT()
  return (
    <figure className="mx-auto flex w-full max-w-[21rem] flex-col items-center text-center sm:max-w-[24rem]">
      <span
        className="font-aeonik-pro text-[3.5rem] leading-none text-muted-foreground/30 sm:text-[4rem]"
        aria-hidden
      >
        &ldquo;
      </span>
      <blockquote className="mt-3 text-sm leading-snug text-muted-foreground sm:text-[15px] sm:leading-6">
        <span className="block">{t(SCALE_QUOTE.lineOne)}</span>
        <span className="mt-1 block">{t(SCALE_QUOTE.lineTwo)}</span>
      </blockquote>
      <figcaption className="mt-7 flex items-center justify-center gap-2.5 sm:mt-8">
        <Avatar className="size-8">
          <AvatarImage src={SCALE_QUOTE.avatar} alt="" />
          <AvatarFallback className="text-xs">RO</AvatarFallback>
        </Avatar>
        <p className="text-start text-sm leading-snug">
          <span className="font-medium text-foreground">{SCALE_QUOTE.name}</span>
          <span className="text-muted-foreground">
            {' '}
            · {t(SCALE_QUOTE.title)}, {SCALE_QUOTE.company}
          </span>
        </p>
      </figcaption>
    </figure>
  )
}

const SCALE_STATS = [
  { value: 24, suffix: 'K+', label: 'Discord members' },
  { value: 56, suffix: 'K+', label: 'GitHub stars' },
  { value: 300, suffix: '+', label: 'PoP locations' },
  { value: 300, suffix: 'K+', label: 'Cloud projects' },
  { value: 500, suffix: 'K+', label: 'Developers' },
  { value: 20, suffix: 'B+', label: 'DB operations / month' },
  { value: 7, suffix: 'B+', label: 'Requests / month' },
] as const

function scaleStatMagnitude(stat: { value: number; suffix: string }): number {
  if (stat.suffix.startsWith('B')) return stat.value * 1_000_000_000
  if (stat.suffix.startsWith('K')) return stat.value * 1_000
  return stat.value
}

const SORTED_SCALE_STATS = [...SCALE_STATS].sort(
  (a, b) => scaleStatMagnitude(a) - scaleStatMagnitude(b),
)

function ScaleStatCard({
  value,
  suffix,
  label,
}: {
  value: number
  suffix: string
  label: string
}) {
  const t = useT()
  return (
    <div className="flex h-full min-w-0 flex-col justify-center rounded-xl border border-border/80 px-2.5 py-2.5 sm:px-3 sm:py-3">
      <p className="text-base font-semibold tabular-nums tracking-tight text-foreground sm:text-lg lg:text-xl">
        {value}
        {suffix}
      </p>
      <p className="mt-0.5 text-[10px] leading-snug text-muted-foreground sm:text-[11px]">
        {t(label)}
      </p>
    </div>
  )
}

function ScaleAreaCurve({ className }: { className?: string }) {
  return (
    <svg
      className={cn(
        'absolute inset-0 h-full w-full origin-center rtl:-scale-x-100',
        className,
      )}
      viewBox="0 0 400 280"
      preserveAspectRatio="none"
      aria-hidden
    >
      <defs>
        <linearGradient
          id="scale-area-fill"
          gradientUnits="userSpaceOnUse"
          x1="0"
          y1="0"
          x2="0"
          y2="280"
        >
          <stop offset="0%" stopColor="var(--brand-cta)" stopOpacity={0.2} />
          <stop offset="38%" stopColor="var(--brand-cta)" stopOpacity={0.1} />
          <stop offset="62%" stopColor="var(--brand-cta)" stopOpacity={0.04} />
          <stop offset="82%" stopColor="var(--brand-cta)" stopOpacity={0.012} />
          <stop offset="100%" stopColor="var(--brand-cta)" stopOpacity={0} />
        </linearGradient>
      </defs>
      <path
        d="M0 280 L0 228 C28 220 56 210 86 198 C114 186 142 170 172 152 C200 134 228 118 256 100 C286 82 314 64 342 46 C368 30 386 22 400 14 L400 280 Z"
        fill="url(#scale-area-fill)"
      />
      <path
        d="M0 228 C28 220 56 210 86 198 C114 186 142 170 172 152 C200 134 228 118 256 100 C286 82 314 64 342 46 C368 30 386 22 400 14"
        fill="none"
        stroke="var(--brand-cta)"
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

function ScaleStatCards() {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-7 min-[1200px]:gap-3">
      {SORTED_SCALE_STATS.map((stat) => (
        <ScaleStatCard
          key={stat.label}
          value={stat.value}
          suffix={stat.suffix}
          label={stat.label}
        />
      ))}
    </div>
  )
}

function ScaleChart() {
  return (
    <div className="relative left-1/2 w-[100dvw] -translate-x-1/2">
      <div className="relative min-h-[22rem] w-full overflow-hidden bg-transparent sm:min-h-[26rem] lg:min-h-[28rem]">
        <div className="pointer-events-none absolute inset-0" aria-hidden>
          <ScaleChartBackground />
          <ScaleAreaCurve className="scale-area-curve" />
        </div>
      </div>
    </div>
  )
}

export function ScaleSection() {
  const t = useT()
  return (
    <section
      id="scale"
      className="scroll-mt-28 border-t border-border bg-background py-16 sm:py-20"
    >
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <h2 className="font-aeonik-pro max-w-3xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
          {t('Over half a million developers scale with Appwrite')} {/* pragma: allowlist secret */}
          <span className="text-[var(--brand-cta)]">_</span>
        </h2>

        <div className="mt-8 sm:mt-10">
          <ScaleStatCards />
        </div>
      </div>

      <div className="mt-6 w-full sm:mt-8">
        <ScaleChart />
      </div>

      <div className="relative z-[1] mx-auto -mt-6 w-full max-w-7xl px-4 sm:-mt-8 sm:px-6">
        <ScaleQuoteBelowChart />
      </div>
    </section>
  )
}
