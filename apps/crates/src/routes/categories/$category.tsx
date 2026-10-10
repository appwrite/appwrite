import { createFileRoute, getRouteApi, notFound } from '@tanstack/react-router'
import { CardGrid, LibraryCard, PageHeader, Section } from '@/components/Page'
import { loadIndex, STATUS, type Status } from '@/lib/docs'
import { useMigration } from '@/lib/prefs'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/categories/$category')({
  loader: async ({ params }) => {
    const index = await loadIndex()
    if (!index?.categories.some((c) => c.id === params.category)) throw notFound()
  },
  head: ({ params }) => ({ meta: [{ title: `${params.category} · Utopia for Rust` }] }),
  component: CategoryPage,
})

function CategoryPage() {
  const index = root.useLoaderData()
  const { category } = Route.useParams()
  const c = index?.categories.find((x) => x.id === category)
  const migration = useMigration()
  if (!index || !c) return null
  const libs = c.libraries.map((s) => index.libraries.find((l) => l.slug === s)).filter((l) => !!l)
  const groups: Status[] = ['complete', 'progress', 'started', 'planned']
  // Grouped by conversion status in the migration view; the Rust crates alone otherwise.
  if (!migration) {
    const rust = libs.filter((l) => l.rust)
    return (
      <div className="mx-auto grid max-w-[1100px] gap-10">
        <PageHeader eyebrow="Category" title={c.title}>
          {c.description}
        </PageHeader>
        <CardGrid>
          {rust.map((l) => (
            <LibraryCard key={l.slug} lib={l} />
          ))}
        </CardGrid>
        {rust.length ? null : <p className="m-0 text-muted-foreground">No libraries in this category are available in Rust yet.</p>}
      </div>
    )
  }
  return (
    <div className="mx-auto grid max-w-[1100px] gap-10">
      <PageHeader eyebrow="Category" title={c.title}>
        {c.description}
      </PageHeader>
      {groups.map((s) => {
        const list = libs.filter((l) => l.status === s)
        return list.length ? (
          <Section key={s} title={STATUS[s].label} description={STATUS[s].note}>
            <CardGrid>
              {list.map((l) => (
                <LibraryCard key={l.slug} lib={l} />
              ))}
            </CardGrid>
          </Section>
        ) : null
      })}
    </div>
  )
}
