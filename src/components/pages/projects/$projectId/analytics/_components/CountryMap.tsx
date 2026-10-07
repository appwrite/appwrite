import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type MouseEvent,
} from 'react'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { CountryFlag } from './BreakdownRow'
import { formatNumber } from './format'

// ─── Geometry ───────────────────────────────────────────────────────────────
//
// Country shapes come from the Natural Earth file the home page globe already
// ships (`src/data/globe.json`), loaded lazily so the analytics chunk only
// pays for it when the map tab is opened. Projected once with Equal Earth (an
// equal-area projection, so big countries don't overstate their traffic) into
// plain SVG paths: no map library, no tiles.

type Ring = number[][]
type GeoFeature = {
  properties: { ISO_A2?: string; ADM0_A3?: string; ADMIN?: string }
  geometry:
    | { type: 'Polygon'; coordinates: Ring[] }
    | { type: 'MultiPolygon'; coordinates: Ring[][] }
}

type CountryShape = { code: string; d: string }

/** Natural Earth marks a few ISO codes as -99; recover the ones that matter. */
const A3_FALLBACK: Record<string, string> = {
  FRA: 'FR',
  NOR: 'NO',
  KOS: 'XK',
}

// Equal Earth constants (Šavrič, Patterson & Jenny, 2018).
const A1 = 1.340264
const A2 = -0.081106
const A3 = 0.000893
const A4 = 0.003796
const M = Math.sqrt(3) / 2

function equalEarth(lng: number, lat: number): [number, number] {
  const lambda = (lng * Math.PI) / 180
  const theta = Math.asin(M * Math.sin((lat * Math.PI) / 180))
  const t2 = theta * theta
  const t6 = t2 * t2 * t2
  const x =
    (lambda * Math.cos(theta)) / (M * (A1 + 3 * A2 * t2 + t6 * (7 * A3 + 9 * A4 * t2)))
  const y = theta * (A1 + A2 * t2 + t6 * (A3 + A4 * t2))
  return [x, -y]
}

/** Projection origin and scale; the viewBox is fitted to the land drawn. */
const [MIN_X] = equalEarth(-180, 0)
const [MAX_X] = equalEarth(180, 0)
const [, MIN_Y] = equalEarth(0, 84)
const SCALE = 1000 / (MAX_X - MIN_X)

type Bounds = { minX: number; minY: number; maxX: number; maxY: number }

function ringPath(ring: Ring, bounds: Bounds): string {
  let d = ''
  ring.forEach(([lng, lat], index) => {
    const [x, y] = equalEarth(lng, lat)
    const px = (x - MIN_X) * SCALE
    const py = (y - MIN_Y) * SCALE
    bounds.minX = Math.min(bounds.minX, px)
    bounds.maxX = Math.max(bounds.maxX, px)
    bounds.minY = Math.min(bounds.minY, py)
    bounds.maxY = Math.max(bounds.maxY, py)
    d += `${index === 0 ? 'M' : 'L'}${px.toFixed(1)} ${py.toFixed(1)}`
  })
  return `${d}Z`
}

type WorldShapes = { shapes: CountryShape[]; viewBox: string }

/**
 * Projects every country and fits the viewBox to the land actually drawn.
 * Equal Earth's full width is only reached at the equator on the antimeridian,
 * where there's no land, so a projection-sized viewBox leaves the map visibly
 * off-centre.
 */
function toShapes(features: GeoFeature[]): WorldShapes {
  const shapes: CountryShape[] = []
  const bounds: Bounds = {
    minX: Infinity,
    minY: Infinity,
    maxX: -Infinity,
    maxY: -Infinity,
  }
  for (const feature of features) {
    let code = feature.properties.ISO_A2 ?? ''
    if (!/^[A-Z]{2}$/.test(code)) {
      code = A3_FALLBACK[feature.properties.ADM0_A3 ?? ''] ?? ''
    }
    if (code === 'AQ') continue
    const polygons =
      feature.geometry.type === 'Polygon'
        ? [feature.geometry.coordinates]
        : feature.geometry.coordinates
    const d = polygons
      .map((rings) => rings.map((ring) => ringPath(ring, bounds)).join(''))
      .join('')
    shapes.push({ code, d })
  }
  const pad = 2
  const viewBox = [
    bounds.minX - pad,
    bounds.minY - pad,
    bounds.maxX - bounds.minX + pad * 2,
    bounds.maxY - bounds.minY + pad * 2,
  ]
    .map((value) => value.toFixed(1))
    .join(' ')
  return { shapes, viewBox }
}

let shapesPromise: Promise<WorldShapes> | null = null

function loadShapes(): Promise<WorldShapes> {
  shapesPromise ??= import('@/data/globe.json').then((module) => {
    const data = (module.default ?? module) as unknown as {
      features: GeoFeature[]
    }
    return toShapes(data.features)
  })
  return shapesPromise
}

function useCountryShapes(): WorldShapes | null {
  const [shapes, setShapes] = useState<WorldShapes | null>(null)
  useEffect(() => {
    let cancelled = false
    loadShapes()
      .then((result) => {
        if (!cancelled) setShapes(result)
      })
      .catch(() => {
        // Allow a retry on the next mount.
        shapesPromise = null
      })
    return () => {
      cancelled = true
    }
  }, [])
  return shapes
}

// ─── Map ────────────────────────────────────────────────────────────────────

/** Brand purple, same as the humans segment. */
const FILL_COLOR = '#7c67fe'

/**
 * Fill strength for a country. Square-root scale so one dominant country
 * doesn't wash every other one out to the minimum.
 */
function fillFor(value: number, max: number): string {
  if (value <= 0 || max <= 0) return 'var(--muted)'
  const strength = 18 + 82 * Math.sqrt(value / max)
  return `color-mix(in oklch, ${FILL_COLOR} ${strength.toFixed(0)}%, var(--muted))`
}

type Hover = { code: string; x: number; y: number }

export function CountryMap({
  rows,
  height,
  formatLabel,
  onSelect,
  isActive,
}: {
  /** Known `country` rows (ISO-2 values). */
  rows: Models.AnalyticsMetric[]
  /** Fixed height of the area the map fits into. */
  height: number
  formatLabel: (code: string) => string
  /** Toggle a country filter; omitted when filtering isn't available. */
  onSelect?: (code: string) => void
  isActive?: (code: string) => boolean
}) {
  const t = useT()
  const world = useCountryShapes()
  const containerRef = useRef<HTMLDivElement>(null)
  const [hover, setHover] = useState<Hover | null>(null)

  const { byCode, max, total } = useMemo(() => {
    const map = new Map<string, number>()
    let maxValue = 0
    let sum = 0
    for (const row of rows) {
      const code = row.value?.toUpperCase()
      if (!code) continue
      map.set(code, (map.get(code) ?? 0) + row.visitors)
      maxValue = Math.max(maxValue, map.get(code)!)
      sum += row.visitors
    }
    return { byCode: map, max: maxValue, total: sum }
  }, [rows])

  const hovered = hover ? byCode.get(hover.code) ?? 0 : 0

  const trackHover = (code: string, event: MouseEvent) => {
    const box = containerRef.current?.getBoundingClientRect()
    if (!box) return
    setHover({ code, x: event.clientX - box.left, y: event.clientY - box.top })
  }

  return (
    <div
      ref={containerRef}
      className="relative w-full"
      style={{ height }}
      onMouseLeave={() => setHover(null)}
    >
      {world ? (
        <svg
          viewBox={world.viewBox}
          preserveAspectRatio="xMidYMid meet"
          className="h-full w-full"
          role="img"
          aria-label={t('Visitors by country')}
        >
          {world.shapes.map((shape, index) => {
            const value = byCode.get(shape.code) ?? 0
            const clickable = !!onSelect && value > 0 && !!shape.code
            const active = !!shape.code && !!isActive?.(shape.code)
            return (
              <path
                key={`${shape.code}-${index}`}
                d={shape.d}
                fill={fillFor(value, max)}
                stroke={active ? 'var(--foreground)' : 'var(--card)'}
                strokeWidth={active ? 1.5 : 0.5}
                className={cn(
                  'transition-[fill,opacity] duration-300',
                  value > 0 && 'hover:opacity-80',
                  clickable && 'cursor-pointer',
                )}
                onMouseMove={
                  value > 0 ? (event) => trackHover(shape.code, event) : undefined
                }
                onMouseLeave={() => setHover(null)}
                onClick={clickable ? () => onSelect(shape.code) : undefined}
              />
            )
          })}
        </svg>
      ) : (
        <div className="h-full w-full animate-pulse rounded-md bg-muted/50" />
      )}

      {/* Scale */}
      {world && max > 0 ? (
        <div className="pointer-events-none absolute bottom-0 start-0 flex items-center gap-1.5 text-[10px] text-muted-foreground">
          <span>{t('Fewer')}</span>
          <span
            className="h-1.5 w-16 rounded-full"
            style={{
              backgroundImage: `linear-gradient(to right, ${fillFor(1, 100)}, ${fillFor(1, 1)})`,
            }}
          />
          <span>{t('More')}</span>
        </div>
      ) : null}

      {hover && hovered > 0 ? (
        <div
          className="pointer-events-none absolute z-10 flex items-center gap-2 whitespace-nowrap rounded-md border border-border bg-popover px-2.5 py-1.5 text-[12px] text-popover-foreground shadow-md"
          style={{
            left: hover.x,
            top: hover.y,
            transform: `translate(${
              hover.x > (containerRef.current?.clientWidth ?? 0) / 2
                ? 'calc(-100% - 12px)'
                : '12px'
            }, -50%)`,
          }}
        >
          <CountryFlag code={hover.code} />
          <span className="font-medium">{formatLabel(hover.code)}</span>
          <span className="tabular-nums text-muted-foreground">
            {formatNumber(hovered)} {t('visitors')}
            {total > 0 ? ` · ${Math.round((hovered / total) * 100)}%` : ''}
          </span>
        </div>
      ) : null}
    </div>
  )
}
