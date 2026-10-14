import { HeadContent, Outlet, Scripts, createRootRoute, useMatch, useRouterState } from '@tanstack/react-router'
import { useEffect, useState, type ReactNode } from 'react'
import appCss from '../styles.css?url'
import { Crumbs } from '@/components/Crumbs'
import { Header } from '@/components/Header'
import { FAVICON } from '@/components/Logo'
import { Footer } from '@/components/Footer'
import { LibraryMenu, Sidebar } from '@/components/Sidebar'
import { loadIndex, type Navigation } from '@/lib/docs'

export const Route = createRootRoute({
  loader: () => loadIndex(),
  head: () => ({
    meta: [
      { charSet: 'utf-8' },
      { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      { title: 'Utopia for Rust' },
      { name: 'description', content: 'Documentation of the Utopia libraries in Rust.' },
    ],
    links: [
      { rel: 'stylesheet', href: appCss },
      { rel: 'icon', type: 'image/svg+xml', href: FAVICON },
      { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
      { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossOrigin: 'anonymous' },
      // Archivo (with its width axis) for titles, Geist for text, Geist Mono for code and labels.
      {
        rel: 'stylesheet',
        href: 'https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@100..125,400..800&family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap',
      },
    ],
  }),
  shellComponent: Shell,
  component: Layout,
})

function Shell({ children }: { children: ReactNode }) {
  return (
    <html lang="en" className="dark">
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
  const [menu, setMenu] = useState(false)
  const path = useRouterState({ select: (s) => s.location.pathname })
  const nav = useMatch({ from: '/$lib', shouldThrow: false })?.loaderData as Navigation | undefined
  useEffect(() => setMenu(false), [path])
  return (
    <div className="min-h-screen">
      <Header onMenu={() => setMenu(true)} />
      {/* The site's pages are in the header; a library's pages add its own menu on the left. */}
      <div className={nav ? 'lg:grid lg:grid-cols-[300px_minmax(0,1fr)]' : ''}>
        {nav ? (
          <aside className="sticky top-14 hidden h-[calc(100vh-3.5rem)] overflow-y-auto border-r border-border bg-sidebar lg:block">
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
          <Crumbs />
          <Outlet />
          {index ? <Footer index={index} /> : null}
        </main>
      </div>
    </div>
  )
}
