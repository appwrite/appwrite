import { Outlet, createFileRoute, notFound } from '@tanstack/react-router'
import { loadLibrary, navigation } from '@/lib/docs'

export const Route = createFileRoute('/$lib')({
  loader: async ({ params }) => {
    const lib = await loadLibrary(params.lib)
    if (!lib) throw notFound()
    return navigation(lib)
  },
  head: ({ loaderData }) => ({ meta: [{ title: `${loaderData?.library.title ?? 'Library'} · Utopia for Rust` }] }),
  component: Outlet,
  notFoundComponent: () => <div className="text-muted-foreground">No library by that name.</div>,
})
