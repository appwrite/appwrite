import { Outlet, createFileRoute, notFound, useMatch } from '@tanstack/react-router'
import { Crumbs } from '@/components/Crumbs'
import { ContentWidth, DocAsideRow } from '@/components/Page'
import { loadLibrary, navigation } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

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
  component: LibraryLayout,
  notFoundComponent: () => <div className="text-muted-foreground">No library by that name.</div>,
})

/** Breadcrumbs share the doc aside grid with the page body so they line up with the content column. */
function LibraryLayout() {
  const symbols = !!useMatch({ from: '/$lib/$', shouldThrow: false })
  const phpApi = !!useMatch({ from: '/$lib/php-api/$', shouldThrow: false })
  const rail = symbols || phpApi
  const php = useMigration()
  const crumbs = (
    <ContentWidth>
      <Crumbs />
    </ContentWidth>
  )
  return (
    <>
      {php && !rail ? crumbs : (
        <DocAsideRow rail={rail} reserveAside={!rail && !php}>
          {crumbs}
        </DocAsideRow>
      )}
      <Outlet />
    </>
  )
}
