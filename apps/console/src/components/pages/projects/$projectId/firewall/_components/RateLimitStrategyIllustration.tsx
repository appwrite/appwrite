import { useMemo } from 'react'
import { format } from 'date-fns'
import { WafRuleAction } from '@appwrite.io/console'
import {
  FIREWALL_PASSED_CHART_COLOR,
  FIREWALL_RATE_LIMIT_STRATEGIES,
  getFirewallActionChartColor,
  type FirewallRateLimitStrategy,
} from '@/lib/firewall/actions'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Same colors as the firewall traffic charts: emerald = passed, amber = rate limited. */
const PASSED_COLOR = FIREWALL_PASSED_CHART_COLOR
const LIMITED_COLOR = getFirewallActionChartColor(WafRuleAction.RateLimit)

export interface RateLimitIllustrationConfig {
  strategy: FirewallRateLimitStrategy
  limit: number
  interval: number
  maxBucketSize: number
}

/** 1D timeline: requests sit on a single line, the window is a span on it. */
const TIMELINE_VIEWBOX = '0 0 280 78'
const WINDOW_LEFT = 74
const WINDOW_RIGHT = 214
const BAND_TOP = 20
const BAND_BOTTOM = 48
const AXIS_Y = 34

const MAX_DOTS_SHOWN = 6

function formatCount(value: number): string {
  return Math.max(0, Math.round(value)).toLocaleString()
}

/** Deterministic PRNG so a render's scatter is stable across re-renders. */
function mulberry32(seed: number) {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/**
 * Requests arrive irregularly: stratified random positions along the timeline
 * (ordered but uneven, never overlapping). Seeded once per mount so dots
 * don't jump while typing.
 */
function useScatteredRequests(count: number, fromX: number, toX: number) {
  const seed = useMemo(() => (Date.now() % 0x7fffffff) | 0, [])
  return useMemo(() => {
    const rand = mulberry32(seed)
    const slice = (toX - fromX) / Math.max(count, 1)
    const xs: number[] = []
    for (let i = 0; i < count; i++) {
      xs.push(Math.round((fromX + slice * (i + 0.25 + 0.5 * rand())) * 10) / 10)
    }
    return xs
  }, [seed, count, fromX, toX])
}

function RequestDot({
  x,
  emphasized = false,
}: {
  x: number
  emphasized?: boolean
}) {
  return (
    <circle
      cx={x}
      cy={AXIS_Y}
      r={emphasized ? 3.75 : 3.25}
      fill={PASSED_COLOR}
      stroke="var(--background)"
      strokeWidth={1.5}
    />
  )
}

/** Quota annotation above the window: "{limit} requests per {interval}s". */
function QuotaLabel({ limit, interval }: { limit: number; interval: number }) {
  const t = useT()
  return (
    <text
      x={(WINDOW_LEFT + WINDOW_RIGHT) / 2}
      y={9}
      textAnchor="middle"
      fontSize={9}
    >
      <tspan fontWeight={600} fill="var(--foreground)">
        {formatCount(limit)}
      </tspan>
      <tspan fill="var(--muted-foreground)">
        {` ${t('requests')} ${t('per')} ${formatCount(interval)}s`}
      </tspan>
    </text>
  )
}

function WindowBand() {
  return (
    <>
      <rect
        x={WINDOW_LEFT}
        y={BAND_TOP}
        width={WINDOW_RIGHT - WINDOW_LEFT}
        height={BAND_BOTTOM - BAND_TOP}
        fill="var(--muted-foreground)"
        fillOpacity={0.07}
      />
      <line x1={6} y1={AXIS_Y} x2={274} y2={AXIS_Y} stroke="var(--border)" />
      <line
        x1={WINDOW_LEFT}
        y1={BAND_TOP}
        x2={WINDOW_LEFT}
        y2={BAND_BOTTOM + 4}
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
      />
      <line
        x1={WINDOW_RIGHT}
        y1={BAND_TOP}
        x2={WINDOW_RIGHT}
        y2={BAND_BOTTOM + 4}
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
      />
    </>
  )
}

function FixedWindowDiagram({
  limit,
  interval,
  ariaLabel,
}: {
  limit: number
  interval: number
  ariaLabel: string
}) {
  // Fixed windows are aligned to the clock: floor "now" to the interval so the
  // boundary labels land on real round times (e.g. :00 seconds for 60s).
  const windowStart = useMemo(() => {
    const ms = Math.max(1, interval) * 1000
    return Math.floor(Date.now() / ms) * ms
  }, [interval])

  const dotCount = Math.min(Math.max(limit, 1), MAX_DOTS_SHOWN)
  const dots = useScatteredRequests(dotCount, 84, 204)

  return (
    <svg
      viewBox={TIMELINE_VIEWBOX}
      className="h-auto w-full"
      role="img"
      aria-label={ariaLabel}
    >
      <QuotaLabel limit={limit} interval={interval} />
      <WindowBand />

      {dots.map((x) => (
        <RequestDot key={x} x={x} />
      ))}

      <text
        x={WINDOW_LEFT}
        y={62}
        textAnchor="middle"
        fontSize={8}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        {format(windowStart, 'HH:mm:ss')}
      </text>
      <text
        x={WINDOW_RIGHT}
        y={62}
        textAnchor="middle"
        fontSize={8}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        {format(windowStart + Math.max(1, interval) * 1000, 'HH:mm:ss')}
      </text>
    </svg>
  )
}

function SlidingWindowDiagram({
  limit,
  interval,
  ariaLabel,
}: {
  limit: number
  interval: number
  ariaLabel: string
}) {
  const t = useT()
  // The window is anchored to traffic, not the clock: a deliberately non-round
  // time (whenever this rendered) marks the client's first request.
  const anchor = useMemo(() => Date.now(), [])

  const dotCount = Math.min(Math.max(limit, 1), MAX_DOTS_SHOWN)
  const scattered = useScatteredRequests(Math.max(dotCount - 1, 0), 92, 206)

  return (
    <svg
      viewBox={TIMELINE_VIEWBOX}
      className="h-auto w-full"
      role="img"
      aria-label={ariaLabel}
    >
      <QuotaLabel limit={limit} interval={interval} />
      <WindowBand />

      <RequestDot x={WINDOW_LEFT} emphasized />
      {scattered.map((x) => (
        <RequestDot key={x} x={x} />
      ))}

      <text
        x={WINDOW_LEFT}
        y={62}
        textAnchor="middle"
        fontSize={8}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        {format(anchor, 'HH:mm:ss')}
      </text>
      <text
        x={WINDOW_LEFT}
        y={72}
        textAnchor="middle"
        fontSize={8}
        fill="var(--muted-foreground)"
      >
        {t('first request')}
      </text>
      <text
        x={WINDOW_RIGHT}
        y={62}
        textAnchor="middle"
        fontSize={8}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        {`+${formatCount(interval)}s`}
      </text>
    </svg>
  )
}

/** Stored tokens stack bottom-up; a capacity below 23 renders exactly that many. */
const BUCKET_SLOTS = [
  { x: 104, y: 103 },
  { x: 117, y: 103 },
  { x: 130, y: 103 },
  { x: 143, y: 103 },
  { x: 156, y: 103 },
  { x: 110, y: 92 },
  { x: 123, y: 92 },
  { x: 136, y: 92 },
  { x: 149, y: 92 },
  { x: 104, y: 81 },
  { x: 117, y: 81 },
  { x: 130, y: 81 },
  { x: 143, y: 81 },
  { x: 156, y: 81 },
  { x: 110, y: 70 },
  { x: 123, y: 70 },
  { x: 136, y: 70 },
  { x: 149, y: 70 },
  { x: 104, y: 59 },
  { x: 117, y: 59 },
  { x: 130, y: 59 },
  { x: 143, y: 59 },
  { x: 156, y: 59 },
]

function TokenBucketDiagram({
  limit,
  interval,
  maxBucketSize,
  ariaLabel,
}: {
  limit: number
  interval: number
  maxBucketSize: number
  ariaLabel: string
}) {
  const t = useT()
  const slotCount = Math.min(Math.max(maxBucketSize, 1), BUCKET_SLOTS.length)
  // Water level follows the shown rows: an almost-full bucket at typical
  // capacities, shallower when the capacity is small. Wall x at a given y
  // follows the bucket's taper.
  const surfaceY =
    slotCount <= 5
      ? 96
      : slotCount <= 9
        ? 85
        : slotCount <= 14
          ? 74
          : slotCount <= 18
            ? 63
            : 52
  const wallLeftX = (y: number) => 88 + 0.1 * (y - 42)
  const wallRightX = (y: number) => 172 - 0.1 * (y - 42)

  return (
    <svg
      viewBox="0 0 280 158"
      className="h-auto w-full"
      role="img"
      aria-label={ariaLabel}
    >
      {/* Refill drip into the open top of the bucket */}
      <circle
        cx={120}
        cy={10}
        r={2.75}
        fill={LIMITED_COLOR}
        fillOpacity={0.35}
      />
      <circle
        cx={120}
        cy={20}
        r={2.75}
        fill={LIMITED_COLOR}
        fillOpacity={0.65}
      />
      <circle cx={120} cy={31} r={2.75} fill={LIMITED_COLOR} />
      <text x={132} y={22} fontSize={9}>
        <tspan fontWeight={600} fill="var(--foreground)">
          {`+${formatCount(limit)}`}
        </tspan>
        <tspan fill="var(--muted-foreground)">
          {` ${t('tokens')} ${t('per')} ${formatCount(interval)}s`}
        </tspan>
      </text>

      {/* Bucket walls, open at the top, with an outflow hole in the bottom */}
      <g
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
        strokeWidth={1.5}
        strokeLinecap="round"
        strokeLinejoin="round"
        fill="none"
      >
        <path d="M 88 42 L 94.6 107 Q 95 112 99.5 112 L 121 112" />
        <path d="M 172 42 L 165.4 107 Q 165 112 160.5 112 L 139 112" />
      </g>

      {/* Stored tokens: the burst reserve */}
      <path
        d={`M ${wallLeftX(surfaceY)} ${surfaceY} L ${wallRightX(surfaceY)} ${surfaceY} L ${wallRightX(111)} 111 L ${wallLeftX(111)} 111 Z`}
        fill={LIMITED_COLOR}
        fillOpacity={0.08}
      />
      <line
        x1={wallLeftX(surfaceY)}
        y1={surfaceY}
        x2={wallRightX(surfaceY)}
        y2={surfaceY}
        stroke={LIMITED_COLOR}
        strokeOpacity={0.35}
      />
      {BUCKET_SLOTS.slice(0, slotCount).map((slot) => (
        <circle
          key={`${slot.x}-${slot.y}`}
          cx={slot.x}
          cy={slot.y}
          r={3}
          fill={LIMITED_COLOR}
          stroke="var(--background)"
          strokeWidth={1.5}
        />
      ))}

      {/* Capacity bracket */}
      <g stroke="var(--muted-foreground)" strokeOpacity={0.4}>
        <line x1={182} y1={42} x2={182} y2={112} />
        <line x1={178} y1={42} x2={182} y2={42} />
        <line x1={178} y1={112} x2={182} y2={112} />
      </g>
      <text x={188} y={73} fontSize={8} fill="var(--muted-foreground)">
        {t('Max bucket size')}
      </text>
      <text x={188} y={85} fontSize={9}>
        <tspan fontWeight={600} fill="var(--foreground)">
          {formatCount(maxBucketSize)}
        </tspan>
        <tspan fill="var(--muted-foreground)">{` ${t('tokens')}`}</tspan>
      </text>

      {/* Outflow: each passing request spends one token */}
      <line
        x1={130}
        y1={117}
        x2={130}
        y2={126}
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
        strokeWidth={1.25}
      />
      <polygon
        points="126.5,125.5 133.5,125.5 130,131"
        fill="var(--muted-foreground)"
        fillOpacity={0.5}
      />
      <circle
        cx={130}
        cy={139}
        r={3.25}
        fill={PASSED_COLOR}
        stroke="var(--background)"
        strokeWidth={1.5}
      />
      <text
        x={130}
        y={152}
        textAnchor="middle"
        fontSize={8.5}
        fill="var(--muted-foreground)"
      >
        {t('each request takes one token')}
      </text>
    </svg>
  )
}

/**
 * Explains the selected rate-limit strategy with the user's actual numbers so
 * the diagram always matches the configuration in the form.
 */
export function RateLimitStrategyIllustration({
  strategy,
  limit,
  interval,
  maxBucketSize,
}: RateLimitIllustrationConfig) {
  const t = useT()
  const strategyLabel = FIREWALL_RATE_LIMIT_STRATEGIES.find(
    (s) => s.value === strategy,
  )?.label
  const ariaLabel = t('Rate limit strategy illustration')

  const caption =
    strategy === 'fixedWindow'
      ? t(
          'Windows align to the clock. Rate limit resets for everyone when the next time interval starts.',
        )
      : strategy === 'slidingWindow'
        ? t(
            "Windows align to the user. Helps prevent traffic spikes since the reset doesn't occur for all users at the same time.",
          )
        : t(
            'Windows align to human behaviour. Allows accumulated short bursts, and refills for sustained pace.',
          )

  return (
    <div className="mt-3 border-t border-border pt-3">
      {strategyLabel ? (
        <p className="text-[12px] font-medium text-foreground">
          {t(strategyLabel)}
        </p>
      ) : null}
      <div className={cn('mx-auto mt-2 w-full max-w-[340px]', FORCE_LTR_CLASS)}>
        {strategy === 'fixedWindow' ? (
          <FixedWindowDiagram
            limit={limit}
            interval={interval}
            ariaLabel={ariaLabel}
          />
        ) : strategy === 'slidingWindow' ? (
          <SlidingWindowDiagram
            limit={limit}
            interval={interval}
            ariaLabel={ariaLabel}
          />
        ) : (
          <TokenBucketDiagram
            limit={limit}
            interval={interval}
            maxBucketSize={maxBucketSize}
            ariaLabel={ariaLabel}
          />
        )}
      </div>
      <p className="mt-2 text-center text-[11px] leading-relaxed text-muted-foreground">
        {caption}
      </p>
    </div>
  )
}
