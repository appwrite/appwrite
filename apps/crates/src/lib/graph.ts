// The dependency map's model: which edges show for the chosen runtimes, each
// node's neighbours, and a seeded force-directed layout, so the map is laid
// out the same on every load (and the same on the server and in the browser).

import { APPWRITE, type GraphEdge, type GraphNode } from './docs'

export type Runtime = 'rust' | 'php' | 'both'

export interface Point {
  x: number
  y: number
}

/** One drawn edge: `source` depends on `target` through one runtime or both. */
export interface Link {
  source: string
  target: string
  rust: boolean
  php: boolean
  /** Only for tests, examples and benchmarks (or `require-dev`). */
  dev: boolean
  /** Appwrite's crates behind it, and how its PHP server depends. */
  crates?: string[]
  via?: 'require' | 'import'
}

const runtime = (e: GraphEdge) => e.kinds.some((k) => k !== 'dev')

/** The edges to draw, one per pair of nodes, with the runtimes each goes through. */
export function links(edges: GraphEdge[], show: Runtime, dev: boolean, appwrite: boolean): Link[] {
  const out = new Map<string, Link>()
  for (const e of edges) {
    if (show !== 'both' && e.runtime !== show) continue
    if (!dev && !runtime(e)) continue
    if (!appwrite && e.source === APPWRITE) continue
    const key = `${e.source}>${e.target}`
    const link = out.get(key) ?? { source: e.source, target: e.target, rust: false, php: false, dev: true }
    link[e.runtime] = true
    link.dev = link.dev && !runtime(e)
    if (e.crates) link.crates = e.crates
    if (e.via) link.via = e.via
    out.set(key, link)
  }
  return [...out.values()]
}

/** Each node's dependencies (`out`) and dependents (`in`) among `links`. */
export function neighbours(links: Link[]) {
  const out = new Map<string, Link[]>()
  const into = new Map<string, Link[]>()
  for (const l of links) {
    out.set(l.source, [...(out.get(l.source) ?? []), l])
    into.set(l.target, [...(into.get(l.target) ?? []), l])
  }
  return { out, in: into }
}

/** FNV-1a: a stable seed per library. */
function hash(text: string) {
  let h = 2166136261
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

/** Mulberry32: a small seeded generator, so layouts repeat. */
function random(seed: number) {
  let a = seed
  return () => {
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/** The space kept around Appwrite, in the middle. */
const HOLE = 190

/**
 * Positions for every node: Appwrite in the middle, each category around it
 * on a ring, and the libraries pulled together by what they depend on.
 * Appwrite's edges are left out of the forces: it depends on nearly every
 * library and would pull them all onto itself.
 */
export function layout(nodes: GraphNode[], edges: GraphEdge[], categories: string[]): Map<string, Point> {
  const ring = 470
  const anchors = new Map<string, Point>()
  categories.forEach((c, i) => {
    const angle = -Math.PI / 2 + (i * 2 * Math.PI) / Math.max(categories.length, 1)
    anchors.set(c, { x: Math.cos(angle) * ring, y: Math.sin(angle) * ring })
  })
  const libs = nodes.filter((n) => n.kind !== 'app')
  const pos = libs.map((n) => {
    const r = random(hash(n.id))
    const a = anchors.get(n.category ?? '') ?? { x: 0, y: 0 }
    return { x: a.x + (r() - 0.5) * 160, y: a.y + (r() - 0.5) * 160, vx: 0, vy: 0 }
  })
  const at = new Map(libs.map((n, i) => [n.id, i]))
  const pairs = new Set<string>()
  const springs: [number, number][] = []
  for (const e of edges) {
    const a = at.get(e.source)
    const b = at.get(e.target)
    if (a === undefined || b === undefined || a === b) continue
    const key = a < b ? `${a}.${b}` : `${b}.${a}`
    if (pairs.has(key)) continue
    pairs.add(key)
    springs.push([a, b])
  }
  // As d3-force does, a spring is as weak as its busier end is connected: a
  // library many depend on (php-std) does not drag them all to itself.
  const degree = new Array<number>(libs.length).fill(0)
  for (const [a, b] of springs) {
    degree[a]++
    degree[b]++
  }
  const steps = 500
  for (let step = 0; step < steps; step++) {
    const alpha = Math.max(0.02, 1 - step / steps)
    const fx = new Float64Array(pos.length)
    const fy = new Float64Array(pos.length)
    for (let i = 0; i < pos.length; i++) {
      for (let j = i + 1; j < pos.length; j++) {
        let dx = pos[i].x - pos[j].x
        let dy = pos[i].y - pos[j].y
        if (dx === 0 && dy === 0) dx = 0.1
        const d2 = Math.max(dx * dx + dy * dy, 64)
        const d = Math.sqrt(d2)
        // Repulsion, and a firmer push inside a label's width.
        let f = 5200 / d2
        if (d < 62) f += (62 - d) * 0.5
        // Labels run right of their node: two on one line and closer than a label apart move off it.
        const ax = Math.abs(dx)
        const ay = Math.abs(dy)
        if (ay < 16 && ax < 90) {
          const push = (16 - ay) * 0.3 * (dy >= 0 ? 1 : -1)
          fy[i] += push
          fy[j] -= push
        }
        dx /= d
        dy /= d
        fx[i] += dx * f
        fy[i] += dy * f
        fx[j] -= dx * f
        fy[j] -= dy * f
      }
    }
    for (const [a, b] of springs) {
      const dx = pos[b].x - pos[a].x
      const dy = pos[b].y - pos[a].y
      const d = Math.max(Math.hypot(dx, dy), 1)
      const f = ((d - 110) * 0.3) / Math.min(degree[a], degree[b]) / Math.max(degree[a], degree[b]) ** 0.5
      fx[a] += (dx / d) * f
      fy[a] += (dy / d) * f
      fx[b] -= (dx / d) * f
      fy[b] -= (dy / d) * f
    }
    libs.forEach((n, i) => {
      const a = anchors.get(n.category ?? '') ?? { x: 0, y: 0 }
      fx[i] += (a.x - pos[i].x) * 0.1
      fy[i] += (a.y - pos[i].y) * 0.1
    })
    for (let i = 0; i < pos.length; i++) {
      pos[i].vx = (pos[i].vx + fx[i] * alpha) * 0.55
      pos[i].vy = (pos[i].vy + fy[i] * alpha) * 0.55
      pos[i].x += pos[i].vx
      pos[i].y += pos[i].vy
      // Room for Appwrite in the middle.
      const d = Math.hypot(pos[i].x, pos[i].y)
      if (d < HOLE) {
        const r = random(hash(libs[i].id) + step)
        const angle = d < 1 ? r() * 2 * Math.PI : Math.atan2(pos[i].y, pos[i].x)
        pos[i].x = Math.cos(angle) * HOLE
        pos[i].y = Math.sin(angle) * HOLE
      }
    }
  }
  const out = new Map<string, Point>(libs.map((n, i) => [n.id, { x: Math.round(pos[i].x), y: Math.round(pos[i].y) }]))
  for (const n of nodes) if (n.kind === 'app') out.set(n.id, { x: 0, y: 0 })
  return out
}

/** The box around every point. */
export function bounds(points: Iterable<Point>) {
  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity
  for (const p of points) {
    minX = Math.min(minX, p.x)
    minY = Math.min(minY, p.y)
    maxX = Math.max(maxX, p.x)
    maxY = Math.max(maxY, p.y)
  }
  return Number.isFinite(minX) ? { minX, minY, maxX, maxY } : { minX: 0, minY: 0, maxX: 0, maxY: 0 }
}
