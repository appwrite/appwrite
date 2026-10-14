import { Link, getRouteApi, useMatch, useRouterState } from '@tanstack/react-router'
import { Menu, Monitor, Moon, Sun } from 'lucide-react'
import { Fragment } from 'react'
import { setMigration, useMigration, useTheme, write } from '@/lib/prefs'
import { DiscordMark, GitHubMark, Logo } from './Logo'

const root = getRouteApi('__root__')

function Crumbs() {
  const index = root.useLoaderData()
  const path = useRouterState({ select: (s) => decodeURIComponent(s.location.pathname) })
  const parts = path.split('/').filter(Boolean)
  const crumbs: { label: string; to?: string; params?: Record<string, string> }[] = []
  const service = useMatch({ from: '/appwrite/$service', shouldThrow: false })?.loaderData?.service
  if (parts[0] === 'appwrite') {
    crumbs.push({ label: 'Appwrite migration', to: '/appwrite' })
    if (parts[1]) crumbs.push({ label: service?.title ?? parts[1] })
  } else if (parts[0] === 'map') {
    crumbs.push({ label: 'Map' })
  } else if (parts[0] === 'changelog') {
    crumbs.push({ label: 'Changelog' })
  } else if (parts[0] === 'libraries') {
    crumbs.push({ label: 'Libraries' })
  } else if (index && parts[0] === 'categories' && parts[1]) {
    crumbs.push({ label: index.categories.find((c) => c.id === parts[1])?.title ?? parts[1] })
  } else if (index && parts[0]) {
    const lib = index.libraries.find((l) => l.slug === parts[0])
    const category = index.categories.find((c) => c.id === lib?.category)
    if (category) crumbs.push({ label: category.title, to: '/categories/$category', params: { category: category.id } })
    if (lib) crumbs.push({ label: lib.title, to: '/$lib', params: { lib: lib.slug } })
    for (const p of parts.slice(1)) crumbs.push({ label: p === 'php-api' ? 'PHP API' : p === 'root' ? 'Crate root' : p === 'functions' && parts.length === 2 ? 'PHP functions' : p })
  }
  return (
    <div className="flex min-w-0 items-center gap-2.5 font-mono text-[11px] tracking-[0.08em] uppercase">
      {crumbs.map((c, i) => (
        <Fragment key={i}>
          <span className="text-muted-foreground/35">/</span>
          {c.to && i < crumbs.length - 1 ? (
            <Link to={c.to} params={c.params} className="truncate text-muted-foreground hover:text-foreground">
              {c.label}
            </Link>
          ) : (
            <span className={`truncate ${i === crumbs.length - 1 ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>{c.label}</span>
          )}
        </Fragment>
      ))}
    </div>
  )
}

function MigrationSwitch() {
  const on = useMigration()
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      onClick={() => setMigration(!on)}
      className={`inline-flex h-8 items-center gap-2 border px-2.5 font-mono text-[10.5px] tracking-[0.08em] uppercase transition-colors ${on ? 'border-migration-border bg-migration text-migration-ink' : 'border-border text-muted-foreground hover:border-foreground hover:text-foreground'}`}
      title="Show the PHP to Rust migration: PHP examples and signatures, conversion status and sync with PHP"
    >
      <span className={`relative inline-flex h-3.5 w-6 flex-none border transition-colors ${on ? 'border-amber-600 bg-amber-500' : 'border-ring bg-transparent'}`}>
        <span className={`absolute top-[2px] size-2 transition-all ${on ? 'left-[12px] bg-background' : 'left-[2px] bg-muted-foreground'}`} />
      </span>
      <span className="sr-only sm:not-sr-only">Migration</span>
    </button>
  )
}

function ThemeToggle() {
  const theme = useTheme()
  const next = theme === 'system' ? 'light' : theme === 'light' ? 'dark' : 'system'
  const Icon = theme === 'light' ? Sun : theme === 'dark' ? Moon : Monitor
  return (
    <button
      type="button"
      onClick={() => write('theme', next)}
      className="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
      aria-label={`Theme: ${theme}. Switch to ${next}.`}
      title={`Theme: ${theme}`}
    >
      <Icon className="size-4" />
    </button>
  )
}

export function Header({ onMenu }: { onMenu: () => void }) {
  return (
    <header className="sticky top-0 z-30 flex h-14 items-center border-b border-border bg-background">
      <button type="button" onClick={onMenu} className="ml-2 inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent lg:hidden" aria-label="Open navigation">
        <Menu className="size-5" />
      </button>
      <Link to="/" className="flex h-14 flex-none items-center gap-2 px-4 lg:w-[240px] lg:border-r lg:border-border">
        <Logo />
      </Link>
      <div className="hidden min-w-0 flex-1 px-4 md:block">
        <Crumbs />
      </div>
      <div className="ml-auto flex items-center gap-1.5 pr-3">
        <MigrationSwitch />
        <span className="mx-0.5 h-5 w-px bg-border sm:mx-1" aria-hidden />
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
          href="https://github.com/appwrite/appwrite/tree/main/crates/utopia"
          target="_blank"
          rel="noopener"
          className="inline-flex size-8 items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label="Source on GitHub"
          title="Source on GitHub"
        >
          <GitHubMark className="size-[17px]" />
        </a>
        <ThemeToggle />
      </div>
    </header>
  )
}
