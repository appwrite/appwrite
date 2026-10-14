import { Link } from '@tanstack/react-router'
import { ArrowUpRight, BookOpen, Code2, FlaskConical, LayoutGrid, List, Package } from 'lucide-react'
import type { ReactNode } from 'react'
import type { LibrarySummary } from '@/lib/docs'
import { setLayout, useLayout, useMigration, type Layout } from '@/lib/prefs'
import { MigrationOnly } from './Migration'
import { StatusBadge } from './Status'
import { SyncBadge } from './Sync'
import { SpeedupBadge } from './Bench'
import { Time } from './Time'
import { LinesBadge } from './Size'

/** Page title block: a mono eyebrow after the red marker, the serif title, and its lead, closed by an ink rule. */
export function PageHeader({ eyebrow, title, mono, children, aside }: { eyebrow?: ReactNode; title: ReactNode; mono?: boolean; children?: ReactNode; aside?: ReactNode }) {
  return (
    <div className="trace-rule trace-bottom grid gap-4 border-b border-foreground pb-8">
      {eyebrow ? (
        <div className="label flex items-center gap-2.5 text-muted-foreground">
          <span className="marker" aria-hidden />
          {eyebrow}
        </div>
      ) : null}
      <div className="flex flex-wrap items-center gap-3">
        <h1 className={mono ? 'font-mono text-[30px] font-medium tracking-tight' : 'text-[38px] sm:text-[46px]'}>{title}</h1>
        {aside}
      </div>
      {children ? <div className="max-w-[68ch] text-[16px] leading-relaxed text-ink-soft">{children}</div> : null}
    </div>
  )
}

export function Section({ id, title, children, description, number }: { id?: string; title: string; children: ReactNode; description?: ReactNode; number?: number }) {
  return (
    <section id={id} className="trace-rule grid scroll-mt-20 gap-5 border-t border-border pt-6">
      <div className="grid gap-2">
        <h2 className="group flex items-baseline gap-3 text-[26px]">
          {number !== undefined ? <span className="font-mono text-[12px] tracking-normal text-highlight tabular-nums">{String(number).padStart(2, '0')}</span> : null}
          {title}
          {id ? (
            <a href={`#${id}`} className="font-mono text-[13px] text-transparent transition-colors group-hover:text-muted-foreground hover:text-highlight" aria-label={`Link to ${title}`}>
              #
            </a>
          ) : null}
        </h2>
        {description ? <p className="m-0 max-w-[68ch] text-[14px] text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  )
}

/** A library in a grid of cards. */
export function LibraryCard({ lib }: { lib: LibrarySummary }) {
  return (
    <Link
      to="/$lib"
      params={{ lib: lib.slug }}
      className="pad group relative grid content-start gap-3 border-r border-b border-border bg-card p-5 transition-colors hover:bg-background"
    >
      <div className="flex items-start justify-between gap-2">
        <div className="grid gap-1.5">
          <span className="flex items-center gap-1.5 font-display text-[16px] font-semibold tracking-tight">
            {lib.title}
            <ArrowUpRight className="size-3.5 text-muted-foreground/0 transition-colors group-hover:text-highlight" />
          </span>
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
      <p className="m-0 line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">{lib.description}</p>
      <div className="mt-auto flex flex-wrap gap-x-3 gap-y-1 border-t border-border pt-3 font-mono text-[10.5px] text-muted-foreground">
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

/** Cards share their hairlines, as cells of one printed table. */
export function CardGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fill,minmax(270px,1fr))] border-t border-l border-border">{children}</div>
}

/** A library as one row of a list: name and crate, what it does, its counts. */
export function LibraryRow({ lib }: { lib: LibrarySummary }) {
  return (
    <Link
      to="/$lib"
      params={{ lib: lib.slug }}
      className="group grid items-center gap-x-4 gap-y-1 px-4 py-3 transition-colors hover:bg-background sm:grid-cols-[minmax(150px,220px)_minmax(0,1fr)_auto]"
    >
      <span className="grid min-w-0 gap-0.5">
        <span className="truncate font-display text-[14.5px] font-semibold tracking-tight">{lib.title}</span>
        <span className="truncate font-mono text-[11px] text-muted-foreground">{lib.rust?.crate ?? lib.php?.package ?? lib.slug}</span>
      </span>
      <span className="min-w-0 truncate text-[13px] text-muted-foreground">{lib.description}</span>
      <span className="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[10.5px] whitespace-nowrap text-muted-foreground sm:justify-end">
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
      <div className="grid divide-y divide-border border-y border-border bg-card">
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
      className="inline-flex size-7 items-center justify-center text-muted-foreground transition-colors hover:text-foreground aria-pressed:bg-accent aria-pressed:text-foreground"
    >
      <Icon className="size-4" />
    </button>
  )
  return (
    <div role="group" aria-label="Layout" className="inline-flex h-9 items-center gap-0.5 border border-border bg-card px-1">
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
      <div className="label mb-3 text-muted-foreground">On this page</div>
      <div className="grid gap-0.5 border-l border-border">
        {entries.map((e) => (
          <a
            key={e.id}
            href={`#${e.id}`}
            className={`-ml-px truncate border-l border-transparent py-1 text-[12.5px] text-muted-foreground transition-colors hover:border-foreground hover:text-foreground ${e.depth ? 'pl-6 font-mono text-[12px]' : 'pl-3'}`}
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
