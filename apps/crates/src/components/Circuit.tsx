import { useEffect, useRef, type CSSProperties } from 'react'

/**
 * The circuitry behind the hero: one 280px tile of traces, each running in from
 * the tile's edge to a via, repeated and faded out towards the middle. On some
 * of them current flows: a short pulse runs along the trace and the via flares
 * as it arrives, fast and every few seconds, now and then in the accent, and only
 * out towards the edges, clear of the copy. It stands still under reduced motion,
 * and pauses while the hero is out of view.
 */
export const TILE = 280
export const TRACES: { d: string; via?: [number, number] }[] = [
  { d: 'M0 40h70l20 20h60', via: [154, 60] },
  { d: 'M280 120h-70l-20-20h-36', via: [150, 100] },
  { d: 'M60 280v-70l20-20v-36', via: [80, 150] },
  { d: 'M200 0v50l-20 20v36', via: [180, 110] },
  { d: 'M0 220h40l20-20h50l20 20h36', via: [170, 220] },
  { d: 'M234 280v-36l20-20h26' },
  { d: 'M120 0v20l-16 16H70', via: [66, 36] },
]

// Enough tiles for the widest hero; the box clips the rest.
const COLUMNS = 9
const ROWS = 2

/** A stable spread of numbers, so the server and the browser draw the same currents. */
function hash(n: number) {
  let x = Math.imul(n ^ (n >>> 16), 0x45d9f3b)
  x = Math.imul(x ^ (x >>> 16), 0x45d9f3b)
  return (x ^ (x >>> 16)) >>> 0
}

/** A quarter of the traces carry current, each on its own clock; one in five runs hot. */
const CURRENTS = Array.from({ length: COLUMNS * ROWS * TRACES.length }, (_, n) => {
  const h = hash(n + 1)
  if (h % 4) return null
  const trace = TRACES[n % TRACES.length]
  const tile = Math.floor(n / TRACES.length)
  const seconds = 3.5 + (h % 7) / 2
  return {
    key: n,
    trace,
    x: (tile % COLUMNS) * TILE,
    y: Math.floor(tile / COLUMNS) * TILE,
    hot: h % 5 === 0,
    // A negative delay starts each clock part way through, so the currents never set off together.
    style: { '--t': `${seconds}s`, '--d': `${-((h >>> 8) % 1000) / 100}s` } as CSSProperties,
  }
}).filter((c) => c !== null)

export function Circuit({ className = '' }: { className?: string }) {
  const ref = useRef<SVGSVGElement>(null)
  useEffect(() => {
    const svg = ref.current
    if (!svg) return
    const observer = new IntersectionObserver(([entry]) => svg.toggleAttribute('data-idle', !entry.isIntersecting))
    observer.observe(svg)
    return () => observer.disconnect()
  }, [])
  return (
    <svg ref={ref} className={`circuit ${className}`} aria-hidden>
      <defs>
        <pattern id="circuit-tile" width={TILE} height={TILE} patternUnits="userSpaceOnUse">
          {TRACES.map((t) => (
            <path key={t.d} className="trace" d={t.d} />
          ))}
          {TRACES.flatMap((t) => (t.via ? [<circle key={t.d} className="trace" cx={t.via[0]} cy={t.via[1]} r={3.5} />] : []))}
        </pattern>
        {/* Current runs only out towards the edges: hidden over the copy in the middle. */}
        <radialGradient id="circuit-fade" cx="0.5" cy="0.45" r="0.5">
          <stop offset="0.6" stopColor="#000" />
          <stop offset="1" stopColor="#fff" />
        </radialGradient>
        <mask id="circuit-edges">
          <rect width="100%" height="100%" fill="url(#circuit-fade)" />
        </mask>
      </defs>
      <rect width="100%" height="100%" fill="url(#circuit-tile)" />
      <g mask="url(#circuit-edges)">
        {CURRENTS.map(({ key, trace, x, y, hot, style }) => (
          <g key={key} transform={`translate(${x} ${y})`} className={hot ? 'hot' : undefined} style={style}>
            <path className="current glow" d={trace.d} pathLength={1} />
            <path className="current" d={trace.d} pathLength={1} />
            {trace.via ? <circle className="spark" cx={trace.via[0]} cy={trace.via[1]} r={3.5} /> : null}
          </g>
        ))}
      </g>
    </svg>
  )
}
