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
  ms,
  pairs,
  type Benchmark,
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

const th = 'px-3 py-2 font-semibold'
const td = 'px-3 py-2 tabular-nums'

/** `x` in favour of Rust: how many times PHP's value Rust's is, or the inverse for throughput. */
function Ratio({ php, rust, higher = false }: { php?: number; rust?: number; higher?: boolean }) {
  if (!php || !rust) return <span className="text-muted-foreground">–</span>
  const x = higher ? rust / php : php / rust
  const good = x >= 1
  return <span className={good ? 'text-ok-ink' : 'text-red-700 dark:text-red-300'}>{x >= 10 ? Math.round(x) : x.toFixed(1)}×</span>
}

/** PHP and Rust side by side for every step of the load ladder. */
export function BenchSteps({ bench }: { bench: Benchmark }) {
  const rows = pairs(bench)
  return (
    <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
      <table className="w-full border-collapse text-[12.5px]">
        <thead>
          <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
            <th className={th}>Load</th>
            <th className={`${th} text-right`} title="Requests per second, PHP / Rust">Req/s</th>
            <th className={`${th} text-right`} title="Median latency in ms, PHP / Rust">p50 ms</th>
            <th className={`${th} text-right`} title="99th percentile latency in ms, PHP / Rust">p99 ms</th>
            <th className={`${th} text-right`} title="API container CPU time per request, PHP / Rust">CPU ms/req</th>
            <th className={`${th} text-right`} title="Peak memory of the API container, PHP / Rust">Peak MiB</th>
            <th className={`${th} text-right`} title="Failed requests, PHP / Rust">Failures</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={`${r.mode}${r.level}`} className="border-b border-border last:border-b-0">
              <td className="px-3 py-2 whitespace-nowrap">{load(r.mode, r.level)}</td>
              <td className={`${td} text-right whitespace-nowrap`}>
                {Math.round(r.php?.rps ?? 0)} / {Math.round(r.rust?.rps ?? 0)} <span className="pl-1 text-[11.5px]"><Ratio php={r.php?.rps} rust={r.rust?.rps} higher /></span>
              </td>
              <td className={`${td} text-right whitespace-nowrap`}>
                {ms(r.php?.latency.p50)} / {ms(r.rust?.latency.p50)} <span className="pl-1 text-[11.5px]"><Ratio php={r.php?.latency.p50} rust={r.rust?.latency.p50} /></span>
              </td>
              <td className={`${td} text-right whitespace-nowrap`}>
                {ms(r.php?.latency.p99)} / {ms(r.rust?.latency.p99)} <span className="pl-1 text-[11.5px]"><Ratio php={r.php?.latency.p99} rust={r.rust?.latency.p99} /></span>
              </td>
              <td className={`${td} text-right whitespace-nowrap`}>
                {ms(r.php?.cpu_ms_per_request)} / {ms(r.rust?.cpu_ms_per_request)}{' '}
                <span className="pl-1 text-[11.5px]"><Ratio php={r.php?.cpu_ms_per_request} rust={r.rust?.cpu_ms_per_request} /></span>
              </td>
              <td className={`${td} text-right whitespace-nowrap`}>
                {Math.round(r.php?.memory_mib.peak ?? 0)} / {Math.round(r.rust?.memory_mib.peak ?? 0)}
              </td>
              <td className={`${td} text-right whitespace-nowrap ${(r.php?.failures ?? 0) + (r.rust?.failures ?? 0) ? 'text-red-700 dark:text-red-300' : 'text-muted-foreground'}`}>
                {r.php?.failures ?? 0} / {r.rust?.failures ?? 0}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Per endpoint latency and throughput at one step, PHP against Rust. */
export function BenchEndpoints({ bench }: { bench: Benchmark }) {
  const rows = pairs(bench).filter((r) => r.php && r.rust)
  const [at, setAt] = useState(Math.max(0, rows.findIndex((r) => r.mode === 'vus')))
  const step = rows[at]
  if (!step?.php || !step.rust) return null
  const names = Object.keys(step.rust.endpoints)
  return (
    <div className="grid min-w-0 gap-2">
      <label className="flex items-center gap-2 text-[12.5px] text-muted-foreground">
        At
        <select value={at} onChange={(e) => setAt(Number(e.target.value))} className="h-8 rounded-md border border-border bg-card px-2 text-[12.5px] text-foreground">
          {rows.map((r, i) => (
            <option key={i} value={i}>
              {load(r.mode, r.level)}
            </option>
          ))}
        </select>
      </label>
      <div className="min-w-0 overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full border-collapse text-[12.5px]">
          <thead>
            <tr className="border-b border-border text-left text-[11px] tracking-wider text-muted-foreground uppercase">
              <th className={th}>Call</th>
              <th className={`${th} text-right`}>Req/s</th>
              <th className={`${th} text-right`}>p50 ms</th>
              <th className={`${th} text-right`}>p95 ms</th>
              <th className={`${th} text-right`}>p99 ms</th>
            </tr>
          </thead>
          <tbody>
            {names.map((n) => {
              const p = step.php?.endpoints[n]
              const r = step.rust?.endpoints[n]
              return (
                <tr key={n} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2">
                    <div className="font-mono text-[12px]">{n}</div>
                    {r?.route ? <div className="font-mono text-[11px] text-muted-foreground">{r.route}</div> : null}
                  </td>
                  <td className={`${td} text-right whitespace-nowrap`}>
                    {p?.rps ?? '–'} / {r?.rps ?? '–'}
                  </td>
                  <td className={`${td} text-right whitespace-nowrap`}>
                    {ms(p?.p50)} / {ms(r?.p50)} <span className="pl-1 text-[11.5px]"><Ratio php={p?.p50} rust={r?.p50} /></span>
                  </td>
                  <td className={`${td} text-right whitespace-nowrap`}>
                    {ms(p?.p95)} / {ms(r?.p95)}
                  </td>
                  <td className={`${td} text-right whitespace-nowrap`}>
                    {ms(p?.p99)} / {ms(r?.p99)} <span className="pl-1 text-[11.5px]"><Ratio php={p?.p99} rust={r?.p99} /></span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="m-0 text-[11.5px] text-muted-foreground">Each cell reads PHP / Rust; the ratio is how many times faster Rust is.</p>
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
      {bench.workload.users} seeded users{bench.workload.hashing ? ', with password hashing' : ''} · CPU from {e.cpu_source} · PHP <code className="font-mono">{e.php.image}</code>, Rust{' '}
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
