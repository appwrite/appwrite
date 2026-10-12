import { ArrowLeftRight, Check, CircleDashed, CircleDot, ExternalLink, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { sourceUrl } from '@/lib/docs'
import { setMigration, useMigration } from '@/lib/prefs'
import {
  HOOK_STATUS,
  KIND_LABEL,
  ROUTE_STATUS,
  SERVICE_STATUS,
  load,
  pairs,
  type Benchmark,
  type BenchStep,
  type Endpoint,
  type Hook,
  type HookStatus,
  type RouteStatus,
  type Run,
  type ServiceStatus,
} from '@/lib/appwrite'

const SERVICE_BADGE: Record<ServiceStatus, string> = {
  rust: 'bg-ok-soft text-ok-ink',
  partial: 'bg-progress-soft text-progress-ink',
  php: 'bg-planned-soft text-planned-ink',
}
const SERVICE_DOT: Record<ServiceStatus, string> = { rust: 'bg-ok', partial: 'bg-progress', php: 'bg-planned' }

export function ServiceBadge({ status }: { status: ServiceStatus }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap ${SERVICE_BADGE[status]}`} title={SERVICE_STATUS[status].note}>
      <span className={`inline-block size-1.5 rounded-full ${SERVICE_DOT[status]}`} />
      {SERVICE_STATUS[status].label}
    </span>
  )
}

const ROUTE_TEXT: Record<RouteStatus, string> = {
  served: 'text-ok-ink',
  ready: 'text-started-ink',
  missing: 'text-red-700 dark:text-red-300',
  php: 'text-muted-foreground',
}
const ROUTE_DOT: Record<RouteStatus, string> = { served: 'bg-ok', ready: 'bg-started', missing: 'bg-red-500', php: 'bg-planned' }

export function RouteStatusLabel({ status }: { status: RouteStatus }) {
  return (
    <span className={`inline-flex items-center gap-1.5 text-[12px] whitespace-nowrap ${ROUTE_TEXT[status]}`} title={ROUTE_STATUS[status].note}>
      <span className={`inline-block size-1.5 flex-none rounded-full ${ROUTE_DOT[status]}`} />
      {ROUTE_STATUS[status].label}
    </span>
  )
}

const HOOK_ICON: Record<HookStatus, ReactNode> = {
  done: <Check className="size-3.5 text-ok" />,
  partial: <CircleDot className="size-3.5 text-progress" />,
  missing: <CircleDashed className="size-3.5 text-muted-foreground" />,
  unknown: <CircleDashed className="size-3.5 text-red-500" />,
}

export function HookStatusLabel({ status }: { status: HookStatus }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-[12px] whitespace-nowrap" title={HOOK_STATUS[status].note}>
      {HOOK_ICON[status]}
      {HOOK_STATUS[status].label}
    </span>
  )
}

const METHOD: Record<string, string> = {
  GET: 'text-started-ink bg-started-soft',
  POST: 'text-ok-ink bg-ok-soft',
  PUT: 'text-progress-ink bg-progress-soft',
  PATCH: 'text-progress-ink bg-progress-soft',
  DELETE: 'text-red-700 bg-red-50 dark:text-red-300 dark:bg-red-950/40',
}

export function MethodBadge({ method }: { method: string }) {
  return <span className={`inline-block w-[52px] rounded px-1 py-px text-center font-mono text-[10.5px] font-semibold ${METHOD[method] ?? 'bg-muted'}`}>{method}</span>
}

/** A progress bar of parts of a whole, in order. */
export function Bar({ parts, total, className = 'h-2' }: { parts: { value: number; className: string; title?: string }[]; total: number; className?: string }) {
  return (
    <div className={`flex overflow-hidden rounded-full bg-muted ${className}`}>
      {parts.map((p, i) =>
        p.value ? <span key={i} className={p.className} style={{ width: `${(p.value / Math.max(total, 1)) * 100}%` }} title={p.title} /> : null,
      )}
    </div>
  )
}

/** A number with its label, for the progress card. */
export function Stat({ label, value, of, children }: { label: string; value: ReactNode; of?: ReactNode; children?: ReactNode }) {
  return (
    <div className="grid content-start gap-1.5">
      <span className="text-[11px] tracking-wider text-muted-foreground uppercase">{label}</span>
      <span className="text-[13px]">
        <span className="font-display text-[22px] leading-none font-medium tabular-nums">{value}</span>
        {of !== undefined ? <span className="text-muted-foreground tabular-nums"> of {of}</span> : null}
      </span>
      {children}
    </div>
  )
}

/** Shown instead of a page while migration mode is off. */
export function MigrationGate({ children }: { children: ReactNode }) {
  const on = useMigration()
  if (on) return <>{children}</>
  return (
    <div className="grid max-w-[72ch] justify-items-start gap-3 rounded-lg border border-border bg-card p-4 text-[13.5px] text-muted-foreground">
      <span>The Appwrite migration is part of the migration view: the move of the Appwrite API itself from PHP to Rust, service by service.</span>
      <button
        type="button"
        onClick={() => setMigration(true)}
        className="inline-flex items-center gap-1.5 rounded-md border border-migration-border bg-migration px-2.5 py-1 text-[13px] font-medium text-migration-ink hover:opacity-90"
      >
        <ArrowLeftRight className="size-3.5" />
        Turn on migration
      </button>
    </div>
  )
}

export function SourceLink({ ref: gitRef, file, line, children }: { ref: string | null | undefined; file: string; line?: number | null; children?: ReactNode }) {
  return (
    <a href={sourceUrl(gitRef, file, line ?? undefined)} target="_blank" rel="noopener" className="inline-flex min-w-0 items-center gap-1 font-mono text-[11.5px] text-muted-foreground hover:text-foreground">
      <span className="truncate">{children ?? `${file}${line ? `:${line}` : ''}`}</span>
      <ExternalLink className="size-3 flex-none" />
    </a>
  )
}

/** A recorded E2E run. */
export function RunCard({ run }: { run: Run }) {
  const failed = run.failures + run.errors
  const [open, setOpen] = useState(false)
  return (
    <div className="grid gap-2 rounded-lg border border-border bg-card p-3">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className={`inline-flex items-center gap-1 text-[13px] font-medium ${failed ? 'text-red-700 dark:text-red-300' : 'text-ok-ink'}`}>
          {failed ? <X className="size-3.5" /> : <Check className="size-3.5" />}
          {run.passed} of {run.tests} passed
        </span>
        <span className="text-[12px] text-muted-foreground">
          against <b className="font-medium text-foreground">{run.runtime === 'rust' ? 'Rust' : 'PHP'}</b> ({run.endpoint})
        </span>
        <span className="text-[12px] text-muted-foreground tabular-nums">
          {run.assertions} assertions{run.skipped ? `, ${run.skipped} skipped` : ''}
        </span>
        <span className="ml-auto text-[12px] text-muted-foreground tabular-nums">
          {run.date.slice(0, 10)} · commit <code className="font-mono">{run.commit}</code> · {run.wall}s
        </span>
      </div>
      {run.failed.length ? (
        <div className="grid gap-1">
          <button type="button" onClick={() => setOpen((o) => !o)} className="justify-self-start text-[12px] text-muted-foreground hover:text-foreground">
            {open ? 'Hide' : 'Show'} {run.failed.length} failed
          </button>
          {open
            ? run.failed.map((f) => (
                <div key={`${f.class}.${f.name}`} className="rounded bg-muted px-2 py-1 font-mono text-[11.5px]">
                  {f.class}::{f.name} <span className="text-muted-foreground">{f.message}</span>
                </div>
              ))
            : null}
        </div>
      ) : null}
      <div className="text-[11.5px] text-muted-foreground">
        {run.environment.php ? (
          <>
            PHP image <code className="font-mono">{run.environment.php.image}</code> ({run.environment.php.id})
          </>
        ) : null}
        {run.environment.rust ? (
          <>
            {' '}
            · Rust image <code className="font-mono">{run.environment.rust.image}</code> ({run.environment.rust.id})
          </>
        ) : null}
        {run.environment.processes ? <> · {run.environment.processes} processes</> : null}
      </div>
    </div>
  )
}


/** How many times better Rust is than PHP on a value (lower is better unless `higher`). */
function advantage(php: number, rust: number, higher: boolean) {
  return higher ? rust / php : php / rust
}

/** `3.7× faster` / `1.2× slower`, coloured by direction. */
export function Verdict({ x, word = ['faster', 'slower'] }: { x: number; word?: [string, string] }) {
  const good = x >= 1
  const v = good ? x : 1 / x
  const n = v >= 10 ? Math.round(v) : v.toFixed(1)
  return (
    <span className={`inline-flex items-center rounded-full px-1.5 py-px text-[11px] font-semibold whitespace-nowrap tabular-nums ${good ? 'bg-ok-soft text-ok-ink' : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'}`}>
      {n}× {good ? word[0] : word[1]}
    </span>
  )
}

/** The legend for every PHP vs Rust comparison on the page. */
export function DuelLegend() {
  return (
    <span className="inline-flex items-center gap-3 text-[11.5px] text-muted-foreground">
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2 w-3 rounded-sm bg-php" /> PHP
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2 w-3 rounded-sm bg-rust" /> Rust
      </span>
      <span className="inline-flex items-center gap-1.5">
        <svg width="14" height="6" aria-hidden="true"><line x1="0" x2="14" y1="3" y2="3" stroke="currentColor" strokeWidth="2" /></svg> median
      </span>
      <span className="inline-flex items-center gap-1.5">
        <svg width="14" height="6" aria-hidden="true"><line x1="0" x2="14" y1="3" y2="3" stroke="currentColor" strokeWidth="1.2" strokeDasharray="3 3" /></svg> p99
      </span>
    </span>
  )
}

/**
 * One measure of PHP against Rust: two bars on one scale with their values,
 * and how many times better Rust is. `higher`: more is better (throughput).
 */
export function Duel({
  label,
  php,
  rust,
  format,
  higher = false,
  compact = false,
}: {
  label?: string
  php?: number | null
  rust?: number | null
  format: (v: number) => string
  higher?: boolean
  compact?: boolean
}) {
  if (!php && !rust) return <span className="text-[12px] text-muted-foreground">–</span>
  const max = Math.max(php || 0, rust || 0)
  const row = (who: 'PHP' | 'Rust', v: number, color: string) => (
    <div className="grid grid-cols-[2.6rem_minmax(0,1fr)_auto] items-center gap-2">
      <span className="text-[11px] text-muted-foreground">{who}</span>
      <div className="h-2 overflow-hidden rounded-full bg-muted">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.max(2, (v / max) * 100)}%` }} />
      </div>
      <span className="min-w-[4.2rem] text-right font-mono text-[11.5px] tabular-nums">{format(v)}</span>
    </div>
  )
  return (
    <div className={`grid min-w-0 ${compact ? 'gap-0.5' : 'gap-1'}`}>
      {label || !compact ? (
        <div className="flex items-center justify-between gap-2">
          {label ? <span className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</span> : <span />}
          {php && rust ? <Verdict x={advantage(php, rust, higher)} word={higher ? ['more', 'less'] : ['faster', 'slower']} /> : null}
        </div>
      ) : null}
      {php ? row('PHP', php, 'bg-php') : null}
      {rust ? row('Rust', rust, 'bg-rust') : null}
    </div>
  )
}

const percentOf = (part: number, whole: number) => (whole ? `${((part / whole) * 100).toFixed(part / whole < 0.01 ? 2 : 1)}%` : '–')
const num = (v: number) => (v >= 100 ? Math.round(v).toLocaleString('en') : v.toFixed(v >= 10 ? 1 : 2))
const millis = (v: number) => `${num(v)} ms`
const reqs = (v: number) => `${num(v)}/s`
const mib = (v: number) => `${Math.round(v)} MiB`

/** PHP against Rust at every step of the load ladder, as paired bars. */
export function BenchSteps({ bench }: { bench: Benchmark }) {
  const rows = pairs(bench).filter((r) => r.php || r.rust)
  return (
    <div className="grid min-w-0 gap-3">
      <DuelLegend />
      {rows.map((r) => {
        const failures = (r.php?.failures ?? 0) + (r.rust?.failures ?? 0) + (r.php?.errors ?? 0) + (r.rust?.errors ?? 0)
        const failed = (s?: BenchStep) => (s ? `${(s.failures ?? 0) + (s.errors ?? 0)} (${percentOf((s.failures ?? 0) + (s.errors ?? 0), s.requests)})` : '–')
        return (
          <div key={`${r.mode}${r.level}`} className="grid min-w-0 gap-3 rounded-lg border border-border bg-card p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-[13.5px] font-medium">{load(r.mode, r.level)}</span>
              <span className={`text-[11.5px] ${failures ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground'}`}>
                {failures ? `Server errors: PHP ${failed(r.php)}, Rust ${failed(r.rust)}` : 'No failed requests'}
              </span>
            </div>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-x-6 gap-y-4">
              <Duel label="Throughput" php={r.php?.rps} rust={r.rust?.rps} format={reqs} higher />
              <Duel label="Median latency" php={r.php?.latency.p50} rust={r.rust?.latency.p50} format={millis} />
              <Duel label="p99 latency" php={r.php?.latency.p99} rust={r.rust?.latency.p99} format={millis} />
              <Duel label="CPU per request" php={r.php?.cpu_ms_per_request} rust={r.rust?.cpu_ms_per_request} format={millis} />
              <Duel label="Peak memory" php={r.php?.memory_mib.peak} rust={r.rust?.memory_mib.peak} format={mib} />
            </div>
          </div>
        )
      })}
    </div>
  )
}

/** The benchmark steps a page can show per endpoint, and the chosen one. */
export function useBenchStep(bench: Benchmark | undefined) {
  const rows = bench ? pairs(bench).filter((r) => r.php || r.rust) : []
  const [at, setAt] = useState(Math.max(0, rows.findIndex((r) => r.mode === 'vus')))
  return { rows, step: rows[at], at, setAt }
}

type Rows = ReturnType<typeof pairs>
type Timing = BenchStep['endpoints'][string]

/** `rate 200` → `200/s`, `vus 50` → `50 users`, short enough for an axis. */
const short = (mode: 'rate' | 'vus', level: number) => (mode === 'rate' ? `${level}/s` : `${level} u`)

/**
 * One scenario's latency at every load step at once: the median (solid) and
 * p99 (dashed) of each runtime on a log scale, fixed-rate steps then
 * closed-loop ones, with how many times faster Rust's median is per step.
 */
function LoadCurve({ rows, name }: { rows: Rows; name: string }) {
  const at = rows.map((r) => ({ row: r, p: r.php?.endpoints[name] as Timing | undefined, r: r.rust?.endpoints[name] as Timing | undefined }))
  const values = at.flatMap(({ p, r }) => [p?.p50, p?.p99, r?.p50, r?.p99]).filter((v): v is number => !!v && v > 0)
  if (!values.length) return null
  const W = 300
  const H = 100
  const left = 40
  const right = 8
  const top = 6
  const bottom = 34
  const lo = Math.log10(Math.min(...values) / 1.3)
  const hi = Math.log10(Math.max(...values) * 1.3)
  // Fixed-rate and closed-loop steps are different experiments: a gap between them.
  const gap = at.some(({ row }) => row.mode === 'vus') && at.some(({ row }) => row.mode === 'rate') ? 0.6 : 0
  const slots = at.map(({ row }, i) => i + (row.mode === 'vus' ? gap : 0))
  const span = Math.max(1, (slots.at(-1) ?? 0) - (slots[0] ?? 0))
  const x = (i: number) => left + ((slots[i] - slots[0]) / span) * (W - left - right)
  const y = (v: number) => top + (1 - (Math.log10(v) - lo) / (hi - lo || 1)) * (H - top - bottom)
  const line = (pick: (t: { p?: Timing; r?: Timing }) => number | undefined) =>
    at
      .map((t, i) => [i, pick(t)] as const)
      .filter(([, v]) => !!v)
      .map(([i, v], k) => `${k ? 'L' : 'M'}${x(i).toFixed(1)},${y(v as number).toFixed(1)}`)
      .join(' ')
  const ticks = [Math.pow(10, lo + (hi - lo) * 0.15), Math.pow(10, lo + (hi - lo) * 0.85)]
  const series = [
    { key: 'php', color: 'var(--php)', p50: line((t) => t.p?.p50), p99: line((t) => t.p?.p99), dots: at.map((t, i) => [i, t.p?.p50] as const) },
    { key: 'rust', color: 'var(--rust)', p50: line((t) => t.r?.p50), p99: line((t) => t.r?.p99), dots: at.map((t, i) => [i, t.r?.p50] as const) },
  ]
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="block h-auto w-full max-w-[340px]" role="img" aria-label={`Latency by load for ${name}`}>
      {ticks.map((t) => (
        <g key={t}>
          <line x1={left} x2={W - right} y1={y(t)} y2={y(t)} className="stroke-border" strokeWidth={1} />
          <text x={left - 4} y={y(t) + 3} textAnchor="end" className="fill-muted-foreground text-[9px] tabular-nums">
            {millis(t)}
          </text>
        </g>
      ))}
      {series.map((s) => (
        <g key={s.key}>
          <path d={s.p99} fill="none" stroke={s.color} strokeWidth={1.2} strokeDasharray="3 3" opacity={0.55} />
          <path d={s.p50} fill="none" stroke={s.color} strokeWidth={2} />
          {s.dots.map(([i, v]) =>
            v ? (
              <circle key={i} cx={x(i)} cy={y(v)} r={2.6} fill={s.color}>
                <title>{`${s.key === 'php' ? 'PHP' : 'Rust'} at ${load(at[i].row.mode, at[i].row.level)}: median ${millis(v)}, p99 ${millis((s.key === 'php' ? at[i].p?.p99 : at[i].r?.p99) ?? 0)}`}</title>
              </circle>
            ) : null,
          )}
        </g>
      ))}
      {at.map(({ row, p, r }, i) => {
        const ratio = p?.p50 && r?.p50 ? p.p50 / r.p50 : null
        return (
          <g key={i}>
            <text x={x(i)} y={H - 19} textAnchor="middle" className="fill-muted-foreground text-[9.5px]">
              {short(row.mode, row.level)}
            </text>
            {ratio ? (
              <text x={x(i)} y={H - 5} textAnchor="middle" className={`text-[10px] font-semibold tabular-nums ${ratio >= 1 ? 'fill-ok-ink' : 'fill-red-600'}`}>
                {ratio >= 10 ? Math.round(ratio) : ratio.toFixed(1)}×
              </text>
            ) : null}
          </g>
        )
      })}
    </svg>
  )
}

/**
 * An endpoint's latency at every load step: each scenario that measures it
 * (a route can have several, like a list and a search) as a [`LoadCurve`] of
 * PHP against Rust, or PHP alone while Rust does not serve it. Without
 * timings, why: no scenario yet, a scenario not run yet, or the reason it is
 * skipped.
 */
export function EndpointBench({ rows, route, bench }: { rows: Rows; route: string; bench: Endpoint['bench'] }) {
  const names = [
    ...new Set(rows.flatMap((r) => [r.php, r.rust]).flatMap((s) => Object.entries(s?.endpoints ?? {}).filter(([, e]) => e.route === route).map(([n]) => n))),
  ]
  if (!names.length) {
    if (bench.status === 'skipped') return <span className="text-[12px] text-muted-foreground">Skipped: {bench.reason}</span>
    if (bench.status === 'covered') return <span className="text-[12px] text-muted-foreground">Scenario written, not run yet</span>
    return <span className="text-[12px] text-red-600 dark:text-red-400">No benchmark scenario</span>
  }
  return (
    <div className="grid min-w-[240px] gap-3">
      {names.map((name) => {
        const note = rows.map((r) => r.php?.endpoints[name]?.note ?? r.rust?.endpoints[name]?.note).find(Boolean)
        const errors = rows
          .map((r) => ({ r, php: r.php?.endpoints[name]?.errors ?? 0, rust: r.rust?.endpoints[name]?.errors ?? 0 }))
          .filter((e) => e.php || e.rust)
        return (
          <div key={name} className="grid gap-1">
            {names.length > 1 || note ? (
              <span className="text-[11px] text-muted-foreground">
                {names.length > 1 ? <span className="font-mono">{name}</span> : null}
                {names.length > 1 && note ? ' · ' : null}
                {note}
              </span>
            ) : null}
            <LoadCurve rows={rows} name={name} />
            {errors.length ? (
              <span className="text-[11px] text-red-700 dark:text-red-300">
                Server errors: {errors.map((e) => `${short(e.r.mode, e.r.level)} PHP ${e.php}, Rust ${e.rust}`).join(' · ')}
              </span>
            ) : null}
          </div>
        )
      })}
    </div>
  )
}

/** A service's benchmark at a glance: throughput under the heaviest closed-loop
 * load, and CPU per request at the highest fixed rate. */
export function ServiceSpeed({ bench }: { bench: Benchmark | undefined }) {
  if (!bench) return <span className="text-[12px] text-muted-foreground">–</span>
  if (!bench.steps.some((s) => s.target === 'rust')) return <span className="text-[12px] text-muted-foreground">PHP baseline</span>
  const rows = pairs(bench).filter((r) => r.php && r.rust)
  const loop = rows.filter((r) => r.mode === 'vus').at(-1)
  const rate = rows.filter((r) => r.mode === 'rate').at(-1)
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {loop?.php?.rps && loop.rust?.rps ? (
        <span title={`Throughput at ${load(loop.mode, loop.level)}: ${Math.round(loop.php.rps)} vs ${Math.round(loop.rust.rps)} req/s`}>
          <Verdict x={loop.rust.rps / loop.php.rps} word={['throughput', 'throughput']} />
        </span>
      ) : null}
      {rate?.php?.cpu_ms_per_request && rate.rust?.cpu_ms_per_request ? (
        <span title={`CPU per request at ${load(rate.mode, rate.level)}: ${rate.php.cpu_ms_per_request} vs ${rate.rust.cpu_ms_per_request} ms`}>
          <Verdict x={rate.php.cpu_ms_per_request / rate.rust.cpu_ms_per_request} word={['less CPU', 'more CPU']} />
        </span>
      ) : null}
    </div>
  )
}

export function BenchEnvironment({ bench }: { bench: Benchmark }) {
  const e = bench.environment
  return (
    <p className="m-0 text-[11.5px] text-muted-foreground">
      {e.machine.cpu ?? e.machine.arch}
      {e.machine.cores ? `, ${e.machine.cores} cores` : ''}
      {e.machine.memory_gib ? `, ${e.machine.memory_gib} GiB` : ''} ({e.machine.os}) · Docker {e.docker} · k6 {e.k6} · {bench.workload.duration} per step,{' '}
      {bench.workload.seed ?? bench.workload.users} seeded records{bench.workload.hashing ? ', with password hashing' : ''}
      {bench.workload.error_budget ? `, server errors allowed up to ${Math.round(bench.workload.error_budget * 100)}% per step` : ''} · CPU from {e.cpu_source} · PHP <code className="font-mono">{e.php.image}</code>, Rust{' '}
      <code className="font-mono">{e.rust.image}</code> · commit <code className="font-mono">{e.commit}</code> · {e.date.slice(0, 10)}. Load generator, API and database share
      the machine; compare the runtimes, not the numbers across machines.
    </p>
  )
}

/** One cross-cutting behaviour and where Rust stands on it. */
export function HookRow({ hook, gitRef, kind = true }: { hook: Hook; gitRef: string | null | undefined; kind?: boolean }) {
  const [open, setOpen] = useState(false)
  const facts = [
    hook.groups.length ? { label: 'Groups', items: hook.groups } : null,
    hook.labels.length ? { label: 'Reads labels', items: hook.labels } : null,
    hook.errors.length ? { label: 'Throws', items: hook.errors } : null,
    hook.headers.length ? { label: 'Sets headers', items: hook.headers } : null,
    hook.injects.length ? { label: 'Injects', items: hook.injects } : null,
  ].filter((f): f is { label: string; items: string[] } => !!f)
  return (
    <li className="grid gap-1.5 border-b border-border px-3 py-2.5 last:border-b-0">
      <div className="flex min-w-0 flex-wrap items-start gap-x-3 gap-y-1">
        <span className="pt-0.5">{HOOK_ICON[hook.rust.status]}</span>
        <div className="grid min-w-0 flex-1 gap-0.5">
          <div className="flex flex-wrap items-baseline gap-x-2">
            <span className="text-[13.5px] font-medium">{hook.title ?? 'Undescribed hook'}</span>
            {kind ? <span className="text-[11.5px] text-muted-foreground">{KIND_LABEL[hook.kind]}</span> : null}
            {hook.needed ? null : <span className="text-[11.5px] text-muted-foreground" title="No route Rust serves today runs this hook">· not needed yet</span>}
          </div>
          {hook.description ? <p className="m-0 text-[12.5px] text-muted-foreground">{hook.description}</p> : null}
          {hook.rust.note ? (
            <p className="m-0 text-[12.5px]">
              <span className="text-muted-foreground">Rust: </span>
              {hook.rust.note}
            </p>
          ) : null}
        </div>
        <HookStatusLabel status={hook.rust.status} />
      </div>
      <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 pl-[26px]">
        <SourceLink ref={gitRef} file={hook.file} line={hook.line} />
        {hook.rust.where.map((w) => (
          <SourceLink key={`${w.file}${w.anchor}`} ref={gitRef} file={w.file} line={w.line}>
            {w.file.replace('crates/appwrite/', '')}
            {w.line ? `:${w.line}` : ' (moved?)'}
          </SourceLink>
        ))}
        {facts.length ? (
          <button type="button" onClick={() => setOpen((o) => !o)} className="text-[11.5px] text-muted-foreground hover:text-foreground">
            {open ? 'Less' : 'Details'}
          </button>
        ) : null}
      </div>
      {open ? (
        <dl className="m-0 grid gap-1 pl-[26px] text-[12px]">
          {facts.map((f) => (
            <div key={f.label} className="flex flex-wrap gap-x-2">
              <dt className="w-24 flex-none text-muted-foreground">{f.label}</dt>
              <dd className="m-0 flex min-w-0 flex-1 flex-wrap gap-1">
                {f.items.map((x) => (
                  <code key={x} className="rounded bg-muted px-1 font-mono text-[11px]">
                    {x}
                  </code>
                ))}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
    </li>
  )
}
