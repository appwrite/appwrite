import { BellRing } from 'lucide-react'
import { CoverCard, InitDayCover } from './InitDayCover'

/** Bandwidth over a day, crossing the monitor's threshold near the end. */
const USAGE = [22, 26, 24, 31, 36, 34, 45, 52, 61, 70, 84]
const THRESHOLD = 80

function usagePath(width: number, height: number) {
  return USAGE.map((value, index) => {
    const x = (index / (USAGE.length - 1)) * width
    const y = height - (value / 100) * height
    return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}

/** Init 5 day 4, Monitors: a bandwidth monitor firing as usage crosses its threshold. */
export function MonitorsDayVisual() {
  const thresholdY = 60 - (THRESHOLD / 100) * 60
  const lastY = 60 - (USAGE[USAGE.length - 1]! / 100) * 60

  return (
    <InitDayCover
      headline="Know when your project needs you"
      mockClassName="start-[48cqw] top-[16cqw] w-[56cqw]"
    >
      <CoverCard className="overflow-hidden">
        <div className="flex items-center gap-[0.6em] border-b border-white/8 px-[0.9em] py-[0.8em]">
          <BellRing
            className="size-[1.2em] shrink-0 text-[var(--brand-cta)]"
            aria-hidden
          />
          <span className="font-medium text-white">Bandwidth</span>
          <span className="ms-auto rounded-[0.35em] border border-[var(--brand-cta)] bg-[rgb(253_54_110/0.14)] px-[0.5em] py-[0.1em] text-[0.85em] text-white">
            Triggered
          </span>
        </div>
        <div className="px-[0.9em] pb-[4.5em] pt-[0.7em]">
          <p className="flex items-baseline justify-between text-[0.85em] text-white/55">
            <span>Alert at {THRESHOLD}% of the plan</span>
            <span className="text-[1.25em] font-semibold tabular-nums text-white">
              84%
            </span>
          </p>
          <svg
            viewBox="0 0 160 60"
            preserveAspectRatio="none"
            className="mt-[0.5em] block h-[6.5em] w-full overflow-visible"
            aria-hidden
          >
            <line
              x1="0"
              x2="160"
              y1={thresholdY}
              y2={thresholdY}
              stroke="var(--brand-cta)"
              strokeDasharray="4 4"
              vectorEffect="non-scaling-stroke"
            />
            <path
              d={usagePath(160, 60)}
              fill="none"
              stroke="white"
              strokeWidth={1.8}
              vectorEffect="non-scaling-stroke"
            />
            <circle cx="160" cy={lastY} r="3.5" fill="var(--brand-cta)" />
          </svg>
        </div>
      </CoverCard>
    </InitDayCover>
  )
}
