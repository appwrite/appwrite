import { useState } from 'react'
import { load, pairs, type Benchmark, type BenchStep, type Endpoint } from '@/lib/appwrite'
import { Duel, DuelLegend, Footnote, Problems, Summary, unit, type SummaryItem } from './Bench'

type Rows = ReturnType<typeof pairs>
type Timing = BenchStep['endpoints'][string]

const percentOf = (part: number, whole: number) => (whole ? `${((part / whole) * 100).toFixed(part / whole < 0.01 ? 2 : 1)}%` : '–')
const who = (target: 'php' | 'rust') => (target === 'php' ? 'PHP' : 'Rust')

/**
 * What went wrong for one runtime in one step: server errors (5xx or no
 * response: the runtime could not keep up), failures (a response the
 * scenario did not expect: a bug), and requests k6 dropped because it ran
 * out of virtual users to send them. A step over the error budget is the
 * runtime saturating; its latency and throughput describe overload.
 */
function StepProblems({ runtime, step, budget }: { runtime: string; step?: BenchStep; budget?: number }) {
  if (!step) return null
  const errors = step.errors ?? 0
  const parts = [
    errors ? `${errors.toLocaleString('en')} server errors (${percentOf(errors, step.requests)})` : null,
    step.failures ? `${step.failures.toLocaleString('en')} unexpected responses (${percentOf(step.failures, step.requests)})` : null,
    step.dropped ? `${step.dropped.toLocaleString('en')} requests dropped` : null,
  ].filter(Boolean)
  const over = budget !== undefined && step.requests > 0 && errors / step.requests > budget
  return (
    <div className={`text-[11.5px] ${parts.length ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground'}`}>
      <span className="font-medium">{runtime}:</span> {parts.length ? parts.join(' · ') : 'no failed requests'}
      {over ? ` — over the ${Math.round(budget * 100)}% error budget: ${runtime} was saturated, so this step's numbers describe overload` : ''}
      {step.failures && !over ? ' — unexpected responses point to a bug, not load' : ''}
    </div>
  )
}

/** One line on a benchmark's failed requests across its steps. */
export function BenchProblems({ bench }: { bench: Benchmark }) {
  const budget = bench.workload.error_budget
  const notes = bench.steps.flatMap((s) => {
    const errors = s.errors ?? 0
    const out: string[] = []
    const at = `${who(s.target)} at ${load(s.mode, s.level).toLowerCase()}`
    if (budget !== undefined && s.requests && errors / s.requests > budget) out.push(`${at} went over the error budget (${percentOf(errors, s.requests)} server errors)`)
    else if (errors) out.push(`${at}: ${errors.toLocaleString('en')} server errors`)
    if (s.failures) out.push(`${at}: ${s.failures} unexpected responses`)
    if (s.dropped) out.push(`${at}: ${s.dropped.toLocaleString('en')} requests dropped`)
    return out
  })
  return <Problems ok="Every request in every step was sent and succeeded." notes={notes} />
}

/**
 * The steps a service's summary reads, each where the comparison is fair: throughput under the heaviest
 * closed-loop load (as fast as each can), CPU and memory at the highest fixed rate (equal load on both) that
 * neither runtime saturated.
 */
function headline(bench: Benchmark): SummaryItem[] {
  const rows = pairs(bench).filter((r) => r.php && r.rust)
  const loop = rows.filter((r) => r.mode === 'vus').at(-1)
  // A runtime over the error budget is saturated: its CPU and memory describe overload, not equal load.
  const budget = bench.workload.error_budget ?? 1
  const within = (s?: BenchStep) => !!s && (!s.requests || (s.errors ?? 0) / s.requests <= budget)
  const rates = rows.filter((r) => r.mode === 'rate')
  const rate = rates.filter((r) => within(r.php) && within(r.rust)).at(-1) ?? rates.at(-1) ?? loop
  const at = (r: (typeof rows)[number] | undefined) => (r ? load(r.mode, r.level).toLowerCase() : '')
  return [
    { metric: 'throughput', php: loop?.php?.rps, rust: loop?.rust?.rps, title: loop ? `Throughput at ${at(loop)}: PHP ${unit.rps(loop.php!.rps)}, Rust ${unit.rps(loop.rust!.rps)}` : undefined },
    {
      metric: 'cpu',
      php: rate?.php?.cpu_ms_per_request,
      rust: rate?.rust?.cpu_ms_per_request,
      title: rate ? `API CPU per request at ${at(rate)}: PHP ${unit.ms(rate.php!.cpu_ms_per_request)}, Rust ${unit.ms(rate.rust!.cpu_ms_per_request)}` : undefined,
    },
    {
      metric: 'memory',
      php: rate?.php?.memory_mib.peak,
      rust: rate?.rust?.memory_mib.peak,
      title: rate ? `Peak API memory at ${at(rate)}: PHP ${unit.mib(rate.php!.memory_mib.peak)}, Rust ${unit.mib(rate.rust!.memory_mib.peak)}` : undefined,
    },
  ]
}

/**
 * A service's benchmark at a glance: `6.3× throughput · 12× less CPU · 40× less memory`, or that only the PHP
 * baseline was measured. `empty` is shown without a benchmark.
 */
export function BenchSummary({ bench, empty = '–', className = '' }: { bench: Benchmark | undefined; empty?: string; className?: string }) {
  if (!bench) return <span className="text-[12px] text-muted-foreground">{empty}</span>
  if (!bench.steps.some((s) => s.target === 'rust')) return <span className="text-[12px] text-muted-foreground">PHP baseline only</span>
  return <Summary items={headline(bench)} className={className} />
}

/** PHP against Rust at every step of the load ladder: speed, then CPU, then memory, as paired bars. */
export function BenchSteps({ bench }: { bench: Benchmark }) {
  const rows = pairs(bench).filter((r) => r.php || r.rust)
  return (
    <div className="grid min-w-0 gap-3">
      <DuelLegend />
      {rows.map((r) => {
        const clean = [r.php, r.rust].every((s) => !s || (!s.failures && !s.errors && !s.dropped))
        return (
          <div key={`${r.mode}${r.level}`} className="grid min-w-0 gap-3 rounded-lg border border-border bg-card p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-[13.5px] font-medium">{load(r.mode, r.level)}</span>
              {clean ? <span className="text-[11.5px] text-muted-foreground">No failed requests</span> : null}
            </div>
            {clean ? null : (
              <div className="grid gap-1">
                <StepProblems runtime="PHP" step={r.php} budget={bench.workload.error_budget} />
                <StepProblems runtime="Rust" step={r.rust} budget={bench.workload.error_budget} />
              </div>
            )}
            <div className="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-x-6 gap-y-4">
              <Duel label="Throughput" php={r.php?.rps} rust={r.rust?.rps} metric="throughput" format={unit.rps} />
              <Duel label="Median latency" php={r.php?.latency.p50} rust={r.rust?.latency.p50} format={unit.ms} />
              <Duel label="p99 latency" php={r.php?.latency.p99} rust={r.rust?.latency.p99} format={unit.ms} />
              <Duel label="CPU per request" php={r.php?.cpu_ms_per_request} rust={r.rust?.cpu_ms_per_request} metric="cpu" format={unit.ms} />
              <Duel label="DB CPU per request" php={r.php?.db_cpu_ms_per_request} rust={r.rust?.db_cpu_ms_per_request} metric="cpu" format={unit.ms} />
              <Duel
                label="Peak memory"
                php={r.php?.memory_mib.peak}
                rust={r.rust?.memory_mib.peak}
                metric="memory"
                format={unit.mib}
                title={{
                  php: r.php ? `Idle ${unit.mib(r.php.memory_mib.baseline)}, average ${unit.mib(r.php.memory_mib.avg)}` : undefined,
                  rust: r.rust ? `Idle ${unit.mib(r.rust.memory_mib.baseline)}, average ${unit.mib(r.rust.memory_mib.avg)}` : undefined,
                }}
              />
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

/** `rate 200` → `200/s`, `vus 50` → `50 u`, short enough for an axis. */
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
            {unit.ms(t)}
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
                <title>{`${s.key === 'php' ? 'PHP' : 'Rust'} at ${load(at[i].row.mode, at[i].row.level)}: median ${unit.ms(v)}, p99 ${unit.ms((s.key === 'php' ? at[i].p?.p99 : at[i].r?.p99) ?? 0)}`}</title>
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

/** Where a service's benchmark ran and what its CPU and memory numbers mean. */
export function BenchEnvironment({ bench }: { bench: Benchmark }) {
  const e = bench.environment
  const w = bench.workload
  return (
    <Footnote
      metrics={
        <>
          CPU per request: the API container's CPU time over the step divided by its requests (from {e.cpu_source ?? 'cgroup counters'}); DB CPU per
          request: PostgreSQL's and Redis's together, the same way. Peak memory: the API container's highest memory use during the step, its idle baseline included (hover
          a bar for the baseline and average).
        </>
      }
    >
      {e.machine.cpu ?? e.machine.arch}
      {e.machine.cores ? `, ${e.machine.cores} cores` : ''}
      {e.machine.memory_gib ? `, ${e.machine.memory_gib} GiB` : ''} ({e.machine.os}) · Docker {e.docker} · k6 {e.k6} · {w.duration} per step, {w.seed ?? w.users} seeded
      records{w.hashing ? ', with password hashing' : ''}
      {w.error_budget ? `, server errors allowed up to ${Math.round(w.error_budget * 100)}% per step` : ''} · PHP <code className="font-mono">{e.php.image}</code>, Rust{' '}
      <code className="font-mono">{e.rust.image}</code> · load generator, API and database share the machine.
    </Footnote>
  )
}
