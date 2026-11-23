'use client'

import React, { useEffect, useRef, useState } from 'react'

// I/O bound benchmark numbers from the post. Ball speed is proportional to
// requests/sec; the latency probe replays the measured p95 in real
// wall-clock milliseconds.
const METRICS = {
  a: { rps: 462, p95: 854.45 },
  b: { rps: 3346, p95: 51.56 },
} as const

// The faster lane crosses the track in this many seconds; the other lane
// scales by the real throughput ratio.
const FASTEST_CROSS_SEC = 0.55

const VIEW_W = 568
const VIEW_H = 316
const LABEL_W = 128
const TRACK_X = 148
const TRACK_R = 552
const TRACK_W = TRACK_R - TRACK_X
const TRACK_H = 64
const BALL_R = 11
const LANES = {
  a: { trackY: 42, probeY: 130 },
  b: { trackY: 172, probeY: 260 },
} as const

function clamp01(t: number): number {
  return t < 0 ? 0 : t > 1 ? 1 : t
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
 * Milliseconds elapsed since mount, advancing only while the element is in
 * the viewport. Frozen at restMs when reduced motion is requested.
 */
function useElapsedMs(
  containerRef: React.RefObject<HTMLDivElement | null>,
  restMs: number,
): number {
  const reduced = usePrefersReducedMotion()
  const [elapsed, setElapsed] = useState(0)
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
      setElapsed(restMs)
      return
    }
    let raf = 0
    let accumulated = 0
    let last: number | null = null
    const tick = (now: number) => {
      if (last !== null && visibleRef.current) {
        accumulated += now - last
        setElapsed(accumulated)
      }
      last = now
      raf = requestAnimationFrame(tick)
    }
    raf = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(raf)
  }, [reduced, restMs])

  return elapsed
}

type LaneProps = {
  which: 'a' | 'b'
  elapsedMs: number
  rps: number
  crossSec: number
  probeStartMs: number | null
  p95: number
  reduced: boolean
}

const Lane: React.FC<LaneProps> = ({
  which,
  elapsedMs,
  rps,
  crossSec,
  probeStartMs,
  p95,
  reduced,
}) => {
  const { trackY, probeY } = LANES[which]
  const color = which === 'b' ? 'var(--brand-cta)' : 'var(--muted-foreground)'
  const centerY = trackY + TRACK_H / 2

  // Ping-pong between the track walls at a speed set by requests/sec.
  const minX = TRACK_X + BALL_R + 6
  const maxX = TRACK_R - BALL_R - 6
  const cycle = (elapsedMs / 1000 / crossSec) % 2
  const p = cycle <= 1 ? cycle : 2 - cycle
  const ballX = minX + p * (maxX - minX)

  // Squash on impact, stretch in flight.
  const distToWall = Math.min(p, 1 - p)
  const squash = 0.72 + clamp01(distToWall / 0.06) * 0.28
  const stretch = 1 / squash

  const count = Math.floor((elapsedMs / 1000) * rps)

  // Latency probe: one request crossing the sub-track in real p95 time.
  const probeT =
    probeStartMs === null
      ? null
      : reduced
        ? 1
        : clamp01((elapsedMs - probeStartMs) / p95)
  const probeDone = probeT === 1

  return (
    <g>
      <rect
        x={0}
        y={trackY}
        width={LABEL_W}
        height={TRACK_H}
        rx={10}
        fill={which === 'b' ? 'var(--brand-cta)' : 'var(--secondary)'}
      />
      <text
        x={16}
        y={trackY + 27}
        fontSize={15}
        fontWeight={600}
        fill={which === 'b' ? 'var(--brand-cta-foreground)' : 'var(--foreground)'}
      >
        {which === 'b' ? 'Hyperloop B' : 'Hyperloop A'}
      </text>
      <text
        x={16}
        y={trackY + 48}
        fontSize={13}
        fill={which === 'b' ? 'var(--brand-cta-foreground)' : 'var(--muted-foreground)'}
        opacity={which === 'b' ? 0.85 : 1}
      >
        {which === 'b' ? 'Appwrite 2.0' : 'Appwrite 1.x'}
      </text>

      <rect
        x={TRACK_X}
        y={trackY}
        width={TRACK_W}
        height={TRACK_H}
        rx={10}
        fill="var(--secondary)"
        fillOpacity={0.35}
        stroke="var(--border)"
        strokeWidth={1.5}
      />
      <g transform={`translate(${ballX} ${centerY}) scale(${squash} ${stretch})`}>
        <circle r={BALL_R} fill={color} />
      </g>

      <text
        x={TRACK_R}
        y={trackY - 10}
        textAnchor="end"
        fontSize={13}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        <tspan fontWeight={600} fill="var(--foreground)">
          {count.toLocaleString('en-US')}
        </tspan>
        {' requests'}
      </text>
      <text
        x={TRACK_X}
        y={trackY - 10}
        fontSize={13}
        fill="var(--muted-foreground)"
        className="tabular-nums"
      >
        {rps.toLocaleString('en-US')} req/s
      </text>

      {probeT !== null ? (
        <g>
          <line
            x1={TRACK_X}
            y1={probeY}
            x2={TRACK_R}
            y2={probeY}
            stroke="var(--border)"
            strokeWidth={1.5}
          />
          <line
            x1={TRACK_X}
            y1={probeY}
            x2={TRACK_X + probeT * TRACK_W}
            y2={probeY}
            stroke={color}
            strokeWidth={2.5}
          />
          <circle
            cx={TRACK_X + probeT * TRACK_W}
            cy={probeY}
            r={5.5}
            fill={color}
            stroke="var(--background)"
            strokeWidth={2.5}
          />
          <text
            x={TRACK_X - 20}
            y={probeY + 4}
            textAnchor="end"
            fontSize={13}
            fill="var(--muted-foreground)"
          >
            p95
          </text>
          <text
            x={TRACK_R}
            y={probeY - 12}
            textAnchor="end"
            fontSize={13}
            className="tabular-nums"
            fill={probeDone ? 'var(--foreground)' : 'var(--muted-foreground)'}
            fontWeight={probeDone ? 600 : 400}
          >
            {probeDone
              ? `${p95.toFixed(2)}ms`
              : `${Math.round(elapsedMs - probeStartMs!)}ms`}
          </text>
        </g>
      ) : null}
    </g>
  )
}

const RaceScene: React.FC<{
  containerRef: React.RefObject<HTMLDivElement | null>
}> = ({ containerRef }) => {
  const reduced = usePrefersReducedMotion()
  const elapsedMs = useElapsedMs(containerRef, 2437)
  const [probeStartMs, setProbeStartMs] = useState<number | null>(null)
  const { a, b } = METRICS

  const maxRps = Math.max(a.rps, b.rps)
  const crossSec = (rps: number) => (FASTEST_CROSS_SEC * maxRps) / rps

  return (
    <>
      <svg
        viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
        className="h-auto w-full"
        role="img"
        aria-label={`Hyperloop A serves ${a.rps.toLocaleString('en-US')} requests per second at a p95 of ${a.p95}ms, Hyperloop B serves ${b.rps.toLocaleString('en-US')} at ${b.p95}ms on the I/O bound workload.`}
      >
        <Lane
          which="a"
          elapsedMs={elapsedMs}
          rps={a.rps}
          crossSec={crossSec(a.rps)}
          probeStartMs={probeStartMs}
          p95={a.p95}
          reduced={reduced}
        />
        <Lane
          which="b"
          elapsedMs={elapsedMs}
          rps={b.rps}
          crossSec={crossSec(b.rps)}
          probeStartMs={probeStartMs}
          p95={b.p95}
          reduced={reduced}
        />
      </svg>
      <div className="mt-2 flex items-center justify-center gap-3">
        <button
          type="button"
          onClick={() => setProbeStartMs(elapsedMs)}
          className="rounded-lg bg-[var(--brand-cta)] px-4 py-2 text-[13px] font-medium text-white transition-transform hover:opacity-90 active:scale-[0.97]"
        >
          Send a request
        </button>
        <span className="text-[12px] text-muted-foreground">
          replays the measured p95 in real time
        </span>
      </div>
    </>
  )
}

export function HyperloopRaceAnimation() {
  const containerRef = useRef<HTMLDivElement | null>(null)

  return (
    <div
      ref={containerRef}
      className="not-prose my-6 overflow-hidden rounded-xl border border-border bg-background"
      aria-label="Hyperloop A versus Hyperloop B throughput race"
    >
      <div className="mx-auto w-full max-w-[640px] px-6 pb-6 pt-6">
        <RaceScene containerRef={containerRef} />
      </div>
    </div>
  )
}
