import { Link, getRouteApi, useMatch, useRouterState } from '@tanstack/react-router'
import { ChevronRight, ChevronsDownUp, ChevronsUpDown, Search } from 'lucide-react'
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { isTopLevel, moduleSplat, type Navigation } from '@/lib/docs'
import { StatusBadge, StatusDot } from './Status'
import { MigrationOnly } from './Migration'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')

const row =
  'flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-[13px] text-sidebar-ink transition-colors hover:bg-sidebar-accent hover:text-sidebar-strong data-[status=active]:bg-sidebar-accent data-[status=active]:text-sidebar-strong'

/** Expand all / Collapse all: each press tells every group in the menu to open or close. */
const Fold = createContext<{ open: boolean; n: number } | null>(null)

function FoldControls({ fold, onFold }: { fold: { open: boolean } | null; onFold: (open: boolean) => void }) {
  // Most groups start closed, so the first press expands.
  const open = !fold?.open
  const label = open ? 'Expand all' : 'Collapse all'
  return (
    <button
      type="button"
      onClick={() => onFold(open)}
      className="inline-flex size-7 flex-none items-center justify-center rounded-md text-sidebar-ink transition-colors hover:bg-sidebar-accent hover:text-sidebar-strong"
      title={label}
      aria-label={label}
    >
      {open ? <ChevronsUpDown className="size-3.5" /> : <ChevronsDownUp className="size-3.5" />}
    </button>
  )
}

function useFold() {
  const [fold, setFold] = useState<{ open: boolean; n: number } | null>(null)
  return [fold, (open: boolean) => setFold((f) => ({ open, n: (f?.n ?? 0) + 1 }))] as const
}

function Group({ label, open: initial, children, depth = 0, badge }: { label: ReactNode; open: boolean; children: ReactNode; depth?: number; badge?: ReactNode }) {
  const [open, setOpen] = useState(initial)
  const fold = useContext(Fold)
  useEffect(() => {
    if (initial) setOpen(true)
  }, [initial])
  useEffect(() => {
    if (fold) setOpen(fold.open)
  }, [fold])
  return (
    <div className="grid">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className={`flex min-w-0 items-center gap-1.5 rounded-md py-1.5 pr-2 text-left text-[13px] transition-colors hover:bg-sidebar-accent ${depth === 0 ? 'pl-2 font-medium text-sidebar-strong' : 'pl-2 text-sidebar-ink hover:text-sidebar-strong'}`}
      >
        <ChevronRight className={`size-3.5 flex-none text-muted-foreground transition-transform ${open ? 'rotate-90' : ''}`} />
        <span className="min-w-0 flex-1 truncate">{label}</span>
        {badge}
      </button>
      {open ? <div className="ml-[13px] grid border-l border-border pl-2">{children}</div> : null}
    </div>
  )
}

const heading = 'px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase'

/** A library's own menu, a second column beside the catalog: its sections, namespaces and items. */
export function LibraryMenu({ nav, onNavigate }: { nav: Navigation; onNavigate?: () => void }) {
  const [fold, setFold] = useFold()
  const slug = nav.library.slug
  const path = useRouterState({ select: (s) => decodeURIComponent(s.location.pathname) })
  const [q, setQ] = useState('')
  const term = q.trim().toLowerCase()
  const top = nav.items.filter(isTopLevel)
  const hit = (text: string) => !term || text.toLowerCase().includes(term)
  const migration = useMigration()
  let body: ReactNode
  if (!nav.library.rust && !migration) {
    body = null
  } else if (!nav.library.rust) {
    const nsOf = (c: string) => (c.includes('\\') ? c.slice(0, c.lastIndexOf('\\')) : '')
    // Namespaces are shown relative to the library's own (`Utopia\CircuitBreaker\Adapter` → `Adapter`).
    const base = nav.phpClasses.map(nsOf).reduce((a, b) => (b.length < a.length ? b : a), nsOf(nav.phpClasses[0] ?? ''))
    const byNamespace = new Map<string, string[]>()
    for (const c of nav.phpClasses.filter(hit)) byNamespace.set(nsOf(c), [...(byNamespace.get(nsOf(c)) ?? []), c])
    body = (
      <>
        <div className="flex items-center justify-between pr-1">
          <div className={heading}>PHP API</div>
          <FoldControls fold={fold} onFold={setFold} />
        </div>
        {[...byNamespace.entries()].map(([ns, classes]) => (
          <Group
            key={ns}
            depth={1}
            label={<span className="font-mono text-[12px]">{ns === base ? (base.split('\\').pop() ?? 'global') : ns.slice(base.length + 1)}</span>}
            open={!!term || byNamespace.size === 1 || classes.some((c) => path.endsWith(`/php-api/${c.split('\\').join('/')}`))}
          >
            {classes.map((c) => (
              <Link key={c} to="/$lib/php-api/$" params={{ lib: slug, _splat: c.split('\\').join('/') }} className={row}>
                <span className="truncate font-mono text-[12px]">{c.split('\\').pop()}</span>
              </Link>
            ))}
          </Group>
        ))}
      </>
    )
  } else {
    // The library's architecture: its top-level namespaces, each a page that
    // holds its whole subtree. Sub-namespaces are sections of that page.
    const modules = nav.modules.filter((m) => m.path && !m.path.includes('::'))
    const below = (path: string) => nav.modules.filter((m) => m.path.startsWith(`${path}::`))
    const shown = modules
      .filter((m) => hit(m.path) || below(m.path).some((c) => hit(c.path)))
      .sort((a, b) => a.path.localeCompare(b.path))
    const hasRoot = top.some((i) => i.module === '') && hit('crate root')
    body = (
      <>
        <div className={heading}>Namespaces</div>
        {hasRoot ? (
          <Link to="/$lib/$" params={{ lib: slug, _splat: moduleSplat('') }} className={row}>
            <span className="truncate">Crate root</span>
          </Link>
        ) : null}
        {shown.map((m) => {
          const parts = below(m.path).length
          return (
            <Link key={m.path} to="/$lib/$" params={{ lib: slug, _splat: moduleSplat(m.path) }} className={`${row} items-start`} title={m.summary || m.path}>
              <span className="grid min-w-0 gap-0.5">
                <span className="truncate font-mono text-[12.5px]">{m.path}</span>
                {m.summary ? <span className="line-clamp-1 text-[11.5px] text-muted-foreground">{m.summary}</span> : null}
              </span>
              {parts ? <span className="ml-auto pt-0.5 text-[10.5px] whitespace-nowrap text-muted-foreground">{parts} parts</span> : null}
            </Link>
          )
        })}
        {!hasRoot && !shown.length ? <span className="px-2 py-1 text-[12.5px] text-muted-foreground">No namespace matches.</span> : null}
      </>
    )
  }

  return (
    <nav className="grid content-start gap-3 p-3" onClick={(e) => (e.target as HTMLElement).closest('a') && onNavigate?.()}>
      <div className="grid gap-2 rounded-lg border border-border bg-background p-2.5">
        <div className="flex items-center justify-between gap-2">
          <Link to="/$lib" params={{ lib: slug }} className="truncate font-display text-[15px] font-medium">
            {nav.library.title}
          </Link>
          <MigrationOnly>
            <StatusBadge status={nav.library.status} />
          </MigrationOnly>
        </div>
        <span className="truncate font-mono text-[11px] text-muted-foreground">{nav.library.rust?.crate ?? nav.library.php?.package}</span>
      </div>
      <label className="flex items-center gap-2 rounded-md border border-border bg-background px-2.5 py-1.5 text-muted-foreground focus-within:border-ring">
        <Search className="size-3.5" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={nav.library.rust ? 'Filter namespaces' : 'Filter classes'}
          aria-label={nav.library.rust ? 'Filter namespaces' : 'Filter classes'}
          className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
        />
      </label>
      <div className="grid gap-0.5">
        <div className={heading}>Library</div>
        <Link to="/$lib" params={{ lib: slug }} activeOptions={{ exact: true, includeHash: false }} className={row}>
          Overview
        </Link>
        {nav.library.counts.guide ? (
          <Link to="/$lib" params={{ lib: slug }} hash="getting-started" activeOptions={{ exact: true, includeHash: true }} className={row}>
            Getting started
          </Link>
        ) : null}
        <Fold.Provider value={fold}>{body}</Fold.Provider>
      </div>
    </nav>
  )
}

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const index = root.useLoaderData()
  const libMatch = useMatch({ from: '/$lib', shouldThrow: false })
  const nav = libMatch?.loaderData as Navigation | undefined
  const [q, setQ] = useState('')
  const migration = useMigration()
  const [fold, setFold] = useFold()
  if (!index) return null
  const activeCategory = nav?.library.category
  const term = q.trim().toLowerCase()
  const bySlug = new Map(index.libraries.map((l) => [l.slug, l]))

  return (
    <nav className="grid content-start gap-3 p-3" onClick={(e) => (e.target as HTMLElement).closest('a') && onNavigate?.()}>
      <label className="flex items-center gap-2 rounded-md border border-border bg-background px-2.5 py-1.5 text-muted-foreground focus-within:border-ring">
        <Search className="size-3.5" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Filter libraries"
          aria-label="Filter libraries"
          className="min-w-0 flex-1 bg-transparent text-[13px] text-foreground outline-none placeholder:text-muted-foreground"
        />
      </label>
      <div className="flex items-center gap-1">
        <Link to="/" activeOptions={{ exact: true }} className={`${row} flex-1`}>
          All libraries
        </Link>
        <FoldControls fold={fold} onFold={setFold} />
      </div>
      <Fold.Provider value={fold}>
      {index.categories.map((c) => {
        const libs = c.libraries
          .map((s) => bySlug.get(s))
          // Libraries without a Rust crate belong to the migration view.
          .filter((l) => l && (migration || l.rust) && (!term || `${l.title} ${l.slug} ${l.description}`.toLowerCase().includes(term)))
        if (!libs.length) return null
        return (
          <Group key={c.id} label={c.title} open={!!term || c.id === activeCategory} badge={<span className="text-[11px] text-muted-foreground tabular-nums">{libs.length}</span>}>
            <Link to="/categories/$category" params={{ category: c.id }} className={row}>
              About {c.title.toLowerCase()}
            </Link>
            {libs.map((l) =>
              l ? (
                <Link key={l.slug} to="/$lib" params={{ lib: l.slug }} className={row}>
                  <MigrationOnly>
                    <StatusDot status={l.status} />
                  </MigrationOnly>
                  <span className="truncate">{l.title}</span>
                </Link>
              ) : null,
            )}
          </Group>
        )
      })}
      </Fold.Provider>
    </nav>
  )
}
