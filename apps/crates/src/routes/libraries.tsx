import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { PageHeader } from '@/components/Page'
import { Catalog, filtersFrom, type Filters } from '@/components/Catalog'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/libraries')({
  validateSearch: filtersFrom,
  head: () => ({ meta: [{ title: 'Libraries · Utopia for Rust' }] }),
  component: Libraries,
})

/** Every library, the list of the home page on its own. */
function Libraries() {
  const index = root.useLoaderData()
  const filters = Route.useSearch()
  const navigate = Route.useNavigate()
  if (!index) return null
  const set = (patch: Filters) => navigate({ search: (s) => ({ ...s, ...patch }), replace: true, resetScroll: false })
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <PageHeader eyebrow="Index" title="Libraries">
        Small, focused crates, each solving one problem, grouped by what they do.
      </PageHeader>
      <Catalog index={index} filters={filters} set={set} />
    </div>
  )
}
