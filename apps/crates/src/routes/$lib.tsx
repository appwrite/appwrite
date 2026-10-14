import { Outlet, createFileRoute, notFound } from '@tanstack/react-router'
import { loadLibrary, navigation } from '@/lib/docs'

/** Sibling pages at the root. `$lib` must not treat these slugs as libraries. */
const PAGES = new Set(['appwrite', 'categories', 'changelog', 'libraries', 'map'])

export const Route = createFileRoute('/$lib')({
  params: {
    parse: ({ lib }) => (PAGES.has(lib) ? false : { lib }),
  },
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    return navigation(lib)
  },
  head: ({ loaderData }) => ({ meta: [{ title: `${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: Outlet,
  notFoundComponent: () => <div className="text-muted-foreground">No library by that name.</div>,
})
