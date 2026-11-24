import { Outlet, createFileRoute, notFound } from '@tanstack/react-router'
import { loadLibrary, navigation } from '@/lib/docs'
import { share } from '@/lib/share'

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
  head: ({ loaderData, params }) => {
    const title = loaderData?.library.title ?? 'Library'
    const description = loaderData?.library.description ?? ''
    // Its card, rendered at build time by scripts/og.tsx.
    return { meta: share({ title, description, image: `/og/${params.lib}.png`, alt: `${title}: ${description}` }) }
  },
  component: LibraryLayout,
  notFoundComponent: () => <div className="text-muted-foreground">No library by that name.</div>,
})

function LibraryLayout() {
  return <Outlet />
}
