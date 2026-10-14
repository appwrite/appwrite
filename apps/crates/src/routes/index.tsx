import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { useMemo } from 'react'
import { Hero } from '@/components/Hero'
import { plan } from '@/components/Factory'
import { Catalog, filtersFrom, type Filters } from '@/components/Catalog'
import { APPWRITE, loadGraph } from '@/lib/docs'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/')({
  validateSearch: filtersFrom,
  loader: () => loadGraph(),
  component: Home,
})

/** The home page: the hero, then every library, as the Libraries page lists them. */
function Home() {
  const index = root.useLoaderData()
  const filters = Route.useSearch()
  const navigate = Route.useNavigate()
  const graph = Route.useLoaderData()
  // The factory drawing: Appwrite's parts, built from the libraries with a crate.
  const factory = useMemo(() => {
    const components = graph?.nodes.find((n) => n.id === APPWRITE)?.components
    return components && index ? plan(components, new Set(index.libraries.filter((l) => l.rust).map((l) => l.slug))) : null
  }, [graph, index])
  if (!index) return null
  const set = (patch: Filters) => navigate({ search: (s) => ({ ...s, ...patch }), replace: true, resetScroll: false })
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <Hero index={index} factory={factory} />
      <Catalog index={index} filters={filters} set={set} />
    </div>
  )
}
