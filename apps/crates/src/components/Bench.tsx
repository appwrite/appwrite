// The pieces every PHP against Rust benchmark on the site is shown with: the
// library benchmarks (`compat bench`, BenchLibrary.tsx) and the Appwrite API
// benchmarks (tests/benchmarks/api, BenchAppwrite.tsx). One set of units, one
// way to word Rust's advantage, one legend and one footnote, so a number reads
// the same wherever it appears. Metrics always come in the same order: speed
// (time, latency, throughput), then CPU, then memory.

import type { ReactNode } from 'react'
import { bytes, commitUrl, duration, times } from '@/lib/docs'
import { absolute } from '@/lib/time'
import { Time } from './Time'

const num = (v: number) => (v >= 100 ? Math.round(v).toLocaleString('en') : v.toFixed(v >= 10 ? 1 : 2))

/** The units every comparison formats its values with. */
export const unit = {
  /** Nanoseconds: `850 ns`, `1.23 µs`, `4.20 ms`. */
  ns: duration,
  /** Milliseconds, formatted like nanoseconds. */
  ms: (ms: number) => duration(ms * 1e6),
  /** Requests per second. */
  rps: (rps: number) => `${num(rps)}/s`,
  /** Bytes: `512 B`, `1.50 KiB`, `2.99 GiB`. */
  bytes,
  /** Mebibytes, formatted like bytes. */
  mib: (mib: number) => bytes(mib * 2 ** 20),
}

/**
 * What a comparison is: `time` (latency, time per call: lower is better), `throughput` (higher is better),
 * `cpu` and `memory` (lower is better). Each has the words for Rust doing better or worse, short inside a
 * labelled comparison and long in a summary.
 */
export const METRIC = {
  time: { higher: false, short: ['faster', 'slower'], long: ['faster', 'slower'] },
  throughput: { higher: true, short: ['more', 'less'], long: ['throughput', 'less throughput'] },
  cpu: { higher: false, short: ['less', 'more'], long: ['less CPU', 'more CPU'] },
  memory: { higher: false, short: ['less', 'more'], long: ['less memory', 'more memory'] },
} as const

export type Metric = keyof typeof METRIC

/**
 * What a benchmark expects of Rust: to `win`; to reach `parity` (a `bound` benchmark, bound by work both
 * runtimes wait on equally); or nothing (`none`: a `compat` benchmark, reported but never gated).
 */
export type Goal = 'win' | 'parity' | 'none'

/** How close to PHP a bound benchmark must come (`compat bench`'s gate). */
export const PARITY = 0.95

/** Whether Rust's advantage `x` meets a goal. */
export const meets = (x: number, goal: Goal) => goal === 'none' || x >= (goal === 'parity' ? PARITY : 1)

/** How many times better Rust is than PHP on a metric: above 1 Rust is better. */
export function advantage(metric: Metric, php: number, rust: number) {
  return METRIC[metric].higher ? rust / php : php / rust
}

/** An advantage as text: `6.3×`, `12×`, or `4%` within 10%, where a percentage says more than `1.0×`. */
export function ratio(x: number) {
  const v = x >= 1 ? x : 1 / x
  return v < 1.1 ? `${Math.max(1, Math.round((v - 1) * 100))}%` : times(v)
}

/** The goal of a library benchmark from its `bound` and `compat` reasons. */
export const goalOf = (r: { bound?: string | null; compat?: string | null }): Goal => (r.compat ? 'none' : r.bound ? 'parity' : 'win')

/**
 * Rust's advantage on one metric as a pill: `6.3× faster`, `12× less CPU`, `4% slower`; `at parity` for a
 * bound benchmark within its tolerance. Green when it meets the goal, red when not, grey when not gated.
 */
export function Verdict({ x, metric = 'time', goal = 'win', long = false, title }: { x: number; metric?: Metric; goal?: Goal; long?: boolean; title?: string }) {
  const words = long ? METRIC[metric].long : METRIC[metric].short
  const better = x >= 1
  const parity = goal === 'parity' && x >= PARITY && x < 1.1
  const tone =
    goal === 'none'
      ? 'bg-muted text-muted-foreground'
      : meets(x, goal)
        ? 'bg-ok-soft text-ok-ink'
        : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'
  return (
    <span className={`inline-flex items-center rounded-full px-1.5 py-px text-[11px] font-semibold whitespace-nowrap tabular-nums ${tone}`} title={title}>
      {parity ? 'at parity' : `${ratio(x)} ${better ? words[0] : words[1]}`}
    </span>
  )
}

/** One verdict of a summary: from PHP's and Rust's values, or an advantage `x` already worked out (an average). Left out without either. */
export interface SummaryItem {
  metric: Metric
  php?: number | null
  rust?: number | null
  x?: number | null
  title?: string
}

/**
 * Rust against PHP in a few words, one pill per metric in the usual order:
 * `6.3× throughput · 12× less CPU · 40× less memory`. Renders nothing without a pair.
 */
export function Summary({ items, className = '' }: { items: SummaryItem[]; className?: string }) {
  const shown = items.flatMap((i) => {
    const x = i.x ?? (i.php && i.rust ? advantage(i.metric, i.php, i.rust) : null)
    return x ? [{ ...i, x }] : []
  })
  if (!shown.length) return null
  return (
    <span className={`inline-flex flex-wrap items-center gap-1 ${className}`}>
      {shown.map((i) => (
        <Verdict key={i.metric} x={i.x} metric={i.metric} long title={i.title} />
      ))}
    </span>
  )
}

/** The legend for every PHP against Rust comparison; `percentiles` adds the latency curves' line styles. */
export function DuelLegend({ percentiles = false }: { percentiles?: boolean }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-muted-foreground">
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2 w-3 rounded-sm bg-php" /> PHP
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2 w-3 rounded-sm bg-rust" /> Rust
      </span>
      {percentiles ? (
        <>
          <span className="inline-flex items-center gap-1.5">
            <svg width="14" height="6" aria-hidden="true">
              <line x1="0" x2="14" y1="3" y2="3" stroke="currentColor" strokeWidth="2" />
            </svg>{' '}
            median
          </span>
          <span className="inline-flex items-center gap-1.5">
            <svg width="14" height="6" aria-hidden="true">
              <line x1="0" x2="14" y1="3" y2="3" stroke="currentColor" strokeWidth="1.2" strokeDasharray="3 3" />
            </svg>{' '}
            p99
          </span>
        </>
      ) : null}
    </span>
  )
}

/**
 * One metric of PHP against Rust: two bars on one scale with their values, and Rust's advantage. A missing
 * side has no bar; neither side reads `empty` ("not measured"). Zero is a value (a call that allocates
 * nothing), so it gets a bar but no verdict.
 */
export function Duel({
  label,
  php,
  rust,
  metric = 'time',
  format,
  goal = 'win',
  compact = false,
  empty = 'not measured',
  title,
}: {
  label?: string
  php?: number | null
  rust?: number | null
  metric?: Metric
  format: (v: number) => string
  goal?: Goal
  compact?: boolean
  empty?: string
  title?: { php?: string; rust?: string }
}) {
  const has = (v: number | null | undefined): v is number => typeof v === 'number' && Number.isFinite(v)
  if (!has(php) && !has(rust)) return <span className="text-[12px] text-muted-foreground">{empty}</span>
  const max = Math.max(has(php) ? php : 0, has(rust) ? rust : 0)
  // Compact rows (table cells) leave the runtime's name to the colour and the legend.
  const row = (who: 'PHP' | 'Rust', v: number, color: string, hint?: string) => (
    <div className={`grid items-center gap-2 ${compact ? 'grid-cols-[minmax(0,1fr)_auto]' : 'grid-cols-[2.6rem_minmax(0,1fr)_auto]'}`} title={hint ? `${who}: ${hint}` : who}>
      {compact ? null : <span className="text-[11px] text-muted-foreground">{who}</span>}
      <div className="h-2 min-w-6 overflow-hidden rounded-full bg-muted">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${max ? Math.max(2, (v / max) * 100) : 2}%` }} />
      </div>
      <span className="min-w-[4.2rem] text-right font-mono text-[11.5px] tabular-nums">{format(v)}</span>
    </div>
  )
  const verdict = has(php) && has(rust) && php > 0 && rust > 0 ? <Verdict x={advantage(metric, php, rust)} metric={metric} goal={goal} /> : null
  return (
    <div className={`grid min-w-0 ${compact ? 'gap-0.5' : 'gap-1'}`}>
      {label || verdict ? (
        <div className="flex items-center justify-between gap-2">
          {label ? <span className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</span> : <span />}
          {verdict}
        </div>
      ) : null}
      {has(php) ? row('PHP', php, 'bg-php', title?.php) : null}
      {has(rust) ? row('Rust', rust, 'bg-rust', title?.rust) : null}
    </div>
  )
}

/** A benchmark's `bound` or `compat` reason, tagged. */
export function GoalNote({ kind, reason }: { kind: 'bound' | 'compat'; reason: string }) {
  const hint =
    kind === 'bound'
      ? 'Bound by work both runtimes wait on equally: the goal is parity with PHP, not a win'
      : 'Times a PHP-compatibility codec no Rust hot path uses: published, never gated, left out of averages'
  return (
    <div className="mt-0.5 text-[11.5px] text-muted-foreground" title={hint}>
      <span className="rounded bg-muted px-1 py-px text-[10.5px] font-medium">{kind}</span> {reason}
    </div>
  )
}

/**
 * When and where a benchmark ran: "Measured 2 days ago on Apple M4 Max at commit 1a2b3c4", and whether the Rust
 * code changed since ("measured before the latest Rust change"). `brief` keeps only the time and staleness.
 */
export function Measured({
  date,
  commit,
  machine,
  stale,
  changed,
  brief = false,
  className = '',
}: {
  date: string | null | undefined
  commit?: string | null
  machine?: string | null
  stale?: boolean | null
  changed?: string | null
  brief?: boolean
  className?: string
}) {
  if (!date) return null
  return (
    <span className={`text-[12px] text-muted-foreground ${className}`}>
      Measured <Time date={date} className="text-foreground" />
      {!brief && machine ? <> on {machine}</> : null}
      {!brief && commit ? (
        <>
          {' '}
          at commit{' '}
          <a href={commitUrl(commit)} target="_blank" rel="noopener" className="font-mono hover:text-foreground hover:underline">
            {commit}
          </a>
        </>
      ) : null}
      {stale ? (
        <span className="text-progress-ink" title={changed ? `Rust code last changed ${absolute(changed)}` : undefined}>
          {' '}
          · measured before the latest Rust change{changed ? <> (<Time date={changed} />)</> : null}
        </span>
      ) : null}
    </span>
  )
}

/**
 * What went wrong in a benchmark run, as one line: `ok` (muted) when nothing did, else the problems (red).
 * Library benchmarks list the ones that miss their gate; API benchmarks their failed requests.
 */
export function Problems({ notes, ok }: { notes: ReactNode[]; ok: ReactNode }) {
  if (!notes.length) return <p className="m-0 text-[12px] text-muted-foreground">{ok}</p>
  return (
    <p className="m-0 text-[12px] text-red-700 dark:text-red-300">
      {notes.map((n, i) => (
        <span key={i}>
          {i ? ' · ' : null}
          {n}
        </span>
      ))}
      .
    </p>
  )
}

/**
 * The footnote under every benchmark: the environment it ran in (`children`), what the CPU and memory numbers
 * mean (`metrics`), and the caveat that numbers compare runtimes on one machine only.
 */
export function Footnote({ children, metrics }: { children: ReactNode; metrics: ReactNode }) {
  return (
    <div className="grid gap-1 text-[11.5px] text-muted-foreground">
      <p className="m-0">{children}</p>
      <p className="m-0">{metrics} Timings depend on the machine; compare the runtimes, not the numbers across machines.</p>
    </div>
  )
}

/**
 * A library's average advantage over PHP, for cards and headers: `2.1× faster · 1.9× less CPU` (geometric
 * means of its gated benchmarks). The averages get no parity tolerance: that applies to single bound benchmarks.
 */
export function SpeedupBadge({ bench }: { bench: { speedup: number; benchmarks: number; cpu?: number | null } | null }) {
  if (!bench) return null
  const of = `geometric mean of ${bench.benchmarks} benchmark${bench.benchmarks === 1 ? '' : 's'} against PHP`
  return (
    <span className="inline-flex flex-wrap items-center justify-end gap-1">
      <Verdict x={bench.speedup} title={`Time per call, ${of}: ${bench.speedup.toFixed(2)}× PHP's speed`} />
      {bench.cpu ? <Verdict x={bench.cpu} metric="cpu" long title={`CPU time per call, ${of}: PHP uses ${bench.cpu.toFixed(2)}× Rust's`} /> : null}
    </span>
  )
}
