import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowUpRight, Maximize, Minus, Plus, Search, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { APPWRITE, CONVERSION, STATUS, type Graph, type GraphNode, type Index, type Status } from '@/lib/docs'
import { bounds, layout, links as linksOf, neighbours, type Link as Edge, type Point, type Runtime } from '@/lib/graph'
import { useMigration } from '@/lib/prefs'
import { StatusBadge, StatusDot } from './Status'
import { MigrationOnly } from './Migration'

// Not converted, by design: hollow and outlined, apart from the conversion's stages.
const STATUS_FILL: Record<Status, string> = { complete: 'var(--ok)', progress: 'var(--progress)', started: 'var(--started)', planned: 'var(--planned)', retired: 'var(--card)' }

/** Pan and zoom: screen = point × k + (x, y). */
interface View {
  x: number
  y: number
  k: number
}

const MIN_ZOOM = 0.25
const MAX_ZOOM = 4

function fit(points: Iterable<Point>, w: number, h: number): View {
  const b = bounds(points)
  // Labels sit right of their node.
  const maxX = b.maxX + 90
  const pad = 36
  const k = Math.min((w - 2 * pad) / Math.max(maxX - b.minX, 1), (h - 2 * pad) / Math.max(b.maxY - b.minY + 30, 1), 1.4)
  return { k, x: w / 2 - ((b.minX + maxX) / 2) * k, y: h / 2 - ((b.minY + b.maxY) / 2) * k }
}

function zoomAt(v: View, mx: number, my: number, factor: number): View {
  const k = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, v.k * factor))
  return { k, x: mx - (mx - v.x) * (k / v.k), y: my - (my - v.y) * (k / v.k) }
}

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

function edgeColor(l: Edge) {
  return l.rust && l.php ? 'var(--muted-foreground)' : l.rust ? 'var(--rust)' : 'var(--php)'
}
const markerId = (l: Edge) => (l.rust && l.php ? 'map-arrow-both' : l.rust ? 'map-arrow-rust' : 'map-arrow-php')

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-2.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

/** Rust, PHP, or both: which dependencies to draw. */
function RuntimeSwitch({ value, onChange }: { value: Runtime; onChange: (r: Runtime) => void }) {
  const option = (r: Runtime, label: string) => (
    <button type="button" aria-pressed={value === r} onClick={() => onChange(r)} className="inline-flex h-7 items-center rounded px-2.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-accent aria-pressed:text-foreground">
      {label}
    </button>
  )
  return (
    <div role="group" aria-label="Runtime" className="inline-flex h-8 items-center gap-0.5 rounded-md border border-border bg-card px-0.5">
      {option('both', 'Both')}
      {option('rust', 'Rust')}
      {option('php', 'PHP')}
    </div>
  )
}

function Toggle({ on, onChange, children }: { on: boolean; onChange: (on: boolean) => void; children: ReactNode }) {
  return (
    <button type="button" aria-pressed={on} onClick={() => onChange(!on)} className={chip}>
      <span className={`inline-flex size-3.5 items-center justify-center rounded-[3px] border ${on ? 'border-foreground bg-foreground' : 'border-ring'}`}>
        {on ? <span className="size-1.5 rounded-[1px] bg-background" /> : null}
      </span>
      {children}
    </button>
  )
}

/** A library in the panel or the list: selects it on the map, with the runtimes it goes through. */
function Neighbour({ node, link, onSelect }: { node: GraphNode | undefined; link: Edge; onSelect?: (id: string) => void }) {
  const migration = useMigration()
  if (!node) return null
  // Outside migration mode every edge is a Rust one: no runtime to tell apart.
  const tags = [migration && link.rust ? 'Rust' : '', migration && link.php ? 'PHP' : '', link.dev ? 'dev' : ''].filter(Boolean)
  const name = (
    <span className="flex min-w-0 items-center gap-1.5">
      {node.status ? (
        <MigrationOnly>
          <StatusDot status={node.status} />
        </MigrationOnly>
      ) : null}
      <span className="truncate">{node.title}</span>
    </span>
  )
  return (
    <li className="flex min-w-0 items-center justify-between gap-2">
      {onSelect ? (
        <span className="flex min-w-0 items-center gap-1">
          <button type="button" onClick={() => onSelect(node.id)} title="Show on the map" className="min-w-0 text-left text-[13px] hover:underline">
            {name}
          </button>
          {node.kind === 'app' ? null : (
            <Link to="/$lib" params={{ lib: node.id }} title={`${node.title} overview`} aria-label={`${node.title} overview`} className="flex-none text-muted-foreground hover:text-foreground">
              <ArrowUpRight className="size-3.5" />
            </Link>
          )}
        </span>
      ) : node.kind === 'app' ? (
        <span className="min-w-0 text-[13px]">{name}</span>
      ) : (
        <Link to="/$lib" params={{ lib: node.id }} className="min-w-0 text-[13px] hover:underline">
          {name}
        </Link>
      )}
      <span className="flex flex-none gap-1">
        {tags.map((t) => (
          <span key={t} className="rounded bg-muted px-1 py-px text-[10.5px] text-muted-foreground">
            {t}
          </span>
        ))}
      </span>
    </li>
  )
}

function Neighbours({ title, links, end, byId, onSelect, empty }: { title: string; links: Edge[]; end: 'source' | 'target'; byId: Map<string, GraphNode>; onSelect?: (id: string) => void; empty: string }) {
  const sorted = [...links].sort((a, b) => (byId.get(a[end])?.title ?? '').localeCompare(byId.get(b[end])?.title ?? ''))
  return (
    <div className="grid gap-1.5">
      <div className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
        {title} <span className="font-normal tabular-nums">{links.length}</span>
      </div>
      {sorted.length ? (
        <ul className="m-0 grid list-none gap-1 p-0">
          {sorted.map((l) => (
            <Neighbour key={`${l.source}>${l.target}`} node={byId.get(l[end])} link={l} onSelect={onSelect} />
          ))}
        </ul>
      ) : (
        <p className="m-0 text-[12.5px] text-muted-foreground">{empty}</p>
      )}
    </div>
  )
}

/** The selected node: what it is, what it depends on and what depends on it. */
function Panel({ node, out, into, byId, category, onSelect, onClose }: { node: GraphNode; out: Edge[]; into: Edge[]; byId: Map<string, GraphNode>; category?: string; onSelect: (id: string) => void; onClose: () => void }) {
  const app = node.kind === 'app'
  return (
    <aside className="absolute inset-y-3 right-3 grid w-[300px] content-start gap-4 overflow-y-auto rounded-lg border border-border bg-card p-4 shadow-sm" aria-label={`${node.title} dependencies`}>
      <div className="flex items-start justify-between gap-2">
        <div className="grid min-w-0 gap-0.5">
          <span className="text-[11px] tracking-wider text-muted-foreground uppercase">{app ? 'Application' : (category ?? 'Library')}</span>
          <h2 className="truncate text-[18px]">{node.title}</h2>
        </div>
        <button type="button" onClick={onClose} aria-label="Close" className="inline-flex size-7 flex-none items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground">
          <X className="size-4" />
        </button>
      </div>
      {node.status ? (
        <MigrationOnly>
          <div>
            <StatusBadge status={node.status} />
          </div>
        </MigrationOnly>
      ) : null}
      {node.description ? <p className="m-0 text-[13px] text-muted-foreground">{node.description}</p> : null}
      <div className="grid gap-0.5 font-mono text-[11.5px] text-muted-foreground">
        {node.rust ? <span className="truncate">{node.rust}</span> : null}
        {app && node.crates?.length ? <span className="[overflow-wrap:anywhere]">{node.crates.join(', ')}</span> : null}
        {node.php ? (
          <MigrationOnly>
            <span className="truncate">{node.php}</span>
          </MigrationOnly>
        ) : null}
        {!app && !node.rust ? <span className="font-sans">No Rust crate yet.</span> : null}
      </div>
      {app ? null : (
        <div className="flex flex-wrap gap-x-3 gap-y-1 text-[13px]">
          <Link to="/$lib" params={{ lib: node.id }} className="hover:underline">
            Overview
          </Link>
          <Link to="/$lib/dependencies" params={{ lib: node.id }} className="hover:underline">
            Dependencies
          </Link>
        </div>
      )}
      {app ? (
        <MigrationOnly>
          <Link to="/appwrite" className="text-[13px] hover:underline">
            Appwrite migration
          </Link>
        </MigrationOnly>
      ) : null}
      <Neighbours title="Depends on" links={out} end="target" byId={byId} onSelect={onSelect} empty="No Utopia library." />
      {app ? null : <Neighbours title="Used by" links={into} end="source" byId={byId} onSelect={onSelect} empty="Nothing depends on it." />}
    </aside>
  )
}

function Legend({ migration, retired }: { migration: boolean; retired: boolean }) {
  const line = (color: string, dashed?: boolean) => (
    <svg width="26" height="8" aria-hidden className="flex-none">
      <line x1="1" y1="4" x2="25" y2="4" style={{ stroke: color }} strokeWidth="1.5" strokeDasharray={dashed ? '3 3' : undefined} />
    </svg>
  )
  const item = (mark: ReactNode, label: string) => (
    <span className="inline-flex items-center gap-1.5">
      {mark}
      {label}
    </span>
  )
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-muted-foreground">
      {item(line('var(--rust)'), migration ? 'Rust' : 'Dependency')}
      {migration ? item(line('var(--php)'), 'PHP') : null}
      {migration ? item(line('var(--muted-foreground)'), 'Both') : null}
      {item(line('var(--muted-foreground)', true), 'Dev only')}
      <span>Arrows point to the dependency.</span>
      {migration ? (
        [...CONVERSION, ...(retired ? (['retired'] as Status[]) : [])].map((s) => (
          <span key={s} className="inline-flex items-center gap-1.5">
            <StatusDot status={s} />
            {STATUS[s].label}
          </span>
        ))
      ) : null}
      <span>Larger nodes have more dependents.</span>
    </div>
  )
}

/** On phones: each category's libraries, with what each depends on and what depends on it. */
function MapList({ nodes, near, byId, category, index }: { nodes: GraphNode[]; near: ReturnType<typeof neighbours>; byId: Map<string, GraphNode>; category: string; index: Index }) {
  const groups = [...index.categories.filter((c) => !category || c.id === category).map((c) => ({ id: c.id, title: c.title })), ...(category ? [] : [{ id: '', title: 'Application' }])]
  return (
    <div className="grid gap-6 md:hidden">
      {groups.map((g) => {
        const members = nodes.filter((n) => (g.id ? n.category === g.id : n.kind === 'app')).sort((a, b) => a.title.localeCompare(b.title))
        if (!members.length) return null
        return (
          <section key={g.id || 'app'} className="grid gap-2">
            <h2 className="text-[16px]">{g.title}</h2>
            <div className="grid divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
              {members.map((n) => (
                <div key={n.id} id={`map-${n.id}`} className="grid gap-2 px-3 py-2.5">
                  <div className="flex items-center gap-1.5">
                    {n.status ? (
                      <MigrationOnly>
                        <StatusDot status={n.status} />
                      </MigrationOnly>
                    ) : null}
                    {n.kind === 'app' ? (
                      <span className="font-display text-[14px] font-medium">{n.title}</span>
                    ) : (
                      <Link to="/$lib/dependencies" params={{ lib: n.id }} className="font-display text-[14px] font-medium hover:underline">
                        {n.title}
                      </Link>
                    )}
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Neighbours title="Depends on" links={near.out.get(n.id) ?? []} end="target" byId={byId} empty="No Utopia library." />
                    {n.kind === 'app' ? null : <Neighbours title="Used by" links={near.in.get(n.id) ?? []} end="source" byId={byId} empty="Nothing depends on it." />}
                  </div>
                </div>
              ))}
            </div>
          </section>
        )
      })}
    </div>
  )
}

/**
 * The ecosystem's dependency map: every library and Appwrite, an arrow from each to what it depends on.
 * Pan by dragging the background, zoom with the wheel or the buttons, drag a node to move it; hovering
 * a node shows its dependencies and dependents, clicking one keeps them and opens its panel.
 */
export function DependencyMap({ graph: all, index }: { graph: Graph; index: Index }) {
  const migration = useMigration()
  // Outside migration mode the map is the Rust ecosystem: its crates, Appwrite, and the Rust edges between them.
  const graph = useMemo(() => {
    if (migration) return all
    const nodes = all.nodes.filter((n) => n.kind === 'app' || n.rust)
    const ids = new Set(nodes.map((n) => n.id))
    return { ...all, nodes, edges: all.edges.filter((e) => e.runtime === 'rust' && ids.has(e.source) && ids.has(e.target)) }
  }, [all, migration])
  const [chosen, setRuntime] = useState<Runtime>('both')
  const runtime: Runtime = migration ? chosen : 'rust'
  const [dev, setDev] = useState(false)
  const [showApp, setShowApp] = useState(true)
  const [category, setCategory] = useState('')
  const [hover, setHover] = useState<string | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [q, setQ] = useState('')
  const [searching, setSearching] = useState(false)

  const categories = useMemo(() => index.categories.map((c) => c.id), [index])
  const titles = useMemo(() => new Map(index.categories.map((c) => [c.id, c.title])), [index])
  const initial = useMemo(() => layout(graph.nodes, graph.edges, categories), [graph, categories])
  // Each category's name, beyond its libraries on the line from the middle.
  const labels = useMemo(() => {
    const out = new Map<string, Point>()
    for (const c of categories) {
      const members = graph.nodes.filter((n) => n.category === c).map((n) => initial.get(n.id)!)
      if (!members.length) continue
      const cx = members.reduce((s, p) => s + p.x, 0) / members.length
      const cy = members.reduce((s, p) => s + p.y, 0) / members.length
      const len = Math.max(Math.hypot(cx, cy), 1)
      const reach = Math.max(...members.map((p) => (p.x * cx + p.y * cy) / len))
      out.set(c, { x: (cx / len) * (reach + 56), y: (cy / len) * (reach + 56) })
    }
    return out
  }, [graph, categories, initial])
  const extent = useMemo(() => [...initial.values(), ...labels.values()], [initial, labels])
  const [moved, setMoved] = useState<Record<string, Point>>({})
  const pos = (id: string) => moved[id] ?? initial.get(id) ?? { x: 0, y: 0 }
  const byId = useMemo(() => new Map(graph.nodes.map((n) => [n.id, n])), [graph])
  const shown = useMemo(() => linksOf(graph.edges, runtime, dev, showApp), [graph, runtime, dev, showApp])
  const near = useMemo(() => neighbours(shown), [shown])
  const nodes = useMemo(() => graph.nodes.filter((n) => showApp || n.kind !== 'app'), [graph, showApp])
  // Size by dependents among the libraries, whichever runtime.
  const weight = useMemo(() => {
    const m = new Map<string, number>()
    for (const l of linksOf(graph.edges, 'both', false, false)) m.set(l.target, (m.get(l.target) ?? 0) + 1)
    return m
  }, [graph])
  const radius = (n: GraphNode) => (n.kind === 'app' ? 12 : 4 + Math.min(Math.sqrt(weight.get(n.id) ?? 0) * 1.6, 8))

  // The canvas: its size, the view, and the drag in progress.
  const box = useRef<HTMLDivElement>(null)
  const svg = useRef<SVGSVGElement>(null)
  const [size, setSize] = useState({ w: 960, h: 620 })
  const sizeRef = useRef(size)
  sizeRef.current = size
  const [view, setView] = useState<View>(() => fit(extent, 960, 620))
  const viewRef = useRef(view)
  viewRef.current = view
  const touched = useRef(false)
  const measured = useRef(false)
  const pending = useRef<string | null>(null)
  const shared = useRef('')
  const hash = useRouterState({ select: (s) => s.location.hash })
  const navigate = useNavigate()
  const frame = useRef(0)
  const drag = useRef<{ id: string | null; x: number; y: number; moved: boolean; view: View; start: Point } | null>(null)

  const center = (id: string, animate: boolean) => {
    const p = pos(id)
    const from = viewRef.current
    const k = Math.max(from.k, 1)
    // Leave room for the panel on the right.
    const { w, h } = sizeRef.current
    const to = { k, x: (w - 300) / 2 - p.x * k, y: h / 2 - p.y * k }
    touched.current = true
    cancelAnimationFrame(frame.current)
    if (!animate || reducedMotion()) return setView(to)
    const start = performance.now()
    const step = (now: number) => {
      const t = Math.min(1, (now - start) / 320)
      const e = 1 - Math.pow(1 - t, 3)
      setView({ k: from.k + (to.k - from.k) * e, x: from.x + (to.x - from.x) * e, y: from.y + (to.y - from.y) * e })
      if (t < 1) frame.current = requestAnimationFrame(step)
    }
    frame.current = requestAnimationFrame(step)
  }

  const select = (id: string | null, focus = false) => {
    setSelected(id)
    // The address records the selection, so it can be shared.
    shared.current = id ?? ''
    navigate({ to: '/map', hash: id ?? '', replace: true, resetScroll: false })
    if (id && focus) center(id, true)
  }

  useEffect(() => {
    const el = box.current
    if (!el) return
    const observer = new ResizeObserver(([entry]) => {
      const { width, height } = entry.contentRect
      if (width && height) setSize({ w: width, h: height })
    })
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  // Fit the map to the canvas, or centre the library named in the address, until the reader moves it.
  useEffect(() => {
    if (size.w === 960 && size.h === 620 && !measured.current) return
    measured.current = true
    if (pending.current) {
      center(pending.current, false)
      pending.current = null
    } else if (!touched.current) setView(fit(extent, size.w, size.h))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [size, initial])

  // `#validators` selects and centres a library: links from each Dependencies page land here.
  useEffect(() => {
    const id = decodeURIComponent(hash)
    if (id === shared.current || !byId.has(id)) return
    shared.current = id
    setSelected(id)
    if (measured.current) center(id, true)
    else pending.current = id
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hash, byId])

  // The wheel zooms around the pointer (a listener that may prevent the page from scrolling).
  useEffect(() => {
    const el = svg.current
    if (!el) return
    const onWheel = (e: WheelEvent) => {
      e.preventDefault()
      const r = el.getBoundingClientRect()
      touched.current = true
      cancelAnimationFrame(frame.current)
      setView((v) => zoomAt(v, e.clientX - r.left, e.clientY - r.top, Math.exp(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.0015))))
    }
    el.addEventListener('wheel', onWheel, { passive: false })
    return () => el.removeEventListener('wheel', onWheel)
  }, [])

  useEffect(() => () => cancelAnimationFrame(frame.current), [])

  const down = (e: React.PointerEvent, id: string | null) => {
    if (e.button !== 0) return
    e.stopPropagation()
    svg.current?.setPointerCapture(e.pointerId)
    cancelAnimationFrame(frame.current)
    drag.current = { id, x: e.clientX, y: e.clientY, moved: false, view, start: id ? pos(id) : { x: 0, y: 0 } }
  }
  const move = (e: React.PointerEvent) => {
    const d = drag.current
    if (!d) return
    const dx = e.clientX - d.x
    const dy = e.clientY - d.y
    if (!d.moved && Math.abs(dx) + Math.abs(dy) < 4) return
    d.moved = true
    touched.current = true
    if (d.id) {
      const id = d.id
      setMoved((m) => ({ ...m, [id]: { x: d.start.x + dx / d.view.k, y: d.start.y + dy / d.view.k } }))
    } else setView({ ...d.view, x: d.view.x + dx, y: d.view.y + dy })
  }
  const up = () => {
    const d = drag.current
    drag.current = null
    if (!d || d.moved) return
    select(d.id && d.id !== selected ? d.id : null)
  }

  const zoom = (factor: number) => {
    touched.current = true
    setView((v) => zoomAt(v, size.w / 2, size.h / 2, factor))
  }
  const reset = () => {
    touched.current = false
    setMoved({})
    setView(fit(extent, size.w, size.h))
  }

  // What to emphasise: the hovered or selected node, its dependencies and its dependents.
  const active = hover ?? selected
  const related = new Set<string>()
  if (active) {
    related.add(active)
    for (const l of near.out.get(active) ?? []) related.add(l.target)
    for (const l of near.in.get(active) ?? []) related.add(l.source)
  }
  const inCategory = (id: string) => !category || byId.get(id)?.category === category
  const dimNode = (id: string) => (active ? !related.has(id) : !inCategory(id))
  const touches = (l: Edge) => !!active && (l.source === active || l.target === active)
  const edgeOpacity = (l: Edge) => {
    if (active) return touches(l) ? 1 : 0.05
    if (category && !inCategory(l.source) && !inCategory(l.target)) return 0.05
    return l.source === APPWRITE ? 0.1 : 0.32
  }
  const ordered = [...shown].sort((a, b) => Number(touches(a)) - Number(touches(b)))

  const term = q.trim().toLowerCase()
  const matches = term
    ? nodes.filter((n) => `${n.title} ${n.id} ${n.rust ?? ''} ${migration ? (n.php ?? '') : ''}`.toLowerCase().includes(term)).sort((a, b) => Number(!a.title.toLowerCase().startsWith(term)) - Number(!b.title.toLowerCase().startsWith(term))).slice(0, 8)
    : []
  const pick = (id: string) => {
    setQ('')
    setSearching(false)
    select(id, true)
    // On phones the list shows instead of the map.
    if (typeof window !== 'undefined' && !window.matchMedia('(min-width: 768px)').matches) document.getElementById(`map-${id}`)?.scrollIntoView({ block: 'start' })
  }

  const node = selected ? byId.get(selected) : undefined
  const label = 11 / Math.min(view.k, 1)

  return (
    <div className="grid gap-3 md:flex md:min-h-0 md:flex-1 md:flex-col">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-full sm:w-64">
          <label className="flex h-8 items-center gap-2 rounded-md border border-border bg-card px-2.5 text-muted-foreground focus-within:border-ring">
            <Search className="size-3.5 flex-none" />
            <input
              type="search"
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setSearching(true)
              }}
              onFocus={() => setSearching(true)}
              onBlur={() => setTimeout(() => setSearching(false), 150)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && matches[0]) pick(matches[0].id)
                if (e.key === 'Escape') setQ('')
              }}
              placeholder="Find a library"
              aria-label="Find a library"
              className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
            />
          </label>
          {searching && matches.length ? (
            <ul className="absolute inset-x-0 top-9 z-20 m-0 grid list-none gap-0.5 rounded-md border border-border bg-card p-1 shadow-sm">
              {matches.map((n) => (
                <li key={n.id}>
                  <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => pick(n.id)} className="flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-left text-[13px] hover:bg-accent">
                    <span className="truncate">{n.title}</span>
                    <span className="truncate font-mono text-[11px] text-muted-foreground">{n.rust ?? (migration ? n.php : null)}</span>
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
        {migration ? <RuntimeSwitch value={runtime} onChange={setRuntime} /> : null}
        <select value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Category" className="h-8 rounded-md border border-border bg-card pr-8 pl-2 text-[12.5px] text-foreground">
          <option value="">All categories</option>
          {index.categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title}
            </option>
          ))}
        </select>
        <Toggle on={dev} onChange={setDev}>
          Dev dependencies
        </Toggle>
        <Toggle on={showApp} onChange={setShowApp}>
          Appwrite
        </Toggle>
      </div>

      <div ref={box} className="relative hidden min-h-0 flex-1 overflow-hidden rounded-lg border border-border bg-card md:block">
        <svg
          ref={svg}
          width={size.w}
          height={size.h}
          className="block cursor-grab touch-none select-none active:cursor-grabbing"
          role="img"
          aria-label="Dependency map of the Utopia libraries"
          onPointerDown={(e) => down(e, null)}
          onPointerMove={move}
          onPointerUp={up}
          onPointerCancel={() => (drag.current = null)}
        >
          <defs>
            {(
              [
                ['map-arrow-rust', 'var(--rust)'],
                ['map-arrow-php', 'var(--php)'],
                ['map-arrow-both', 'var(--muted-foreground)'],
              ] as const
            ).map(([id, color]) => (
              <marker key={id} id={id} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" markerUnits="userSpaceOnUse" orient="auto">
                <path d="M0,1 L10,5 L0,9 z" style={{ fill: color }} />
              </marker>
            ))}
          </defs>
          <g transform={`translate(${view.x},${view.y}) scale(${view.k})`}>
            {/* Category names just outside their libraries. */}
            {index.categories.map((c) => {
              const at = labels.get(c.id)
              if (!at) return null
              return (
                <text
                  key={c.id}
                  x={at.x}
                  y={at.y}
                  textAnchor="middle"
                  style={{ fill: 'var(--muted-foreground)', fontSize: 12 / Math.min(view.k, 1), letterSpacing: '0.08em', opacity: category && category !== c.id ? 0.3 : 0.75 }}
                  className="pointer-events-none font-sans uppercase"
                >
                  {c.title}
                </text>
              )
            })}
            {ordered.map((l) => {
              const a = pos(l.source)
              const b = pos(l.target)
              const ra = byId.get(l.source) ? radius(byId.get(l.source)!) : 6
              const rb = byId.get(l.target) ? radius(byId.get(l.target)!) : 6
              const dx = b.x - a.x
              const dy = b.y - a.y
              const len = Math.max(Math.hypot(dx, dy), 1)
              // A slight bend, so edges in both directions stay apart.
              const cx = (a.x + b.x) / 2 - (dy / len) * len * 0.08
              const cy = (a.y + b.y) / 2 + (dx / len) * len * 0.08
              const sx = a.x + ((cx - a.x) / Math.max(Math.hypot(cx - a.x, cy - a.y), 1)) * ra
              const sy = a.y + ((cy - a.y) / Math.max(Math.hypot(cx - a.x, cy - a.y), 1)) * ra
              const ex = b.x - ((b.x - cx) / Math.max(Math.hypot(b.x - cx, b.y - cy), 1)) * (rb + 2)
              const ey = b.y - ((b.y - cy) / Math.max(Math.hypot(b.x - cx, b.y - cy), 1)) * (rb + 2)
              return (
                <path
                  key={`${l.source}>${l.target}`}
                  d={`M${sx},${sy} Q${cx},${cy} ${ex},${ey}`}
                  fill="none"
                  markerEnd={`url(#${markerId(l)})`}
                  strokeWidth={touches(l) ? 1.6 : 1.1}
                  strokeDasharray={l.dev ? '4 3' : undefined}
                  vectorEffect="non-scaling-stroke"
                  style={{ stroke: edgeColor(l), opacity: edgeOpacity(l), transition: 'opacity 120ms' }}
                />
              )
            })}
            {nodes.map((n) => {
              const p = pos(n.id)
              const r = radius(n)
              const dim = dimNode(n.id)
              const on = n.id === active
              const fill = n.kind === 'app' ? 'var(--foreground)' : migration && n.status ? STATUS_FILL[n.status] : n.rust ? 'var(--foreground)' : 'var(--card)'
              return (
                <g
                  key={n.id}
                  transform={`translate(${p.x},${p.y})`}
                  style={{ opacity: dim ? 0.15 : 1, transition: 'opacity 120ms', cursor: 'pointer' }}
                  onPointerDown={(e) => down(e, n.id)}
                  onPointerEnter={() => !drag.current && setHover(n.id)}
                  onPointerLeave={() => setHover((h) => (h === n.id ? null : h))}
                >
                  <title>{n.title}</title>
                  {/* A larger target than the dot. */}
                  <circle r={Math.max(r, 9)} fill="transparent" />
                  {n.kind === 'app' ? (
                    <rect x={-r} y={-r} width={r * 2} height={r * 2} rx={4} style={{ fill }} />
                  ) : (
                    <circle r={r} style={{ fill, stroke: (n.rust || migration) && !(migration && n.status === 'retired') ? 'var(--card)' : 'var(--muted-foreground)' }} strokeWidth={1.2} vectorEffect="non-scaling-stroke" />
                  )}
                  {on || n.id === selected ? <circle r={r + 4} fill="none" style={{ stroke: 'var(--foreground)' }} strokeWidth={1.2} vectorEffect="non-scaling-stroke" /> : null}
                  <text
                    x={r + 4}
                    y={label * 0.35}
                    style={{
                      fill: 'var(--foreground)',
                      fontSize: n.kind === 'app' ? label * 1.15 : label,
                      fontWeight: on || n.kind === 'app' ? 600 : 400,
                      paintOrder: 'stroke',
                      stroke: 'var(--card)',
                      strokeWidth: 3 / Math.max(view.k, 0.5),
                      strokeLinejoin: 'round',
                    }}
                    className="font-sans"
                  >
                    {n.title}
                  </text>
                </g>
              )
            })}
          </g>
        </svg>

        <div className="absolute bottom-3 left-3 grid gap-1">
          {(
            [
              ['Zoom in', Plus, () => zoom(1.3)],
              ['Zoom out', Minus, () => zoom(1 / 1.3)],
              ['Fit and reset', Maximize, reset],
            ] as const
          ).map(([title, Icon, onClick]) => (
            <button key={title} type="button" onClick={onClick} title={title} aria-label={title} className="inline-flex size-8 items-center justify-center rounded-md border border-border bg-card text-muted-foreground shadow-sm hover:text-foreground">
              <Icon className="size-4" />
            </button>
          ))}
        </div>

        {node ? <Panel node={node} out={near.out.get(node.id) ?? []} into={near.in.get(node.id) ?? []} byId={byId} category={node.category ? titles.get(node.category) : undefined} onSelect={(id) => select(id, true)} onClose={() => select(null)} /> : null}
      </div>

      <div className="hidden md:block">
        <Legend migration={migration} retired={graph.nodes.some((n) => n.status === 'retired')} />
      </div>

      <MapList nodes={nodes} near={near} byId={byId} category={category} index={index} />
    </div>
  )
}
