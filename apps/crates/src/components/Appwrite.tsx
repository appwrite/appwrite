import { ArrowLeftRight, Check, CircleDashed, CircleDot, ExternalLink, X } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { sourceUrl } from '@/lib/docs'
import { setMigration, useMigration } from '@/lib/prefs'
import { Time } from './Time'
import { badge } from './Status'
import { HOOK_STATUS, KIND_LABEL, ROUTE_STATUS, SERVICE_STATUS, type Hook, type HookStatus, type RouteStatus, type Run, type ServiceStatus } from '@/lib/appwrite'

const SERVICE_BADGE: Record<ServiceStatus, string> = {
  rust: 'bg-ok-soft text-ok-ink',
  partial: 'bg-progress-soft text-progress-ink',
  php: 'bg-planned-soft text-planned-ink',
}
const SERVICE_DOT: Record<ServiceStatus, string> = { rust: 'bg-ok', partial: 'bg-progress', php: 'bg-planned' }

export function ServiceBadge({ status }: { status: ServiceStatus }) {
  return (
    <span className={`${badge} ${SERVICE_BADGE[status]}`} title={SERVICE_STATUS[status].note}>
      <span className={`inline-block size-1.5 ${SERVICE_DOT[status]}`} />
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
  return <span className={`inline-block w-[52px] px-1 py-px text-center font-mono text-[10.5px] font-semibold ${METHOD[method] ?? 'bg-muted'}`}>{method}</span>
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
          <Time date={run.date} /> · commit <code className="font-mono">{run.commit}</code> · {run.wall}s
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
