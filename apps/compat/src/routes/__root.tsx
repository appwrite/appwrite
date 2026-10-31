import { HeadContent, Link, Outlet, Scripts, createRootRoute } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import appCss from '../styles.css?url'
import { loadIndex } from '@/lib/report'
import { fmt } from '@/components/ui'

export const Route = createRootRoute({
  loader: () => loadIndex(),
  head: () => ({
    meta: [
      { charSet: 'utf-8' },
      { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      { title: 'Utopia Rust Parity' },
      { name: 'description', content: 'Conversion status of the Utopia libraries from PHP to Rust.' },
    ],
    links: [
      { rel: 'stylesheet', href: appCss },
      { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
      { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap' },
    ],
  }),
  shellComponent: Shell,
  component: Layout,
})

function Shell({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
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
  return (
    <div className="px-4 pb-12">
      <header className="sticky top-0 z-10 -mx-4 border-b border-border bg-background/80 px-4 py-3 backdrop-blur">
        <div className="mx-auto flex max-w-[1200px] flex-wrap items-baseline justify-between gap-x-5 gap-y-2">
          <Link to="/" className="flex items-baseline gap-2.5">
            <span className="font-display text-[0.8rem] font-semibold tracking-wide text-periwinkle">Utopia</span>
            <h1 className="text-2xl leading-tight">PHP ↔ Rust parity</h1>
          </Link>
          {index ? (
            <div className="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-muted-foreground">
              {(
                [
                  ['Commit', index.commit],
                  ['Branch', index.branch],
                  ['Generated', index.generated.replace('T', ' ').replace('Z', ' UTC')],
                  ['Fuzz seed', index.seed],
                  ['Inputs per profile', fmt(index.iterations)],
                ] as const
              ).map(([k, v]) => (
                <span key={k}>
                  {k} <b className="font-mono text-[0.72rem] font-medium text-foreground">{v ?? '—'}</b>
                </span>
              ))}
            </div>
          ) : null}
        </div>
      </header>
      <main className="mx-auto grid max-w-[1200px] gap-7 pt-6">
        {index ? (
          <Outlet />
        ) : (
          <div className="rounded-[10px] border border-warn-border bg-warn-surface px-4 py-3 text-warn-ink">
            <b>No report yet.</b> Generate one from the repository root with <code className="font-mono">bin/compat report</code> (or{' '}
            <code className="font-mono">bun run generate</code> here), then reload.
          </div>
        )}
      </main>
    </div>
  )
}
