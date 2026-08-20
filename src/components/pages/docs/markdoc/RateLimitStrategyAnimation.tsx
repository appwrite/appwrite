'use client'

import React, { useEffect, useRef, useState } from 'react'
import { FIREWALL_PASSED_CHART_COLOR } from '@/lib/firewall/actions'

// Amber used by the firewall charts for the rate limit action.
const LIMITED_COLOR = '#f59e0b'

const FPS = 30

/** Linear interpolation over a piecewise input/output range, clamped at both ends. */
function interpolate(value: number, input: number[], output: number[]): number {
  if (value <= input[0]) return output[0]
  if (value >= input[input.length - 1]) return output[output.length - 1]
  for (let i = 0; i < input.length - 1; i++) {
    if (value <= input[i + 1]) {
      const t = (value - input[i]) / (input[i + 1] - input[i])
      return output[i] + t * (output[i + 1] - output[i])
    }
  }
  return output[output.length - 1]
}

function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false)
  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)')
    setReduced(query.matches)
    const onChange = (event: MediaQueryListEvent) => setReduced(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])
  return reduced
}

/**
 * Looping animation clock. Returns the current frame in [0, durationFrames).
 * Pauses (frozen at restFrame) when reduced motion is requested or the
 * element is out of the viewport.
 */
function useLoopFrame(
  durationFrames: number,
  restFrame: number,
  containerRef: React.RefObject<HTMLDivElement | null>,
): number {
  const reduced = usePrefersReducedMotion()
  const [frame, setFrame] = useState(0)
  const visibleRef = useRef(true)

  useEffect(() => {
    const node = containerRef.current
    if (!node || typeof IntersectionObserver === 'undefined') return
    const observer = new IntersectionObserver(([entry]) => {
      visibleRef.current = entry?.isIntersecting ?? true
    })
    observer.observe(node)
    return () => observer.disconnect()
  }, [containerRef])

  useEffect(() => {
    if (reduced) {
      setFrame(restFrame)
      return
    }
    let raf = 0
    let start: number | null = null
    const tick = (now: number) => {
      if (start === null) start = now
      if (visibleRef.current) {
        setFrame(Math.floor(((now - start) / 1000) * FPS) % durationFrames)
      }
      raf = requestAnimationFrame(tick)
    }
    raf = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(raf)
  }, [durationFrames, restFrame, reduced])

  return frame
}

// Scene coordinates, matching the console's RateLimitStrategyIllustration
// geometry scaled 2x (280-wide viewBox -> 568).
const VIEW_W = 568
const AXIS_Y = 160
const BAND_TOP = 130
const BAND_BOTTOM = 190

const RequestDot: React.FC<{
  x: number
  y: number
  color?: string
  emphasized?: boolean
  opacity?: number
}> = ({
  x,
  y,
  color = FIREWALL_PASSED_CHART_COLOR,
  emphasized,
  opacity = 1,
}) => (
  <circle
    cx={x}
    cy={y}
    r={emphasized ? 7.5 : 6.5}
    fill={color}
    stroke="var(--background)"
    strokeWidth={3}
    opacity={opacity}
  />
)

const WindowBand: React.FC<{
  left: number
  right: number
  opacity?: number
}> = ({ left, right, opacity = 1 }) => (
  <g opacity={opacity}>
    <rect
      x={left}
      y={BAND_TOP}
      width={right - left}
      height={BAND_BOTTOM - BAND_TOP}
      fill="var(--muted-foreground)"
      fillOpacity={0.07}
    />
    <line
      x1={left}
      y1={BAND_TOP}
      x2={left}
      y2={BAND_BOTTOM + 8}
      stroke="var(--muted-foreground)"
      strokeOpacity={0.5}
      strokeWidth={2}
    />
    <line
      x1={right}
      y1={BAND_TOP}
      x2={right}
      y2={BAND_BOTTOM + 8}
      stroke="var(--muted-foreground)"
      strokeOpacity={0.5}
      strokeWidth={2}
    />
  </g>
)

const QuotaLabel: React.FC<{ x: number; bold: string; rest: string }> = ({
  x,
  bold,
  rest,
}) => (
  <text x={x} y={72} textAnchor="middle" fontSize={18}>
    <tspan fontWeight={600} fill="var(--foreground)">
      {bold}
    </tspan>
    <tspan fill="var(--muted-foreground)">{` ${rest}`}</tspan>
  </text>
)

const dropIn = (frame: number, start: number) => {
  const t = interpolate(frame, [start, start + 12], [0, 1])
  return { y: AXIS_Y - (1 - t) * 46, opacity: t }
}

// --- Fixed window ---------------------------------------------------------

const FIXED_DURATION = 330
const FIXED_ARRIVALS = [
  { frame: 20, x: 152 },
  { frame: 55, x: 205 },
  { frame: 85, x: 262 },
  { frame: 115, x: 318 },
  { frame: 145, x: 371 },
]
const FIXED_LIMITED = { frame: 185, x: 415 }
const FIXED_RESET = 245
const FIXED_NEXT = { frame: 285, x: 158 }
const FIXED_LEFT = 120
const FIXED_RIGHT = 448

const FixedWindowDiagram: React.FC<{ frame: number }> = ({ frame }) => {
  const afterReset = frame >= FIXED_RESET
  const oldOpacity = interpolate(frame, [FIXED_RESET, FIXED_RESET + 14], [1, 0])
  const sweepOpacity = interpolate(
    frame,
    [FIXED_RESET - 4, FIXED_RESET, FIXED_RESET + 18],
    [0, 1, 0],
  )
  const count = FIXED_ARRIVALS.filter((a) => frame >= a.frame).length
  const limitedVisible = frame >= FIXED_LIMITED.frame && !afterReset
  const limitedLabelOpacity = interpolate(
    frame,
    [FIXED_LIMITED.frame + 4, FIXED_LIMITED.frame + 14],
    [0, 1],
  )
  const timesOpacity = afterReset
    ? interpolate(frame, [FIXED_RESET + 6, FIXED_RESET + 18], [0, 1])
    : 1

  return (
    <svg viewBox={`0 0 ${VIEW_W} 320`} className="h-auto w-full" role="img">
      <QuotaLabel
        x={(FIXED_LEFT + FIXED_RIGHT) / 2}
        bold="5"
        rest="requests per 15s"
      />
      <text
        x={FIXED_RIGHT}
        y={110}
        textAnchor="end"
        fontSize={15}
        fill="var(--muted-foreground)"
        opacity={oldOpacity}
        className="tabular-nums"
      >
        <tspan fontWeight={600} fill="var(--foreground)">
          {Math.min(count, 5)}
        </tspan>
        {' / 5 used'}
      </text>

      <line
        x1={24}
        y1={AXIS_Y}
        x2={544}
        y2={AXIS_Y}
        stroke="var(--border)"
        strokeWidth={2}
      />
      <WindowBand left={FIXED_LEFT} right={FIXED_RIGHT} />
      <line
        x1={FIXED_RIGHT}
        y1={BAND_TOP - 8}
        x2={FIXED_RIGHT}
        y2={BAND_BOTTOM + 16}
        stroke={LIMITED_COLOR}
        strokeWidth={3}
        opacity={sweepOpacity}
      />

      <g opacity={oldOpacity}>
        {FIXED_ARRIVALS.filter((a) => frame >= a.frame).map((a) => {
          const d = dropIn(frame, a.frame)
          return <RequestDot key={a.x} x={a.x} y={d.y} opacity={d.opacity} />
        })}
        {limitedVisible ? (
          <>
            <RequestDot
              x={FIXED_LIMITED.x}
              y={dropIn(frame, FIXED_LIMITED.frame).y}
              opacity={dropIn(frame, FIXED_LIMITED.frame).opacity}
              color={LIMITED_COLOR}
              emphasized
            />
            <text
              x={FIXED_LIMITED.x}
              y={AXIS_Y - 26}
              textAnchor="middle"
              fontSize={14}
              fill={LIMITED_COLOR}
              opacity={limitedLabelOpacity}
            >
              rate limited
            </text>
          </>
        ) : null}
      </g>

      {frame >= FIXED_NEXT.frame ? (
        <RequestDot
          x={FIXED_NEXT.x}
          y={dropIn(frame, FIXED_NEXT.frame).y}
          opacity={dropIn(frame, FIXED_NEXT.frame).opacity}
        />
      ) : null}

      <g opacity={timesOpacity}>
        <text
          x={FIXED_LEFT}
          y={222}
          textAnchor="middle"
          fontSize={16}
          fill="var(--muted-foreground)"
          className="tabular-nums"
        >
          {afterReset ? '11:42:30' : '11:42:15'}
        </text>
        <text
          x={FIXED_RIGHT}
          y={222}
          textAnchor="middle"
          fontSize={16}
          fill="var(--muted-foreground)"
          className="tabular-nums"
        >
          {afterReset ? '11:42:45' : '11:42:30'}
        </text>
      </g>
    </svg>
  )
}

// --- Sliding window -------------------------------------------------------

const SLIDING_DURATION = 330
const SLIDING_LEFT = 120
const SLIDING_WINDOW_W = 328
const SLIDING_SLIDE_START = 170
const SLIDING_SLIDE_END = 320
const SLIDING_SLIDE_DIST = 96
const SLIDING_ARRIVALS = [
  { frame: 18, x: SLIDING_LEFT, emphasized: true },
  { frame: 60, x: 190 },
  { frame: 90, x: 248 },
  { frame: 118, x: 305 },
  { frame: 142, x: 356 },
  { frame: 236, x: 470 },
]

const SlidingWindowDiagram: React.FC<{ frame: number }> = ({ frame }) => {
  const bandIn = interpolate(frame, [22, 44], [0, 1])
  const slide = interpolate(
    frame,
    [SLIDING_SLIDE_START, SLIDING_SLIDE_END],
    [0, SLIDING_SLIDE_DIST],
  )
  const bandLeft = SLIDING_LEFT + slide
  const bandRight = bandLeft + SLIDING_WINDOW_W
  const slidingLabelOpacity = interpolate(
    frame,
    [SLIDING_SLIDE_START + 10, SLIDING_SLIDE_START + 26],
    [0, 1],
  )

  return (
    <svg viewBox={`0 0 ${VIEW_W} 320`} className="h-auto w-full" role="img">
      <QuotaLabel x={284} bold="60" rest="requests per 120s" />

      <line
        x1={24}
        y1={AXIS_Y}
        x2={544}
        y2={AXIS_Y}
        stroke="var(--border)"
        strokeWidth={2}
      />
      <WindowBand left={bandLeft} right={bandRight} opacity={bandIn} />

      {SLIDING_ARRIVALS.filter((a) => frame >= a.frame).map((a) => {
        const d = dropIn(frame, a.frame)
        const agedOpacity =
          a.x < bandLeft ? interpolate(bandLeft - a.x, [0, 36], [1, 0.25]) : 1
        return (
          <RequestDot
            key={a.x}
            x={a.x}
            y={d.y}
            opacity={d.opacity * agedOpacity}
            emphasized={a.emphasized}
          />
        )
      })}

      <g opacity={bandIn}>
        <text
          x={SLIDING_LEFT}
          y={222}
          textAnchor="middle"
          fontSize={16}
          fill="var(--muted-foreground)"
          className="tabular-nums"
        >
          11:42:31
        </text>
        <text
          x={SLIDING_LEFT}
          y={244}
          textAnchor="middle"
          fontSize={14}
          fill="var(--muted-foreground)"
        >
          first request
        </text>
        <text
          x={bandRight}
          y={222}
          textAnchor="middle"
          fontSize={16}
          fill="var(--muted-foreground)"
          className="tabular-nums"
        >
          +120s
        </text>
      </g>

      <text
        x={bandLeft + SLIDING_WINDOW_W / 2}
        y={118}
        textAnchor="middle"
        fontSize={14}
        fill="var(--muted-foreground)"
        opacity={slidingLabelOpacity}
      >
        window slides with time
      </text>
    </svg>
  )
}

// --- Token bucket ---------------------------------------------------------

const BUCKET_DURATION = 390
const BUCKET_START_TOKENS = 12
const BUCKET_REFILLS = [
  30, 70, 110, 150, 240, 250, 270, 290, 310, 330, 350, 370,
]
const BUCKET_REQUESTS = [
  50, 90, 130, 182, 189, 196, 203, 210, 217, 224, 231, 360,
]
const BUCKET_BURST_START = 182
const BUCKET_BURST_END = 231
const BUCKET_OUT_X = 264

const BUCKET_ROW_YS = [226, 204, 182, 160, 138]
const BUCKET_ROW5 = [212, 238, 264, 290, 316]
const BUCKET_ROW4 = [224, 250, 276, 302]
const BUCKET_SLOTS: Array<{ x: number; y: number }> = []
BUCKET_ROW_YS.forEach((y, row) => {
  const xs = row % 2 === 0 ? BUCKET_ROW5 : BUCKET_ROW4
  xs.forEach((x) => BUCKET_SLOTS.push({ x, y }))
})

const wallLeftX = (y: number) => 180 + 0.1 * (y - 104)
const wallRightX = (y: number) => 348 - 0.1 * (y - 104)

const surfaceYFor = (count: number) => {
  if (count <= 0) return 234
  if (count <= 5) return 212
  if (count <= 9) return 190
  if (count <= 14) return 168
  if (count <= 18) return 146
  return 124
}

const TokenBucketDiagram: React.FC<{ frame: number }> = ({ frame }) => {
  const refills = BUCKET_REFILLS.filter((f) => frame >= f)
  const requests = BUCKET_REQUESTS.filter((f) => frame >= f)
  const tokenCount = Math.max(
    0,
    Math.min(23, BUCKET_START_TOKENS + refills.length - requests.length),
  )
  const surfaceY = surfaceYFor(tokenCount)

  const lastRefill = refills[refills.length - 1]
  const dripT =
    lastRefill === undefined
      ? 1
      : interpolate(frame, [lastRefill, lastRefill + 14], [0, 1])

  const lastRequest = requests[requests.length - 1]
  const outT =
    lastRequest === undefined
      ? 1
      : interpolate(frame, [lastRequest, lastRequest + 16], [0, 1])

  const burstLabelOpacity = interpolate(
    frame,
    [
      BUCKET_BURST_START,
      BUCKET_BURST_START + 10,
      BUCKET_BURST_END + 20,
      BUCKET_BURST_END + 34,
    ],
    [0, 1, 1, 0],
  )

  return (
    <svg viewBox={`0 0 ${VIEW_W} 352`} className="h-auto w-full" role="img">
      {[40, 60, 82].map((cy, i) => (
        <circle
          key={cy}
          cx={244}
          cy={cy}
          r={5.5}
          fill={LIMITED_COLOR}
          fillOpacity={
            [0.35, 0.65, 1][i] *
            (0.4 + 0.6 * Math.sin(Math.PI * Math.min(1, dripT + i * 0.18)))
          }
        />
      ))}

      <text x={268} y={64} fontSize={18}>
        <tspan fontWeight={600} fill="var(--foreground)">
          +10
        </tspan>
        <tspan fill="var(--muted-foreground)"> tokens per 60s</tspan>
      </text>

      <g
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
        strokeWidth={3}
        strokeLinecap="round"
        strokeLinejoin="round"
        fill="none"
      >
        <path d="M 180 104 L 193.2 234 Q 194 244 203 244 L 246 244" />
        <path d="M 348 104 L 334.8 234 Q 334 244 325 244 L 282 244" />
      </g>

      <polygon
        points={`${wallLeftX(surfaceY)},${surfaceY} ${wallRightX(surfaceY)},${surfaceY} ${wallRightX(242)},242 ${wallLeftX(242)},242`}
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
        strokeWidth={2}
      />

      {BUCKET_SLOTS.slice(0, tokenCount).map((slot) => (
        <circle
          key={`${slot.x}-${slot.y}`}
          cx={slot.x}
          cy={slot.y}
          r={6}
          fill={LIMITED_COLOR}
          stroke="var(--background)"
          strokeWidth={3}
        />
      ))}

      <g stroke="var(--muted-foreground)" strokeOpacity={0.4} strokeWidth={2}>
        <line x1={368} y1={104} x2={368} y2={244} />
        <line x1={368} y1={104} x2={360} y2={104} />
        <line x1={368} y1={244} x2={360} y2={244} />
      </g>
      <text x={380} y={166} fontSize={15} fill="var(--muted-foreground)">
        Max bucket size
      </text>
      <text x={380} y={190} fontSize={17}>
        <tspan fontWeight={600} fill="var(--foreground)">
          250
        </tspan>
        <tspan fill="var(--muted-foreground)"> tokens</tspan>
      </text>

      <text
        x={118}
        y={168}
        textAnchor="middle"
        fontSize={14}
        fill={FIREWALL_PASSED_CHART_COLOR}
        opacity={burstLabelOpacity}
      >
        burst spends
      </text>
      <text
        x={118}
        y={188}
        textAnchor="middle"
        fontSize={14}
        fill={FIREWALL_PASSED_CHART_COLOR}
        opacity={burstLabelOpacity}
      >
        saved tokens
      </text>

      <line
        x1={BUCKET_OUT_X}
        y1={254}
        x2={BUCKET_OUT_X}
        y2={272}
        stroke="var(--muted-foreground)"
        strokeOpacity={0.5}
        strokeWidth={2}
      />
      <polygon
        points={`${BUCKET_OUT_X - 7},271 ${BUCKET_OUT_X + 7},271 ${BUCKET_OUT_X},282`}
        fill="var(--muted-foreground)"
        fillOpacity={0.5}
      />
      <circle
        cx={BUCKET_OUT_X}
        cy={interpolate(outT, [0, 1], [288, 300])}
        r={6.5}
        fill={FIREWALL_PASSED_CHART_COLOR}
        stroke="var(--background)"
        strokeWidth={3}
        opacity={interpolate(outT, [0, 0.15, 0.8, 1], [0, 1, 1, 0.9])}
      />
      <text
        x={BUCKET_OUT_X}
        y={326}
        textAnchor="middle"
        fontSize={15}
        fill="var(--muted-foreground)"
      >
        each request takes one token
      </text>
    </svg>
  )
}

// --- Public component -----------------------------------------------------

const STRATEGIES = {
  fixedWindow: {
    duration: FIXED_DURATION,
    restFrame: 160,
    label: 'Fixed window rate limiting',
    Diagram: FixedWindowDiagram,
  },
  slidingWindow: {
    duration: SLIDING_DURATION,
    restFrame: 260,
    label: 'Sliding window rate limiting',
    Diagram: SlidingWindowDiagram,
  },
  tokenBucket: {
    duration: BUCKET_DURATION,
    restFrame: 150,
    label: 'Token bucket rate limiting',
    Diagram: TokenBucketDiagram,
  },
} as const

export type RateLimitStrategyAnimationProps = {
  strategy?: string
}

export function RateLimitStrategyAnimation({
  strategy,
}: RateLimitStrategyAnimationProps) {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const config =
    STRATEGIES[strategy as keyof typeof STRATEGIES] ?? STRATEGIES.fixedWindow
  const frame = useLoopFrame(config.duration, config.restFrame, containerRef)
  const { Diagram } = config

  return (
    <div
      ref={containerRef}
      className="not-prose my-6 overflow-hidden rounded-xl border border-border bg-background"
      role="img"
      aria-label={config.label}
    >
      <div className="mx-auto w-full max-w-[640px] px-6 py-8">
        <Diagram frame={frame} />
      </div>
    </div>
  )
}
