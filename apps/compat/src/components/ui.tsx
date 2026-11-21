import { useState, type ReactNode } from 'react'
import type { Fit, LibSummary, Verdict } from '@/lib/report'
import { problemCount } from '@/lib/report'

export const fmt = (n: number | null | undefined) => Number(n ?? 0).toLocaleString('en-US')
export const pct = (a: number, b: number) => (b ? Math.round((a / b) * 1000) / 10 : 100)

type Tone = 'ok' | 'bad' | 'warn' | 'info' | 'plain'

const TONES: Record<Tone, string> = {
  ok: 'bg-ok-surface border-ok-border text-ok-ink',
  bad: 'bg-bad-surface border-bad-border text-bad-ink',
  warn: 'bg-warn-surface border-warn-border text-warn-ink',
  info: 'bg-info-surface border-info-border text-info-ink',
  plain: 'bg-muted border-border text-muted-foreground',
}
const DOTS: Record<Tone, string> = { ok: 'bg-ok', bad: 'bg-bad', warn: 'bg-warn', info: 'bg-info', plain: 'hidden' }

export function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 text-xs font-medium ${TONES[tone]}`}>
      <span className={`size-1.5 rounded-full ${DOTS[tone]}`} />
      {children}
    </span>
  )
}

export function Chip({ children, mono }: { children: ReactNode; mono?: boolean }) {
  return (
    <span className={`inline-block whitespace-nowrap rounded-md border border-border px-1.5 text-[0.7rem] text-muted-foreground ${mono ? 'font-mono' : ''}`}>
      {children}
    </span>
  )
}

export function Dot({ tone }: { tone: Tone }) {
  return <span className={`size-2 flex-none rounded-full ${DOTS[tone] === 'hidden' ? 'bg-ring' : DOTS[tone]}`} />
}

export function LibStatus({ lib }: { lib: LibSummary }) {
  const problems = problemCount(lib)
  if (problems) return <Pill tone="bad">{problems} problem{problems > 1 ? 's' : ''}</Pill>
  if (lib.complete) return <Pill tone="ok">Complete</Pill>
  return <Pill tone="warn">In progress</Pill>
}

export function Bar({ value, total, extra = 0, label }: { value: number; total: number; extra?: number; label: ReactNode }) {
  const a = pct(value, total)
  const w = pct(extra, total)
  return (
    <div className="grid min-w-28 gap-1">
      <div className="flex h-1.5 overflow-hidden rounded bg-muted">
        <div className={a + w >= 100 ? 'bg-ok' : 'bg-warn'} style={{ width: `${a}%` }} />
        {extra ? <div className="bg-ring" style={{ width: `${w}%` }} /> : null}
      </div>
      <div className="text-xs tabular-nums text-muted-foreground">{label}</div>
    </div>
  )
}

export const VERDICTS: [Verdict, string][] = [
  ['same', 'Same'],
  ['compatible', 'Compatible'],
  ['review', 'Review'],
  ['differs', 'Differs'],
  ['type', 'Type only'],
  ['missing', 'No Rust counterpart'],
]
export const VERDICT_BG: Record<Verdict, string> = {
  same: 'bg-ok',
  compatible: 'bg-ok/45',
  review: 'bg-warn',
  differs: 'bg-bad',
  type: 'bg-ring',
  missing: 'bg-ring',
}
export const VERDICT_TONE: Record<Verdict, Tone> = {
  same: 'ok',
  compatible: 'ok',
  review: 'warn',
  differs: 'bad',
  type: 'plain',
  missing: 'plain',
}
export const FIT_TONE: Record<Fit, Tone> = { same: 'ok', compatible: 'ok', review: 'warn', differs: 'bad' }
export const verdictName = (v: Verdict) => VERDICTS.find(([k]) => k === v)?.[1] ?? v

export function VerdictStack({ iface }: { iface: LibSummary['interface'] }) {
  if (!iface.methods) return <span className="text-xs text-muted-foreground">—</span>
  const v = iface.verdicts
  const ok = (v.same ?? 0) + (v.compatible ?? 0)
  return (
    <div className="grid min-w-32 gap-1">
      <div className="flex h-2 overflow-hidden rounded bg-muted">
        {VERDICTS.filter(([k]) => v[k]).map(([k, name]) => (
          <span key={k} className={VERDICT_BG[k]} style={{ width: `${pct(v[k] ?? 0, iface.methods)}%` }} title={`${name}: ${v[k]}`} />
        ))}
      </div>
      <div className="text-xs tabular-nums text-muted-foreground">
        {fmt(ok)} / {fmt(iface.methods)} match
        {v.differs ? ` · ${v.differs} differ` : ''}
        {v.review ? ` · ${v.review} review` : ''}
      </div>
    </div>
  )
}

export function Stat({ label, value, note }: { label: string; value: ReactNode; note?: ReactNode }) {
  return (
    <div className="grid gap-0.5 rounded-[10px] border border-border px-3.5 py-3">
      <span className="text-[0.7rem] uppercase tracking-wider text-muted-foreground">{label}</span>
      <span className="font-display text-xl font-medium tabular-nums tracking-tight">{value}</span>
      {note ? <span className="text-xs text-muted-foreground">{note}</span> : null}
    </div>
  )
}

export function Stats({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-2.5">{children}</div>
}

export function Callout({ tone, children }: { tone: 'ok' | 'bad' | 'utopia'; children: ReactNode }) {
  const cls = tone === 'utopia' ? 'border-periwinkle/30 bg-periwinkle/5' : TONES[tone]
  return <div className={`rounded-[10px] border px-3 py-2.5 text-[0.82rem] [overflow-wrap:anywhere] ${cls}`}>{children}</div>
}

export function Panel({ title, extra, children, short }: { title: string; extra?: ReactNode; children: ReactNode; short?: boolean }) {
  return (
    <div className="min-w-0 overflow-hidden rounded-[10px] border border-code-border bg-code text-code-foreground">
      <div className="flex justify-between gap-2 border-b border-code-border px-2.5 py-1 text-[0.7rem] text-code-muted">
        <b className="font-semibold text-zinc-200">{title}</b>
        <span>{extra}</span>
      </div>
      <pre className={`m-0 overflow-auto whitespace-pre px-2.5 py-2 font-mono text-[11.5px] leading-normal ${short ? 'max-h-40' : 'max-h-90'}`}>{children}</pre>
    </div>
  )
}

export const json = (v: unknown) => (v === undefined ? '—' : JSON.stringify(v, null, 2))

/** Both results, with the lines that differ highlighted (an LCS line diff). */
export function ResultPair({ php, rust, phpExtra }: { php: unknown; rust: unknown; phpExtra?: string }) {
  const a = json(php)
  const b = json(rust)
  if (a === b) {
    return (
      <div className="grid gap-2 md:grid-cols-2">
        <Panel title="PHP" extra={phpExtra}>{a}</Panel>
        <Panel title="Rust" extra="identical">{b}</Panel>
      </div>
    )
  }
  const [left, right] = lineDiff(a, b)
  return (
    <div className="grid gap-2 md:grid-cols-2">
      <Panel title="PHP" extra={phpExtra}>{left}</Panel>
      <Panel title="Rust" extra="differs">{right}</Panel>
    </div>
  )
}

function lineDiff(a: string, b: string): [ReactNode, ReactNode] {
  const x = a.split('\n')
  const y = b.split('\n')
  if (x.length * y.length > 250_000) return [a, b]
  const t = Array.from({ length: x.length + 1 }, () => new Uint16Array(y.length + 1))
  for (let i = x.length - 1; i >= 0; i--)
    for (let j = y.length - 1; j >= 0; j--) t[i][j] = x[i] === y[j] ? t[i + 1][j + 1] + 1 : Math.max(t[i + 1][j], t[i][j + 1])
  const left: ReactNode[] = []
  const right: ReactNode[] = []
  let i = 0
  let j = 0
  const line = (s: string, cls: string, k: string) => (cls ? <span key={k} className={cls}>{s || ' '}</span> : `${s}\n`)
  while (i < x.length && j < y.length) {
    if (x[i] === y[j]) {
      left.push(line(x[i], '', `l${i}`))
      right.push(line(y[j], '', `r${j}`))
      i++
      j++
    } else if (t[i + 1][j] >= t[i][j + 1]) left.push(line(x[i], 'diff-del', `l${i++}`))
    else right.push(line(y[j], 'diff-add', `r${j++}`))
  }
  while (i < x.length) left.push(line(x[i], 'diff-del', `l${i++}`))
  while (j < y.length) right.push(line(y[j], 'diff-add', `r${j++}`))
  return [left, right]
}

/** A row that renders its body only once opened. */
export function Expand({ summary, children }: { summary: ReactNode; children: () => ReactNode }) {
  const [open, setOpen] = useState(false)
  return (
    <details className="group min-w-0 border-b border-border last:border-b-0" onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}>
      <summary className="flex cursor-pointer list-none flex-wrap items-center gap-2.5 px-3 py-2 transition-colors hover:bg-accent/60 [&::-webkit-details-marker]:hidden">
        {summary}
        <span className="w-3 text-muted-foreground transition-transform group-open:rotate-90">›</span>
      </summary>
      {open ? <div className="grid min-w-0 gap-3 px-3 pb-3.5 pt-1">{children()}</div> : null}
    </details>
  )
}

export function List({ children }: { children: ReactNode }) {
  return <div className="grid overflow-hidden rounded-[10px] border border-border">{children}</div>
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="p-4 text-center text-muted-foreground">{children}</div>
}

export function Segmented<T extends string>({
  value,
  options,
  onChange,
}: {
  value: T
  options: [T, string, number?][]
  onChange: (v: T) => void
}) {
  return (
    <div className="inline-flex flex-wrap overflow-hidden rounded-lg border border-border" role="group">
      {options.map(([k, name, count]) => (
        <button
          key={k}
          type="button"
          aria-pressed={value === k}
          onClick={() => onChange(k)}
          className="border-r border-border px-2.5 py-1.5 text-xs text-muted-foreground last:border-r-0 aria-pressed:bg-primary aria-pressed:text-primary-foreground"
        >
          {name}
          {count !== undefined ? <span className="ml-1 tabular-nums">{count}</span> : null}
        </button>
      ))}
    </div>
  )
}

export function Search({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <input
      type="search"
      value={value}
      placeholder={placeholder}
      aria-label={placeholder}
      onChange={(e) => onChange(e.target.value)}
      className="min-w-0 flex-[1_1_240px] rounded-lg border border-border bg-background px-2.5 py-1.5"
    />
  )
}

export function More({ shown, total, onMore }: { shown: number; total: number; onMore: () => void }) {
  if (shown >= total) return null
  return (
    <div className="p-4 text-center">
      <button type="button" onClick={onMore} className="rounded-lg border border-border bg-background px-3 py-1.5 shadow-xs hover:bg-accent">
        Show {Math.min(100, total - shown)} more of {fmt(total - shown)}
      </button>
    </div>
  )
}

export function Source({ href, label }: { href: string; label: string }) {
  return (
    <a href={href} target="_blank" rel="noopener" className="text-xs text-muted-foreground [overflow-wrap:anywhere] hover:text-foreground">
      {label}
    </a>
  )
}

/** PHP's docblock without its comment markers. */
export function docblock(doc: string | null | undefined) {
  return String(doc ?? '')
    .replace(/^\s*\/\*\*\s?/, '')
    .replace(/\s*\*\/\s*$/, '')
    .split('\n')
    .map((l) => l.replace(/^\s*\* ?/, ''))
    .join('\n')
    .trim()
}
