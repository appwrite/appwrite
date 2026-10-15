'use client'

import React, { useEffect, useRef, useState } from 'react'

const FPS = 30
const ACCENT = 'var(--brand-cta)'

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

/** Looping animation clock, frozen at restFrame for reduced motion or off screen. */
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

// Scene: one query, two acts. Act 1 scans the table row by row. Act 2 walks
// the index, the same ids kept sorted, halving the candidates each step.
const VIEW_W = 680
const VIEW_H = 268
const SOFT = 0.14

const QUERY = 'WHERE id = 71'
const ROWS = [12, 88, 37, 50, 62, 25, 94, 71, 45, 19]
const SORTED = [...ROWS].sort((a, b) => a - b)
const TARGET = 71
const MATCH_ROW = ROWS.indexOf(TARGET)
const MATCH_IDX = SORTED.indexOf(TARGET)

const ROW_H = 18
const TOP = 74
const COL_W = 150
const GAP = 110 // holds the scan tags
const LEFT_X = (VIEW_W - (COL_W * 2 + GAP)) / 2
const RIGHT_X = LEFT_X + COL_W + GAP
const COUNTER_W = GAP - 30

// Timeline (frames at 30fps)
const DURATION = 440
const ACT1 = 20
const STEP = 14
const ACT2 = 200
const S1 = 220 // look at the middle (50)
const S1_CUT = 258 // drop 12..50
const S2 = 280 // look at the middle of the rest (88)
const S2_CUT = 318 // drop 88..94
const S3 = 340 // 71 found
const ARROW = 362
const RESET = 424

const rowY = (i: number) => TOP + i * ROW_H

const Row: React.FC<{
  x: number
  y: number
  id: number
  state: 'idle' | 'visited' | 'active' | 'match' | 'dropped'
}> = ({ x, y, id, state }) => {
  const accent = state === 'active' || state === 'match'
  return (
    <g opacity={state === 'dropped' ? 0.25 : 1}>
      <rect
        x={x}
        y={y}
        width={COL_W}
        height={ROW_H - 4}
        rx={4}
        fill={accent ? ACCENT : 'var(--muted-foreground)'}
        fillOpacity={accent ? SOFT : state === 'visited' ? 0.16 : 0.07}
        stroke={state === 'active' ? ACCENT : 'none'}
        strokeWidth={1.5}
      />
      <text
        x={x + 10}
        y={y + 11}
        fontSize={11}
        fill={state === 'match' ? ACCENT : 'var(--foreground)'}
        fontWeight={state === 'match' ? 600 : 400}
        className="font-mono"
      >
        {`id ${id}`}
      </text>
    </g>
  )
}

const Tag: React.FC<{
  x: number
  y: number
  text: string
  accent?: boolean
  opacity?: number
}> = ({ x, y, text, accent, opacity = 1 }) => (
  <text
    x={x}
    y={y}
    fontSize={11}
    fill={accent ? ACCENT : 'var(--muted-foreground)'}
    fontWeight={accent ? 600 : 400}
    opacity={opacity}
  >
    {text}
  </text>
)

const Counter: React.FC<{
  x: number
  value: number
  opacity?: number
  accent?: boolean
}> = ({ x, value, opacity = 1, accent = false }) => (
  <text
    x={x}
    y={52}
    fontSize={13}
    textAnchor="end"
    className="tabular-nums"
    opacity={opacity}
  >
    <tspan
      fontWeight={600}
      fill={accent && value === 1 ? ACCENT : 'var(--foreground)'}
    >
      {value}
    </tspan>
    <tspan fill="var(--muted-foreground)">
      {value === 1 ? ' row read' : ' rows read'}
    </tspan>
  </text>
)

const IndexLookupDiagram: React.FC<{ frame: number }> = ({ frame }) => {
  const fade = interpolate(frame, [RESET, DURATION], [1, 0])

  // Act 1: scan
  const scanned = Math.min(
    ROWS.length,
    Math.max(0, Math.floor((frame - ACT1) / STEP) + 1),
  )
  const cursor = Math.min(ROWS.length - 1, scanned - 1)
  const act1Alive = frame >= ACT1 && frame < ACT2
  const leftOpacity =
    frame >= ACT2 ? interpolate(frame, [ACT2, ACT2 + 12], [1, 0.45]) : 1
  const rightOpacity = interpolate(frame, [ACT2 - 12, ACT2], [0.3, 1])

  // Act 2: index walk
  const lo = MATCH_IDX // 6 (0-based) in SORTED: 12 19 25 37 45 50 62 71 88 94
  const step1 = frame >= S1 ? 1 : 0
  const cut1 = frame >= S1_CUT ? 1 : 0
  const step2 = frame >= S2 ? 1 : 0
  const cut2 = frame >= S2_CUT ? 1 : 0
  const found = frame >= S3 ? 1 : 0
  const arrow = interpolate(frame, [ARROW, ARROW + 22], [0, 1])
  const indexRows = found ? 1 : 0

  const sortedState = (i: number): 'idle' | 'active' | 'match' | 'dropped' => {
    if (found && i === lo) return 'match'
    if (cut1 && i <= 5) return 'dropped'
    if (cut2 && i >= 8) return 'dropped'
    if (step2 && !cut2 && i === 8) return 'active'
    if (step1 && !cut1 && i === 5) return 'active'
    return 'idle'
  }

  // arrow from sorted 71 to table row 71
  const ax1 = RIGHT_X
  const ay = rowY(lo) + (ROW_H - 4) / 2
  const ax2 = LEFT_X + COL_W
  const by = rowY(MATCH_ROW) + (ROW_H - 4) / 2
  const arrowLen = 220

  return (
    <svg
      viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
      className="h-auto w-full"
      role="img"
    >
      <g opacity={fade}>
        <text
          x={VIEW_W / 2}
          y={26}
          fontSize={14}
          textAnchor="middle"
          fill="var(--foreground)"
          className="font-mono"
        >
          {QUERY}
        </text>

        {/* Act 1: table scan */}
        <g opacity={leftOpacity}>
          <text
            x={LEFT_X}
            y={52}
            fontSize={13}
            fontWeight={600}
            fill="var(--foreground)"
          >
            Table, no index
          </text>
          <Counter x={LEFT_X + COL_W + COUNTER_W} value={scanned} />
          {ROWS.map((id, i) => {
            const state =
              frame >= ACT2 && i === MATCH_ROW && arrow > 0
                ? 'match'
                : i === MATCH_ROW && scanned > i
                  ? 'match'
                  : act1Alive && i === cursor
                    ? 'active'
                    : i < scanned
                      ? 'visited'
                      : 'idle'
            return <Row key={id} x={LEFT_X} y={rowY(i)} id={id} state={state} />
          })}
          {ROWS.map((id, i) =>
            i < scanned && !(i === MATCH_ROW && arrow > 0) ? (
              <Tag
                key={id}
                x={LEFT_X + COL_W + 8}
                y={rowY(i) + 11}
                text={i === MATCH_ROW ? 'match' : 'no'}
                accent={i === MATCH_ROW}
              />
            ) : null,
          )}
        </g>

        {/* Act 2: index walk */}
        <g opacity={rightOpacity}>
          <text
            x={RIGHT_X}
            y={52}
            fontSize={13}
            fontWeight={600}
            fill="var(--foreground)"
          >
            Index, same ids sorted
          </text>
          <Counter
            x={RIGHT_X + COL_W + COUNTER_W}
            accent
            value={indexRows}
            opacity={frame >= ACT2 ? 1 : 0}
          />
          {SORTED.map((id, i) => (
            <Row
              key={id}
              x={RIGHT_X}
              y={rowY(i)}
              id={id}
              state={frame >= ACT2 ? sortedState(i) : 'idle'}
            />
          ))}
          <Tag
            x={RIGHT_X + COL_W + 8}
            y={rowY(5) + 11}
            text="middle is 50, 71 is bigger"
            opacity={step1 && !cut1 ? 1 : 0}
          />
          <Tag
            x={RIGHT_X + COL_W + 8}
            y={rowY(2) + 11}
            text="skip this half"
            opacity={cut1 ? 1 : 0}
          />
          <Tag
            x={RIGHT_X + COL_W + 8}
            y={rowY(8) + 11}
            text="middle is 88, 71 is smaller"
            opacity={step2 && !cut2 ? 1 : 0}
          />
          <Tag
            x={RIGHT_X + COL_W + 8}
            y={rowY(9) + 11}
            text="skip"
            opacity={cut2 ? 1 : 0}
          />
          <Tag
            x={RIGHT_X + COL_W + 8}
            y={rowY(lo) + 11}
            text="match"
            accent
            opacity={found}
          />
        </g>

        <path
          d={`M ${ax1} ${ay} C ${ax1 - 40} ${ay}, ${ax2 + 40} ${by}, ${ax2 + 3} ${by}`}
          fill="none"
          stroke={ACCENT}
          strokeWidth={2}
          strokeDasharray={arrowLen}
          strokeDashoffset={arrowLen * (1 - arrow)}
        />
      </g>
    </svg>
  )
}

export function IndexLookupAnimation() {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const frame = useLoopFrame(DURATION, ARROW + 30, containerRef)
  return (
    <div
      ref={containerRef}
      className="not-prose my-6 overflow-hidden rounded-xl border border-border bg-background"
      aria-label="The same lookup done as a table scan, checking every row, and as an index walk over the sorted ids, halving the candidates three times and reading one row"
    >
      <div className="mx-auto flex w-full max-w-[640px] items-center px-6 py-5">
        <IndexLookupDiagram frame={frame} />
      </div>
    </div>
  )
}
