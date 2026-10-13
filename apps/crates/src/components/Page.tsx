import { Link } from '@tanstack/react-router'
import { BookOpen, Code2, FlaskConical, LayoutGrid, List, Package } from 'lucide-react'
import type { ReactNode } from 'react'
import type { LibrarySummary } from '@/lib/docs'
import { setLayout, useLayout, useMigration, type Layout } from '@/lib/prefs'
import { MigrationOnly } from './Migration'
import { StatusBadge } from './Status'
import { SyncBadge } from './Sync'
import { SpeedupBadge } from './Bench'
import { Time } from './Time'
import { LinesBadge } from './Size'

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
            <SpeedupBadge bench={lib.bench} />
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
        <LinesBadge lib={lib} />
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

/** A library as one row of a list: name and crate, what it does, its counts. */
export function LibraryRow({ lib }: { lib: LibrarySummary }) {
  return (
    <Link
      to="/$lib"
      params={{ lib: lib.slug }}
      className="grid items-center gap-x-4 gap-y-1 px-4 py-2.5 transition-colors hover:bg-accent/40 sm:grid-cols-[minmax(150px,220px)_minmax(0,1fr)_auto]"
    >
      <span className="grid min-w-0 gap-0.5">
        <span className="truncate font-display text-[14.5px] font-medium">{lib.title}</span>
        <span className="truncate font-mono text-[11px] text-muted-foreground">{lib.rust?.crate ?? lib.php?.package ?? lib.slug}</span>
      </span>
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">{lib.description}</span>
      <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] whitespace-nowrap text-muted-foreground sm:justify-end">
        {lib.rust ? (
          <span className="inline-flex items-center gap-1">
            <Package className="size-3" />
            {lib.counts.items}
          </span>
        ) : null}
        {lib.counts.examples ? (
          <span className="inline-flex items-center gap-1">
            <Code2 className="size-3" />
            {lib.counts.examples}
          </span>
        ) : null}
        <LinesBadge lib={lib} />
        <MigrationOnly>
          {lib.compat ? (
            <span className="inline-flex items-center gap-1">
              <FlaskConical className="size-3" />
              {lib.compat.cases}
            </span>
          ) : null}
          <SpeedupBadge bench={lib.bench} />
          <SyncBadge sync={lib.sync} />
          <StatusBadge status={lib.status} />
        </MigrationOnly>
        {lib.updated?.at ? (
          <span className="min-w-[7.5rem] sm:text-right">
            Updated <Time date={lib.updated.at} />
          </span>
        ) : null}
      </span>
    </Link>
  )
}

/** Libraries as the reader chose to see them: cards or rows. */
export function LibraryList({ libs }: { libs: LibrarySummary[] }) {
  const layout = useLayout()
  if (layout === 'list') {
    return (
      <div className="grid divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
        {libs.map((lib) => (
          <LibraryRow key={lib.slug} lib={lib} />
        ))}
      </div>
    )
  }
  return (
    <CardGrid>
      {libs.map((lib) => (
        <LibraryCard key={lib.slug} lib={lib} />
      ))}
    </CardGrid>
  )
}

/** Grid or list: switches every list of libraries, remembered in this browser. */
export function LayoutToggle() {
  const layout = useLayout()
  const option = (value: Layout, label: string, Icon: typeof List) => (
    <button
      type="button"
      aria-pressed={layout === value}
      aria-label={`${label} view`}
      title={`${label} view`}
      onClick={() => setLayout(value)}
      className="inline-flex size-7 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-accent aria-pressed:text-foreground"
    >
      <Icon className="size-4" />
    </button>
  )
  return (
    <div role="group" aria-label="Layout" className="inline-flex h-9 items-center gap-0.5 rounded-md border border-border bg-card px-1">
      {option('grid', 'Grid', LayoutGrid)}
      {option('list', 'List', List)}
    </div>
  )
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
