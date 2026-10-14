import { Link, getRouteApi } from '@tanstack/react-router'
import { ArrowLeftRight, Search } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { dependents as dependentsOf, isTopLevel, moduleSplat, taskHash, type Navigation } from '@/lib/docs'
import { StatusBadge, StatusDot } from './Status'
import { MigrationOnly } from './Migration'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')

// The active row: ink text and the red square before it.
const row =
  'relative flex min-w-0 items-center gap-2 px-2 py-[5px] text-[13px] text-sidebar-ink transition-colors hover:bg-sidebar-accent hover:text-sidebar-strong data-[status=active]:bg-sidebar-accent data-[status=active]:font-medium data-[status=active]:text-sidebar-strong data-[status=active]:before:absolute data-[status=active]:before:top-1/2 data-[status=active]:before:-left-[11.5px] data-[status=active]:before:size-[6px] data-[status=active]:before:-translate-y-1/2 data-[status=active]:before:bg-highlight'

/**
 * A menu item shown only in migration mode carries the migration marking (a yellow tint), so the
 * menu reads as the Rust docs plus what migration mode adds. Every such row and group goes through this.
 */
const MIGRATION_TITLE = 'Shown in migration mode'
const item = (migration?: boolean) => (migration ? `${row} nav-migration` : row)

// A group's title: mono capitals, so it never reads as one of the rows below.
const heading = 'label mt-3 px-2 pt-2 pb-1 text-muted-foreground first:mt-0 first:pt-1'

/**
 * A group of the menu, always open: its title (a link to the group's own page
 * when it has one) and its rows below, no folding.
 */
function Section({ title, count, migration, children }: { title: ReactNode; count?: number; migration?: boolean; children: ReactNode }) {
  return (
    <div className="grid gap-px">
      <div className={`flex min-w-0 items-center gap-2 ${heading} ${migration ? 'nav-migration' : ''}`} title={migration ? MIGRATION_TITLE : undefined}>
        <span className="min-w-0 flex-1 truncate">{title}</span>
        {count !== undefined ? <span className="tabular-nums tracking-normal">{count}</span> : null}
      </div>
      {children}
    </div>
  )
}


/** A library's own menu, a second column beside the catalog: its sections, modules (PHP: namespaces) and items. */
export function LibraryMenu({ nav, onNavigate }: { nav: Navigation; onNavigate?: () => void }) {
  const slug = nav.library.slug
  const [q, setQ] = useState('')
  const term = q.trim().toLowerCase()
  const top = nav.items.filter(isTopLevel)
  const hit = (text: string) => !term || text.toLowerCase().includes(term)
  const migration = useMigration()
  // The binaries the crate ships; in migration mode, also how many PHP tasks have none yet.
  const commands = nav.tasks.filter((t) => t.status === 'ported' && hit(t.bin ?? t.name))
  const unported = migration && !term ? nav.tasks.filter((t) => t.status !== 'ported').length : 0
  // The crate's dependencies; for a library without one, the PHP package's requirements.
  const counts = nav.library.counts.dependencies
  const deps = counts && (nav.library.rust ? counts.rust : migration ? counts.php : undefined)
  // Everything depending on it, Appwrite included: through either runtime in migration mode, Rust alone otherwise.
  const dependents = counts ? dependentsOf(counts, migration).count : undefined
  // Without a crate or a Rust dependent, the page is only in the menu for migration mode.
  const depsMigration = !nav.library.rust && !(counts && dependentsOf(counts, false).count)
  let body: ReactNode
  if (!nav.library.rust && !migration) {
    body = null
  } else if (!nav.library.rust) {
    const nsOf = (c: string) => (c.includes('\\') ? c.slice(0, c.lastIndexOf('\\')) : '')
    // Namespaces are shown relative to the library's own (`Utopia\CircuitBreaker\Adapter` → `Adapter`).
    const base = nav.phpClasses.map(nsOf).reduce((a, b) => (b.length < a.length ? b : a), nsOf(nav.phpClasses[0] ?? ''))
    const byNamespace = new Map<string, string[]>()
    for (const c of nav.phpClasses.filter(hit)) byNamespace.set(nsOf(c), [...(byNamespace.get(nsOf(c)) ?? []), c])
    body = [...byNamespace.entries()].map(([ns, classes]) => (
      <Section key={ns} migration title={`PHP · ${ns === base ? (base.split('\\').pop() ?? 'global') : ns.slice(base.length + 1)}`} count={classes.length}>
        {classes.map((c) => (
          <Link key={c} to="/$lib/php-api/$" params={{ lib: slug, _splat: c.split('\\').join('/') }} className={row}>
            <span className="truncate font-mono text-[12px]">{c.split('\\').pop()}</span>
          </Link>
        ))}
      </Section>
    ))
  } else {
    // The library's architecture: its top-level modules, each a page that
    // holds its whole subtree. Submodules are sections of that page.
    const modules = nav.modules.filter((m) => m.path && !m.path.includes('::'))
    const below = (path: string) => nav.modules.filter((m) => m.path.startsWith(`${path}::`))
    const shown = modules
      .filter((m) => hit(m.path) || below(m.path).some((c) => hit(c.path)))
      .sort((a, b) => a.path.localeCompare(b.path))
    const hasRoot = top.some((i) => i.module === '') && hit('crate root')
    body = (
      <>
        <div className={heading}>Modules</div>
        {hasRoot ? (
          <Link to="/$lib/$" params={{ lib: slug, _splat: moduleSplat('') }} className={row}>
            <span className="truncate">Crate root</span>
          </Link>
        ) : null}
        {shown.map((m) => {
          const parts = below(m.path).length
          return (
            <Link key={m.path} to="/$lib/$" params={{ lib: slug, _splat: moduleSplat(m.path) }} className={row} title={m.summary || m.path}>
              <span className="truncate font-mono text-[12.5px]">{m.path}</span>
              {parts ? <span className="ml-auto font-mono text-[10px] whitespace-nowrap text-muted-foreground">{parts} {parts === 1 ? 'submodule' : 'submodules'}</span> : null}
            </Link>
          )
        })}
        {!hasRoot && !shown.length ? <span className="px-2 py-1 text-[12.5px] text-muted-foreground">No module matches.</span> : null}
      </>
    )
  }

  return (
    <nav className="grid content-start gap-3 p-3" onClick={(e) => (e.target as HTMLElement).closest('a') && onNavigate?.()}>
      <div className="grid gap-1.5 border-b border-foreground pb-3">
        <div className="flex items-center justify-between gap-2">
          <Link to="/$lib" params={{ lib: slug }} className="title truncate text-[19px] leading-none">
            {nav.library.title}
          </Link>
          <MigrationOnly>
            <StatusBadge status={nav.library.status} />
          </MigrationOnly>
        </div>
        <span className="truncate font-mono text-[11px] text-muted-foreground">{nav.library.rust?.crate ?? (migration ? nav.library.php?.package : nav.library.slug)}</span>
      </div>
      <label className="flex h-8 items-center gap-2 border border-border bg-card px-2.5 text-muted-foreground transition-colors focus-within:border-foreground">
        <Search className="size-3.5" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={nav.library.rust ? 'Filter modules and commands' : 'Filter classes'}
          aria-label={nav.library.rust ? 'Filter modules and commands' : 'Filter classes'}
          className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
        />
      </label>
      <div className="grid gap-0.5">
        <div className={heading}>Library</div>
        <Link to="/$lib" params={{ lib: slug }} activeOptions={{ exact: true, includeHash: false }} className={row}>
          Overview
        </Link>
        {nav.library.rust || migration || dependents ? (
          <Link to="/$lib/dependencies" params={{ lib: slug }} className={item(depsMigration)} title={depsMigration ? MIGRATION_TITLE : undefined}>
            <span className="truncate">Dependencies</span>
            <span className="ml-auto flex items-center gap-1.5 font-mono text-[10px] whitespace-nowrap text-muted-foreground tabular-nums">
              {deps !== undefined ? <span title={`${deps} ${deps === 1 ? 'dependency' : 'dependencies'}`}>{deps}</span> : null}
              {dependents !== undefined ? (
                <span title={`Used by ${dependents} across the ecosystem`} className={deps !== undefined ? 'border-l border-border pl-1.5' : ''}>
                  used by {dependents}
                </span>
              ) : null}
            </span>
          </Link>
        ) : null}
        {nav.library.counts.php_functions ? (
          <Link to="/$lib/functions" params={{ lib: slug }} className={row}>
            <span className="truncate">PHP functions</span>
            <span className="ml-auto font-mono text-[10px] whitespace-nowrap text-muted-foreground tabular-nums">{nav.library.counts.php_functions.entries}</span>
          </Link>
        ) : null}
        {migration && nav.library.bench ? (
          <Link to="/$lib/benchmarks" params={{ lib: slug }} className={item(true)} title={MIGRATION_TITLE}>
            <span className="truncate">Benchmarks vs PHP</span>
          </Link>
        ) : null}
        {body}
        {commands.length || unported ? (
          <>
            <div className={heading}>Commands</div>
            {commands.map((t) => (
              <Link key={t.name} to="/$lib" params={{ lib: slug }} hash={taskHash(t.name)} activeOptions={{ exact: true, includeHash: true }} className={row} title={t.name}>
                <span className="truncate font-mono text-[12.5px]">{t.bin ?? t.name}</span>
              </Link>
            ))}
            {unported ? (
              <Link to="/$lib" params={{ lib: slug }} hash="commands" activeOptions={{ exact: true, includeHash: true }} className={`${item(true)} text-muted-foreground`} title={MIGRATION_TITLE}>
                <StatusDot status="planned" />
                <span className="truncate">
                  {unported} PHP {unported === 1 ? 'task' : 'tasks'} not ported
                </span>
              </Link>
            ) : null}
          </>
        ) : null}
      </div>
    </nav>
  )
}

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const index = root.useLoaderData()
  const migration = useMigration()
  // A category is in the menu when it has a library to show: with a crate, or any in migration mode.
  const bySlug = new Map(index?.libraries.map((l) => [l.slug, l]) ?? [])
  return (
    <nav className="grid content-start gap-3 p-3" onClick={(e) => (e.target as HTMLElement).closest('a') && onNavigate?.()}>
      <div className="grid gap-px">
        <Link to="/" activeOptions={{ exact: true, includeSearch: false }} className={row}>
          Home
        </Link>
        <Link to="/libraries" activeOptions={{ includeSearch: false }} className={row}>
          Libraries
        </Link>
        <Link to="/map" className={row}>
          Map
        </Link>
        <Link to="/changelog" className={row}>
          Changelog
        </Link>
        {index ? (
          <MigrationOnly>
            <Link to="/appwrite" className={item(true)} title={MIGRATION_TITLE}>
              <ArrowLeftRight className="size-3.5 flex-none text-migration-ink" />
              Appwrite migration
            </Link>
          </MigrationOnly>
        ) : null}
      </div>
      {index ? (
        <Section title="Categories">
          {index.categories.map((c) => {
            const libs = c.libraries.map((s) => bySlug.get(s)).filter((l) => l && (migration || l.rust))
            if (!libs.length) return null
            const rust = libs.some((l) => l?.rust)
            return (
              <Link key={c.id} to="/categories/$category" params={{ category: c.id }} className={item(!rust)} title={rust ? undefined : MIGRATION_TITLE}>
                <span className="truncate">{c.title}</span>
                <span className="ml-auto font-mono text-[10px] text-muted-foreground tabular-nums">{libs.length}</span>
              </Link>
            )
          })}
        </Section>
      ) : null}
    </nav>
  )
}
