import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useEffect, useId, useMemo, useState, type ReactNode } from 'react'
import { chord, ribbon, type Chord, type ChordSubgroup } from 'd3-chord'
import { arc } from 'd3-shape'
import { APPWRITE, type Graph, type GraphNode, type Index } from '@/lib/docs'
import { links as linksOf, neighbours, weight, type Link as Edge, type Runtime } from '@/lib/graph'
import { useMigration } from '@/lib/prefs'
import { StatusBadge, StatusDot } from './Status'
import { MigrationOnly } from './Migration'

const SIZE = 840

/** One hue per category, spaced around the wheel so none share a colour. */
const CATEGORY_COLOR: Record<string, string> = {
  servers: 'oklch(0.72 0.14 185)',
  transport: 'oklch(0.70 0.16 55)',
  data: 'oklch(0.65 0.20 290)',
  security: 'oklch(0.65 0.20 18)',
  observability: 'oklch(0.76 0.15 95)',
  platform: 'oklch(0.70 0.16 145)',
  utilities: 'oklch(0.64 0.18 255)',
  integrations: 'oklch(0.68 0.20 340)',
  [APPWRITE]: 'oklch(0.66 0.23 8)',
}

type Mode = 'libraries' | 'categories'

type ChordNode = {
  key: string
  group: string
  label: string
  color: string
  gap?: boolean
}

type ChordTie = {
  a: string
  b: string
  weight: number
}

type Selection = { type: 'node'; key: string } | { type: 'group'; key: string } | { type: 'tie'; a: string; b: string }

const chip =
  'inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-2.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

function tieKey(a: string, b: string) {
  return a < b ? `${a}|${b}` : `${b}|${a}`
}

function clipLabel(value: string, max: number) {
  if (value.length <= max) return value
  return `${value.slice(0, Math.max(1, max - 1))}…`
}

function layoutWeight(strength: number) {
  return Math.pow(Math.max(strength, 0), 0.72)
}

function polar(angle: number, radius: number) {
  const shifted = angle - Math.PI / 2
  return { x: Math.cos(shifted) * radius, y: Math.sin(shifted) * radius }
}

function labelPlacement(start: number, end: number, radius: number) {
  const mid = (start + end) / 2
  const degrees = (mid * 180) / Math.PI - 90
  const flip = mid > Math.PI
  return {
    transform: `rotate(${degrees}) translate(${radius}) ${flip ? 'rotate(180)' : ''}`,
    anchor: flip ? ('end' as const) : ('start' as const),
    dx: flip ? -5 : 5,
  }
}

function outerLabel(start: number, end: number, radius: number) {
  const mid = (start + end) / 2
  const point = polar(mid, radius)
  const cosine = Math.cos(mid - Math.PI / 2)
  return {
    x: point.x,
    y: point.y,
    anchor: cosine > 0.4 ? ('start' as const) : cosine < -0.4 ? ('end' as const) : ('middle' as const),
  }
}

function colorForGroup(id: string) {
  return CATEGORY_COLOR[id] ?? 'var(--muted-foreground)'
}

function gapNode(i: number): ChordNode {
  return { key: `gap-${i}`, group: `gap-${i}`, label: '', color: 'transparent', gap: true }
}

/** Dummy slices between categories, including the seam where the last meets the first. */
function withCategoryGaps(nodes: ChordNode[]): ChordNode[] {
  if (nodes.length < 2) return nodes
  const out: ChordNode[] = []
  for (const node of nodes) {
    const prev = out[out.length - 1]
    if (prev && !prev.gap && prev.group !== node.group) out.push(gapNode(out.length))
    out.push(node)
  }
  const first = out.find((n) => !n.gap)
  const last = [...out].reverse().find((n) => !n.gap)
  if (first && last && first.group !== last.group && !out[out.length - 1]?.gap) out.push(gapNode(out.length))
  return out
}

function colorForGroups(ids: string[]) {
  return new Map(ids.map((id) => [id, colorForGroup(id)]))
}

function degreeOf(links: Edge[]) {
  const degree = new Map<string, number>()
  for (const l of links) {
    const w = weight(l)
    degree.set(l.source, (degree.get(l.source) ?? 0) + w)
    degree.set(l.target, (degree.get(l.target) ?? 0) + w)
  }
  return degree
}

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

function Segment({ value, onChange, options, label }: { value: string; onChange: (v: string) => void; options: { key: string; label: string }[]; label: string }) {
  return (
    <div role="group" aria-label={label} className="inline-flex h-8 items-center gap-0.5 rounded-md border border-border bg-card px-0.5">
      {options.map((o) => (
        <button key={o.key} type="button" aria-pressed={value === o.key} onClick={() => onChange(o.key)} className="inline-flex h-7 items-center rounded px-2.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-accent aria-pressed:text-foreground">
          {o.label}
        </button>
      ))}
    </div>
  )
}

function Toggle({ on, onChange, children }: { on: boolean; onChange: (on: boolean) => void; children: ReactNode }) {
  return (
    <button type="button" aria-pressed={on} onClick={() => onChange(!on)} className={chip}>
      <span className={`inline-flex size-3.5 items-center justify-center rounded-[3px] border ${on ? 'border-foreground bg-foreground' : 'border-ring'}`}>{on ? <span className="size-1.5 rounded-[1px] bg-background" /> : null}</span>
      {children}
    </button>
  )
}

function Chord({
  nodes,
  ties,
  selection,
  onSelect,
  grouped,
  compactLabels,
  groupTitles,
}: {
  nodes: ChordNode[]
  ties: ChordTie[]
  selection: Selection | null
  onSelect: (next: Selection | null, commit?: boolean) => void
  grouped: boolean
  compactLabels: boolean
  groupTitles: Map<string, string>
}) {
  const uid = useId().replace(/:/g, '')
  const geometry = grouped
    ? { inner: 198, outer: 212, bandIn: 220, bandOut: 232, label: 240, groupLabel: 348, pad: 0.012, font: nodes.length > 16 ? 9.5 : 11 }
    : { inner: 210, outer: 236, bandIn: 0, bandOut: 0, label: 254, groupLabel: 0, pad: 0.04, font: 14 }

  const model = useMemo(() => {
    const matrixNodes = withCategoryGaps(nodes)

    const index = new Map(matrixNodes.map((node, i) => [node.key, i]))
    const size = matrixNodes.length
    if (size < 2) return null
    const matrix = Array.from({ length: size }, () => Array<number>(size).fill(0))
    for (const tie of ties) {
      const i = index.get(tie.a)
      const j = index.get(tie.b)
      if (i == null || j == null || i === j) continue
      const w = layoutWeight(tie.weight)
      if (w <= 0) continue
      matrix[i][j] = w
      matrix[j][i] = w
    }
    const mass = matrix.reduce((sum, row) => sum + row.reduce((inner, value) => inner + value, 0), 0)
    const gap = Math.max(mass, 1) * 0.014
    matrixNodes.forEach((node, i) => {
      const row = matrix[i].reduce((s, v) => s + v, 0)
      if (node.gap || row === 0) matrix[i][i] = gap
    })
    if (matrix.every((row) => row.every((v) => v === 0))) return null

    const laid = chord().padAngle(geometry.pad).sortGroups(null).sortSubgroups((a, b) => b - a)(matrix)
    const drawRibbon = ribbon<Chord, ChordSubgroup>().radius(geometry.inner - 1)
    type ArcAngles = { startAngle: number; endAngle: number }
    const ring = arc<ArcAngles>().innerRadius(geometry.inner).outerRadius(geometry.outer)
    const band = arc<ArcAngles>().innerRadius(geometry.bandIn).outerRadius(geometry.bandOut)

    const sectors: Array<{ key: string; color: string; startAngle: number; endAngle: number }> = []
    if (grouped) {
      for (const group of laid.groups) {
        const node = matrixNodes[group.index]
        if (!node || node.gap) continue
        const last = sectors[sectors.length - 1]
        if (last && last.key === node.group) last.endAngle = group.endAngle
        else sectors.push({ key: node.group, color: node.color, startAngle: group.startAngle, endAngle: group.endAngle })
      }
    }

    const ribbons = laid
      .filter((item) => item.source && item.target && item.source.index !== item.target.index)
      .filter((item) => {
        const source = matrixNodes[item.source.index]
        const target = matrixNodes[item.target.index]
        return source && target && !source.gap && !target.gap
      })
      .sort((a, b) => a.source.value + a.target.value - (b.source.value + b.target.value))

    return { matrixNodes, laid, drawRibbon, ring, band, sectors, ribbons }
  }, [geometry.bandIn, geometry.bandOut, geometry.inner, geometry.outer, geometry.pad, grouped, nodes, ties])

  if (!model) return null

  const { matrixNodes, laid, drawRibbon, ring, band, sectors, ribbons } = model
  const groupOf = new Map(nodes.map((node) => [node.key, node.group]))

  function nodeTone(node: ChordNode) {
    if (!selection) return 'idle' as const
    if (selection.type === 'group') return node.group === selection.key ? ('active' as const) : ('dim' as const)
    if (selection.type === 'tie') return node.key === selection.a || node.key === selection.b ? ('active' as const) : ('dim' as const)
    if (node.key === selection.key) return 'active' as const
    const linked = ties.some((tie) => (tie.a === selection.key || tie.b === selection.key) && (tie.a === node.key || tie.b === node.key))
    return linked ? ('active' as const) : ('dim' as const)
  }

  function tieTone(a: string, b: string) {
    if (!selection) return 'idle' as const
    if (selection.type === 'tie') return tieKey(selection.a, selection.b) === tieKey(a, b) ? ('active' as const) : ('dim' as const)
    if (selection.type === 'group') return groupOf.get(a) === selection.key || groupOf.get(b) === selection.key ? ('active' as const) : ('dim' as const)
    return a === selection.key || b === selection.key ? ('active' as const) : ('dim' as const)
  }

  function sectorTone(group: string) {
    if (!selection) return 'idle' as const
    if (selection.type === 'group') return group === selection.key ? ('active' as const) : ('dim' as const)
    if (selection.type === 'tie') return groupOf.get(selection.a) === group || groupOf.get(selection.b) === group ? ('active' as const) : ('dim' as const)
    if (groupOf.get(selection.key) === group) return 'active' as const
    const linked = ties.some((tie) => {
      if (tie.a !== selection.key && tie.b !== selection.key) return false
      return groupOf.get(tie.a === selection.key ? tie.b : tie.a) === group
    })
    return linked ? ('active' as const) : ('dim' as const)
  }

  return (
    <svg viewBox={`0 0 ${SIZE} ${SIZE}`} role="img" aria-label="Map of the Utopia libraries" preserveAspectRatio="xMidYMid meet" className="mx-auto block size-full max-h-full max-w-full text-foreground">
      <g transform={`translate(${SIZE / 2} ${SIZE / 2})`}>
        <circle r={geometry.groupLabel + 90} fill="transparent" onClick={() => onSelect(null, true)} />
        <defs>
          {ribbons.map((item) => {
            const source = matrixNodes[item.source.index]
            const target = matrixNodes[item.target.index]
            if (!source || !target) return null
            const from = polar((item.source.startAngle + item.source.endAngle) / 2, geometry.inner)
            const to = polar((item.target.startAngle + item.target.endAngle) / 2, geometry.inner)
            return (
              <linearGradient key={tieKey(source.key, target.key)} id={`${uid}-${item.source.index}-${item.target.index}`} gradientUnits="userSpaceOnUse" x1={from.x} y1={from.y} x2={to.x} y2={to.y}>
                <stop offset="0%" stopColor={source.color} />
                <stop offset="100%" stopColor={target.color} />
              </linearGradient>
            )
          })}
        </defs>
        {ribbons.map((item) => {
          const source = matrixNodes[item.source.index]
          const target = matrixNodes[item.target.index]
          if (!source || !target) return null
          const d = (drawRibbon as (c: Chord) => string | null)(item)
          if (!d) return null
          const tone = tieTone(source.key, target.key)
          return (
            <path
              key={tieKey(source.key, target.key)}
              d={d}
              fill={`url(#${uid}-${item.source.index}-${item.target.index})`}
              stroke="var(--card)"
              strokeWidth={0.7}
              strokeOpacity={tone === 'dim' ? 0.15 : 0.9}
              style={{ fillOpacity: tone === 'active' ? 0.92 : tone === 'dim' ? 0.06 : 0.42, transition: 'fill-opacity 160ms ease, stroke-opacity 160ms ease', cursor: 'pointer' }}
              onMouseEnter={() => onSelect({ type: 'tie', a: source.key, b: target.key })}
              onClick={() => onSelect({ type: 'tie', a: source.key, b: target.key }, true)}
            >
              <title>{`${source.label} and ${target.label}`}</title>
            </path>
          )
        })}
        {sectors.map((sector) => {
          const d = band(sector)
          if (!d) return null
          const tone = sectorTone(sector.key)
          return (
            <path
              key={sector.key}
              d={d}
              fill={sector.color}
              style={{ fillOpacity: tone === 'dim' ? 0.2 : 0.95, cursor: 'pointer', transition: 'fill-opacity 160ms ease' }}
              onMouseEnter={() => onSelect({ type: 'group', key: sector.key })}
              onClick={() => onSelect({ type: 'group', key: sector.key }, true)}
            />
          )
        })}
        {laid.groups.map((group) => {
          const node = matrixNodes[group.index]
          if (!node || node.gap) return null
          const d = ring(group)
          if (!d) return null
          const tone = nodeTone(node)
          const span = group.endAngle - group.startAngle
          const showLabel = (tone === 'active' || (!compactLabels && span > (grouped ? 0.055 : 0.04))) && !(grouped && node.group === APPWRITE && tone !== 'active')
          const pack = grouped ? labelPlacement(group.startAngle, group.endAngle, geometry.label) : null
          const category = grouped ? null : outerLabel(group.startAngle, group.endAngle, geometry.label)
          return (
            <g key={node.key}>
              <path
                d={d}
                fill={node.color}
                stroke="var(--card)"
                strokeWidth={1}
                style={{ fillOpacity: tone === 'dim' ? 0.28 : 1, cursor: 'pointer', transition: 'fill-opacity 160ms ease' }}
                onMouseEnter={() => onSelect({ type: 'node', key: node.key })}
                onClick={() => onSelect({ type: 'node', key: node.key }, true)}
              >
                <title>{node.label}</title>
              </path>
              {showLabel && pack ? (
                <text
                  transform={pack.transform}
                  dx={pack.dx}
                  textAnchor={pack.anchor}
                  dominantBaseline="central"
                  fontSize={geometry.font}
                  className={`font-sans ${tone === 'active' ? 'fill-foreground' : 'fill-muted-foreground'}`}
                  style={{ cursor: 'pointer', opacity: tone === 'dim' ? 0.35 : 1 }}
                  onMouseEnter={() => onSelect({ type: 'node', key: node.key })}
                >
                  {clipLabel(node.label, 14)}
                </text>
              ) : null}
              {showLabel && category ? (
                <text
                  x={category.x}
                  y={category.y}
                  textAnchor={category.anchor}
                  dominantBaseline="central"
                  fontSize={13}
                  letterSpacing="0.06em"
                  className="font-sans uppercase"
                  style={{
                    cursor: 'pointer',
                    opacity: tone === 'dim' ? 0.35 : 1,
                    paintOrder: 'stroke',
                    stroke: 'var(--card)',
                    strokeWidth: 5,
                    strokeLinejoin: 'round',
                    fill: node.color,
                  }}
                  onMouseEnter={() => onSelect({ type: 'node', key: node.key })}
                >
                  {node.label}
                </text>
              ) : null}
            </g>
          )
        })}
        {grouped
          ? sectors.map((sector) => {
              const title = groupTitles.get(sector.key) ?? sector.key
              const placement = outerLabel(sector.startAngle, sector.endAngle, geometry.groupLabel)
              const tone = sectorTone(sector.key)
              return (
                <text
                  key={`group-${sector.key}`}
                  x={placement.x}
                  y={placement.y}
                  textAnchor={placement.anchor}
                  dominantBaseline="central"
                  fontSize={13}
                  letterSpacing="0.06em"
                  className="font-sans uppercase"
                  style={{
                    fill: sector.color,
                    cursor: 'pointer',
                    opacity: tone === 'dim' ? 0.28 : 1,
                    paintOrder: 'stroke',
                    stroke: 'var(--card)',
                    strokeWidth: 5,
                    strokeLinejoin: 'round',
                  }}
                  onMouseEnter={() => onSelect({ type: 'group', key: sector.key })}
                  onClick={() => onSelect({ type: 'group', key: sector.key }, true)}
                >
                  {title}
                </text>
              )
            })
          : null}
      </g>
    </svg>
  )
}

function tagsOf(link: Edge, migration: boolean) {
  return [migration && link.rust ? 'Rust' : '', migration && link.php ? 'PHP' : '', link.dev ? 'dev' : ''].filter(Boolean)
}

function linkBetween(links: Edge[], a: string, b: string) {
  return links.find((l) => (l.source === a && l.target === b) || (l.source === b && l.target === a))
}

function StrengthBar({ value, max, color }: { value: number; max: number; color: string }) {
  const width = max > 0 ? Math.max(8, (value / max) * 100) : 0
  return (
    <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted">
      <div className="h-full rounded-full" style={{ width: `${width}%`, background: color }} />
    </div>
  )
}

function StatCell({ value, label }: { value: string; label: string }) {
  return (
    <div className="min-w-0">
      <div className="font-mono text-[22px] leading-none tracking-tight tabular-nums">{value}</div>
      <div className="mt-1 font-mono text-[9px] tracking-[0.18em] text-muted-foreground uppercase">{label}</div>
    </div>
  )
}

function NameRow({ node, color, onSelect, migration }: { node: GraphNode; color: string; onSelect: (id: string) => void; migration: boolean }) {
  return (
    <span className="flex min-w-0 items-center gap-2">
      <span className="size-2.5 flex-none rounded-full" style={{ background: color }} />
      <button type="button" onClick={() => onSelect(node.id)} className="flex min-w-0 items-center gap-1.5 text-left hover:underline">
        {node.status ? (
          <MigrationOnly>
            <StatusDot status={node.status} />
          </MigrationOnly>
        ) : null}
        <span className="truncate text-[15px] font-medium tracking-tight">{node.title}</span>
      </button>
      {node.kind === 'app' ? null : (
        <Link to="/$lib" params={{ lib: node.id }} className="truncate font-mono text-[11px] text-muted-foreground hover:text-foreground">
          {node.rust ?? (migration ? node.php : null)}
        </Link>
      )}
    </span>
  )
}

function LibraryBoard({
  node,
  out,
  into,
  byId,
  colors,
  titles,
  onSelect,
  migration,
}: {
  node: GraphNode
  out: Edge[]
  into: Edge[]
  byId: Map<string, GraphNode>
  colors: Map<string, string>
  titles: Map<string, string>
  onSelect: (id: string) => void
  migration: boolean
}) {
  const seen = new Set<string>()
  const rivals: { other: GraphNode; link: Edge; strength: number }[] = []
  for (const l of [...into, ...out]) {
    const id = l.source === node.id ? l.target : l.source
    if (seen.has(id)) continue
    seen.add(id)
    const other = byId.get(id)
    if (!other) continue
    rivals.push({ other, link: l, strength: weight(l) })
  }
  rivals.sort((a, b) => b.strength - a.strength || a.other.title.localeCompare(b.other.title))
  const top = rivals.slice(0, 5)
  const peak = top[0]?.strength ?? 1
  const category = titles.get(groupOf(node)) ?? 'Library'
  const color = colors.get(groupOf(node)) ?? 'var(--muted-foreground)'
  return (
    <div>
      <div className="flex items-center gap-2">
        <span className="size-1.5 rounded-full" style={{ background: color }} />
        <span className="font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">{category}</span>
      </div>
      <div className="mt-3 flex items-start justify-between gap-2">
        {node.kind === 'app' ? (
          <h2 className="text-[26px] leading-[1.05] font-medium tracking-tight">{node.title}</h2>
        ) : (
          <Link to="/$lib" params={{ lib: node.id }} className="text-[26px] leading-[1.05] font-medium tracking-tight hover:underline">
            {node.title}
          </Link>
        )}
        {node.status ? (
          <MigrationOnly>
            <StatusBadge status={node.status} />
          </MigrationOnly>
        ) : null}
      </div>
      {node.description ? <p className="mt-2 text-[13px] text-muted-foreground">{node.description}</p> : null}
      <div className="mt-5 grid grid-cols-2 gap-3 border-y border-border py-3">
        <StatCell value={String(out.length)} label="Depends on" />
        <StatCell value={String(into.length)} label="Used by" />
      </div>
      {node.kind === 'app' ? (
        <MigrationOnly>
          <Link to="/appwrite" className="mt-3 inline-block text-[13px] hover:underline">
            Appwrite migration
          </Link>
        </MigrationOnly>
      ) : (
        <div className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[13px]">
          <Link to="/$lib" params={{ lib: node.id }} className="hover:underline">
            Overview
          </Link>
          <Link to="/$lib/dependencies" params={{ lib: node.id }} className="hover:underline">
            Dependencies
          </Link>
        </div>
      )}
      <div className="mt-5 font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">Strongest ties</div>
      <div className="mt-3 flex flex-col gap-3.5">
        {top.length ? (
          top.map((rival, i) => {
      const depends = out.some((l) => l.target === rival.other.id)
      const used = into.some((l) => l.source === rival.other.id)
      const direction = depends && used ? 'Both ways' : depends ? 'Depends on' : 'Used by'
            const tags = tagsOf(rival.link, migration)
            const rivalColor = colors.get(groupOf(rival.other)) ?? 'var(--muted-foreground)'
            return (
              <div key={rival.other.id}>
                <div className="flex items-start gap-3">
                  <span className="w-5 pt-1 font-mono text-[11px] text-muted-foreground tabular-nums">{String(i + 1).padStart(2, '0')}</span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                      <NameRow node={rival.other} color={rivalColor} onSelect={onSelect} migration={migration} />
                      <span className="shrink-0 pt-1 font-mono text-[18px] leading-none tabular-nums">{rival.strength}</span>
                    </div>
                    <StrengthBar value={rival.strength} max={peak} color={rivalColor} />
                    <div className="mt-1.5 font-mono text-[10px] tracking-wide text-muted-foreground">
                      {direction}
                      {tags.length ? ` · ${tags.join(' · ')}` : ''}
                    </div>
                  </div>
                </div>
              </div>
            )
          })
        ) : (
          <p className="m-0 text-[13px] text-muted-foreground">No Utopia library.</p>
        )}
      </div>
    </div>
  )
}

function TieBoard({
  a,
  b,
  link,
  byId,
  colors,
  onSelect,
  migration,
}: {
  a: string
  b: string
  link: Edge | undefined
  byId: Map<string, GraphNode>
  colors: Map<string, string>
  onSelect: (id: string) => void
  migration: boolean
}) {
  const left = byId.get(a)
  const right = byId.get(b)
  if (!left || !right) return <p className="m-0 font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">Hover a library</p>
  const leftColor = colors.get(groupOf(left)) ?? 'var(--muted-foreground)'
  const rightColor = colors.get(groupOf(right)) ?? 'var(--muted-foreground)'
  const aDepends = link?.source === a
  const bDepends = link?.source === b
  const story = aDepends && bDepends ? 'Each depends on the other' : aDepends ? `${left.title} depends on ${right.title}` : `${right.title} depends on ${left.title}`
  const tags = link ? tagsOf(link, migration) : []
  return (
    <div>
      <div className="font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">Head to head</div>
      <div className="mt-4 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
        <button type="button" onClick={() => onSelect(left.id)} className="min-w-0 text-left">
          <span className="mb-2 inline-block size-3 rounded-full" style={{ background: leftColor }} />
          <span className="block truncate text-[15px] font-medium tracking-tight hover:underline">{left.title}</span>
        </button>
        <div className="text-center">
          <div className="font-mono text-[28px] leading-none tabular-nums">{link ? weight(link) : 0}</div>
          <div className="mt-1 font-mono text-[9px] tracking-[0.18em] text-muted-foreground uppercase">Strength</div>
        </div>
        <button type="button" onClick={() => onSelect(right.id)} className="min-w-0 text-right">
          <span className="mb-2 ml-auto inline-block size-3 rounded-full" style={{ background: rightColor }} />
          <span className="block truncate text-[15px] font-medium tracking-tight hover:underline">{right.title}</span>
        </button>
      </div>
      <p className="mt-5 border-y border-border py-3 text-[13px] text-muted-foreground">
        {story}
        {tags.length ? ` · ${tags.join(' · ')}` : ''}
      </p>
    </div>
  )
}

function groupOf(n: GraphNode) {
  return n.kind === 'app' ? APPWRITE : (n.category ?? 'uncategorized')
}

function pairsFor(shown: Edge[], byId: Map<string, GraphNode>, match: (left: GraphNode, right: GraphNode) => boolean) {
  return shown
    .map((l) => {
      const left = byId.get(l.source)
      const right = byId.get(l.target)
      if (!left || !right || !match(left, right)) return null
      return { left, right, link: l }
    })
    .filter((p): p is { left: GraphNode; right: GraphNode; link: Edge } => !!p)
    .sort((a, b) => weight(b.link) - weight(a.link))
    .slice(0, 4)
}

function CategoryBoard({
  title,
  color,
  pairs,
  onSelect,
}: {
  title: string
  color: string
  pairs: { left: GraphNode; right: GraphNode; link: Edge }[]
  onSelect: (id: string) => void
}) {
  const peak = pairs[0] ? weight(pairs[0].link) : 1
  return (
    <div>
      <div className="flex items-center gap-2">
        <span className="size-1.5 rounded-full" style={{ background: color }} />
        <span className="font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">{title}</span>
      </div>
      <div className="mt-2 text-[22px] font-medium tracking-tight">Strongest pairs</div>
      <div className="mt-4 flex flex-col gap-3.5">
        {pairs.length ? (
          pairs.map((pair) => {
            const strength = weight(pair.link)
            return (
              <div key={`${pair.left.id}-${pair.right.id}`}>
                <div className="flex items-center justify-between gap-3">
                  <button type="button" onClick={() => onSelect(pair.left.id)} className="truncate text-[13px] font-medium hover:underline">
                    {pair.left.title}
                  </button>
                  <span className="text-muted-foreground">·</span>
                  <button type="button" onClick={() => onSelect(pair.right.id)} className="truncate text-[13px] font-medium hover:underline">
                    {pair.right.title}
                  </button>
                  <span className="shrink-0 font-mono text-[18px] leading-none tabular-nums">{strength}</span>
                </div>
                <StrengthBar value={strength} max={peak} color={color} />
                <div className="mt-1.5 font-mono text-[10px] tracking-wide text-muted-foreground">{pair.link.source === pair.left.id ? `${pair.left.title} depends on ${pair.right.title}` : `${pair.right.title} depends on ${pair.left.title}`}</div>
              </div>
            )
          })
        ) : (
          <p className="m-0 text-[13px] text-muted-foreground">No ties in this category.</p>
        )}
      </div>
    </div>
  )
}

function groupTitle(id: string, titles: Map<string, string>) {
  return titles.get(id) ?? id
}

function Board({
  selection,
  shown,
  near,
  byId,
  colors,
  titles,
  onSelect,
  migration,
  mode,
}: {
  selection: Selection | null
  shown: Edge[]
  near: ReturnType<typeof neighbours>
  byId: Map<string, GraphNode>
  colors: Map<string, string>
  titles: Map<string, string>
  onSelect: (id: string) => void
  migration: boolean
  mode: Mode
}) {
  let body: ReactNode
  if (selection?.type === 'tie' && byId.has(selection.a) && byId.has(selection.b)) {
    body = <TieBoard a={selection.a} b={selection.b} link={linkBetween(shown, selection.a, selection.b)} byId={byId} colors={colors} onSelect={onSelect} migration={migration} />
  } else if (selection?.type === 'tie') {
    const pairs = pairsFor(shown, byId, (left, right) => {
      const groups = new Set([groupOf(left), groupOf(right)])
      return groups.has(selection.a) && groups.has(selection.b)
    })
    body = <CategoryBoard title={`${groupTitle(selection.a, titles)} · ${groupTitle(selection.b, titles)}`} color={colors.get(selection.a) ?? 'var(--muted-foreground)'} pairs={pairs} onSelect={onSelect} />
  } else if (selection?.type === 'node' && byId.has(selection.key)) {
    const node = byId.get(selection.key)!
    body = <LibraryBoard node={node} out={near.out.get(node.id) ?? []} into={near.in.get(node.id) ?? []} byId={byId} colors={colors} titles={titles} onSelect={onSelect} migration={migration} />
  } else if ((selection?.type === 'node' || selection?.type === 'group') && titles.has(selection.key)) {
    const pairs = pairsFor(shown, byId, (left, right) => groupOf(left) === selection.key || groupOf(right) === selection.key)
    body = <CategoryBoard title={groupTitle(selection.key, titles)} color={colors.get(selection.key) ?? 'var(--muted-foreground)'} pairs={pairs} onSelect={onSelect} />
  } else {
    body = <p className="m-0 font-mono text-[10px] tracking-[0.2em] text-muted-foreground uppercase">{mode === 'categories' ? 'Hover a category' : 'Hover a library'}</p>
  }
  return (
    <aside className="relative flex w-full shrink-0 overflow-y-auto border-t border-border px-6 py-6 lg:w-[min(24rem,38vw)] lg:border-t-0 lg:border-s lg:px-8 lg:py-8">
      <div className="m-auto w-full" aria-live="polite">
        {body}
      </div>
    </aside>
  )
}

/**
 * The ecosystem's map: every library and Appwrite, a ribbon between each pair that depends on one
 * another. Hover a library to see what it depends on and what depends on it; click one to keep it.
 */
export function MapView({ graph: all, index }: { graph: Graph; index: Index }) {
  const migration = useMigration()
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
  const [mode, setMode] = useState<Mode>('libraries')
  const [selection, setSelection] = useState<Selection | null>(null)
  const [q, setQ] = useState('')
  const [searching, setSearching] = useState(false)
  const [compact, setCompact] = useState(false)

  const categories = useMemo(() => [{ id: APPWRITE, title: 'Appwrite' }, ...index.categories], [index])
  const titles = useMemo(() => new Map(categories.map((c) => [c.id, c.title])), [categories])
  const colors = useMemo(() => colorForGroups(categories.map((c) => c.id)), [categories])
  const byId = useMemo(() => new Map(graph.nodes.map((n) => [n.id, n])), [graph])
  const shown = useMemo(() => linksOf(graph.edges, runtime, dev, showApp), [graph, runtime, dev, showApp])
  const near = useMemo(() => neighbours(shown), [shown])
  const nodes = useMemo(() => graph.nodes.filter((n) => showApp || n.kind !== 'app'), [graph, showApp])
  const degree = useMemo(() => degreeOf(shown), [shown])

  const hash = useRouterState({ select: (s) => s.location.hash })
  const navigate = useNavigate()
  const sharedRef = useMemo(() => ({ current: '' }), [])

  useEffect(() => {
    const media = window.matchMedia('(max-width: 640px)')
    const sync = () => setCompact(media.matches)
    sync()
    media.addEventListener('change', sync)
    return () => media.removeEventListener('change', sync)
  }, [])

  const selectLibrary = (id: string | null) => {
    setMode('libraries')
    setSelection(id ? { type: 'node', key: id } : null)
    sharedRef.current = id ?? ''
    navigate({ to: '/map', hash: id ?? '', replace: true, resetScroll: false })
  }

  const onSelect = (next: Selection | null, commit = false) => {
    setSelection(next)
    if (!commit) return
    if (next?.type === 'node' && byId.has(next.key)) {
      sharedRef.current = next.key
      navigate({ to: '/map', hash: next.key, replace: true, resetScroll: false })
    } else if (sharedRef.current) {
      sharedRef.current = ''
      navigate({ to: '/map', hash: '', replace: true, resetScroll: false })
    }
  }

  useEffect(() => {
    const id = decodeURIComponent(hash)
    if (id === sharedRef.current) return
    if (!id || !byId.has(id)) {
      if (!id) {
        sharedRef.current = ''
        setSelection(null)
      }
      return
    }
    sharedRef.current = id
    setMode('libraries')
    setSelection({ type: 'node', key: id })
  }, [hash, byId, sharedRef])

  const chordModel = useMemo(() => {
    if (mode === 'categories') {
      const present = new Set<string>()
      const ties = new Map<string, ChordTie>()
      for (const l of shown) {
        const source = byId.get(l.source)
        const target = byId.get(l.target)
        if (!source || !target) continue
        const a = groupOf(source)
        const b = groupOf(target)
        if (a === b) continue
        present.add(a)
        present.add(b)
        const key = tieKey(a, b)
        const tie = ties.get(key) ?? { a, b, weight: 0 }
        tie.weight += weight(l)
        ties.set(key, tie)
      }
      const groups = categories.map((c) => c.id).filter((id) => (id !== APPWRITE || showApp) && present.has(id))
      const chordNodes: ChordNode[] = groups.map((id) => ({
        key: id,
        group: id,
        label: titles.get(id) ?? id,
        color: colors.get(id) ?? 'var(--muted-foreground)',
      }))
      return { nodes: chordNodes, ties: [...ties.values()], grouped: false }
    }

    const rank = new Map(categories.map((c, i) => [c.id, i]))
    const ordered = [...nodes].sort((a, b) => {
      const teamDelta = (rank.get(groupOf(a)) ?? 99) - (rank.get(groupOf(b)) ?? 99)
      if (teamDelta) return teamDelta
      return (degree.get(b.id) ?? 0) - (degree.get(a.id) ?? 0) || a.title.localeCompare(b.title)
    })
    const chordNodes: ChordNode[] = ordered.map((n) => {
      const group = groupOf(n)
      return { key: n.id, group, label: n.title, color: colors.get(group) ?? 'var(--muted-foreground)' }
    })
    const ties: ChordTie[] = shown.filter((l) => l.source !== l.target).map((l) => ({ a: l.source, b: l.target, weight: weight(l) }))
    return { nodes: chordNodes, ties, grouped: true }
  }, [mode, shown, byId, categories, showApp, titles, colors, nodes, degree])

  const legend = useMemo(() => {
    const keys = new Set(chordModel.nodes.map((n) => n.group))
    return categories.filter((c) => keys.has(c.id))
  }, [chordModel.nodes, categories])

  const term = q.trim().toLowerCase()
  const matches = term
    ? nodes
        .filter((n) => `${n.title} ${n.id} ${n.rust ?? ''} ${migration ? (n.php ?? '') : ''}`.toLowerCase().includes(term))
        .sort((a, b) => Number(!a.title.toLowerCase().startsWith(term)) - Number(!b.title.toLowerCase().startsWith(term)))
        .slice(0, 8)
    : []
  const pick = (id: string) => {
    setQ('')
    setSearching(false)
    selectLibrary(id)
  }

  const hasTies = chordModel.ties.length > 0 && chordModel.nodes.length >= 2

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
        <Segment
          value={mode}
          onChange={(v) => {
            setMode(v as Mode)
            setSelection(null)
            sharedRef.current = ''
            navigate({ to: '/map', hash: '', replace: true, resetScroll: false })
          }}
          options={[
            { key: 'libraries', label: 'Libraries' },
            { key: 'categories', label: 'Categories' },
          ]}
          label="View"
        />
        {migration ? <RuntimeSwitch value={runtime} onChange={setRuntime} /> : null}
        <Toggle on={dev} onChange={setDev}>
          Dev dependencies
        </Toggle>
        <Toggle on={showApp} onChange={setShowApp}>
          Appwrite
        </Toggle>
      </div>

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-border bg-card lg:flex-row">
        {hasTies ? (
          <>
            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
              <div className="flex min-h-0 flex-1 items-center justify-center px-2 pt-2">
                <Chord nodes={chordModel.nodes} ties={chordModel.ties} selection={selection} onSelect={onSelect} grouped={chordModel.grouped} compactLabels={compact} groupTitles={titles} />
              </div>
              {legend.length > 1 ? (
                <ul className="m-0 flex flex-none list-none flex-wrap justify-center gap-x-4 gap-y-2 px-4 pt-1 pb-4">
                  {legend.map((c) => (
                    <li key={c.id}>
                      <button
                        type="button"
                        className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground hover:text-foreground"
                        onMouseEnter={() => onSelect({ type: 'group', key: c.id })}
                        onClick={() => onSelect({ type: 'group', key: c.id }, true)}
                      >
                        <span className="size-2 rounded-full" style={{ background: colors.get(c.id) }} />
                        {c.title}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
            <Board selection={selection} shown={shown} near={near} byId={byId} colors={colors} titles={titles} onSelect={selectLibrary} migration={migration} mode={mode} />
          </>
        ) : (
          <p className="m-0 px-4 py-10 text-sm text-muted-foreground">No dependencies to draw for this view.</p>
        )}
      </div>
    </div>
  )
}
