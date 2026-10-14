// The map's model: which edges show for the chosen runtimes, and each node's neighbours.

import { APPWRITE, type GraphEdge } from './docs'

export type Runtime = 'rust' | 'php' | 'both'

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

/** How wide a ribbon is: one per runtime the edge goes through. */
export function weight(l: Link) {
  return (l.rust ? 1 : 0) + (l.php ? 1 : 0)
}
