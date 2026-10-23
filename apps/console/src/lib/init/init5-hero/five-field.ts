import { getInit5FiveGlyphPoints, INIT5_FIVE_GLYPH_BOUNDS } from './five-glyph'

/**
 * Geometry helpers for the Init 5 hero "5".
 *
 * "Stage units": the glyph box is centered on the origin, its height is 1 and
 * y points up (three.js convention). Every hero scene works in these units and
 * scales a root group to fit the layout anchor.
 */

export type Vec2 = { x: number; y: number }

const BOUNDS = INIT5_FIVE_GLYPH_BOUNDS
const GLYPH_HEIGHT = BOUNDS.maxY - BOUNDS.minY
const GLYPH_CENTER_X = (BOUNDS.minX + BOUNDS.maxX) / 2
const GLYPH_CENTER_Y = (BOUNDS.minY + BOUNDS.maxY) / 2

/** Width / height of the glyph box. */
export const FIVE_ASPECT = (BOUNDS.maxX - BOUNDS.minX) / GLYPH_HEIGHT

function signedArea(points: Vec2[]) {
  let area = 0
  for (let i = 0; i < points.length; i++) {
    const a = points[i]
    const b = points[(i + 1) % points.length]
    area += a.x * b.y - b.x * a.y
  }
  return area / 2
}

let cachedContour: Vec2[] | null = null

/** The traced outline in stage units, counter-clockwise, starting at the top-left corner. */
export function getFiveContour(): Vec2[] {
  if (cachedContour) return cachedContour
  const points = getInit5FiveGlyphPoints().map(([x, y]) => ({
    x: (x - GLYPH_CENTER_X) / GLYPH_HEIGHT,
    y: -(y - GLYPH_CENTER_Y) / GLYPH_HEIGHT,
  }))
  if (signedArea(points) < 0) {
    // Keep the top-left corner as the start point after flipping winding.
    const [first, ...rest] = points
    points.splice(0, points.length, first, ...rest.reverse())
  }
  cachedContour = points
  return points
}

function reflect(anchor: Vec2, other: Vec2): Vec2 {
  return { x: anchor.x * 2 - other.x, y: anchor.y * 2 - other.y }
}

/** Centripetal Catmull-Rom (alpha 0.5) - no cusps or overshoot on uneven spacing. */
function catmullRom(p0: Vec2, p1: Vec2, p2: Vec2, p3: Vec2, t: number): Vec2 {
  const knot = (a: Vec2, b: Vec2) =>
    Math.max(1e-6, Math.sqrt(Math.hypot(b.x - a.x, b.y - a.y)))
  const t0 = 0
  const t1 = t0 + knot(p0, p1)
  const t2 = t1 + knot(p1, p2)
  const t3 = t2 + knot(p2, p3)
  const u = t1 + (t2 - t1) * t

  const lerp = (a: Vec2, b: Vec2, ta: number, tb: number) => {
    const w = (u - ta) / (tb - ta)
    return { x: a.x + (b.x - a.x) * w, y: a.y + (b.y - a.y) * w }
  }

  const a1 = lerp(p0, p1, t0, t1)
  const a2 = lerp(p1, p2, t1, t2)
  const a3 = lerp(p2, p3, t2, t3)
  const b1 = lerp(a1, a2, t0, t2)
  const b2 = lerp(a2, a3, t1, t3)
  return lerp(b1, b2, t1, t2)
}

/**
 * Subdivide the polygon so curves read smooth at hero scale while straight
 * edges and corners (bar, stem, terminal cut) stay crisp.
 */
export function refineContour(
  points: Vec2[],
  maxSegment: number,
  cornerAngleDeg = 38,
): Vec2[] {
  const count = points.length
  const cornerCos = Math.cos((cornerAngleDeg * Math.PI) / 180)
  const corner = points.map((point, index) => {
    const prev = points[(index - 1 + count) % count]
    const next = points[(index + 1) % count]
    const ax = point.x - prev.x
    const ay = point.y - prev.y
    const bx = next.x - point.x
    const by = next.y - point.y
    const dot =
      (ax * bx + ay * by) /
      ((Math.hypot(ax, ay) || 1) * (Math.hypot(bx, by) || 1))
    return dot < cornerCos
  })

  const out: Vec2[] = []
  for (let i = 0; i < count; i++) {
    const p1 = points[i]
    const p2 = points[(i + 1) % count]
    const length = Math.hypot(p2.x - p1.x, p2.y - p1.y)
    const steps = Math.max(1, Math.ceil(length / maxSegment))
    const p0 = corner[i] ? reflect(p1, p2) : points[(i - 1 + count) % count]
    const p3 = corner[(i + 1) % count]
      ? reflect(p2, p1)
      : points[(i + 2) % count]
    for (let step = 0; step < steps; step++) {
      out.push(
        step === 0 ? { ...p1 } : catmullRom(p0, p1, p2, p3, step / steps),
      )
    }
  }
  return out
}

/** Chaikin corner cutting for closed loops (used on SDF iso-lines). */
export function chaikinClosed(points: Vec2[], iterations = 1): Vec2[] {
  let current = points
  for (let iteration = 0; iteration < iterations; iteration++) {
    const next: Vec2[] = []
    for (let i = 0; i < current.length; i++) {
      const a = current[i]
      const b = current[(i + 1) % current.length]
      next.push(
        { x: a.x * 0.75 + b.x * 0.25, y: a.y * 0.75 + b.y * 0.25 },
        { x: a.x * 0.25 + b.x * 0.75, y: a.y * 0.25 + b.y * 0.75 },
      )
    }
    current = next
  }
  return current
}

export type ResampledLoop = {
  points: Vec2[]
  /** Normalized arc length (0-1) for each point. */
  arc: number[]
  length: number
}

/** Resample a closed loop at (approximately) uniform arc-length spacing. */
export function resampleClosed(points: Vec2[], spacing: number): ResampledLoop {
  const cumulative: number[] = [0]
  for (let i = 1; i <= points.length; i++) {
    const a = points[i - 1]
    const b = points[i % points.length]
    cumulative.push(cumulative[i - 1] + Math.hypot(b.x - a.x, b.y - a.y))
  }
  const length = cumulative[cumulative.length - 1]
  const count = Math.max(8, Math.round(length / spacing))
  const out: Vec2[] = []
  const arc: number[] = []
  let segment = 0
  for (let i = 0; i < count; i++) {
    const target = (i / count) * length
    while (segment < points.length - 1 && cumulative[segment + 1] < target)
      segment++
    const a = points[segment]
    const b = points[(segment + 1) % points.length]
    const span = cumulative[segment + 1] - cumulative[segment] || 1
    const w = (target - cumulative[segment]) / span
    out.push({ x: a.x + (b.x - a.x) * w, y: a.y + (b.y - a.y) * w })
    arc.push(i / count)
  }
  return { points: out, arc, length }
}

/** Deterministic PRNG (mulberry32) so every replay looks identical. */
export function createRandom(seed = 0x5eed) {
  let state = seed >>> 0
  return () => {
    state = (state + 0x6d2b79f5) >>> 0
    let t = state
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

export type FiveField = {
  resolution: number
  /** Side of the square sampling domain, in stage units. */
  domain: number
  /** Signed distance to the outline in stage units (negative inside). */
  sdf: Float32Array
  /** Bilinear SDF lookup in stage units. */
  sample: (x: number, y: number) => number
}

const EDT_INF = 1e20

function edt1d(
  f: Float64Array,
  d: Float64Array,
  v: Int32Array,
  z: Float64Array,
  n: number,
) {
  let k = 0
  v[0] = 0
  z[0] = -EDT_INF
  z[1] = EDT_INF
  for (let q = 1; q < n; q++) {
    let s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k])
    while (s <= z[k]) {
      k--
      s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k])
    }
    k++
    v[k] = q
    z[k] = s
    z[k + 1] = EDT_INF
  }
  k = 0
  for (let q = 0; q < n; q++) {
    while (z[k + 1] < q) k++
    const dq = q - v[k]
    d[q] = dq * dq + f[v[k]]
  }
}

/** Felzenszwalb squared Euclidean distance transform, in place. */
function edt2d(grid: Float64Array, width: number, height: number) {
  const n = Math.max(width, height)
  const f = new Float64Array(n)
  const d = new Float64Array(n)
  const v = new Int32Array(n)
  const z = new Float64Array(n + 1)
  for (let x = 0; x < width; x++) {
    for (let y = 0; y < height; y++) f[y] = grid[y * width + x]
    edt1d(f, d, v, z, height)
    for (let y = 0; y < height; y++) grid[y * width + x] = d[y]
  }
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) f[x] = grid[y * width + x]
    edt1d(f, d, v, z, width)
    for (let x = 0; x < width; x++) grid[y * width + x] = d[x]
  }
}

const fieldCache = new Map<string, FiveField>()

/** Rasterize the 5 and build a signed distance field (cached per resolution). */
export function buildFiveField(resolution = 512, domain = 1.4): FiveField {
  const cacheKey = `${resolution}:${domain}`
  const cached = fieldCache.get(cacheKey)
  if (cached) return cached

  const canvas = document.createElement('canvas')
  canvas.width = resolution
  canvas.height = resolution
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) throw new Error('2D canvas unavailable for Init 5 field')

  const scale = resolution / domain
  const half = domain / 2
  ctx.fillStyle = '#fff'
  ctx.beginPath()
  getFiveContour().forEach((point, index) => {
    const px = (point.x + half) * scale
    const py = (half - point.y) * scale
    if (index === 0) ctx.moveTo(px, py)
    else ctx.lineTo(px, py)
  })
  ctx.closePath()
  ctx.fill()

  const pixels = ctx.getImageData(0, 0, resolution, resolution).data
  const total = resolution * resolution
  const toInside = new Float64Array(total)
  const toOutside = new Float64Array(total)
  const alpha = new Float32Array(total)
  for (let i = 0; i < total; i++) {
    const a = pixels[i * 4 + 3] / 255
    alpha[i] = a
    const inside = a >= 0.5
    toInside[i] = inside ? 0 : EDT_INF
    toOutside[i] = inside ? EDT_INF : 0
  }
  edt2d(toInside, resolution, resolution)
  edt2d(toOutside, resolution, resolution)

  const sdf = new Float32Array(total)
  for (let i = 0; i < total; i++) {
    const a = alpha[i]
    let px: number
    if (a > 0.02 && a < 0.98) {
      px = 0.5 - a
    } else if (a >= 0.5) {
      px = -(Math.sqrt(toOutside[i]) - 0.5)
    } else {
      px = Math.sqrt(toInside[i]) - 0.5
    }
    sdf[i] = px / scale
  }

  const sample = (x: number, y: number) => {
    const fx = (x + half) * scale - 0.5
    const fy = (half - y) * scale - 0.5
    const x0 = Math.floor(fx)
    const y0 = Math.floor(fy)
    if (x0 < 0 || y0 < 0 || x0 >= resolution - 1 || y0 >= resolution - 1)
      return half
    const tx = fx - x0
    const ty = fy - y0
    const i = y0 * resolution + x0
    const top = sdf[i] * (1 - tx) + sdf[i + 1] * tx
    const bottom = sdf[i + resolution] * (1 - tx) + sdf[i + resolution + 1] * tx
    return top * (1 - ty) + bottom * ty
  }

  const field: FiveField = { resolution, domain, sdf, sample }
  fieldCache.set(cacheKey, field)
  return field
}

/**
 * Marching squares on the SDF at `level` (negative = inset, positive = outset).
 * Returns closed loops in stage units, longest first.
 */
export function extractIsoContours(field: FiveField, level: number): Vec2[][] {
  const { resolution: res, sdf, domain } = field
  const scale = res / domain
  const half = domain / 2
  const value = (x: number, y: number) =>
    x < 0 || y < 0 || x >= res || y >= res ? half : sdf[y * res + x]

  const points = new Map<number, Vec2>()
  const adjacency = new Map<number, number[]>()

  const edgePoint = (
    key: number,
    x0: number,
    y0: number,
    x1: number,
    y1: number,
  ) => {
    if (!points.has(key)) {
      const a = value(x0, y0)
      const b = value(x1, y1)
      const t = Math.abs(b - a) < 1e-12 ? 0.5 : (level - a) / (b - a)
      const px = x0 + (x1 - x0) * t
      const py = y0 + (y1 - y0) * t
      points.set(key, {
        x: (px + 0.5) / scale - half,
        y: half - (py + 0.5) / scale,
      })
    }
    return key
  }
  const link = (a: number, b: number) => {
    let listA = adjacency.get(a)
    if (!listA) adjacency.set(a, (listA = []))
    let listB = adjacency.get(b)
    if (!listB) adjacency.set(b, (listB = []))
    listA.push(b)
    listB.push(a)
  }

  const stride = res + 2
  const hKey = (x: number, y: number) => ((y + 1) * stride + (x + 1)) * 2
  const vKey = (x: number, y: number) => ((y + 1) * stride + (x + 1)) * 2 + 1

  for (let y = -1; y < res; y++) {
    for (let x = -1; x < res; x++) {
      const tl = value(x, y) < level ? 1 : 0
      const tr = value(x + 1, y) < level ? 1 : 0
      const br = value(x + 1, y + 1) < level ? 1 : 0
      const bl = value(x, y + 1) < level ? 1 : 0
      const index = tl * 8 + tr * 4 + br * 2 + bl
      if (index === 0 || index === 15) continue

      const top = () => edgePoint(hKey(x, y), x, y, x + 1, y)
      const bottom = () => edgePoint(hKey(x, y + 1), x, y + 1, x + 1, y + 1)
      const left = () => edgePoint(vKey(x, y), x, y, x, y + 1)
      const right = () => edgePoint(vKey(x + 1, y), x + 1, y, x + 1, y + 1)
      const centerInside =
        (value(x, y) +
          value(x + 1, y) +
          value(x + 1, y + 1) +
          value(x, y + 1)) /
          4 <
        level

      switch (index) {
        case 1:
        case 14:
          link(left(), bottom())
          break
        case 2:
        case 13:
          link(bottom(), right())
          break
        case 3:
        case 12:
          link(left(), right())
          break
        case 4:
        case 11:
          link(top(), right())
          break
        case 6:
        case 9:
          link(top(), bottom())
          break
        case 7:
        case 8:
          link(left(), top())
          break
        case 5:
          if (centerInside) {
            link(left(), top())
            link(bottom(), right())
          } else {
            link(left(), bottom())
            link(top(), right())
          }
          break
        case 10:
          if (centerInside) {
            link(left(), bottom())
            link(top(), right())
          } else {
            link(left(), top())
            link(bottom(), right())
          }
          break
      }
    }
  }

  const visited = new Set<number>()
  const loops: Vec2[][] = []
  for (const start of adjacency.keys()) {
    if (visited.has(start)) continue
    const loop: Vec2[] = []
    let previous = -1
    let current: number | undefined = start
    while (current !== undefined && !visited.has(current)) {
      visited.add(current)
      const point = points.get(current)
      if (point) loop.push(point)
      const neighbours: number[] = adjacency.get(current) ?? []
      const next: number | undefined = neighbours.find(
        (candidate) => candidate !== previous && !visited.has(candidate),
      )
      previous = current
      current = next
    }
    if (loop.length > 24) loops.push(loop)
  }

  return loops.sort((a, b) => b.length - a.length)
}

/**
 * Random points inside the 5 (rejection sampled against the SDF).
 * Returns `[x, y, sdf]` triplets in stage units.
 */
export function sampleFiveInterior(
  field: FiveField,
  count: number,
  random: () => number,
): Float32Array {
  const out = new Float32Array(count * 3)
  const halfWidth = FIVE_ASPECT / 2
  let written = 0
  let guard = 0
  while (written < count && guard < count * 40) {
    guard++
    const x = (random() * 2 - 1) * halfWidth
    const y = random() - 0.5
    const distance = field.sample(x, y)
    if (distance >= 0) continue
    out[written * 3] = x
    out[written * 3 + 1] = y
    out[written * 3 + 2] = distance
    written++
  }
  return out
}

/** Contour length cache for arc-length sampling along the outline. */
export function measureLoop(points: Vec2[]) {
  const cumulative = new Float32Array(points.length + 1)
  for (let i = 1; i <= points.length; i++) {
    const a = points[i - 1]
    const b = points[i % points.length]
    cumulative[i] = cumulative[i - 1] + Math.hypot(b.x - a.x, b.y - a.y)
  }
  return cumulative
}

/** Point + outward normal at normalized arc position `t` along a closed loop. */
export function pointOnLoop(
  points: Vec2[],
  cumulative: Float32Array,
  t: number,
): { x: number; y: number; nx: number; ny: number } {
  const total = cumulative[cumulative.length - 1]
  const target = (((t % 1) + 1) % 1) * total
  let lo = 0
  let hi = points.length - 1
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1
    if (cumulative[mid] <= target) lo = mid
    else hi = mid - 1
  }
  const a = points[lo]
  const b = points[(lo + 1) % points.length]
  const span = cumulative[lo + 1] - cumulative[lo] || 1
  const w = (target - cumulative[lo]) / span
  const dx = b.x - a.x
  const dy = b.y - a.y
  const len = Math.hypot(dx, dy) || 1
  // Counter-clockwise winding: outward normal is the tangent rotated clockwise.
  return {
    x: a.x + dx * w,
    y: a.y + dy * w,
    nx: dy / len,
    ny: -dx / len,
  }
}
