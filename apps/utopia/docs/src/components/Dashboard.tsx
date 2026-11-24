// The migration dashboard (`/migration`): its tabs, the progress bar at the top of
// each, and the expandable rows its lists share.

import { Link } from '@tanstack/react-router'
import { Check, ChevronDown, ChevronRight, CircleDot, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { Bar } from './Appwrite'
import { percent } from '@/lib/appwrite'
import type { Progress, Tone } from '@/lib/progress'

const FILL: Record<Tone, string> = { ok: 'bg-ok', progress: 'bg-progress', started: 'bg-started', missing: 'bg-red-500' }

export type TabId = 'utopia' | 'services' | 'hooks' | 'facts' | 'tasks'

export const TABS: { id: TabId; to: string; label: string; note: string }[] = [
  { id: 'utopia', to: '/migration/utopia', label: 'Utopia', note: 'The Utopia libraries Appwrite stands on, each converted and proven against PHP.' },
  { id: 'services', to: '/migration/services', label: 'Services', note: 'The Appwrite API, service by service, and how much of it Rust serves.' },
  { id: 'hooks', to: '/migration/hooks', label: 'Hooks', note: 'The shared logic every request runs through: hooks and request resources.' },
  { id: 'facts', to: '/migration/facts', label: 'Facts', note: 'The project in numbers.' },
  { id: 'tasks', to: '/migration/tasks', label: 'Tasks', note: 'The work, by status.' },
]

const tab =
  'relative -mb-px inline-flex h-10 flex-none items-center gap-2 border-b-2 border-transparent px-3 text-[13.5px] text-muted-foreground transition-colors hover:text-foreground'

/** The dashboard's tabs, each with its section's share done. */
export function Tabs({ progress }: { progress: Partial<Record<TabId, Progress>> }) {
  return (
    <nav className="flex min-w-0 overflow-x-auto border-b border-border" aria-label="Migration">
      {TABS.map((t) => {
        const p = progress[t.id]
        return (
          <Link key={t.id} to={t.to} className={tab} activeProps={{ className: '!border-foreground !text-foreground', 'aria-current': 'page' }} title={t.note}>
            {t.label}
            {p ? <span className="font-mono text-[11px] text-muted-foreground tabular-nums">{percent(p.done, p.total)}%</span> : null}
          </Link>
        )
      })}
    </nav>
  )
}

/** The progress bar at the top of a tab: the share done (`count`: and of how many), the bar, its legend, and a line about it. */
export function TabProgress({ title, progress, count = true, children }: { title: string; progress: Progress; count?: boolean; children?: ReactNode }) {
  const p = progress
  const legend = [...p.parts.filter((x) => x.value).map((x) => ({ ...x, fill: FILL[x.tone] })), ...(p.rest?.value ? [{ ...p.rest, fill: 'bg-muted ring-1 ring-inset ring-border' }] : [])]
  return (
    <div className="grid gap-2.5 rounded-lg border border-border bg-card p-4">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="label text-muted-foreground">{title}</span>
        <span className="ml-auto text-[13px] text-muted-foreground tabular-nums">
          <span className="font-display text-[28px] leading-none font-medium text-foreground">{percent(p.done, p.total)}%</span>
          {count ? (
            <span>
              {' '}
              · {p.done.toLocaleString('en-US')} of {p.total.toLocaleString('en-US')}
            </span>
          ) : null}
        </span>
      </div>
      <Bar className="h-3" total={p.total} parts={p.parts.map((x) => ({ value: x.value, className: FILL[x.tone], title: `${x.label}: ${x.value}` }))} />
      {legend.length > 1 ? (
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-muted-foreground">
          {legend.map((x) => (
            <span key={x.label} className="inline-flex items-center gap-1.5">
              <span className={`inline-block size-2 rounded-full ${x.fill}`} aria-hidden />
              {x.label} <span className="text-foreground tabular-nums">{x.value.toLocaleString('en-US')}</span>
            </span>
          ))}
        </div>
      ) : null}
      {children ? <div className="text-[12.5px] text-muted-foreground">{children}</div> : null}
    </div>
  )
}

/** The button that opens a row's full results. */
export function Expand({ open, onClick, label }: { open: boolean; onClick: () => void; label: string }) {
  const Icon = open ? ChevronDown : ChevronRight
  return (
    <button
      type="button"
      onClick={onClick}
      aria-expanded={open}
      aria-label={`${open ? 'Hide' : 'Show'} ${label}`}
      title={`${open ? 'Hide' : 'Show'} ${label}`}
      className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
    >
      <Icon className="size-4" />
    </button>
  )
}

/** A test result in a list: passed (green), failed (red), partly (amber) or not run (grey). */
export function TestState({ state, children, title }: { state: 'pass' | 'fail' | 'partial' | 'none'; children: ReactNode; title?: string }) {
  const icon =
    state === 'pass' ? (
      <Check className="size-3.5 flex-none text-ok" />
    ) : state === 'fail' ? (
      <X className="size-3.5 flex-none text-red-500" />
    ) : state === 'partial' ? (
      <CircleDot className="size-3.5 flex-none text-progress" />
    ) : null
  const text = state === 'fail' ? 'text-red-700 dark:text-red-300' : state === 'none' ? 'text-muted-foreground' : ''
  return (
    <span className={`inline-flex items-center gap-1 text-[12px] whitespace-nowrap tabular-nums ${text}`} title={title}>
      {icon}
      {children}
    </span>
  )
}

export const chip =
  'inline-flex h-7 items-center gap-1.5 rounded-md border border-border px-2.5 text-[12.5px] text-muted-foreground transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'
