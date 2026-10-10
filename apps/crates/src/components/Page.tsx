import { Link } from '@tanstack/react-router'
import { BookOpen, Code2, FlaskConical, Package } from 'lucide-react'
import type { ReactNode } from 'react'
import type { LibrarySummary } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'
import { MigrationOnly } from './Migration'
import { StatusBadge } from './Status'
import { SyncBadge } from './Sync'

/** Page title block in the console's style. */
export function PageHeader({ eyebrow, title, mono, children, aside }: { eyebrow?: ReactNode; title: ReactNode; mono?: boolean; children?: ReactNode; aside?: ReactNode }) {
  return (
    <div className="grid gap-2">
      {eyebrow ? <div className="text-[13px] text-muted-foreground">{eyebrow}</div> : null}
      <div className="flex flex-wrap items-center gap-3">
        <h1 className={mono ? 'font-mono text-[26px] font-medium tracking-tight' : 'text-[28px] leading-tight'}>{title}</h1>
        {aside}
      </div>
      {children ? <div className="max-w-[72ch] text-[15px] text-muted-foreground">{children}</div> : null}
    </div>
  )
}

export function Section({ id, title, children, description }: { id?: string; title: string; children: ReactNode; description?: ReactNode }) {
  return (
    <section id={id} className="grid scroll-mt-20 gap-3">
      <div className="grid gap-1">
        <h2 className="text-xl">{title}</h2>
        {description ? <p className="m-0 max-w-[72ch] text-[13px] text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  )
}

/** A library in a grid of cards. */
export function LibraryCard({ lib }: { lib: LibrarySummary }) {
  return (
    <Link to="/$lib" params={{ lib: lib.slug }} className="group grid content-start gap-2.5 rounded-lg border border-border bg-card p-4 transition-colors hover:border-ring hover:bg-accent/40">
      <div className="flex items-start justify-between gap-2">
        <div className="grid gap-0.5">
          <span className="font-display text-[16px] font-medium">{lib.title}</span>
          <span className="font-mono text-[11px] text-muted-foreground">{lib.rust?.crate ?? lib.php?.package ?? lib.slug}</span>
        </div>
        <MigrationOnly>
          <div className="grid justify-items-end gap-1">
            <StatusBadge status={lib.status} />
            <SyncBadge sync={lib.sync} />
          </div>
        </MigrationOnly>
      </div>
      <p className="m-0 line-clamp-2 text-[13px] text-muted-foreground">{lib.description}</p>
      <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
        {lib.rust ? (
          <span className="inline-flex items-center gap-1">
            <Package className="size-3" />
            {lib.counts.items} items
          </span>
        ) : null}
        <MigrationOnly>
        {lib.compat ? (
          <span className="inline-flex items-center gap-1">
            <FlaskConical className="size-3" />
            {lib.compat.cases} cases vs PHP
          </span>
        ) : null}
        </MigrationOnly>
        {lib.counts.examples ? (
          <span className="inline-flex items-center gap-1">
            <Code2 className="size-3" />
            {lib.counts.examples} examples
          </span>
        ) : null}
        <MigrationOnly>
        {!lib.rust && lib.php ? (
          <span className="inline-flex items-center gap-1">
            <BookOpen className="size-3" />
            {lib.counts.php_symbols} PHP methods
          </span>
        ) : null}
        </MigrationOnly>
      </div>
    </Link>
  )
}

export function CardGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fill,minmax(270px,1fr))] gap-3">{children}</div>
}

/** "On this page": a sticky rail of anchors beside long pages (wide screens). */
export function Toc({ entries }: { entries: { id: string; label: string; depth?: number }[] }) {
  if (entries.length < 2) return null
  return (
    <nav className="sticky top-20 hidden max-h-[calc(100vh-6rem)] overflow-y-auto xl:block">
      <div className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">On this page</div>
      <div className="grid gap-0.5 border-l border-border">
        {entries.map((e) => (
          <a
            key={e.id}
            href={`#${e.id}`}
            className={`-ml-px truncate border-l border-transparent py-1 text-[12.5px] text-muted-foreground hover:border-foreground hover:text-foreground ${e.depth ? 'pl-6 font-mono text-[12px]' : 'pl-3'}`}
          >
            {e.label}
          </a>
        ))}
      </div>
    </nav>
  )
}

/** Page body with a rail: "On this page" from `toc`, or a page's own `rail`. */
export function WithToc({ children, toc = [], rail }: { children: ReactNode; toc?: { id: string; label: string; depth?: number }[]; rail?: ReactNode }) {
  // Comparing with PHP puts two code panels side by side; they get the rail's width,
  // except on screens wide enough for both.
  const php = useMigration()
  const columns = rail ? (php ? '2xl:grid-cols-[minmax(0,1fr)_260px]' : 'xl:grid-cols-[minmax(0,1fr)_260px]') : php ? '' : 'xl:grid-cols-[minmax(0,1fr)_200px]'
  return (
    <div className={`grid gap-10 ${columns}`}>
      <div className="grid min-w-0 content-start gap-9">{children}</div>
      {rail ? <div className={`hidden ${php ? '2xl:block' : 'xl:block'}`}>{rail}</div> : php ? null : <Toc entries={toc} />}
    </div>
  )
}
