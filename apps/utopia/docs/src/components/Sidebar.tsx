import { Link, getRouteApi } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { isTopLevel, moduleSplat, type Index, type Navigation } from '@/lib/docs'
import { StatusBadge } from './Status'

const root = getRouteApi('__root__')

// The active row: ink text and the red square before it.
export const row =
  'relative flex min-w-0 items-center gap-2 px-2 py-[5px] text-[13px] text-sidebar-ink transition-colors hover:bg-sidebar-accent hover:text-sidebar-strong data-[status=active]:bg-sidebar-accent data-[status=active]:font-medium data-[status=active]:text-sidebar-strong data-[status=active]:before:absolute data-[status=active]:before:top-1/2 data-[status=active]:before:-left-[11.5px] data-[status=active]:before:size-[6px] data-[status=active]:before:-translate-y-1/2 data-[status=active]:before:bg-highlight'

/** A row of the header's and the drawer's menus. */
export const item = row

// A group's title: mono capitals, so it never reads as one of the rows below.
const heading = 'label mt-3 px-2 pt-2 pb-1 text-muted-foreground first:mt-0 first:pt-1'

/**
 * A group of the menu, always open: its title (a link to the group's own page
 * when it has one) and its rows below, no folding.
 */
function Section({ title, count, children }: { title: ReactNode; count?: number; children: ReactNode }) {
  return (
    <div className="grid gap-px">
      <div className={`flex min-w-0 items-center gap-2 ${heading}`}>
        <span className="min-w-0 flex-1 truncate">{title}</span>
        {count !== undefined ? <span className="tabular-nums tracking-normal">{count}</span> : null}
      </div>
      {children}
    </div>
  )
}

/** A library's own menu, a second column beside the catalog: its sections and modules. */
export function LibraryMenu({ nav, onNavigate }: { nav: Navigation; onNavigate?: () => void }) {
  const slug = nav.library.slug
  const [q, setQ] = useState('')
  const term = q.trim().toLowerCase()
  const top = nav.items.filter(isTopLevel)
  const hit = (text: string) => !term || text.toLowerCase().includes(term)
  const counts = nav.library.counts
  const deps = counts.dependencies.rust
  // Everything depending on it: the other crates, and Appwrite when its server imports it.
  const dependents = counts.dependencies.used_by + (counts.appwrite ? 1 : 0)
  // The library's architecture: its top-level modules, each a page that
  // holds its whole subtree. Submodules are sections of that page.
  const modules = nav.modules.filter((m) => m.path && !m.path.includes('::'))
  const below = (path: string) => nav.modules.filter((m) => m.path.startsWith(`${path}::`))
  const shown = modules
    .filter((m) => hit(m.path) || below(m.path).some((c) => hit(c.path)))
    .sort((a, b) => a.path.localeCompare(b.path))
  const hasRoot = top.some((i) => i.module === '') && hit('crate root')
  const body = (
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

  return (
    <nav className="grid content-start gap-3 px-5 py-3" onClick={(e) => (e.target as HTMLElement).closest('a') && onNavigate?.()}>
      <div className="grid gap-1.5 border-b border-foreground pb-3">
        <div className="flex items-center justify-between gap-2">
          <Link to="/$lib" params={{ lib: slug }} className="title truncate text-[19px] leading-none">
            {nav.library.title}
          </Link>
          <StatusBadge status={nav.library.status} />
        </div>
        <span className="truncate font-mono text-[11px] text-muted-foreground">{nav.library.rust?.crate ?? nav.library.slug}</span>
      </div>
      <label className="flex h-8 items-center gap-2 border border-border bg-card px-2.5 text-muted-foreground transition-colors focus-within:border-foreground">
        <Search className="size-3.5" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Filter modules"
          aria-label="Filter modules"
          className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
        />
      </label>
      {/* The rows' highlight bleeds into the gutter so their text lines up with the header's. */}
      <div className="-mx-2 grid gap-0.5">
        <div className={heading}>Library</div>
        <Link to="/$lib" params={{ lib: slug }} activeOptions={{ exact: true, includeHash: false }} className={row}>
          Overview
        </Link>
        <Link to="/$lib/dependencies" params={{ lib: slug }} className={row}>
          <span className="truncate">Dependencies</span>
          <span className="ml-auto flex items-center gap-1.5 font-mono text-[10px] whitespace-nowrap text-muted-foreground tabular-nums">
            <span title={`${deps} ${deps === 1 ? 'dependency' : 'dependencies'}`}>{deps}</span>
            <span title={`Used by ${dependents} across the ecosystem`} className="border-l border-border pl-1.5">
              used by {dependents}
            </span>
          </span>
        </Link>
        {body}
      </div>
    </nav>
  )
}

/** The categories the menus list: those with a library, each with how many it has. */
export function menuCategories(index: Index) {
  return index.categories.flatMap((category) => (category.libraries.length ? [{ category, count: category.libraries.length }] : []))
}

/** The site's pages and categories as a column: the header's menu, in the drawer on small screens. */
export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const index = root.useLoaderData()
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
        <Link to="/contributing" className={row}>
          Contributing
        </Link>
      </div>
      {index ? (
        <Section title="Categories">
          {menuCategories(index).map(({ category: c, count }) => (
            <Link key={c.id} to="/categories/$category" params={{ category: c.id }} className={row}>
              <span className="truncate">{c.title}</span>
              <span className="ml-auto font-mono text-[10px] text-muted-foreground tabular-nums">{count}</span>
            </Link>
          ))}
        </Section>
      ) : null}
    </nav>
  )
}
