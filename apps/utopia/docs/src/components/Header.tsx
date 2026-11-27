import { Link, getRouteApi, useMatch, useRouterState } from '@tanstack/react-router'
import { ChevronDown, Menu as MenuIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { DiscordMark, GitHubMark, Logo } from './Logo'
import { item, menuCategories } from './Sidebar'

const root = getRouteApi('__root__')

const link =
  'inline-flex h-8 items-center gap-1.5 rounded-sm px-3.5 text-[13px] text-sidebar-ink transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-strong'
const active = 'bg-sidebar-accent text-sidebar-strong'
const pressed = 'bg-sidebar-accent/60 text-sidebar-strong'

/** The categories, from the header: a panel of the same rows as the drawer's, opened on click. */
function CategoriesMenu() {
  const index = root.useLoaderData()
  const path = useRouterState({ select: (s) => s.location.pathname })
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  useEffect(() => setOpen(false), [path])
  useEffect(() => {
    if (!open) return
    const outside = (e: PointerEvent) => box.current?.contains(e.target as Node) || setOpen(false)
    const escape = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      setOpen(false)
      button.current?.focus()
    }
    document.addEventListener('pointerdown', outside)
    document.addEventListener('keydown', escape)
    return () => {
      document.removeEventListener('pointerdown', outside)
      document.removeEventListener('keydown', escape)
    }
  }, [open])
  if (!index) return null
  return (
    <div ref={box} className="relative flex h-14 items-center">
      <button ref={button} type="button" aria-expanded={open} aria-controls="categories-menu" onClick={() => setOpen((o) => !o)} className={`${link} cursor-pointer ${path.startsWith('/categories/') ? active : open ? pressed : ''}`}>
        Categories
        <ChevronDown className={`size-3.5 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open ? (
        <nav id="categories-menu" aria-label="Categories" className="absolute top-full left-0 -mt-px grid w-[260px] gap-px border border-border bg-background p-3">
          {menuCategories(index).map(({ category: c, count }) => (
            <Link key={c.id} to="/categories/$category" params={{ category: c.id }} className={item}>
              <span className="truncate">{c.title}</span>
              <span className="ml-auto font-mono text-[10px] text-muted-foreground tabular-nums">{count}</span>
            </Link>
          ))}
        </nav>
      ) : null}
    </div>
  )
}

/** The site's pages, across the header on wide screens; the drawer holds them on small ones. */
function Menu() {
  // A library's pages sit under Libraries.
  const library = !!useMatch({ from: '/$lib', shouldThrow: false })
  return (
    <nav className="hidden h-14 items-center gap-0.5 pl-2 lg:flex" aria-label="Site">
      <Link to="/libraries" activeOptions={{ includeSearch: false }} className={`${link} ${library ? active : ''}`} activeProps={{ className: active }}>
        Libraries
      </Link>
      <CategoriesMenu />
      <Link to="/map" className={link} activeProps={{ className: active }}>
        Map
      </Link>
      <Link to="/changelog" className={link} activeProps={{ className: active }}>
        Changelog
      </Link>
      <Link to="/contributing" className={link} activeProps={{ className: active }}>
        Contributing
      </Link>
    </nav>
  )
}

export function Header({ onMenu }: { onMenu: () => void }) {
  return (
    <header className="sticky top-0 z-30 flex h-14 items-center border-b border-border bg-background">
      <button type="button" onClick={onMenu} className="ml-2 inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent lg:hidden" aria-label="Open navigation">
        <MenuIcon className="size-5" />
      </button>
      <Link to="/" className="flex h-14 flex-none items-center gap-2 px-4 lg:pl-5" aria-label="Utopia for Rust, home">
        <Logo />
      </Link>
      <span className="hidden h-5 w-px bg-border lg:block" aria-hidden />
      <Menu />
      <div className="ml-auto flex items-center gap-1.5 pr-3">
        <a
          href="https://appwrite.io/discord"
          target="_blank"
          rel="noopener"
          className="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label="Appwrite Discord"
          title="Appwrite Discord"
        >
          <DiscordMark className="size-[17px]" />
        </a>
        <a
          href="https://github.com/utopia-rs"
          target="_blank"
          rel="noopener"
          className="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label="utopia-rs on GitHub"
          title="utopia-rs on GitHub"
        >
          <GitHubMark className="size-[17px]" />
        </a>
      </div>
    </header>
  )
}
