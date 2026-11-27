import { useNavigate } from '@tanstack/react-router'
import { memo, useCallback, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import type { AppwriteComponent, LibrarySummary } from '@/lib/docs'
import { AppwriteLogo } from './Logo'

/**
 * Appwrite as a circuit board, drawn in axonometric, each kind of service in
 * its own district and its own shape, every block one crate that part of
 * Appwrite is built from (the libraries its files import, from `graph.json`):
 *
 * - the API: a tower of cubes per module, in its socket
 * - realtime: a round stack of discs; MQTT: a hexagonal one
 * - the schedulers: a cluster of cans, like capacitors
 * - the workers: cards in parallel slots, a row of chips on each
 *
 * A queue bus runs across the board between the API and the workers, a lane
 * from it to every worker, packets moving along them. On a loop the board is populated block by block, the most
 * shared crates light up across every kind of service, the drawing opens
 * into an exploded view, and the board clears.
 */

// The camera: true isometric, the three axes 120° apart and equally foreshortened. Looking down at
// 35.26°, depth moves down the screen tan 30° as fast as it moves across, and a unit of height
// shows as much as a unit along the board. Everything below (heights, ellipses, draw order) follows from it.
const ELEVATION = Math.asin(Math.tan(Math.PI / 6))
const K = Math.sin(ELEVATION)
const S = 20
const H = Math.SQRT2 * Math.cos(ELEVATION) * S
// A cube's height and the gap around it on its cell; the board's thickness; an API socket (2×2) and its lane.
const BLOCK = 0.56
const INSET = 0.07
const PLATE = 0.6
const PITCH = 3
const MARGIN = 1
// How many API modules the board holds, the largest first, and the files a module's crate must be imported by to count.
const TOWERS = 12
const CORE = 5
// A worker's card: a chip's pitch along it, the card's width and the slot pitch between cards.
const CHIP = 0.56
const CARD = 0.74
const SLOT = 1.1
// Where an API tower's cubes go on its socket, back cell first.
const CELLS: [number, number][] = [
  [0, 0],
  [1, 0],
  [0, 1],
  [1, 1],
]

// The loop, in seconds: assemble, put shared blocks in focus, explode and collapse, clear, rest.
const DROP = 0.55
const RISE = 3.4
const HOLD = 0.8
const FOCUS = 1.7
const EXPLODE = 1.1
const EXPLODED = 2.2
const COLLAPSE = 1
const CLEAR = 1.9
const LIFT = 0.6
const REST = 0.5
// Exploded view: everything lifts off the board, and each layer off the one below by most of its height.
const SEPARATE = 0.5
const SPREAD = 0.75
// The board floats this high over the ground its shadow falls on.
const GROUND = 1.8

type Point = [number, number]
type Point3 = [number, number, number]

const project = (x: number, y: number, z: number): Point => [(x - y) * S, (x + y) * S * K - z * H]
const points = (...ps: Point3[]) => ps.map((p) => project(...p).map((n) => n.toFixed(2)).join(',')).join(' ')
const path = (ps: Point[]) => ps.map(([x, y], i) => `${i ? 'L' : 'M'}${project(x, y, 0).map((n) => n.toFixed(2)).join(' ')}`).join(' ')
const clamp = (n: number) => Math.min(1, Math.max(0, n))
const easeOut = (p: number) => 1 - (1 - p) ** 4
const easeIn = (p: number) => p ** 3
const easeInOut = (p: number) => (p < 0.5 ? 4 * p ** 3 : 1 - (-2 * p + 2) ** 3 / 2)

type Kind = AppwriteComponent['kind']

/** How each kind reads in the readout and on tags. */
const KIND: Record<Kind, string> = { http: 'API', worker: 'Worker', schedule: 'Schedulers', realtime: 'Realtime', mqtt: 'MQTT' }

interface Component {
  kind: Kind
  name: string
  /** As the readout shows it: `API · Databases`, `Worker · Builds`, `Realtime`. */
  title: string
  crates: string[]
  /** Where its tag points: the top of its stack. */
  anchor: Point3
}

/** A block's shape: a cube of a tower, a worker's chip, a disc, a hexagonal plate, a can. */
type Shape = 'cube' | 'chip' | 'disc' | 'hex' | 'can'

interface Block {
  slug: string
  component: number
  shape: Shape
  /** Boxes (cube, chip): the back corner; round shapes: the centre. */
  x: number
  y: number
  z: number
  /** Boxes: the size along x and y; round shapes: the radius in `w`. */
  w: number
  d: number
  h: number
  /** Its place in its stack, for the exploded view. */
  layer: number
  /** Its place in the assembly order. */
  order: number
}

/** The board's furniture: sockets, worker cards, the bus, its lanes and the traces. */
interface Board {
  X: number
  Y: number
  sockets: { kind: 'square' | 'round' | 'hex' | 'cans'; x: number; y: number; w: number; d: number }[]
  cards: { x: number; y: number; w: number; d: number }[]
  bus: { x0: number; x1: number; y0: number; y1: number }
  lanes: Point[][]
  traces: Point[][]
}

export interface Plan {
  components: Component[]
  /** In assembly order. */
  blocks: Block[]
  board: Board
  /** Each crate and how many components are built from it, most shared first. */
  shared: [string, number][]
}

/** A component's crates, most used first; an API module's only when enough of its files import them. */
function cratesOf(c: AppwriteComponent, crates: Set<string>) {
  return Object.entries(c.libraries)
    .filter(([slug, files]) => crates.has(slug) && (c.kind !== 'http' || files >= CORE))
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .map(([slug]) => slug)
}

/**
 * Lays the board out, square so it reads as a level diamond: the API's towers
 * at the back, the tallest in the middle; realtime, MQTT and the schedulers in
 * a column beside them; the queue bus across the board in front of both; and
 * the workers' cards in a bank of parallel slots in front of the bus, a lane
 * from the bus to each.
 */
export function plan(components: AppwriteComponent[], crates: Set<string>): Plan | null {
  const all = components
    .map((c) => ({ kind: c.kind, name: c.name, crates: cratesOf(c, crates) }))
    .filter((c) => c.crates.length)
  const http = all
    .filter((c) => c.kind === 'http')
    .sort((a, b) => b.crates.length - a.crates.length || a.name.localeCompare(b.name))
    .slice(0, TOWERS)
  if (!http.length) return null
  const workers = all.filter((c) => c.kind === 'worker').sort((a, b) => a.name.localeCompare(b.name))
  const realtime = all.find((c) => c.kind === 'realtime')
  const mqtt = all.find((c) => c.kind === 'mqtt')
  const schedule = all.find((c) => c.kind === 'schedule')

  const out: Component[] = []
  const blocks: Block[] = []
  const add = (c: (typeof all)[number], anchor: Point3) => {
    out.push({ kind: c.kind, name: c.name, title: c.kind === 'http' || c.kind === 'worker' ? `${KIND[c.kind]} · ${c.name}` : c.name, crates: c.crates, anchor })
    return out.length - 1
  }
  const block = (b: Omit<Block, 'order'>) => blocks.push({ ...b, order: blocks.length })

  // The board's districts. The API: sockets on a grid; the bus in front of it; the cards in front of the bus.
  const rows = Math.max(1, Math.floor(Math.sqrt(http.length)))
  const cols = Math.ceil(http.length / rows)
  const apiW = cols * PITCH - 1
  const apiD = rows * PITCH - 1
  const length = (w: (typeof all)[number]) => w.crates.length * CHIP + 0.4
  const busY0 = MARGIN + apiD + 0.9
  const busY1 = busY0 + 1.4
  const cardY = busY1 + 0.6
  const bank = workers.length * SLOT - (SLOT - CARD)
  const N = Math.ceil(Math.max(MARGIN + apiW + 4.2 + MARGIN, MARGIN * 2 + bank, cardY + Math.max(...workers.map(length), 1) + MARGIN))
  const [X, Y] = [N, N]
  const sockets: Board['sockets'] = []
  const traces: Point[][] = []

  // The API: the tallest towers in the middle, assembled from the back.
  const mid = [(cols - 1) / 2, (rows - 1) / 2]
  const slots = Array.from({ length: cols * rows }, (_, i) => [i % cols, Math.floor(i / cols)] as const).sort(
    (a, b) => Math.hypot(a[0] - mid[0], a[1] - mid[1]) - Math.hypot(b[0] - mid[0], b[1] - mid[1]) || a[0] + a[1] - (b[0] + b[1]),
  )
  const towers = http.map((c, i) => ({ c, x: MARGIN + slots[i][0] * PITCH, y: MARGIN + slots[i][1] * PITCH }))
  for (const t of [...towers].sort((a, b) => a.x + a.y - (b.x + b.y) || a.x - b.x)) {
    const layers = Math.ceil(t.c.crates.length / CELLS.length)
    const i = add(t.c, [t.x + 1, t.y + 1, layers * BLOCK])
    sockets.push({ kind: 'square', x: t.x, y: t.y, w: 2, d: 2 })
    t.c.crates.forEach((slug, k) => {
      const [cx, cy] = CELLS[k % CELLS.length]
      const layer = Math.floor(k / CELLS.length)
      block({ slug, component: i, shape: 'cube', x: t.x + cx + INSET, y: t.y + cy + INSET, z: layer * BLOCK, w: 1 - 2 * INSET, d: 1 - 2 * INSET, h: BLOCK - 0.07, layer })
    })
  }
  // Traces between neighbouring sockets, a pair running straight along the board's axes, and from the front row down to the bus.
  const at = new Map(towers.map((t) => [`${(t.x - MARGIN) / PITCH},${(t.y - MARGIN) / PITCH}`, t]))
  for (const t of towers) {
    const c = (t.x - MARGIN) / PITCH
    const r = (t.y - MARGIN) / PITCH
    if (at.has(`${c + 1},${r}`)) for (const o of [0.75, 1.25]) traces.push([[t.x + 2, t.y + o], [t.x + 3, t.y + o]])
    if (at.has(`${c},${r + 1}`)) for (const o of [0.75, 1.25]) traces.push([[t.x + o, t.y + 2], [t.x + o, t.y + 3]])
    else traces.push([[t.x + 1, t.y + 2], [t.x + 1, busY0]])
  }

  // Beside the API, centred in the space to its right: realtime's discs, MQTT's hexagonal plates, the schedulers' cans.
  const side = (MARGIN + apiW + X - MARGIN) / 2
  const edge = MARGIN + apiW + 0.15
  const stack = (c: (typeof all)[number] | undefined, y: number, shape: 'disc' | 'hex', r: number) => {
    if (!c) return
    const h = 0.2
    const i = add(c, [side, y, c.crates.length * (h + 0.03)])
    sockets.push({ kind: shape === 'disc' ? 'round' : 'hex', x: side, y, w: r + 0.25, d: r + 0.25 })
    c.crates.forEach((slug, k) => block({ slug, component: i, shape, x: side, y, z: k * (h + 0.03), w: r, d: r, h, layer: k }))
    traces.push([[side - r - 0.25, y], [edge, y]])
  }
  stack(realtime, MARGIN + 1.2, 'disc', 0.95)
  stack(mqtt, MARGIN + apiD / 2 + 0.3, 'hex', 0.9)
  if (schedule) {
    const y0 = MARGIN + apiD - 1.2
    const i = add(schedule, [side, y0 + 0.45, 0.75])
    sockets.push({ kind: 'cans', x: side - 1.35, y: y0 - 0.5, w: 2.7, d: 1.9 })
    schedule.crates.forEach((slug, k) => block({ slug, component: i, shape: 'can', x: side - 0.85 + (k % 3) * 0.85, y: y0 + Math.floor(k / 3) * 0.9, z: 0, w: 0.32, d: 0.32, h: 0.75, layer: 0 }))
    traces.push([[side, y0 + 1.4], [side, busY0]])
  }

  // The workers: a card per worker in a bank of parallel slots, centred, as long as its row of chips, a lane from the bus to each.
  const x0 = (X - bank) / 2
  const cards: Board['cards'] = []
  const lanes: Point[][] = []
  workers.forEach((w, n) => {
    const x = x0 + n * SLOT
    const i = add(w, [x + CARD / 2, cardY + 0.2 + (w.crates.length * CHIP) / 2, 0.3])
    cards.push({ x, y: cardY, w: CARD, d: length(w) })
    lanes.push([[x + CARD / 2, busY0], [x + CARD / 2, cardY]])
    w.crates.forEach((slug, k) => block({ slug, component: i, shape: 'chip', x: x + 0.13, y: cardY + 0.22 + k * CHIP, z: 0.08, w: CARD - 0.26, d: CHIP - 0.12, h: 0.18, layer: 0 }))
  })

  const uses = new Map<string, number>()
  for (const c of out) for (const s of c.crates) uses.set(s, (uses.get(s) ?? 0) + 1)
  return {
    components: out,
    blocks,
    board: { X, Y, sockets, cards, bus: { x0: MARGIN - 0.3, x1: X - MARGIN + 0.3, y0: busY0, y1: busY1 }, lanes, traces },
    shared: [...uses.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])),
  }
}

/** The loop's phases, from the number of blocks and of blocks put in focus. */
function timeline(n: number, focuses: number) {
  const stagger = Math.min(0.08, 7.5 / Math.max(1, n))
  const built = (n - 1) * stagger + DROP
  const focus = built + HOLD
  const explode = focus + FOCUS * focuses
  const collapse = explode + EXPLODE + EXPLODED
  const clear = collapse + COLLAPSE
  const end = clear + CLEAR + REST
  return { stagger, built, focus, explode, collapse, clear, end }
}

type Phase = 'assembling' | 'assembled' | 'focus' | 'exploded' | 'clearing' | 'ready'

interface Readout {
  phase: Phase
  /** The component being assembled. */
  component: number | null
  /** The crate in focus, by the loop. */
  focus: string | null
  /** How many blocks have landed. */
  landed: number
}

/** How far a block rises in the exploded view: off the board, and off the layer below by most of its height. */
const spread = (b: Block) => SEPARATE + b.layer * b.h * SPREAD

/** The top centre of a block, where its beam lands. */
const crown = (b: Block): Point3 => (b.shape === 'cube' || b.shape === 'chip' ? [b.x + b.w / 2, b.y + b.d / 2, b.z + b.h] : [b.x, b.y, b.z + b.h])

/** Painter's order: a block's distance towards the camera. */
const depth = (b: Block) => {
  const [x, y] = b.shape === 'cube' || b.shape === 'chip' ? [b.x + b.w / 2, b.y + b.d / 2] : [b.x, b.y]
  return ((x + y) * Math.cos(ELEVATION)) / Math.SQRT2 + (b.z + b.h / 2) * Math.sin(ELEVATION)
}

/** A circle at height z, as the ellipse it projects to. */
function ellipse(x: number, y: number, z: number, r: number) {
  const [cx, cy] = project(x, y, z)
  return { cx, cy, rx: Math.SQRT2 * r * S, ry: Math.SQRT2 * r * S * K }
}

/** A hexagon's corners at height z, two of its sides along the board's x axis like everything else on it. */
const hexagon = (x: number, y: number, z: number, r: number): Point3[] =>
  Array.from({ length: 6 }, (_, i) => {
    const a = (Math.PI / 3) * i
    return [x + r * Math.cos(a), y + r * Math.sin(a), z]
  })

/** A block's faces, back to front, and its flash last (the loop reaches it as the group's last child). */
function Faces({ b }: { b: Block }) {
  if (b.shape === 'cube' || b.shape === 'chip') {
    const [x0, x1, y0, y1, z0, z1] = [b.x, b.x + b.w, b.y, b.y + b.d, b.z, b.z + b.h]
    const top = points([x0, y0, z1], [x1, y0, z1], [x1, y1, z1], [x0, y1, z1])
    return (
      <>
        <polygon className="face left" points={points([x0, y1, z1], [x1, y1, z1], [x1, y1, z0], [x0, y1, z0])} />
        <polygon className="face right" points={points([x1, y1, z1], [x1, y0, z1], [x1, y0, z0], [x1, y1, z0])} />
        <polygon className="face top" points={top} />
        <polygon className="flash" opacity={0} points={top} />
      </>
    )
  }
  if (b.shape === 'hex') {
    const low = hexagon(b.x, b.y, b.z, b.w)
    const high = hexagon(b.x, b.y, b.z + b.h, b.w)
    // The sides facing the camera, drawn back to front; each takes the tone of the box face it is closest to.
    const sides = low
      .map((p, i) => ({ i, mid: [(p[0] + low[(i + 1) % 6][0]) / 2, (p[1] + low[(i + 1) % 6][1]) / 2] as Point }))
      .filter(({ mid }) => mid[0] - b.x + (mid[1] - b.y) > 0)
      .sort((a, c) => a.mid[0] + a.mid[1] - (c.mid[0] + c.mid[1]))
    const top = points(...high)
    return (
      <>
        {sides.map(({ i, mid }) => (
          <polygon key={i} className={`face ${mid[1] - b.y > mid[0] - b.x ? 'left' : 'right'}`} points={points(high[i], high[(i + 1) % 6], low[(i + 1) % 6], low[i])} />
        ))}
        <polygon className="face top" points={top} />
        <polygon className="flash" opacity={0} points={top} />
      </>
    )
  }
  // Round: the side as a band between the bottom and top ellipses, then the top.
  const lo = ellipse(b.x, b.y, b.z, b.w)
  const hi = ellipse(b.x, b.y, b.z + b.h, b.w)
  const side = `M${hi.cx - hi.rx} ${hi.cy}L${lo.cx - lo.rx} ${lo.cy}A${lo.rx} ${lo.ry} 0 0 0 ${lo.cx + lo.rx} ${lo.cy}L${hi.cx + hi.rx} ${hi.cy}Z`
  return (
    <>
      <path className="face left" d={side} />
      <ellipse className="face top" {...hi} />
      {b.shape === 'can' ? (
        // A can's vent: the cross scored into a capacitor's top.
        <path className="vent" d={`M${hi.cx - hi.rx * 0.55} ${hi.cy}L${hi.cx + hi.rx * 0.55} ${hi.cy}M${hi.cx} ${hi.cy - hi.ry * 0.55}L${hi.cx} ${hi.cy + hi.ry * 0.55}`} />
      ) : null}
      <ellipse className="flash" opacity={0} {...hi} />
    </>
  )
}

/** Appwrite as its makers count it: one platform of eight products, before the services and crates the board draws. */
const PLATFORMS = 1
const PRODUCTS = 8

/** The board: its body, the logo along its front edge, sockets, cards, the bus with its lanes and packets, and the traces. */
function Furniture({ plan }: { plan: Plan }) {
  const { X, Y, sockets, cards, bus, lanes, traces } = plan.board
  const [lx, ly] = project(0.7, Y, 0)
  return (
    <>
      <polygon className="board top" points={points([0, 0, 0], [X, 0, 0], [X, Y, 0], [0, Y, 0])} />
      <polygon className="board left" points={points([0, Y, 0], [X, Y, 0], [X, Y, -PLATE], [0, Y, -PLATE])} />
      <polygon className="board right" points={points([X, Y, 0], [X, 0, 0], [X, 0, -PLATE], [X, Y, -PLATE])} />
      {/* Appwrite's logo and the board's counts, printed along its front edge. */}
      <g transform={`matrix(1 ${K} 0 1 ${lx.toFixed(2)} ${(ly + 2.4).toFixed(2)})`}>
        <AppwriteLogo className="engraving-logo" transform="scale(0.44)" />
        <text className="engraving mono" x={66} y={8.4}>
          {PLATFORMS} PLATFORM · {PRODUCTS} PRODUCTS · {plan.components.length} SERVICES · {plan.shared.length} CRATES
        </text>
      </g>
      <polygon className="bus" points={points([bus.x0, bus.y0, 0], [bus.x1, bus.y0, 0], [bus.x1, bus.y1, 0], [bus.x0, bus.y1, 0])} />
      {traces.map((t, i) => (
        <path key={`t${i}`} className="trace" d={path(t)} />
      ))}
      {lanes.map((l, i) => (
        <path key={`l${i}`} className="trace lane" d={path(l)} />
      ))}
      {lanes.map(([from, to], i) => {
        // A packet: a small square riding the lane from the bus to its worker, out of step with the others.
        const s = 0.13
        const [dx, dy] = [(to[0] - from[0] - (to[1] - from[1])) * S, (to[0] - from[0] + (to[1] - from[1])) * S * K]
        return (
          <polygon
            key={`p${i}`}
            className="packet"
            points={points([from[0] - s, from[1] - s, 0.02], [from[0] + s, from[1] - s, 0.02], [from[0] + s, from[1] + s, 0.02], [from[0] - s, from[1] + s, 0.02])}
            style={{ '--dx': `${dx.toFixed(1)}px`, '--dy': `${dy.toFixed(1)}px`, animationDuration: `${(2.6 + ((i * 0.53) % 1.6)).toFixed(2)}s`, animationDelay: `${-((i * 1.13) % 4).toFixed(2)}s` } as CSSProperties}
          />
        )
      })}
      {sockets.map((s, i) => {
        if (s.kind === 'square' || s.kind === 'cans')
          return <polygon key={`s${i}`} className="plot" points={points([s.x, s.y, 0], [s.x + s.w, s.y, 0], [s.x + s.w, s.y + s.d, 0], [s.x, s.y + s.d, 0])} />
        if (s.kind === 'hex') return <polygon key={`s${i}`} className="plot" points={points(...hexagon(s.x, s.y, 0, s.w))} />
        return <ellipse key={`s${i}`} className="plot" {...ellipse(s.x, s.y, 0, s.w)} />
      })}
      {cards.map((c, i) => {
        const [x0, x1, y0, y1, z] = [c.x, c.x + c.w, c.y, c.y + c.d, 0.08]
        return (
          <g key={`c${i}`}>
            <polygon className="face card left" points={points([x0, y1, z], [x1, y1, z], [x1, y1, 0], [x0, y1, 0])} />
            <polygon className="face card right" points={points([x1, y1, z], [x1, y0, z], [x1, y0, 0], [x1, y1, 0])} />
            <polygon className="face card top" points={points([x0, y0, z], [x1, y0, z], [x1, y1, z], [x0, y1, z])} />
          </g>
        )
      })}
    </>
  )
}

/** The drawing itself: the board and the blocks, with the loop writing their positions straight to the DOM. */
const Scene = memo(function Scene({
  plan,
  onBlock,
  onLeave,
  onOpen,
  label,
}: {
  plan: Plan
  onBlock: (b: Block) => void
  onLeave: () => void
  onOpen: (slug: string) => void
  label: number | null
}) {
  const { X, Y } = plan.board
  // Back to front: each block is drawn after the ones it may hide.
  const drawn = useMemo(() => [...plan.blocks].sort((a, b) => depth(a) - depth(b)), [plan])
  const top = Math.min(project(0, 0, 0)[1], ...plan.blocks.map((b) => project(...crown(b))[1] - spread(b) * H)) - 34
  const bottom = project(X, Y, -PLATE - GROUND)[1] + 28
  const left = project(0, Y, 0)[0] - 8
  const right = project(X, 0, 0)[0] + 8
  const tag = label === null ? null : plan.components[label]
  return (
    <svg
      viewBox={`${left.toFixed(1)} ${top.toFixed(1)} ${(right - left).toFixed(1)} ${(bottom - top).toFixed(1)}`}
      className="block h-auto w-full"
      role="img"
      aria-label={`Appwrite, ${PLATFORMS} platform of ${PRODUCTS} products, drawn as ${plan.components.length} services built from ${plan.shared.length} crates`}
      onPointerLeave={onLeave}
    >
      {/* The board floats: its shadow on the ground below, softened, and everything above bobbing gently. */}
      <defs>
        <filter id="factory-shadow" x="-20%" y="-40%" width="140%" height="180%">
          <feGaussianBlur stdDeviation="16" />
        </filter>
      </defs>
      <polygon
        className="shadow"
        filter="url(#factory-shadow)"
        points={points([0.6, 0.6, -PLATE - GROUND], [X - 0.6, 0.6, -PLATE - GROUND], [X - 0.6, Y - 0.6, -PLATE - GROUND], [0.6, Y - 0.6, -PLATE - GROUND])}
      />
      <g className="floating">
        <g data-platform>
          <Furniture plan={plan} />
        </g>
        <g data-blocks>
          {drawn.map((b) => (
            <g key={b.order} data-block={b.order} className={`blk ${b.shape}`} onPointerEnter={() => onBlock(b)} onClick={() => onOpen(b.slug)}>
              <g>
                <Faces b={b} />
              </g>
            </g>
          ))}
        </g>
        <g data-beams>
          {plan.blocks.map((b) => {
            const [x, y] = project(...crown(b))
            return <line key={b.order} data-beam={b.order} className="beam" x1={x} y1={y - RISE * H - 30} x2={x} y2={y} opacity={0} />
          })}
        </g>
        {tag ? <Tag component={tag} /> : null}
      </g>
    </svg>
  )
})

/** A component's name on a leader line above it. */
function Tag({ component }: { component: Component }) {
  const [x, y] = project(...component.anchor)
  const text = `${component.title.toUpperCase()} · ${component.crates.length}`
  const width = text.length * 4.6 + 12
  return (
    <g className="tag" pointerEvents="none">
      <line x1={x} y1={y - 3} x2={x} y2={y - 22} />
      <rect x={x - width / 2} y={y - 34} width={width} height={12} />
      <text x={x} y={y - 25.6} textAnchor="middle">
        {text}
      </text>
    </g>
  )
}

export function Factory({ plan, libraries }: { plan: Plan; libraries: LibrarySummary[] }) {
  const navigate = useNavigate()
  const host = useRef<HTMLDivElement>(null)
  // The loop's three most telling blocks: the most shared, one in the middle, and one fewer services share.
  const focuses = useMemo(() => {
    const multi = plan.shared.filter(([, n]) => n > 1)
    const pick = [multi[0], multi[Math.floor(multi.length / 3)], multi[Math.floor((multi.length * 2) / 3)]]
    return [...new Set(pick.filter(Boolean).map(([slug]) => slug))]
  }, [plan])
  const times = useMemo(() => timeline(plan.blocks.length, focuses.length), [plan, focuses])
  const [hover, setHover] = useState<Block | null>(null)
  const [readout, setReadout] = useState<Readout>({ phase: 'assembled', component: null, focus: null, landed: plan.blocks.length })
  // Starts on the assembled board, just before the shared blocks light up.
  const clock = useRef({ base: 0, at: times.built + HOLD / 2, running: false })
  const hoverRef = useRef<Block | null>(null)
  hoverRef.current = hover
  // The drawing's nodes, by assembly order, and what was last written to each: a frame only touches what changed.
  const nodes = useRef<{ root: SVGSVGElement; blocks: { g: SVGGElement; inner: SVGGElement; flash: SVGElement; beam: SVGElement | null; last: string }[] } | null>(null)

  const at = useCallback(() => {
    const c = clock.current
    return c.running ? ((performance.now() - c.base) / 1000) % times.end : c.at
  }, [times])

  /** Draws the frame at `t`: every block's lift and fade, the landing flashes and beams, and the focus. */
  const frame = useCallback(
    (t: number, motion: boolean) => {
      const root = host.current?.querySelector('svg')
      if (!root) return
      if (nodes.current?.root !== root) {
        nodes.current = {
          root,
          blocks: plan.blocks.map((b) => {
            const g = root.querySelector<SVGGElement>(`[data-block="${b.order}"]`)!
            const inner = g.firstElementChild as SVGGElement
            return { g, inner, flash: inner.lastElementChild as SVGElement, beam: root.querySelector<SVGElement>(`[data-beam="${b.order}"]`), last: '' }
          }),
        }
      }
      const ex =
        t < times.explode
          ? 0
          : t < times.explode + EXPLODE
            ? easeInOut((t - times.explode) / EXPLODE)
            : t < times.collapse
              ? 1
              : t < times.clear
                ? 1 - easeInOut((t - times.collapse) / COLLAPSE)
                : 0
      const index = t >= times.focus && t < times.explode ? Math.floor((t - times.focus) / FOCUS) : -1
      const loopFocus = index >= 0 ? (focuses[index] ?? null) : null
      const focus = hoverRef.current?.slug ?? loopFocus
      const n = plan.blocks.length
      let landed = 0
      let active: number | null = null
      for (const b of plan.blocks) {
        const start = b.order * times.stagger
        let lift = 0
        let opacity = 1
        let flash = 0
        let beam = 0
        if (t < times.built + 0.001 && motion) {
          const p = clamp((t - start) / DROP)
          lift = (1 - easeOut(p)) * RISE
          opacity = t < start ? 0 : clamp(p * 2.5)
          beam = p > 0 && p < 1 ? 0.75 * (1 - p) : 0
          flash = t >= start + DROP ? clamp(1 - (t - start - DROP) / 0.45) : 0
          if (t >= start) active = b.component
          if (p >= 1) landed++
        } else if (t >= times.clear && motion) {
          const p = clamp((t - times.clear - (n - 1 - b.order) * ((CLEAR - LIFT) / Math.max(1, n))) / LIFT)
          lift = easeIn(p) * RISE
          opacity = 1 - p
        } else {
          landed++
        }
        lift += ex * spread(b)
        const node = nodes.current.blocks[b.order]
        const hi = !!focus && b.slug === focus
        const state = `${lift.toFixed(3)} ${opacity.toFixed(3)} ${flash.toFixed(3)} ${beam.toFixed(3)} ${hi}`
        if (state === node.last) continue
        node.last = state
        node.g.setAttribute('transform', lift ? `translate(0 ${(-lift * H).toFixed(2)})` : '')
        node.g.style.visibility = opacity <= 0 ? 'hidden' : ''
        node.inner.setAttribute('opacity', opacity.toFixed(3))
        node.flash.setAttribute('opacity', flash.toFixed(3))
        node.beam?.setAttribute('opacity', beam.toFixed(3))
        if (hi) node.g.setAttribute('data-hi', '')
        else node.g.removeAttribute('data-hi')
      }
      const platform = root.querySelector<SVGGElement>('[data-platform]')
      platform?.setAttribute('transform', ex ? `translate(0 ${(ex * SEPARATE * 0.6 * H).toFixed(2)})` : '')
      if (focus) root.setAttribute('data-focus', '')
      else root.removeAttribute('data-focus')
      const phase: Phase = !motion
        ? 'assembled'
        : t < times.built
          ? 'assembling'
          : t < times.focus
            ? 'assembled'
            : t < times.explode
              ? 'focus'
              : t < times.clear
                ? 'exploded'
                : t < times.end - REST
                  ? 'clearing'
                  : 'ready'
      setReadout((r) =>
        r.phase === phase && r.component === active && r.focus === loopFocus && r.landed === landed ? r : { phase, component: active, focus: loopFocus, landed },
      )
    },
    [plan, times, focuses],
  )

  // The loop: runs while the drawing is on screen and motion is welcome, and holds still while a block is inspected.
  useEffect(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)')
    let raf = 0
    let visible = true
    const tick = () => {
      frame(at(), true)
      raf = requestAnimationFrame(tick)
    }
    const sync = () => {
      const c = clock.current
      const run = visible && !document.hidden && !hoverRef.current && !reduced.matches
      if (run && !c.running) {
        c.base = performance.now() - c.at * 1000
        c.running = true
        raf = requestAnimationFrame(tick)
      } else if (!run && c.running) {
        c.at = at()
        c.running = false
        cancelAnimationFrame(raf)
      }
      if (!c.running) frame(c.at, !reduced.matches)
    }
    const observer = new IntersectionObserver(([e]) => {
      visible = e.isIntersecting
      sync()
    })
    if (host.current) observer.observe(host.current)
    document.addEventListener('visibilitychange', sync)
    reduced.addEventListener('change', sync)
    sync()
    return () => {
      observer.disconnect()
      document.removeEventListener('visibilitychange', sync)
      reduced.removeEventListener('change', sync)
      cancelAnimationFrame(raf)
      clock.current.at = at()
      clock.current.running = false
    }
  }, [frame, at, hover])

  const onBlock = useCallback((b: Block) => setHover(b), [])
  const onLeave = useCallback(() => setHover(null), [])
  const onOpen = useCallback((slug: string) => navigate({ to: '/$lib', params: { lib: slug } }), [navigate])

  const bySlug = useMemo(() => new Map(libraries.map((l) => [l.slug, l])), [libraries])
  const uses = useMemo(() => new Map(plan.shared), [plan])
  const focus = hover?.slug ?? readout.focus
  const lib = focus ? bySlug.get(focus) : undefined
  const label = hover ? hover.component : readout.phase === 'assembling' ? readout.component : null
  const recent = readout.phase === 'assembling' ? plan.blocks.slice(Math.max(0, readout.landed - 4), readout.landed).reverse() : []
  const status: ReactNode = hover
    ? 'Inspecting'
    : {
        assembling: `Assembling ${readout.component !== null ? plan.components[readout.component].title : ''}`,
        assembled: 'Assembled',
        focus: 'Shared block',
        exploded: 'Exploded view',
        clearing: 'Clearing the board',
        ready: 'Ready',
      }[readout.phase]

  return (
    <div ref={host} className="factory relative grid gap-4">
      <Scene plan={plan} onBlock={onBlock} onLeave={onLeave} onOpen={onOpen} label={label} />

      {/* The readout: what the board is doing, the log of placed blocks, and the block in focus. Under the board on phones, in its top corner wider. */}
      <div className="pointer-events-none grid w-full max-w-[320px] min-w-0 content-start gap-3 font-mono text-[11px] md:absolute md:top-0 md:left-0">
        <div className="flex items-center gap-2 text-foreground">
          <span className={`marker ${readout.phase === 'assembling' && !hover ? 'animate-pulse' : ''}`} aria-hidden />
          <span className="label truncate">{status}</span>
        </div>
        {focus ? (
          <div className="grid gap-1 border-l border-highlight pl-3">
            <span className="text-[13px] text-foreground">{lib?.rust?.crate ?? focus}</span>
            <span className="text-muted-foreground">
              in {uses.get(focus) ?? 0} of {plan.components.length} services
            </span>
            {hover ? <span className="text-muted-foreground">{plan.components[hover.component].title} · click to open</span> : null}
          </div>
        ) : (
          <ol className="m-0 hidden min-h-[68px] list-none gap-0.5 p-0 text-muted-foreground md:grid">
            {recent.map((b, i) => (
              <li key={b.order} className={`truncate ${i === 0 ? 'text-foreground' : ''}`} style={{ opacity: 1 - i * 0.22 }}>
                <span className="text-highlight">+</span> {b.slug} <span className="opacity-50">→</span> {plan.components[b.component].title}
              </li>
            ))}
          </ol>
        )}
      </div>
    </div>
  )
}
