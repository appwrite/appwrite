import { HeadContent, Outlet, Scripts, createRootRoute, useMatch, useRouterState } from '@tanstack/react-router'
import { useEffect, useState, type ReactNode } from 'react'
import appCss from '../styles.css?url'
import { Header } from '@/components/Header'
import { FAVICON } from '@/components/Logo'
import { LibraryMenu, Sidebar } from '@/components/Sidebar'
import { loadIndex, type Navigation } from '@/lib/docs'
import { THEME_SCRIPT, applyTheme, useTheme } from '@/lib/prefs'

export const Route = createRootRoute({
  loader: () => loadIndex(),
  head: () => ({
    meta: [
      { charSet: 'utf-8' },
      { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      { title: 'Utopia for Rust' },
      { name: 'description', content: 'Documentation of the Utopia libraries in Rust, with their PHP counterparts.' },
    ],
    links: [
      { rel: 'stylesheet', href: appCss },
      { rel: 'icon', type: 'image/svg+xml', href: FAVICON },
      { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
      { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossOrigin: 'anonymous' },
      // Poppins, as the design system loads it; body and code use system fonts.
      { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap' },
    ],
    scripts: [{ children: THEME_SCRIPT }],
  }),
  shellComponent: Shell,
  component: Layout,
})

function Shell({ children }: { children: ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <HeadContent />
      </head>
      <body>
        {children}
        <Scripts />
      </body>
    </html>
  )
}

function Layout() {
  const index = Route.useLoaderData()
  const theme = useTheme()
  const [menu, setMenu] = useState(false)
  const path = useRouterState({ select: (s) => s.location.pathname })
  const nav = useMatch({ from: '/$lib', shouldThrow: false })?.loaderData as Navigation | undefined
  useEffect(() => applyTheme(theme), [theme])
  useEffect(() => {
    if (theme !== 'system') return
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const update = () => applyTheme('system')
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [theme])
  useEffect(() => setMenu(false), [path])
  return (
    <div className="min-h-screen">
      <Header onMenu={() => setMenu(true)} />
      {/* Two menus, as in the Appwrite docs: the catalog, and beside it the open library's own menu. */}
      <div className={`lg:grid ${nav ? 'lg:grid-cols-[240px_300px_minmax(0,1fr)]' : 'lg:grid-cols-[240px_minmax(0,1fr)]'}`}>
        <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] overflow-y-auto border-r border-border bg-sidebar lg:block">
          <Sidebar />
        </aside>
        {nav ? (
          <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] overflow-y-auto border-r border-border bg-background lg:block">
            <LibraryMenu key={nav.library.slug} nav={nav} />
          </aside>
        ) : null}
        {menu ? (
          <div className="fixed inset-0 z-40 lg:hidden">
            <button type="button" className="absolute inset-0 bg-black/40" aria-label="Close navigation" onClick={() => setMenu(false)} />
            <aside className="absolute inset-y-0 left-0 w-[300px] max-w-[85vw] overflow-y-auto border-r border-border bg-sidebar">
              {nav ? (
                <div className="border-b border-border">
                  <LibraryMenu key={nav.library.slug} nav={nav} onNavigate={() => setMenu(false)} />
                </div>
              ) : null}
              <Sidebar onNavigate={() => setMenu(false)} />
            </aside>
          </div>
        ) : null}
        <main className="min-w-0 px-5 pt-8 pb-16 md:px-10">
          {index ? (
            <Outlet />
          ) : (
            <div className="max-w-2xl rounded-lg border border-border bg-card p-5">
              <h1 className="text-xl">No documentation yet</h1>
              <p className="mt-2 text-muted-foreground">
                Generate it from the repository root with <code className="font-mono">bin/compat crates</code> (or <code className="font-mono">bun run generate</code> here), then reload.
              </p>
            </div>
          )}
        </main>
      </div>
    </div>
  )
}
