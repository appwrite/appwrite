import { Link, getRouteApi, useMatch, useRouterState } from '@tanstack/react-router'
import { Fragment } from 'react'

const root = getRouteApi('__root__')

/**
 * Where a page sits, above its content: the category, the library and the path inside it.
 * Left out where the header's menu or the page's own header already say it: the top-level
 * pages, a category, and a library's overview.
 */
export function Crumbs() {
  const index = root.useLoaderData()
  const path = useRouterState({ select: (s) => decodeURIComponent(s.location.pathname) })
  const parts = path.split('/').filter(Boolean)
  const crumbs: { label: string; to?: string; params?: Record<string, string> }[] = []
  const service = useMatch({ from: '/migration/services/$service', shouldThrow: false })?.loaderData?.service
  if (parts[0] === 'migration') {
    // The dashboard's tabs say where its own pages sit; a service's page sits under Services.
    if (parts[1] === 'services' && parts[2]) {
      crumbs.push({ label: 'Migration', to: '/migration' }, { label: 'Services', to: '/migration/services' }, { label: service?.title ?? parts[2] })
    }
  } else if (index && parts.length > 1 && !['map', 'changelog', 'libraries', 'categories'].includes(parts[0])) {
    const lib = index.libraries.find((l) => l.slug === parts[0])
    const category = index.categories.find((c) => c.id === lib?.category)
    if (category) crumbs.push({ label: category.title, to: '/categories/$category', params: { category: category.id } })
    if (lib) crumbs.push({ label: lib.title, to: '/$lib', params: { lib: lib.slug } })
    for (const p of parts.slice(1)) crumbs.push({ label: p === 'php-api' ? 'PHP API' : p === 'root' ? 'Crate root' : p === 'functions' && parts.length === 2 ? 'PHP functions' : p })
  }
  if (crumbs.length < 2) return null
  return (
    <nav aria-label="Breadcrumbs" className="mb-6 flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1 font-mono text-[11px] tracking-[0.08em] uppercase">
      {crumbs.map((c, i) => (
        <Fragment key={i}>
          {i ? <span className="text-muted-foreground/35">/</span> : null}
          {c.to && i < crumbs.length - 1 ? (
            <Link to={c.to} params={c.params} className="truncate text-muted-foreground transition-colors hover:text-foreground">
              {c.label}
            </Link>
          ) : (
            <span className={`truncate ${i === crumbs.length - 1 ? 'font-medium text-foreground' : 'text-muted-foreground'}`} aria-current={i === crumbs.length - 1 ? 'page' : undefined}>
              {c.label}
            </span>
          )}
        </Fragment>
      ))}
    </nav>
  )
}
